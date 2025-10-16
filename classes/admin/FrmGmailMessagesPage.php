<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Hidden admin page that lists ALL messages for a given Account (idx) + Parser code.
 * It reuses FrmGmailParser::getAllMessages() with the exact filter block matched by parser_code.
 *
 * URL (example):
 *   /wp-admin/admin.php?page=frm-gmail-messages&idx=0&parser_code=UPS
 *
 * Notes:
 * - This is registered as a "hidden" page via add_submenu_page( null, ... ), so it won't clutter the admin menu.
 * - Requires the same capability as the main UI.
 */
final class FrmGmailMessagesPage {
    private const PAGE_SLUG = 'frm-gmail-messages';

    public static function bootstrap(): void {
        if ( is_admin() ) {
            add_action('admin_menu', [__CLASS__, 'register_hidden_page']);
        }
    }

    /** Register a hidden admin page (no menu item) */
    public static function register_hidden_page(): void {
        add_submenu_page(
            null, // hidden
            __('Gmail messages (All)', 'frm-gmail'),
            __('Gmail messages (All)', 'frm-gmail'),
            FrmGmailParserHelper::CAPABILITY,
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    /** Render full messages page */
    public static function render_page(): void {
        if ( ! current_user_can(FrmGmailParserHelper::CAPABILITY) ) {
            wp_die(__('You do not have permission to view this page.', 'frm-gmail'));
        }

        $idx         = isset($_GET['idx']) ? absint($_GET['idx']) : -1;
        $parser_code = isset($_GET['parser_code']) ? sanitize_text_field((string) $_GET['parser_code']) : '';

        if ($idx < 0 || $parser_code === '') {
            echo '<div class="wrap"><h1>'.esc_html__('Gmail messages (All)', 'frm-gmail').'</h1>';
            echo '<div class="notice notice-warning"><p>'.esc_html__('Missing or invalid parameters. Provide both idx and parser_code.', 'frm-gmail').'</p></div></div>';
            return;
        }

        $account = FrmGmailParserHelper::getAccount($idx);
        if (!$account) {
            echo '<div class="wrap"><h1>'.esc_html__('Gmail messages (All)', 'frm-gmail').'</h1>';
            echo '<div class="notice notice-error"><p>'.esc_html__('Account not found.', 'frm-gmail').'</p></div></div>';
            return;
        }

        // Find the filter matching parser_code (case-insensitive compare)
        $matchedFilter = null;
        $filters = isset($account['filters']) && is_array($account['filters']) ? $account['filters'] : [];
        foreach ($filters as $f) {
            $pc = isset($f['parser_code']) ? (string)$f['parser_code'] : '';
            if ($pc !== '' && strcasecmp($pc, $parser_code) === 0) {
                $matchedFilter = $f;
                break;
            }
        }

        if (!$matchedFilter) {
            echo '<div class="wrap"><h1>'.esc_html__('Gmail messages (All)', 'frm-gmail').'</h1>';
            echo '<div class="notice notice-warning"><p>'.esc_html__('Parser code not found for this account.', 'frm-gmail').'</p></div></div>';
            return;
        }

        // Build options for the parser based on the matched filter
        $opts = [
            'title_filter'          => isset($matchedFilter['title_filter']) ? (string)$matchedFilter['title_filter'] : '',
            'order_id_search_area'  => isset($matchedFilter['order_id_search_area']) && in_array($matchedFilter['order_id_search_area'], ['to','from','subject'], true)
                                        ? (string)$matchedFilter['order_id_search_area'] : 'subject',
            'mask'                  => isset($matchedFilter['mask']) ? (string)$matchedFilter['mask'] : '',
            'status_search_area'    => [],
        ];

        // status areas
        if (!empty($matchedFilter['status_search_area']) && is_array($matchedFilter['status_search_area'])) {
            foreach ($matchedFilter['status_search_area'] as $a) {
                $a = trim((string)$a);
                if (in_array($a, ['subject','body'], true)) { $opts['status_search_area'][] = $a; }
            }
        }
        if (empty($opts['status_search_area'])) {
            $opts['status_search_area'] = ['subject'];
        }

        // statuses: support both legacy 'status' (comma/newline) or 'statuses' array in the saved filter
        if (!empty($matchedFilter['statuses']) && is_array($matchedFilter['statuses'])) {
            $opts['statuses'] = array_values(array_filter(array_map('trim', $matchedFilter['statuses']), static fn($s)=>$s!==''));
        } else {
            $single = isset($matchedFilter['status']) ? (string)$matchedFilter['status'] : '';
            if ($single !== '') {
                $parts = preg_split('/[\n,]+/', $single);
                $parts = array_map('trim', (array)$parts);
                $opts['statuses'] = array_values(array_filter($parts, static fn($s)=>$s!==''));
            }
        }

        // Fetch ALL (increase pages to 10)
        $result = FrmGmailParser::getAllMessages($idx, 500, 10, $opts);

        echo '<div class="wrap frm-gmail-messages-all">';
        echo '<h1>'.esc_html__('Gmail messages (All)', 'frm-gmail').'</h1>';

        // header info
        $accountTitle = isset($account['title']) ? (string)$account['title'] : sprintf(__('Account #%d', 'frm-gmail'), $idx + 1);
        echo '<p><strong>'.esc_html__('Account:', 'frm-gmail').'</strong> '.esc_html($accountTitle).'</p>';
        echo '<p><strong>'.esc_html__('Parser code:', 'frm-gmail').'</strong> '.esc_html($parser_code).'</p>';

        // back link
        $backUrl = admin_url('admin.php?page=frm-gmail');
        echo '<p><a class="button" href="'.esc_url($backUrl).'">&larr; '.esc_html__('Back to Gmail parser', 'frm-gmail').'</a></p>';

        // result
        if ($result['error']) {
            echo '<div class="notice notice-error"><p>'.esc_html($result['error']).'</p></div>';
            echo '</div>';
            return;
        }

        $items = $result['items'];
        $count = count($items);
        echo '<p><strong>'.esc_html__('Total messages:', 'frm-gmail').'</strong> '.esc_html((string)$count).'</p>';

        if (!$items) {
            echo '<div class="notice notice-info"><p>'.esc_html__('No messages matched your filter.', 'frm-gmail').'</p></div>';
            echo '</div>';
            return;
        }

        // basic styles
        echo '<style>
            .frm-mail-list .email-item{padding:12px 14px;border:1px solid #eee;border-radius:8px;margin-bottom:10px;background:#fff}
            .frm-mail-list .subject{font-weight:600;margin-bottom:2px}
            .frm-mail-list .meta{color:#555;font-size:12px;margin-bottom:6px}
            .frm-mail-list .meta .lbl{color:#666}
            .frm-mail-list .body{margin-top:6px;white-space:pre-wrap}
        </style>';

        echo '<div class="frm-mail-list">';
        foreach ($items as $row) {
            $body    = (string)($row['body'] ?? '');
            // show more on this page (not trimmed to 800)
            $snippet = $body;
            echo '<div class="email-item">';
            echo '<div class="subject">'. esc_html($row['subject']) .'</div>';
            echo '<div class="meta"><span class="lbl">'.esc_html__('From: ', 'frm-gmail').'</span>'. esc_html($row['from']) . ' &nbsp; ';
            echo '<span class="lbl">'.esc_html__('Delivered-To: ', 'frm-gmail').'</span>'. esc_html($row['deliveredTo'] ?? '') . '</div>';
            echo '<div class="meta"><span class="lbl">'.esc_html__('Status: ', 'frm-gmail').'</span><strong>'. esc_html($row['status'] ?: __('(not found)', 'frm-gmail')) . '</strong> &nbsp; ';
            echo '<span class="lbl">'.esc_html__('Entry ID: ', 'frm-gmail').'</span><strong>'. esc_html($row['entryId'] ?: __('(not found)', 'frm-gmail')) . '</strong></div>';
            echo '<div class="body">'. nl2br(esc_html($snippet)) . '</div>';
            echo '</div>';
        }
        echo '</div>'; // list

        echo '</div>'; // wrap
    }
}

FrmGmailMessagesPage::bootstrap();
