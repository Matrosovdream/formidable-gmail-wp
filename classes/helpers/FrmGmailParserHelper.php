<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Helper for settings access and small utilities
 * - Reads/writes option
 * - Per-account message fetching and updating (with optional mass archiving)
 *
 * New API:
 *  - getMessagesByAccountFilter(int $idx, int $filterIndex, int $batchSize = 500, int $maxPages = 300): array
 *  - getMessagesByAccount(int $idx, int $batchSize = 500, int $maxPages = 300): array
 *  - updateEntriesByAccountFilter(int $idx, int $filterIndex, int $batchSize = 50, int $maxPages = 300): array
 *  - updateEntriesByAccount(int $idx, int $batchSize = 50, int $maxPages = 300): array
 *
 * Notes:
 * - When account setting `archive_entries` is enabled, successful updates trigger a **single mass archive**
 *   call via FrmGmailApi->archiveMessages([...]) using the message IDs that were updated.
 */
final class FrmGmailParserHelper {
    public const OPTION_NAME  = 'frm_gmail';
    public const CAPABILITY   = 'manage_options';

    /** -----------------------------
     *  Messaging helpers (new)
     *  ----------------------------- */

    /**
     * Build parser options array from a saved filter row.
     */
    private static function buildParserOptsFromFilter(array $filter): array {
        $opts = [
            'title_filter'          => isset($filter['title_filter']) ? (string)$filter['title_filter'] : '',
            'order_id_search_area'  => (isset($filter['order_id_search_area']) && in_array($filter['order_id_search_area'], ['to','from','subject'], true))
                                        ? (string)$filter['order_id_search_area'] : 'subject',
            'mask'                  => isset($filter['mask']) ? (string)$filter['mask'] : '',
            'status_search_area'    => [],
            // statuses may be provided as 'statuses' array or 'status' (comma/newline string)
        ];

        if (!empty($filter['status_search_area']) && is_array($filter['status_search_area'])) {
            foreach ($filter['status_search_area'] as $a) {
                $a = trim((string)$a);
                if (in_array($a, ['subject','body'], true)) {
                    $opts['status_search_area'][] = $a;
                }
            }
        }
        if (empty($opts['status_search_area'])) {
            $opts['status_search_area'] = ['subject'];
        }

        if (!empty($filter['statuses']) && is_array($filter['statuses'])) {
            $opts['statuses'] = array_values(array_filter(array_map('trim', $filter['statuses']), static fn($s)=>$s!==''));
        } else {
            $single = isset($filter['status']) ? (string)$filter['status'] : '';
            if ($single !== '') {
                $parts = preg_split('/[\n,]+/', $single);
                $parts = array_map('trim', (array)$parts);
                $opts['statuses'] = array_values(array_filter($parts, static fn($s)=>$s!==''));
            }
        }

        // pass through extra_fields if present
        if (!empty($filter['extra_fields']) && is_array($filter['extra_fields'])) {
            $opts['extra_fields'] = $filter['extra_fields'];
        }

        return $opts;
    }

    public static function getAllAccounts(): array {
        $settings = self::getSettings();
        return isset($settings['accounts']) && is_array($settings['accounts']) ? $settings['accounts'] : [];
    }

    /*
    REMOVE LATER
    public static function updateEntryStatuses(): void {

        $accounts = self::getAllAccounts();

        foreach ($accounts as $idx => $account) {

            if( !$account['active_cron'] ) {  continue; }

            $filters = isset($account['filters']) && is_array($account['filters']) ? $account['filters'] : [];
            foreach ($filters as $fi => $filter) {
                //self::updateEntriesByAccountFilter((int)$idx, (int)$fi, 50, 300);
            }
        }
    }
    */

