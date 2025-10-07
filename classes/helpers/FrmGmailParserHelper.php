<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Helper for settings access and small utilities
 * - Reads/writes option
 * - Gives access to account rows, credentials, tokens, statuses, masks, parser start_date
 * - Misc field sanitation and JSON validation helpers
 */
final class FrmGmailParserHelper {
    public const OPTION_NAME  = 'frm_gmail';
    public const CAPABILITY   = 'manage_options';


    public static function updateEntryStatuses(int $batchSize = 50, int $maxPages = 300): array {
        $agg = self::getAllMessageAccounts($batchSize, $maxPages);
    
        $summary = [
            'totals' => [
                'accounts_processed'     => 0,
                'updated'                => 0,
                'skipped_no_status_field'=> 0,
                'skipped_no_entry_id'    => 0,
                'skipped_empty_status'   => 0,
                'errors'                 => 0,
            ],
            'accounts' => [],
        ];
    
        foreach (($agg['accounts'] ?? []) as $acc) {
            $statusFieldId = isset($acc['statusFieldId']) ? (int)$acc['statusFieldId'] : 0;
            $items         = isset($acc['items']) && is_array($acc['items']) ? $acc['items'] : [];
    
            $acct = [
                'idx'                    => (int)($acc['idx'] ?? -1),
                'title'                  => (string)($acc['title'] ?? ''),
                'email'                  => (string)($acc['email'] ?? ''),
                'statusFieldId'          => $statusFieldId,
                'updated'                => 0,
                'skipped_no_status_field'=> 0,
                'skipped_no_entry_id'    => 0,
                'skipped_empty_status'   => 0,
                'errors'                 => 0,
                'items' => $items,
            ];
    
            if ($statusFieldId <= 0) {
                // No field configured → count all items as skipped for this reason
                $acct['skipped_no_status_field'] = count($items);
                $summary['totals']['skipped_no_status_field'] += $acct['skipped_no_status_field'];
                $summary['accounts'][] = $acct;
                $summary['totals']['accounts_processed']++;
                continue;
            }
    
            foreach ($items as $it) {
                $entryId = isset($it['entryId']) ? (int)$it['entryId'] : 0;
                if ($entryId <= 0) {
                    $acct['skipped_no_entry_id']++;
                    continue;
                }
    
                $status = isset($it['status']) ? (string)$it['status'] : '';
                if ($status === '') {
                    $acct['skipped_empty_status']++;
                    continue;
                }
    
                $ok = FrmGmailEntryHelper::updateEntryMeta($entryId, $statusFieldId, $status);
                if ($ok) {
                    $acct['updated']++;
                } else {
                    $acct['errors']++;
                }
            }
    
            $summary['totals']['updated']              += $acct['updated'];
            $summary['totals']['skipped_no_entry_id']  += $acct['skipped_no_entry_id'];
            $summary['totals']['skipped_empty_status'] += $acct['skipped_empty_status'];
            $summary['totals']['errors']               += $acct['errors'];
            $summary['totals']['accounts_processed']++;
    
            $summary['accounts'][] = $acct;
        }
    
        return $summary;
    }

    public static function getAllMessageAccounts(int $batchSize = 500, int $maxPages = 300): array {

        $settings = self::getSettings();
        $accounts = $settings['accounts'] ?? [];
    
        $out = [
            'accounts' => [],
            'totals'   => [
                'accounts' => 0,
                'messages' => 0,
                'errors'   => 0,
            ],
        ];
    
        foreach ($accounts as $idx => $row) {
            $title          = isset($row['title']) ? (string)$row['title'] : '';
            $email          = isset($row['connected_email']) ? (string)$row['connected_email'] : '';
            $statusFieldId  = isset($row['status_field_id']) ? (int)$row['status_field_id'] : 0; // <-- NEW
    
            // Delegate to parser
            $res   = FrmGmailParser::getAllMessages((int)$idx, $batchSize, $maxPages);
            $items = isset($res['items']) && is_array($res['items']) ? $res['items'] : [];
            $error = isset($res['error']) && $res['error'] !== null ? (string)$res['error'] : null;
    
            if ($error !== null && $error !== '') {
                $out['totals']['errors']++;
            }
            $out['totals']['messages'] += count($items);
    
            $out['accounts'][] = [
                'idx'           => (int)$idx,
                'title'         => $title,
                'email'         => $email,
                'statusFieldId' => $statusFieldId, // <-- NEW
                'items'         => $items,
                'error'         => $error,
            ];
        }
    
        $out['totals']['accounts'] = count($accounts);
        return $out;

    }


