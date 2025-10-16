<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Parser with Title filter in API query and multi-value "Status (single string)":
 * - Gmail query is built from:
 *     • statuses (as subject:"...") when subject area is selected,
 *     • Title filter (as subject:"<title_filter>") when provided,
 *     • optional start_date (after:YYYY/MM/DD).
 *   If only "body" is selected for status matching, we STILL include Title filter
 *   in the API query (safe), but body matches are done locally.
 *
 * - Order Id Mask is a SINGLE pattern (may contain {entry_id} → \d+) and is applied
 *   against the chosen area: to (Delivered-To/To), from (From), or subject (Subject).
 *
 * - "Status (single string)" accepts comma-separated and/or newline-separated values
 *   (e.g. "Paid, Refunded, Cancelled") → we search for ANY (OR) of them.
 */
final class FrmGmailParser {

    /** Make one regex from a single Order Id Mask. {entry_id} → (?P<entry_id>\d+) digits only. */
    public static function parseOrderIdMask(string $maskText): ?array {
        $mask = trim((string)$maskText);
        if ($mask === '') { return null; }

        $prefix = $mask;
        if (($p = strpos($prefix, '{entry_id}')) !== false) { $prefix = substr($prefix, 0, $p); }
        if (($p = strpos($prefix, '*')) !== false)         { $prefix = substr($prefix, 0, $p); }
        $prefix = trim($prefix);

        $pat   = preg_quote($mask, '/');
        $pat   = str_replace('\{entry_id\}', '(?P<entry_id>\d+)', $pat);
        $regex = '/'.$pat.'/i';

        return ['raw' => $mask, 'prefix' => $prefix, 'regex' => $regex];
    }

    /**
     * Build Gmail 'q' based on:
     *  - statuses (subject:"...") when subject is among statusAreas,
     *  - Title filter (always as subject:"...") when provided,
     *  - startDate (after:YYYY/MM/DD).
     *
     * If only "body" is selected for status matching, we still include Title filter
     * in the query (subject constraint) and rely on local body matching for statuses.
     */
    private static function buildQuery(array $statuses, ?string $startDate, array $statusAreas, string $titleFilter = ''): string {
        $esc = function(string $s): string {
            return str_replace(['\\', '"'], ['\\\\', '\"'], $s);
        };

        $statuses    = array_values(array_filter(array_map('trim', $statuses), static fn($s) => $s !== ''));
        $statusAreas = array_values(array_unique(array_map('trim', $statusAreas)));
        $qParts      = [];

        if (in_array('subject', $statusAreas, true) && !empty($statuses)) {
            $statusTerms = array_map(fn($s) => 'subject:"'.$esc($s).'"', $statuses);
            $qParts[] = '(' . implode(' OR ', $statusTerms) . ')';
        }

        $titleFilter = trim($titleFilter);
        if ($titleFilter !== '') {
            $qParts[] = 'subject:"' . $esc($titleFilter) . '"';
        }

        if ($startDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $qParts[] = 'after:' . str_replace('-', '/', $startDate); // Gmail expects YYYY/MM/DD
        }

        return trim(implode(' ', $qParts));
    }

    /** ---- Body helpers ---- */