    /**
     * Get all messages for a single account **filter**.
     *
     * @return array{
     *   items: array,
     *   error: ?string,
     *   meta: array{ idx:int, filter_index:int, parser_code:string, statusFieldId:int }
     * }
     */
    public static function getMessagesByAccountFilter(int $idx, int $filterIndex, array $options=[], int $batchSize = 500, int $maxPages = 300): array {
        $account = self::getAccount($idx);
        if (!$account) {
            return ['items'=>[], 'error'=>'Account not found.', 'meta'=>['idx'=>$idx,'filter_index'=>$filterIndex,'parser_code'=>'','statusFieldId'=>0]];
        }

        $filters = isset($account['filters']) && is_array($account['filters']) ? $account['filters'] : [];
        if (!isset($filters[$filterIndex]) || !is_array($filters[$filterIndex])) {
            return ['items'=>[], 'error'=>'Filter not found.', 'meta'=>['idx'=>$idx,'filter_index'=>$filterIndex,'parser_code'=>'','statusFieldId'=>0]];
        }

        $filter = $filters[$filterIndex];
        $opts   = self::buildParserOptsFromFilter($filter);
        // allow parser to resolve extras via fidx as well
        $opts['fidx'] = $filterIndex;
        if( 
            isset($options['start_date']) && $options['start_date'] != ''
            ) {
            $opts['start_date'] = $options['start_date'];
        }

        $res = FrmGmailParser::getAllMessages($idx, $batchSize, $maxPages, $opts);

        $statusFieldId = isset($filter['status_field_id']) ? (int)$filter['status_field_id'] : 0;
        $parserCode    = isset($filter['parser_code']) ? (string)$filter['parser_code'] : '';

        return [
            'items' => isset($res['items']) && is_array($res['items']) ? $res['items'] : [],
            'error' => isset($res['error']) && $res['error'] ? (string)$res['error'] : null,
            'meta'  => [
                'idx'           => $idx,
                'filter_index'  => $filterIndex,
                'parser_code'   => $parserCode,
                'statusFieldId' => $statusFieldId,
            ],
        ];
    }

    /**
     * Get all messages for **all filters** of a single account.
     * Replaces old getAllMessageAccounts() which aggregated across accounts.
     *
     * @return array{
     *   idx:int, title:string, email:string,
     *   totals: array{ filters:int, messages:int, errors:int },
     *   filters: array<array{
     *     filter_index:int, parser_code:string, statusFieldId:int,
     *     items: array, error:?string
     *   }>
     * }
     */
    public static function getMessagesByAccount(int $idx, int $batchSize = 500, int $maxPages = 300): array {
        $account = self::getAccount($idx);
        $title   = $account['title'] ?? sprintf(__('Account #%d', 'frm-gmail'), $idx + 1);
        $email   = $account['connected_email'] ?? '';

        $out = [
            'idx'    => $idx,
            'title'  => (string)$title,
            'email'  => (string)$email,
            'totals' => ['filters'=>0,'messages'=>0,'errors'=>0],
            'filters'=> [],
        ];

        if (!$account) {
            $out['totals']['errors'] = 1;
            return $out;
        }

        $filters = isset($account['filters']) && is_array($account['filters']) ? $account['filters'] : [];
        foreach ($filters as $fi => $filter) {
            $r = self::getMessagesByAccountFilter($idx, (int)$fi, $batchSize, $maxPages);

            $items = $r['items'] ?? [];
            $error = $r['error'] ?? null;
            $meta  = $r['meta']  ?? ['parser_code'=>'','statusFieldId'=>0];

            $out['filters'][] = [
                'filter_index'  => (int)$fi,
                'parser_code'   => (string)($meta['parser_code'] ?? ''),
                'statusFieldId' => (int)($meta['statusFieldId'] ?? 0),
                'items'         => $items,
                'error'         => $error,
            ];

            $out['totals']['messages'] += is_array($items) ? count($items) : 0;
            if (!empty($error)) {
                $out['totals']['errors']++;
            }
        }

        $out['totals']['filters'] = count($filters);
        return $out;
    }

    /**
     * Internal: perform mass archive for message IDs for a given account.
     * Only runs if $archiveEnabled === true and there are IDs.
     *
     * @return array{ archived:int, archive_errors:int }
     */
    private static function maybeArchiveMessagesForAccount(int $idx, bool $archiveEnabled, array $messageIds): array {
        $out = ['archived' => 0, 'archive_errors' => 0];
        if (!$archiveEnabled) { return $out; }

        $messageIds = array_values(array_filter(array_map('strval', $messageIds), static fn($s)=>$s!==''));
        if (empty($messageIds)) { return $out; }

        try {
            $account = self::getAccount($idx);
            if (!$account) { return $out; }

            $creds = $account['credentials'] ?? '';
            $token = $account['token'] ?? null;
            if (!$creds || !is_array($token)) { return $out; }

            // Instantiate API and call the (to be implemented) archiver
            $api = new FrmGmailApi($creds, $token);
            $client = $api->makeClient();
            if (!$client) { return $out; }

            // Optional: ensure fresh token (mirror your other flows)
            [$client, $newTok] = $api->ensureFreshToken($client);
            if ($newTok) {
                self::setTokenForAccount($idx, $newTok);
            }

            // Implement this method in FrmGmailApi:
            // archiveMessages(array $messageIds): array{ ok:int, fail:int, errors:array }
            $res = $api->archiveMessages($messageIds);

            $ok   = isset($res['ok']) ? (int)$res['ok'] : 0;
            $fail = isset($res['fail']) ? (int)$res['fail'] : 0;

            $out['archived']       = $ok;
            $out['archive_errors'] = $fail;

        } catch (\Throwable $e) {
            // fail silently but count as errors
            $out['archive_errors'] += count($messageIds);
        }

        return $out;
    }

