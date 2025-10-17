<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Hidden admin page that lists ALL messages for a given Account (idx) + Parser code.
 * It reuses FrmGmailParser::getAllMessages() with the exact filter block matched by parser_code,
 * including its extra_fields (title, code, mask, search_area, entry_field_id).
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

        echo '<div class="wrap frm-gmail-messages-all">';
        echo '<h1>'.esc_html__('Gmail messages (All)', 'frm-gmail').'</h1>';

        if ($idx < 0 || $parser_code === '') {
            echo '<div class="notice notice-warning"><p>'.esc_html__('Missing or invalid parameters. Provide both idx and parser_code.', 'frm-gmail').'</p></div>';
            echo '</div>';
            return;
        }

        $account = FrmGmailParserHelper::getAccount($idx);
        if (!$account) {
            echo '<div class="notice notice-error"><p>'.esc_html__('Account not found.', 'frm-gmail').'</p></div>';
            echo '</div>';
            return;
        }

        // Find the filter matching parser_code (case-insensitive compare) and remember its index
        $matchedFilter = null;
        $matchedIndex  = null;
        $filters = isset($account['filters']) && is_array($account['filters']) ? $account['filters'] : [];
        foreach ($filters as $fi => $f) {
            $pc = isset($f['parser_code']) ? (string)$f['parser_code'] : '';
            if ($pc !== '' && strcasecmp($pc, $parser_code) === 0) {
                $matchedFilter = $f;
                $matchedIndex  = $fi;
                break;
            }
        }

        if (!$matchedFilter) {
            echo '<div class="notice notice-warning"><p>'.esc_html__('Parser code not found for this account.', 'frm-gmail').'</p></div>';
            echo '</div>';
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

        // >>> Extra fields support <<<
        // Prefer passing explicit extra_fields to ensure parser extracts values.
        if (!empty($matchedFilter['extra_fields']) && is_array($matchedFilter['extra_fields'])) {
            $opts['extra_fields'] = $matchedFilter['extra_fields'];
        }
        // Also pass fidx in case the parser wants to resolve from settings:
        if ($matchedIndex !== null) {
            $opts['fidx'] = (int)$matchedIndex;
        }

        // Fetch ALL (increase pages to 10)
        $result = FrmGmailParser::getAllMessages($idx, 500, 10, $opts);

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
            .frm-mail-list .extras{margin-top:6px}
            .frm-mail-list .extras ul{margin:6px 0 0 18px;list-style:disc}
            .frm-mail-list .body{margin-top:8px;white-space:pre-wrap}
            .frm-mail-list code{background:#f6f8fa;border:1px solid #eee;border-radius:4px;padding:1px 4px}
        </style>';

        echo '<div class="frm-mail-list">';
        foreach ($items as $row) {
            $body    = (string)($row['body'] ?? '');
            // show full body on this page
            $snippet = $body;

            echo '<div class="email-item">';
            echo '<div class="subject">'. esc_html($row['subject']) .'</div>';

            echo '<div class="meta">';
                echo '<span class="lbl">'.esc_html__('From: ', 'frm-gmail').'</span>'. esc_html($row['from']) . ' &nbsp; ';
                echo '<span class="lbl">'.esc_html__('Delivered-To: ', 'frm-gmail').'</span>'. esc_html($row['deliveredTo'] ?? '') . ' &nbsp; ';
                echo '<span class="lbl">'.esc_html__('Status: ', 'frm-gmail').'</span><strong>'. esc_html($row['status'] ?: __('(not found)', 'frm-gmail')) . '</strong> &nbsp; ';
                echo '<span class="lbl">'.esc_html__('Entry ID: ', 'frm-gmail').'</span><strong>'. esc_html($row['entryId'] ?: __('(not found)', 'frm-gmail')) . '</strong>';
            echo '</div>';

            // >>> Render extra fields (if any) <<<
            if (!empty($row['extras']) && is_array($row['extras'])) {
                echo '<div class="extras"><strong>'. esc_html__('Extra fields:', 'frm-gmail') .'</strong>';
                echo '<ul>';
                foreach ($row['extras'] as $ef) {
                    $label = '';
                    if (!empty($ef['title'])) {
                        $label = (string)$ef['title'];
                    } elseif (!empty($ef['code'])) {
                        $label = (string)$ef['code'];
                    } else {
                        $label = __('(unnamed)', 'frm-gmail');
                    }
                    $val = isset($ef['value']) ? (string)$ef['value'] : '';
                    echo '<li><em>'. esc_html($label) .'</em>: <code>'. esc_html($val !== '' ? $val : __('(not found)', 'frm-gmail')) .'</code>';
                    if (!empty($ef['entry_field_id'])) {
                        echo ' <span class="lbl">['. esc_html((string)(int)$ef['entry_field_id']) .']</span>';
                    }
                    echo '</li>';
                }
                echo '</ul></div>';
            }

            echo '<div class="body">'. nl2br(esc_html($snippet)) . '</div>';
            echo '</div>';
        }
        echo '</div>'; // list

        echo '</div>'; // wrap
    }
}

FrmGmailMessagesPage::bootstrap();