    /** Safe settings read (with defaults) */
    public static function getSettings(): array {
        $opts = get_option(self::OPTION_NAME, []);
        $defaults = [
            'parser'   => [ 'start_date' => '' ],
            'accounts' => [],
        ];
        if (!is_array($opts)) { $opts = []; }
        $opts = array_merge($defaults, $opts);

        $opts['parser'] = isset($opts['parser']) && is_array($opts['parser'])
            ? array_merge($defaults['parser'], $opts['parser'])
            : $defaults['parser'];

        $opts['accounts'] = isset($opts['accounts']) && is_array($opts['accounts'])
            ? $opts['accounts']
            : [];

        return $opts;
    }

    public static function updateSettings(array $settings): void {
        update_option(self::OPTION_NAME, $settings, false);
    }

    public static function getAccount(int $idx): ?array {
        $settings = self::getSettings();
        return $settings['accounts'][$idx] ?? null;
    }

    /** Requested: get credentials JSON by option index */
    public static function getCredsByOption(int $idx): ?string {
        $row = self::getAccount($idx);
        if (!$row) { return null; }
        $creds = $row['credentials'] ?? '';
        return is_string($creds) ? $creds : '';
    }

    public static function getTokenByOption(int $idx): ?array {
        $row = self::getAccount($idx);
        $tok = $row['token'] ?? null;
        return is_array($tok) ? $tok : null;
    }

    public static function setTokenForAccount(int $idx, array $token): void {
        $settings = self::getSettings();
        if (!isset($settings['accounts'][$idx])) { return; }
        $settings['accounts'][$idx]['token'] = $token;
        self::updateSettings($settings);
    }

    public static function mergeRefreshTokenIfMissing(int $idx, array $newToken): array {
        $settings = self::getSettings();
        $prev = $settings['accounts'][$idx]['token'] ?? [];
        if ( empty($newToken['refresh_token']) && is_array($prev) && !empty($prev['refresh_token']) ) {
            $newToken['refresh_token'] = $prev['refresh_token'];
        }
        return $newToken;
    }

    public static function setConnectedEmailAndTime(int $idx, string $email): void {
        $settings = self::getSettings();
        if (!isset($settings['accounts'][$idx])) { return; }
        if ($email) { $settings['accounts'][$idx]['connected_email'] = $email; }
        $settings['accounts'][$idx]['connected_at'] = current_time('mysql');
        self::updateSettings($settings);
    }

    public static function getStartDate(): string {
        $s = self::getSettings();
        return $s['parser']['start_date'] ?? '';
    }

    public static function getStatusesArrayForAccount(int $idx): array {
        $row = self::getAccount($idx) ?? [];
        $statuses = (string)($row['statuses'] ?? '');
        if ($statuses === '') { return []; }
        $parts = preg_split('/[\n,]+/', $statuses);
        $parts = array_map('trim', $parts);
        return array_values(array_filter($parts, fn($s) => $s !== ''));
    }

    public static function getMaskTextForAccount(int $idx): string {
        $row = self::getAccount($idx) ?? [];
        return (string)($row['mask'] ?? '');
    }

    /** JSON validation utility (returns 1-based indices of bad rows) */
    public static function findInvalidJsonRows(array $accounts): array {
        $bad = [];
        foreach ($accounts as $idx => $row) {
            $txt = $row['credentials'] ?? '';
            if ($txt === '') { continue; }
            if ( ! is_array(FrmGmailApi::decodeJsonRobust($txt)) ) {
                $bad[] = $idx + 1;
            }
        }
        return $bad;
    }

    /** Textareas sanitizers used by AdminPage */
    public static function sanitizeTextareaKeepJson($text): string {
        $text = is_string($text) ? $text : '';
        if ( function_exists('wp_unslash') ) {
            $text = wp_unslash($text);
        } else {
            $text = stripslashes($text);
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim($text);
    }

    public static function sanitizeTextareaSimple($text): string {
        $text = is_string($text) ? $text : '';
        $text = wp_kses_post($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim($text);
    }

    /** Small helpers for asset base */
    public static function baseUrl(): string {
        return defined('FRM_GMAIL_URL') ? rtrim(FRM_GMAIL_URL, '/').'/' : plugin_dir_url(__FILE__);
    }
    public static function basePath(): string {
        return defined('FRM_GMAIL_PATH') ? rtrim(FRM_GMAIL_PATH, '/').'/' : plugin_dir_path(__FILE__);
    }
}