    /**
     * Update entries for a **single account filter**.
     * Uses that filter's status_field_id. Skips if not configured (>0 required).
     * If the account flag `archive_entries` is enabled, archives messages that were updated.
     *
     * @return array{
     *   idx:int, filter_index:int, statusFieldId:int,
     *   archive_entries:bool, active_cron:bool,
     *   updated:int, skipped_no_status_field:int, skipped_no_entry_id:int, skipped_empty_status:int, errors:int,
     *   archived:int, archive_errors:int,
     *   items: array
     * }
     */
    public static function updateEntriesByAccountFilter(int $idx, int $filterIndex, array $opts=[], int $batchSize = 50, int $maxPages = 300): array {
        $account = self::getAccount($idx);

        //$archiveEnabled = !empty($account['archive_entries']);
        $archiveEnabled = true;
        $activeCron     = !empty($account['active_cron']);

        $summary = [
            'idx'                     => $idx,
            'filter_index'            => $filterIndex,
            'statusFieldId'           => 0,
            'archive_entries'         => (bool)$archiveEnabled,
            'active_cron'             => (bool)$activeCron,
            'updated'                 => 0,
            'skipped_no_status_field' => 0,
            'skipped_no_entry_id'     => 0,
            'skipped_empty_status'    => 0,
            'errors'                  => 0,
            'archived'                => 0,
            'archive_errors'          => 0,
            'items'                   => [],
        ];

        if (!$account) {
            $summary['errors'] = 1;
            return $summary;
        }

        $filters = isset($account['filters']) && is_array($account['filters']) ? $account['filters'] : [];
        if (!isset($filters[$filterIndex]) || !is_array($filters[$filterIndex])) {
            $summary['errors'] = 1;
            return $summary;
        }

        $filter        = $filters[$filterIndex];
        $statusFieldId = isset($filter['status_field_id']) ? (int)$filter['status_field_id'] : 0;
        $summary['statusFieldId'] = $statusFieldId;

        $res = self::getMessagesByAccountFilter($idx, $filterIndex, $opts, $batchSize, $maxPages);
        $items = isset($res['items']) && is_array($res['items']) ? $res['items'] : [];
        $summary['items'] = $items;

        // Slice size
        if ( isset($opts['slice_size']) && $opts['slice_size'] > 0 ) {
            $items = array_slice($items, 0, $opts['slice_size']);
            $summary['items'] = $items;
        }

        // Add a filter before updating
        $items = apply_filters('frm_gmail_before_update_entries', $items, $idx, $filterIndex, $account, $opts);
        $summary['items'] = $items;

        /*
        echo "<pre>";
    print_r($items);
    echo "</pre>";
    die();
    */

        // Mode == test: just return items without updating
        if ( isset($opts['mode']) && $opts['mode'] == 'test' ) {
            return $summary;
        }

        // Collect message IDs to archive only for successfully updated entries
        $messageIdsToArchive = [];

        if ($statusFieldId <= 0) {
            // No field configured → count all items as skipped for this reason
            $summary['skipped_no_status_field'] = count($items);
            return $summary;
        }

        // Loop through and update entries
        foreach ($items as $it) {
            $entryId = isset($it['entryId']) ? (int)$it['entryId'] : 0;
            if ($entryId <= 0) { $summary['skipped_no_entry_id']++; continue; }

            $status = isset($it['status']) ? (string)$it['status'] : '';
            if ($status === '') { $summary['skipped_empty_status']++; continue; }

            $ok = FrmGmailEntryHelper::updateEntryMeta($entryId, $statusFieldId, $status);

            if ($ok) {
                $summary['updated']++;
                // queue for archive
                if ($archiveEnabled && !empty($it['id'])) {
                    $messageIdsToArchive[] = (string)$it['id'];
                }
            } else {
                $summary['errors']++;
            }

            // Update extras if any
            if ( !empty($it['extras']) && is_array($it['extras']) ) {
                foreach ($it['extras'] as $ex) {
                    $fieldId = isset($ex['entry_field_id']) ? (int)$ex['entry_field_id'] : 0;
                    $value   = $ex['value'] ?? '';
                    if ( $fieldId > 0 && $value !== '' ) {
                        FrmGmailEntryHelper::updateEntryMeta($entryId, $fieldId, $value);
                    }   
                }
            }
            
            // Update entry action force
            //FrmGmailEntryHelper::forceEntryUpdate($entryId);

            // Add action hook after each entry update
            do_action('frm_gmail_after_entry_update', $entryId);

        }

        // Mass archive (if enabled)
        if ($archiveEnabled && !empty($messageIdsToArchive)) {
            $arch = self::maybeArchiveMessagesForAccount($idx, true, $messageIdsToArchive);
            $summary['archived']       = $arch['archived'];
            $summary['archive_errors'] = $arch['archive_errors'];

            if( 
                isset($opts['print_results']) &&
                $opts['print_results'] === true
                ) {
                $summary['all_items'] = $items;
            }

        }

        return $summary;
    }

