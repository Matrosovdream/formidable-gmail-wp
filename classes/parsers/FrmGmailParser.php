<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Parser:
 * - Gmail query is built ONLY from statuses (+ optional start_date)
 * - Masks are applied AFTER fetching results (Delivered-To ONLY), and {entry_id} is extracted then
 * - {entry_id} captures DIGITS ONLY (\d+) to support patterns like store+12345@...
 */
final class FrmGmailParser {

    /** Parse masks like 'order-{entry_id}', 'store+{entry_id}', etc. → [raw,prefix,regex] */
    public static function parseSubjectMasks(string $maskText): array {
        $parts = preg_split('/[\n,]+/', (string)$maskText);
        $out = [];
        foreach ($parts as $mask) {
            $mask = trim($mask);
            if ($mask === '') { continue; }

            // prefix (kept for potential diagnostics)
            $prefix = $mask;
            if (($p = strpos($prefix, '{entry_id}')) !== false) { $prefix = substr($prefix, 0, $p); }
            if (($p = strpos($prefix, '*')) !== false)         { $prefix = substr($prefix, 0, $p); }
            $prefix = trim($prefix);

            // digits-only entry_id
            $pat   = preg_quote($mask, '/');
            $pat   = str_replace('\{entry_id\}', '(?P<entry_id>\d+)', $pat);
            $regex = '/'.$pat.'/i';

            $out[] = ['raw' => $mask, 'prefix' => $prefix, 'regex' => $regex];
        }
        return $out;
    }

    /** Build Gmail 'q' from statuses (+ optional start_date); masks are NOT used here */
    private static function buildStatusOnlyQuery(array $statuses, ?string $startDate): string {
        $esc = function(string $s): string {
            return str_replace(['\\', '"'], ['\\\\', '\"'], $s);
        };
        $statuses = array_values(array_filter(array_map('trim', $statuses), fn($s) => $s !== ''));
        if (!$statuses) { return ''; }

        $statusTerms = array_map(fn($s) => 'subject:"'.$esc($s).'"', $statuses);
        $q = '(' . implode(' OR ', $statusTerms) . ')';

        if ($startDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $q .= ' after:' . str_replace('-', '/', $startDate); // Gmail expects YYYY/MM/DD
        }
        return $q;
    }

    /** ---- Helpers to decode message bodies ---- */

