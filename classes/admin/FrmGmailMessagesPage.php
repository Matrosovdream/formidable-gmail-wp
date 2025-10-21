<?php if ( ! defined('ABSPATH') ) { exit; }

/**
 * Hidden admin page that lists ALL messages for a given Account (idx) + Parser code.
 * Adds a large, visible "Merchant Email Receipt: <date>" line and a right-side
 * "Test entry update" button per message that posts to admin-ajax with:
 * - message_id
 * - account_id
 * - parser_id
 */
final class FrmGmailMessagesPage {
    private const PAGE_SLUG      = 'frm-gmail-messages';
    private const AJAX_ACTION    = 'frm_gmail_test_entry_update';
    private const NONCE_ACTION   = 'frm_gmail_messages_nonce';

    public static function bootstrap(): void {
        if ( is_admin() ) {
            add_action('admin_menu', [__CLASS__, 'register_hidden_page']);
            add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_test_entry_update']);
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

    /** AJAX handler for the "Test entry update" button */
    public static function ajax_test_entry_update(): void {
        
        if ( ! current_user_can(FrmGmailParserHelper::CAPABILITY) ) {
            wp_send_json_error([ 'message' => __('Permission denied', 'frm-gmail') ], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce');

        $message_id = isset($_POST['message_id']) ? sanitize_text_field((string) $_POST['message_id']) : '';
        $account_id = isset($_POST['account_id']) ? absint($_POST['account_id']) : -1;
        $parser_id  = isset($_POST['parser_id']) ? absint($_POST['parser_id']) : -1;

        if ($message_id === '' || $account_id < 0 || $parser_id < 0) {
            wp_send_json_error([ 'message' => __('Missing or invalid parameters.', 'frm-gmail') ], 400);
        }

        $payload = FrmGmailParserHelper::updateWithMessageId($account_id, $parser_id, $message_id, [
            'mode' => 'live',
        ]);
        
        if (!empty($payload['errors'])) {
            wp_send_json_error([ 'message' => __('Update failed.', 'frm-gmail'), 'data' => $payload ], 400);
        }
        
        wp_send_json_success([ 'message' => __('Updated.', 'frm-gmail'), 'data' => $payload ]);
        
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
            'title_filter'         => isset($matchedFilter['title_filter']) ? (string)$matchedFilter['title_filter'] : '',
            'order_id_search_area' => isset($matchedFilter['order_id_search_area']) && in_array($matchedFilter['order_id_search_area'], ['to','from','subject'], true)
                                        ? (string)$matchedFilter['order_id_search_area'] : 'subject',
            'mask'                 => isset($matchedFilter['mask']) ? (string)$matchedFilter['mask'] : '',
            'status_search_area'   => [],
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

        // statuses
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

        // Extra fields
        if (!empty($matchedFilter['extra_fields']) && is_array($matchedFilter['extra_fields'])) {
            $opts['extra_fields'] = $matchedFilter['extra_fields'];
        }
        if ($matchedIndex !== null) {
            $opts['fidx'] = (int)$matchedIndex;
        }

        // Fetch ALL (increase pages to 10)
        $result = FrmGmailParser::getAllMessages($idx, 500, 10, $opts);

        // header info
        $accountTitle = isset($account['title']) ? (string)$account['title'] : sprintf(__('Account #%d', 'frm-gmail'), $idx + 1);
        echo '<p><strong>'.esc_html__('Account:', 'frm-gmail').'</strong> '.esc_html($accountTitle).'</p>';
        echo '<p><strong>'.esc_html__('Parser code:', 'frm-gmail').'</strong> '.esc_html($parser_code).'</p>';

        // back link + nonce for AJAX
        $backUrl = admin_url('admin.php?page=frm-gmail');
        $nonce   = wp_create_nonce(self::NONCE_ACTION);
        echo '<p><a class="button" href="'.esc_url($backUrl).'">&larr; '.esc_html__('Back to Gmail parser', 'frm-gmail').'</a></p>';
        echo '<input type="hidden" id="frm-gmail-messages-nonce" value="'. esc_attr($nonce) .'" />';

        // result
        if ($result['error']) {
            echo '<div class="notice notice-error"><p>'.esc_html($result['error']).'</p></div>';
            echo '</div>';
            return;
        }

        $opts['idx'] = $_GET['idx'];

        $items = $result['items'];
        $count = count($items);
        echo '<p><strong>'.esc_html__('Total messages:', 'frm-gmail').'</strong> '.esc_html((string)$count).'</p>';

        if (!$items) {
            echo '<div class="notice notice-info"><p>'.esc_html__('No messages matched your filter.', 'frm-gmail').'</p></div>';
            echo '</div>';
            return;
        }

        // Styles
        echo '<style>
            .frm-mail-list .email-item{padding:12px 14px;border:1px solid #eee;border-radius:8px;margin-bottom:10px;background:#fff}
            .frm-mail-list .email-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
            .frm-mail-list .subject{font-weight:700;font-size:17px;margin-bottom:2px;color:#111}
            .frm-mail-list .date-line{font-size:13px;color:#555;margin-top:3px}
            .frm-mail-list .date-line .lbl{color:#444;font-weight:600}
            .frm-mail-list .meta{color:#555;font-size:12px;margin:10px 0 6px}
            .frm-mail-list .meta .lbl{color:#666}
            .frm-mail-list .extras{margin-top:6px}
            .frm-mail-list .extras ul{margin:6px 0 0 18px;list-style:disc}
            .frm-mail-list .body{margin-top:8px;white-space:pre-wrap}
            .frm-mail-list code{background:#f6f8fa;border:1px solid #eee;border-radius:4px;padding:1px 4px}
            .frm-mail-list .actions{min-width:180px;text-align:right}
            .frm-mail-list .btn-test-update{
                display:inline-block;
                padding:8px 12px;
                border:1px solid #8bd48b;
                border-radius:6px;
                background:#eaffea;
                color:#1f6f1f;
                text-decoration:none;
                cursor:pointer;
                font-weight:600;
            }
            .frm-mail-list .btn-test-update[disabled]{opacity:0.6;cursor:not-allowed}
        </style>';

        echo '<div class="frm-mail-list">';

        foreach ($items as $row) {
            $body    = (string)($row['body'] ?? '');
            $snippet = $body; // show full body on this page

            // Resolve message id for AJAX payload (support several possible keys)
            $message_id = '';
            if (!empty($row['message_id'])) { $message_id = (string)$row['message_id']; }
            elseif (!empty($row['id'])) { $message_id = (string)$row['id']; }
            elseif (!empty($row['gmailId'])) { $message_id = (string)$row['gmailId']; }

            // Format a "Merchant Email Receipt" date
            $date_display = __('(unknown)', 'frm-gmail');
            $raw_date     = $row['date'] ?? ($row['internalDate'] ?? '');
            if ($raw_date !== '') {
                $ts = null;
                if (is_numeric($raw_date)) {
                    // Gmail internalDate is usually ms. Normalize to seconds if it looks like ms.
                    $ts = (int)$raw_date;
                    if ($ts > 2000000000) { $ts = (int) floor($ts / 1000); }
                } else {
                    $ts = strtotime((string)$raw_date);
                }
                if ($ts) {
                    // Use WP timezone formatting
                    $date_display = date_i18n('M j, Y H:i', $ts);
                }
            }

            echo '<div class="email-item">';

                echo '<div class="email-head">';
                    echo '<div class="left">';
                        // Larger subject
                        echo '<div class="subject">'. esc_html($row['subject']) .'</div>';
            
                        // Smaller "Date:" line under subject
                        $date_display = __('(unknown)', 'frm-gmail');
                        $raw_date = $row['date'] ?? ($row['internalDate'] ?? '');
                        if ($raw_date !== '') {
                            $ts = is_numeric($raw_date) ? (int)$raw_date : strtotime((string)$raw_date);
                            if ($ts > 2000000000) { $ts = (int) floor($ts / 1000); }
                            if ($ts) { $date_display = date_i18n('M j, Y H:i', $ts); }
                        }
                        echo '<div class="date-line"><span class="lbl">'.esc_html__('Date:', 'frm-gmail').'</span> '.esc_html($date_display).'</div>';
                    echo '</div>';
            
                    // Right-side button
                    echo '<div class="actions">';
                        echo '<button type="button" class="btn-test-update" '.
                                'data-message-id="'. esc_attr($message_id) .'" '.
                                'data-account-id="'. esc_attr( $opts['fidx'] ) .'" '.
                                'data-parser-id="'. esc_attr( $opts['idx'] ) .'">'.
                                esc_html__('Test entry update', 'frm-gmail') .
                            '</button>';
                    echo '</div>';
            
                echo '</div>'; // .email-head

                // Meta
                echo '<div class="meta">';
                    echo '<span class="lbl">'.esc_html__('From: ', 'frm-gmail').'</span>'. esc_html($row['from']) . ' &nbsp; ';
                    echo '<span class="lbl">'.esc_html__('Delivered-To: ', 'frm-gmail').'</span>'. esc_html($row['deliveredTo'] ?? '') . ' &nbsp; ';
                    echo '<span class="lbl">'.esc_html__('Status: ', 'frm-gmail').'</span><strong>'. esc_html($row['status'] ?: __('(not found)', 'frm-gmail')) . '</strong> &nbsp; ';
                    echo '<span class="lbl">'.esc_html__('Entry ID: ', 'frm-gmail').'</span><strong>'. esc_html($row['entryId'] ?: __('(not found)', 'frm-gmail')) . '</strong>';
                echo '</div>';

                // Extra fields (if any)
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

            echo '</div>'; // .email-item
        }

        echo '</div>'; // .frm-mail-list

        // Inline JS – AJAX post on button click
        ?>
        <script>
        (function($){
            $(document).on('click', '.btn-test-update', function(e){
                e.preventDefault();
                var $btn = $(this);
                if ($btn.is('[disabled]')) { return; }

                var payload = {
                    action: '<?php echo esc_js(self::AJAX_ACTION); ?>',
                    _ajax_nonce: $('#frm-gmail-messages-nonce').val(),
                    message_id: $btn.data('message-id') || '',
                    account_id: $btn.data('account-id') || '',
                    parser_id:  $btn.data('parser-id')  || ''
                };

                $btn.attr('disabled', 'disabled').text('<?php echo esc_js(__('Testing…', 'frm-gmail')); ?>');

                $.post(ajaxurl, payload)
                 .done(function(resp){
                    if (resp && resp.success) {
                        alert('<?php echo esc_js(__('OK: Test request received.', 'frm-gmail')); ?>');
                    } else {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js(__('Unknown error', 'frm-gmail')); ?>';
                        alert('<?php echo esc_js(__('Error:', 'frm-gmail')); ?> ' + msg);
                    }
                 })
                 .fail(function(){
                    alert('<?php echo esc_js(__('Network error', 'frm-gmail')); ?>');
                 })
                 .always(function(){
                    $btn.removeAttr('disabled').text('<?php echo esc_js(__('Test entry update', 'frm-gmail')); ?>');
                 });
            });
        })(jQuery);
        </script>
        <?php

        echo '</div>'; // wrap
    }
}

FrmGmailMessagesPage::bootstrap();