    /**
     * Update entries for **all filters** of a single account.
     * Respects account flags: archive_entries (mass-archive updated messages), active_cron (exposed in summary).
     *
     * @return array{
     *   idx:int, title:string, email:string,
     *   archive_entries:bool, active_cron:bool,
     *   totals: array{
     *     filters:int, updated:int, skipped_no_status_field:int, skipped_no_entry_id:int,
     *     skipped_empty_status:int, errors:int, archived:int, archive_errors:int
     *   },
     *   filters: array<array{ filter_index:int, parser_code:string } + per-filter summary>
     * }
     */
    public static function updateEntriesByAccount(int $idx, int $batchSize = 50, int $maxPages = 300): array {
        $account = self::getAccount($idx);
        $title   = $account['title'] ?? sprintf(__('Account #%d', 'frm-gmail'), $idx + 1);
        $email   = $account['connected_email'] ?? '';

        $archiveEnabled = !empty($account['archive_entries']);
        $activeCron     = !empty($account['active_cron']);

        $out = [
            'idx'             => $idx,
            'title'           => (string)$title,
            'email'           => (string)$email,
            'archive_entries' => (bool)$archiveEnabled,
            'active_cron'     => (bool)$activeCron,
            'totals' => [
                'filters'                 => 0,
                'updated'                 => 0,
                'skipped_no_status_field' => 0,
                'skipped_no_entry_id'     => 0,
                'skipped_empty_status'    => 0,
                'errors'                  => 0,
                'archived'                => 0,
                'archive_errors'          => 0,
            ],
            'filters'=> [],
        ];

        if (!$account) {
            $out['totals']['errors'] = 1;
            return $out;
        }

        $filters = isset($account['filters']) && is_array($account['filters']) ? $account['filters'] : [];
        foreach ($filters as $fi => $filter) {
            $sum = self::updateEntriesByAccountFilter($idx, (int)$fi, $batchSize, $maxPages);

            $parserCode = isset($filter['parser_code']) ? (string)$filter['parser_code'] : '';

            $out['filters'][] = array_merge(
                [
                    'filter_index'  => (int)$fi,
                    'parser_code'   => $parserCode,
                ],
                $sum
            );

            // roll up totals
            $out['totals']['updated']                 += $sum['updated'] ?? 0;
            $out['totals']['skipped_no_status_field'] += $sum['skipped_no_status_field'] ?? 0;
            $out['totals']['skipped_no_entry_id']     += $sum['skipped_no_entry_id'] ?? 0;
            $out['totals']['skipped_empty_status']    += $sum['skipped_empty_status'] ?? 0;
            $out['totals']['errors']                  += $sum['errors'] ?? 0;
            $out['totals']['archived']                += $sum['archived'] ?? 0;
            $out['totals']['archive_errors']          += $sum['archive_errors'] ?? 0;
        }

        $out['totals']['filters'] = count($filters);
        return $out;
    }

    /** -----------------------------
     *  Settings + utilities (existing)
     *  ----------------------------- */

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

    /** Legacy helpers kept for compatibility (not used by new flow directly) */
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