    /** Base64url decode */
    private static function b64url_decode(string $data): string {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) { $data .= str_repeat('=', 4 - $pad); }
        return base64_decode($data) ?: '';
    }

    /** Recursively collect parts by mime */
    private static function collectPartsByMime($payload, string $wantMime, array &$out): void {
        if (!$payload) { return; }
        $mimeType = method_exists($payload, 'getMimeType') ? $payload->getMimeType() : '';
        if ($mimeType === $wantMime && method_exists($payload, 'getBody') && $payload->getBody()) {
            $raw = $payload->getBody()->getData() ?? '';
            if ($raw !== '') {
                $out[] = self::b64url_decode($raw);
            }
        }
        if (method_exists($payload, 'getParts') && $payload->getParts()) {
            foreach ($payload->getParts() as $p) {
                self::collectPartsByMime($p, $wantMime, $out);
            }
        }
    }

    /** Extract best-effort plain body (prefer text/plain; else strip tags from text/html; else fallback to decoded body) */
    private static function extractPlainBody(\Google\Service\Gmail\Message $msg): string {
        $payload = $msg->getPayload();
        if (!$payload) { return ''; }

        // First try text/plain parts
        $plain = [];
        self::collectPartsByMime($payload, 'text/plain', $plain);
        if (!empty($plain)) {
            return trim(implode("\n\n", $plain));
        }

        // Next try text/html parts (strip tags)
        $html = [];
        self::collectPartsByMime($payload, 'text/html', $html);
        if (!empty($html)) {
            $joined = trim(implode("\n\n", $html));
            // Basic html to text
            $text = wp_strip_all_tags($joined, true);
            return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Fallback: sometimes the body is directly on the payload
        if ($payload->getBody() && $payload->getBody()->getSize() > 0) {
            $raw = $payload->getBody()->getData() ?? '';
            return trim(self::b64url_decode($raw));
        }

        return '';
    }

    /**
     * Fetch and post-filter messages.
     *
     * @param int  $idx        Account index (option row)
     * @param int  $batchSize  Number of message IDs per Gmail API page (default 500)
     * @param int  $maxPages   Max number of pages to scan (default 3)
     *
     * @return array {
     *   @type array       $items  [ ['id','from','subject','status','entryId','body'], ... ]
     *   @type null|string $error
     * }
     */
    public static function getAllMessages(int $idx, int $batchSize = 500, int $maxPages = 3): array {
        $row = FrmGmailParserHelper::getAccount($idx);
        if (!$row) {
            return ['items' => [], 'error' => 'Account not found.'];
        }

        $creds     = $row['credentials'] ?? '';
        $token     = $row['token']       ?? null;
        $startDate = FrmGmailParserHelper::getStartDate();
        $statuses  = FrmGmailParserHelper::getStatusesArrayForAccount($idx);
        $maskText  = FrmGmailParserHelper::getMaskTextForAccount($idx);

        if (empty($statuses)) {
            return ['items' => [], 'error' => 'Please add at least one Status (in subject) to test.'];
        }
        if ( empty($token) || !is_array($token) ) {
            return ['items' => [], 'error' => 'Not connected.'];
        }

        $query      = self::buildStatusOnlyQuery($statuses, $startDate);
        $masks      = self::parseSubjectMasks($maskText);
        $hasMasks   = !empty($masks);
        $statusesRx = array_map(fn($s) => '/' . preg_quote($s, '/') . '/i', $statuses);

        try {
            $api    = new FrmGmailApi($creds, $token);
            $client = $api->makeClient();
            if (!$client) {
                return ['items' => [], 'error' => 'Invalid credentials JSON.'];
            }

            $client->setApplicationName('Gmail Parser (WP) - Account #' . ($idx + 1));
            $client->setScopes([ \Google\Service\Gmail::GMAIL_READONLY ]);

            // Refresh if needed
            [$client, $newTok] = $api->ensureFreshToken($client);
            if ($newTok) {
                FrmGmailParserHelper::setTokenForAccount($idx, $newTok);
            } elseif ( $client->isAccessTokenExpired() ) {
                return ['items' => [], 'error' => 'Token expired and no refresh token present. Please Reconnect.'];
            }

            $gmail = new \Google\Service\Gmail($client);

            $items      = [];
            $pageToken  = null;
            $safetyPage = 0;

            do {
                $params = ['maxResults' => max(1, (int)$batchSize)];
                if ($query !== '') { $params['q'] = $query; }
                if ($pageToken)    { $params['pageToken'] = $pageToken; }

                $list = $gmail->users_messages->listUsersMessages('me', $params);
                $msgs = $list->getMessages() ?: [];
                $pageToken = $list->getNextPageToken();

                foreach ($msgs as $m) {
                    // Get FULL message to access body
                    $msg = $gmail->users_messages->get('me', $m->getId(), ['format' => 'full']);

                    // Headers
                    $headers = [];
                    foreach ($msg->getPayload()->getHeaders() as $h) { $headers[$h->getName()] = $h->getValue(); }
                    $from         = $headers['From'] ?? '';
                    $deliveredTo  = $headers['Delivered-To'] ?? ($headers['To'] ?? '');
                    $subject      = $headers['Subject'] ?? '';

                    // Body (plain text best-effort)
                    $body = self::extractPlainBody($msg);

                    // If masks exist: require match ONLY in Delivered-To; extract {entry_id} (digits only)
                    $entryId = '';
                    if ($hasMasks) {
                        $matchedMask = false;

                        foreach ($masks as $mm) {

                            if (preg_match($mm['regex'], $deliveredTo, $cap)) {
                                $entryId = $cap['entry_id'] ?? '';
                                $matchedMask = true;
                                break;
                            }
                        }
                        if (!$matchedMask) {
                            continue; // skip this message (no mask match in Delivered-To)
                        }
                    }

                    // Determine matched status from Subject OR Body
                    $matchedStatus = '';
                    $haystack = $subject . "\n" . $body;
                    foreach ($statusesRx as $i => $rx) {
                        if (preg_match($rx, $haystack)) { $matchedStatus = $statuses[$i]; break; }
                    }

                    $items[] = [
                        'id'         => $m->getId(),
                        'from'       => $from,
                        'deliveredTo'=> $deliveredTo,
                        'subject'    => $subject,
                        'status'     => $matchedStatus,
                        'entryId'    => $entryId,
                        'body'       => $body,
                    ];
                }

                $safetyPage++;
            } while ($pageToken && $safetyPage < max(1, (int)$maxPages));

            return ['items' => $items, 'error' => null];

        } catch (\Throwable $e) {
            return ['items' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Render the small preview block (uses getAllMessages()).
     * Shows up to 5 items, including the body snippet.
     *
     * @param int    $idx
     * @param string $oauthRedirectUri (kept for signature compatibility; not required here)
     */
    public static function renderTestListHtml(int $idx, string $oauthRedirectUri): string {
        if ( ! current_user_can(FrmGmailParserHelper::CAPABILITY) ) {
            return '<div class="frm-gmail-test-results error"><p>Forbidden</p></div>';
        }

        // We still need masks presence to tailor the "no results" message
        $maskText = FrmGmailParserHelper::getMaskTextForAccount($idx);
        $hasMasks = trim((string)$maskText) !== '';

        $result = self::getAllMessages($idx, 500, 3);
        if ($result['error']) {
            return '<div class="frm-gmail-test-results error"><p>Error: ' . esc_html($result['error']) . '</p></div>';
        }

        $items = array_slice($result['items'], 0, 5); // preview top 5

        ob_start();
        if (!$items) {
            $msg = $hasMasks
                ? '<em>No messages matched the masks (in Delivered-To) for the selected statuses.</em>'
                : '<em>No messages for the selected statuses.</em>';
            echo '<div class="frm-gmail-test-results"><p>'.$msg.'</p></div>';
        } else {
            echo '<div class="frm-gmail-test-results">';
            foreach ($items as $row) {
                $body    = (string)($row['body'] ?? '');
                $snippet = mb_substr($body, 0, 800);
                $more    = mb_strlen($body) > 800 ? '…' : '';

                echo '<div class="email-item">';
                echo '<div class="subject">'. esc_html($row['subject']) .'</div>';
                echo '<div class="from">'. esc_html__('From: ', 'frm-gmail'). esc_html($row['from']) .'</div>';
                echo '<div class="to">'. esc_html__('Delivered-To: ', 'frm-gmail'). esc_html($row['deliveredTo']) .'</div>';
                echo '<div class="status">'. esc_html__('Status: ', 'frm-gmail') . '<strong>' . esc_html($row['status'] ?: __('(not found)', 'frm-gmail')) . '</strong></div>';
                echo '<div class="status">'. esc_html__('Entry ID: ', 'frm-gmail') . '<strong>' . esc_html($row['entryId'] ?: __('(not found)', 'frm-gmail')) . '</strong></div>';
                echo '<div class="body"><strong>'. esc_html__('Body:', 'frm-gmail') .'</strong><br>' . nl2br(esc_html($snippet)) . $more . '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        return (string)ob_get_clean();
    }
}