    private static function b64url_decode(string $data): string {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) { $data .= str_repeat('=', 4 - $pad); }
        return base64_decode($data) ?: '';
    }

    private static function collectPartsByMime($payload, string $wantMime, array &$out): void {
        if (!$payload) { return; }
        $mimeType = method_exists($payload, 'getMimeType') ? $payload->getMimeType() : '';
        if ($mimeType === $wantMime && method_exists($payload, 'getBody') && $payload->getBody()) {
            $raw = $payload->getBody()->getData() ?? '';
            if ($raw !== '') { $out[] = self::b64url_decode($raw); }
        }
        if (method_exists($payload, 'getParts') && $payload->getParts()) {
            foreach ($payload->getParts() as $p) {
                self::collectPartsByMime($p, $wantMime, $out);
            }
        }
    }

    private static function extractPlainBody(\Google\Service\Gmail\Message $msg): string {
        $payload = $msg->getPayload();
        if (!$payload) { return ''; }

        $plain = [];
        self::collectPartsByMime($payload, 'text/plain', $plain);
        if (!empty($plain)) {
            return trim(implode("\n\n", $plain));
        }

        $html = [];
        self::collectPartsByMime($payload, 'text/html', $html);
        if (!empty($html)) {
            $joined = trim(implode("\n\n", $html));
            $text = wp_strip_all_tags($joined, true);
            return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if ($payload->getBody() && $payload->getBody()->getSize() > 0) {
            $raw = $payload->getBody()->getData() ?? '';
            return trim(self::b64url_decode($raw));
        }
        return '';
    }

    /** Normalize statuses from either array or comma/newline string. */
    private static function normalizeStatuses(array $opts, int $idx): array {
        if (!empty($opts['statuses']) && is_array($opts['statuses'])) {
            $arr = $opts['statuses'];
        } elseif (!empty($opts['status']) && is_string($opts['status'])) {
            $arr = preg_split('/[\n,]+/', (string)$opts['status']);
        } else {
            $arr = FrmGmailParserHelper::getStatusesArrayForAccount($idx); // legacy fallback
        }
        $arr = array_map('trim', (array)$arr);
        $arr = array_filter($arr, static fn($s)=>$s!=='');
        // de-duplicate while preserving order
        $seen = [];
        $out  = [];
        foreach ($arr as $s) {
            if (!isset($seen[strtolower($s)])) {
                $seen[strtolower($s)] = true;
                $out[] = $s;
            }
        }
        return array_values($out);
    }

    /**
     * Fetch and post-filter messages.
     *
     * @param int   $idx        Account index (option row)
     * @param int   $batchSize  Number of message IDs per Gmail API page (default 500)
     * @param int   $maxPages   Max number of pages to scan (default 3)
     * @param array $opts       Optional overrides from the UI:
     *                          - statuses: string[]  OR  status: string (comma/newline OK)
     *                          - status_search_area: string[] in {'subject','body'} (default ['subject'])
     *                          - order_id_search_area: 'to'|'from'|'subject' (default 'subject')
     *                          - mask: string (single pattern; may include {entry_id})
     *                          - title_filter: string (subject substring; INCLUDED IN API QUERY)
     *
     * @return array { items: array<array>, error: ?string }
     */
    public static function getAllMessages(int $idx, int $batchSize = 500, int $maxPages = 3, array $opts = []): array {
        $row = FrmGmailParserHelper::getAccount($idx);
        if (!$row) {
            return ['items' => [], 'error' => 'Account not found.'];
        }

        $creds     = $row['credentials'] ?? '';
        $token     = $row['token']       ?? null;
        $startDate = FrmGmailParserHelper::getStartDate();

        // ---- statuses (now supports comma-separated in single string) ----
        $statuses = self::normalizeStatuses($opts, $idx);

        // ---- areas ----
        $statusAreas = (!empty($opts['status_search_area']) && is_array($opts['status_search_area']))
            ? array_values(array_unique(array_map('trim', $opts['status_search_area'])))
            : ['subject'];
        $statusAreas = array_values(array_intersect($statusAreas, ['subject','body']));
        if (empty($statusAreas)) { $statusAreas = ['subject']; }

        $orderIdArea = (!empty($opts['order_id_search_area']) && in_array($opts['order_id_search_area'], ['to','from','subject'], true))
            ? $opts['order_id_search_area']
            : 'subject';

        $maskText     = isset($opts['mask']) ? (string)$opts['mask'] : FrmGmailParserHelper::getMaskTextForAccount($idx);
        $maskCompiled = self::parseOrderIdMask($maskText); // single or null

        $titleFilter  = isset($opts['title_filter']) ? trim((string)$opts['title_filter']) : '';

        if (empty($statuses)) {
            return ['items' => [], 'error' => 'Please add at least one Status to test.'];
        }
        if ( empty($token) || !is_array($token) ) {
            return ['items' => [], 'error' => 'Not connected.'];
        }

        // Gmail query based on statuses (subject if possible) + Title filter (subject) + date
        $query      = self::buildQuery($statuses, $startDate, $statusAreas, $titleFilter);
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
                    // Get FULL message to access headers + body
                    $msg = $gmail->users_messages->get('me', $m->getId(), ['format' => 'full']);

                    // Headers
                    $headers = [];
                    $payload = $msg->getPayload();
                    if ($payload && method_exists($payload, 'getHeaders') && $payload->getHeaders()) {
                        foreach ($payload->getHeaders() as $h) { $headers[$h->getName()] = $h->getValue(); }
                    }

                    $from        = $headers['From'] ?? '';
                    $deliveredTo = $headers['Delivered-To'] ?? ($headers['To'] ?? '');
                    $toHeader    = $headers['To'] ?? $deliveredTo;
                    $subject     = $headers['Subject'] ?? '';

                    // Body
                    $body = self::extractPlainBody($msg);

                    // ---- Title filter safeguard (should already be in API query) ----
                    if ($titleFilter !== '' && stripos($subject, $titleFilter) === false) {
                        continue;
                    }

                    // ---- Order Id Mask (single) on selected area ----
                    $entryId = '';
                    if ($maskCompiled) {
                        $fieldText = '';
                        switch ($orderIdArea) {
                            case 'to':      $fieldText = $toHeader ?: $deliveredTo; break;
                            case 'from':    $fieldText = $from; break;
                            case 'subject': $fieldText = $subject; break;
                        }
                        if ($fieldText === '' || !preg_match($maskCompiled['regex'], $fieldText, $cap)) {
                            continue; // mask required → skip if no match
                        }
                        $entryId = $cap['entry_id'] ?? '';
                    }

                    // ---- Determine matched status respecting selected areas (OR logic for all statuses) ----
                    $matchedStatus = '';
                    foreach ($statusesRx as $i => $rx) {
                        $hit = false;
                        if (in_array('subject', $statusAreas, true) && preg_match($rx, $subject)) {
                            $hit = true;
                        }
                        if (!$hit && in_array('body', $statusAreas, true) && $body !== '' && preg_match($rx, $body)) {
                            $hit = true;
                        }
                        if ($hit) { $matchedStatus = $statuses[$i]; break; }
                    }

                    $items[] = [
                        'id'          => $m->getId(),
                        'from'        => $from,
                        'deliveredTo' => $deliveredTo,
                        'subject'     => $subject,
                        'status'      => $matchedStatus,
                        'entryId'     => $entryId,
                        'body'        => $body,
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
     * @param int         $idx
     * @param string      $oauthRedirectUri (kept for signature compatibility)
     * @param array       $filters Optional per-filter overrides from admin AJAX:
     *                             - title_filter
     *                             - order_id_search_area ('to'|'from'|'subject')
     *                             - mask (single)
     *                             - status_search_area (['subject','body'])
     *                             - status (single string; comma/newline OK) or statuses (array)
     *                             - parser_code, status_field_id (ignored here)
     */
    public static function renderTestListHtml(int $idx, string $oauthRedirectUri, array $filters = []): string {
        if ( ! current_user_can(FrmGmailParserHelper::CAPABILITY) ) {
            return '<div class="frm-gmail-test-results error"><p>Forbidden</p></div>';
        }

        $opts = [];

        if (isset($filters['title_filter']))          { $opts['title_filter'] = (string)$filters['title_filter']; }
        if (isset($filters['order_id_search_area']))  { $opts['order_id_search_area'] = (string)$filters['order_id_search_area']; }
        if (isset($filters['mask']))                  { $opts['mask'] = (string)$filters['mask']; }

        if (isset($filters['status_search_area'])) {
            $areas = is_array($filters['status_search_area']) ? $filters['status_search_area'] : [(string)$filters['status_search_area']];
            $opts['status_search_area'] = array_values(array_filter(array_map('trim', $areas), static fn($a)=>in_array($a, ['subject','body'], true)));
            if (empty($opts['status_search_area'])) { $opts['status_search_area'] = ['subject']; }
        }

        if (isset($filters['statuses']) && is_array($filters['statuses'])) {
            $opts['statuses'] = $filters['statuses'];
        } elseif (isset($filters['status'])) {
            // can be "Paid, Refunded, Cancelled"
            $opts['status'] = (string)$filters['status'];
        }

        $result = self::getAllMessages($idx, 500, 3, $opts);
        if ($result['error']) {
            return '<div class="frm-gmail-test-results error"><p>Error: ' . esc_html($result['error']) . '</p></div>';
        }

        $items = array_slice($result['items'], 0, 5);

        ob_start();
        if (!$items) {
            $needsMask = isset($opts['mask']) && trim((string)$opts['mask']) !== '';
            $msg = $needsMask
                ? '<em>No messages matched the Order Id Mask in the selected area for the chosen statuses / title filter.</em>'
                : '<em>No messages for the selected statuses / title filter.</em>';
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

        // Build "Show all" link if we have a parser code (preferred) or at least a title filter
        $pc   = isset($filters['parser_code']) ? (string)$filters['parser_code'] : '';
        $tf   = isset($filters['title_filter']) ? (string)$filters['title_filter'] : '';
        $base = admin_url('admin.php?page=frm-gmail-messages&idx='.intval($idx));

        if ($pc !== '') {
            $url = $base . '&parser_code=' . rawurlencode($pc);
            echo '<p class="frm-gmail-all-link" style="margin-top:8px;"><a class="button button-secondary" href="'.esc_url($url).'" target="_blank">'.esc_html__('Show all', 'frm-gmail').'</a></p>';
        } elseif ($tf !== '') {
            // Optional fallback: if no parser_code provided, you may choose to pass title_filter instead.
            // The messages page is designed around parser_code, so prefer sending parser_code where possible.
            $url = $base . '&parser_code=' . rawurlencode($tf);
            echo '<p class="frm-gmail-all-link" style="margin-top:8px;"><a class="button button-secondary" href="'.esc_url($url).'" target="_blank">'.esc_html__('Show all', 'frm-gmail').'</a></p>';
        }

        return (string)ob_get_clean();
    }
}
