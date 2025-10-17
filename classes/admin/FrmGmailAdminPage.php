<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Admin UI + OAuth + AJAX wiring (multi-filter per account + status normalization + default subject area).
 * Adds Order Id Search area (placed before Order Id Mask) and forces Order Id Mask to be a single value.
 * Uses:
 *  - FrmGmailParserHelper for settings & utils
 *  - FrmGmailApi for Google Client + token handling
 *  - FrmGmailParser for building queries & listing test emails
 */
final class FrmGmailAdminPage {
    private const CAPABILITY      = 'manage_options';
    private const MENU_PARENT     = 'formidable';
    private const MENU_SLUG       = 'frm-gmail';
    private const ACTION_SAVE     = 'frm_gmail_save_accounts';
    private const NONCE_ACTION    = 'frm_gmail_nonce_action';
    private const NONCE_NAME      = 'frm_gmail_nonce';

    // OAuth callback + state
    private const AJAX_OAUTH_ACTION = 'frm_gmail_oauth';
    private const AJAX_TEST_ACTION  = 'frm_gmail_test_list';
    private const STATE_PREFIX      = 'frm_gmail_state_'; // transient key prefix

    /** Bootstrap everything (admin UI + public OAuth endpoints) */
    public static function bootstrap(): void {
        add_action('wp_ajax_nopriv_' . self::AJAX_OAUTH_ACTION, [__CLASS__, 'oauth_callback']);
        add_action('wp_ajax_'       . self::AJAX_OAUTH_ACTION, [__CLASS__, 'oauth_callback']);

        if ( is_admin() ) {
            add_action('admin_menu', [__CLASS__, 'register_menu']);
            add_action('admin_post_' . self::ACTION_SAVE, [__CLASS__, 'handle_save']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
            add_action('wp_ajax_' . self::AJAX_TEST_ACTION, [__CLASS__, 'ajax_test_list']);
        }
    }

    /** Add submenu under Formidable (fallback to Tools if Formidable missing) */
    public static function register_menu(): void {
        $parent = self::MENU_PARENT;
        if ( ! function_exists('menu_page_url') || ! menu_page_url($parent, false) ) {
            $parent = 'tools.php';
        }
        add_submenu_page(
            $parent,
            __('Gmail parser', 'frm-gmail'),
            __('Gmail parser', 'frm-gmail'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [__CLASS__, 'render_page'],
            30
        );
    }

    /** Load JS/CSS only on our page */
    public static function enqueue_assets(string $hook): void {
        if ( ! isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG ) {
            return;
        }
        $ver = '1.4.1';
        $baseUrl = (defined('FRM_GML_BASE_PATH') ? FRM_GML_BASE_PATH : FrmGmailParserHelper::baseUrl());

        wp_enqueue_style(
            'frm-gmail-admin',
            $baseUrl . 'assets/css/frm-gmail-admin.css?t=' . time(),
            [],
            $ver
        );
        wp_enqueue_script(
            'frm-gmail-admin',
            $baseUrl . 'assets/js/frm-gmail-admin.js?t=' . time(),
            ['jquery'],
            $ver,
            true
        );
        wp_localize_script('frm-gmail-admin', 'FrmGmailAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    /** OAuth: build redirect URI (static; Google must authorize this exact URL) */
    private static function oauth_redirect_uri(): string {
        if (defined('FRM_GML_REDIRECT_OVERRIDE') && FRM_GML_REDIRECT_OVERRIDE) {
            return FRM_GML_REDIRECT_OVERRIDE;
        }
        $url = admin_url('admin-ajax.php?action=' . self::AJAX_OAUTH_ACTION);
        if ( stripos($url, 'http://') === 0 && ( is_ssl() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ) ) {
            $url = 'https://' . substr($url, 7);
        }
        return $url;
    }

    /** Admin save (accounts + parser settings) */
    public static function handle_save(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(__('You do not have permission to perform this action.', 'frm-gmail'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);
    
        $previous = FrmGmailParserHelper::getSettings();
        $raw      = $_POST['frm_gmail'] ?? [];
    
        // Optional: per-row disconnect request
        if ( isset($_POST['frm_gmail_disconnect_idx']) ) {
            $idx = (int) $_POST['frm_gmail_disconnect_idx'];
            if ( isset($previous['accounts'][$idx]) ) {
                unset(
                    $previous['accounts'][$idx]['token'],
                    $previous['accounts'][$idx]['connected_email'],
                    $previous['accounts'][$idx]['connected_at']
                );
                FrmGmailParserHelper::updateSettings($previous);
    
                set_transient('frm_gmail_notice', [
                    'type' => 'success',
                    'msg'  => sprintf(__('Disconnected account #%d.', 'frm-gmail'), $idx + 1),
                ], 30);
            }
    
            // If this came from the AJAX save path, return JSON and stop.
            if ( isset($_POST['frm_gmail_ajax']) && $_POST['frm_gmail_ajax'] === '1' ) {
                wp_send_json_success([ 'saved' => true ]);
            }
    
            wp_safe_redirect( add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php')) );
            exit;
        }
    
        // Parser settings
        $start_date = '';
        if ( isset($raw['parser']['start_date']) ) {
            $candidate = sanitize_text_field( (string) $raw['parser']['start_date'] );
            if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) ) {
                $start_date = $candidate;
            }
        }
    
        // --- Accounts blocks ---
        $safe_accounts = [];
        if ( isset($raw['accounts']) && is_array($raw['accounts']) ) {
            foreach ( $raw['accounts'] as $i => $row ) {
                // General (always present)
                $title       = isset($row['title']) ? sanitize_text_field($row['title']) : '';
                $credentials = isset($row['credentials']) ? FrmGmailParserHelper::sanitizeTextareaKeepJson($row['credentials']) : '';
    
                // Filters (array of sub-blocks)
                $filters_in  = isset($row['filters']) && is_array($row['filters']) ? $row['filters'] : [];
                $filters_out = [];
    
                foreach ( $filters_in as $fidx => $frow ) {
                    $parser_code = isset($frow['parser_code']) ? sanitize_text_field($frow['parser_code']) : '';
                    $title_filter= isset($frow['title_filter']) ? sanitize_text_field($frow['title_filter']) : '';
                    $mask        = isset($frow['mask']) ? sanitize_text_field($frow['mask']) : ''; // single value
                    $status      = isset($frow['status']) ? sanitize_text_field($frow['status']) : '';
                    $status_field_id = isset($frow['status_field_id']) ? absint($frow['status_field_id']) : 0;
    
                    // Order Id Search area (to|from|subject)
                    $order_id_search_area = 'subject';
                    if ( isset($frow['order_id_search_area']) ) {
                        $candidate = sanitize_text_field($frow['order_id_search_area']);
                        if ( in_array($candidate, ['to','from','subject'], true) ) {
                            $order_id_search_area = $candidate;
                        }
                    }
    
                    // Status search area (subject/body), default to subject
                    $status_search_area = [];
                    if ( isset($frow['status_search_area']) ) {
                        $areas = (array) $frow['status_search_area'];
                        foreach ($areas as $a) {
                            $a = sanitize_text_field($a);
                            if (in_array($a, ['body','subject'], true)) {
                                $status_search_area[] = $a;
                            }
                        }
                    }
                    if (empty($status_search_area)) {
                        $status_search_area = ['subject'];
                    }
    
                    // --- Extra fields (array of sub-sub-blocks) ---
                    $extra_in  = isset($frow['extra_fields']) && is_array($frow['extra_fields']) ? $frow['extra_fields'] : [];
                    $extra_out = [];
    
                    foreach ($extra_in as $eidx => $erow) {
                        $ef_title          = isset($erow['title']) ? sanitize_text_field($erow['title']) : '';
                        $ef_code           = isset($erow['code']) ? sanitize_text_field($erow['code']) : '';
                        $ef_mask           = isset($erow['mask']) ? FrmGmailParserHelper::sanitizeTextareaSimple($erow['mask']) : '';
                        $ef_search_area    = isset($erow['search_area']) ? sanitize_text_field($erow['search_area']) : 'subject';
                        $ef_entry_field_id = isset($erow['entry_field_id']) ? absint($erow['entry_field_id']) : 0;
    
                        // Skip fully empty rows
                        if ($ef_title === '' && $ef_code === '' && $ef_mask === '' && $ef_entry_field_id === 0) {
                            continue;
                        }
                        if (!in_array($ef_search_area, ['subject','body'], true)) {
                            $ef_search_area = 'subject';
                        }
    
                        $extra_out[] = [
                            'title'          => $ef_title,
                            'code'           => $ef_code,
                            'mask'           => $ef_mask,        // mask can include {value}
                            'search_area'    => $ef_search_area, // subject|body
                            'entry_field_id' => $ef_entry_field_id,
                        ];
                    }
    
                    // Skip empty filter rows (all blank)
                    $filterAllEmpty = (
                        $parser_code==='' &&
                        $title_filter==='' &&
                        $mask==='' &&
                        empty($status_search_area) &&
                        $status==='' &&
                        $status_field_id===0 &&
                        $order_id_search_area==='subject' &&
                        empty($extra_out)
                    );
                    if ($filterAllEmpty) {
                        continue;
                    }
    
                    $filters_out[] = [
                        'parser_code'           => $parser_code,
                        'title_filter'          => $title_filter,
                        'mask'                  => $mask, // single
                        'order_id_search_area'  => $order_id_search_area,
                        'status_search_area'    => $status_search_area,
                        'status'                => $status,
                        'status_field_id'       => $status_field_id,
                        'extra_fields'          => array_values($extra_out), // <-- persist extras
                    ];
                }
    
                // Entire account empty?
                $accountEmpty = ($title==='' && $credentials==='' && empty($filters_out));
                if ( $accountEmpty ) {
                    continue;
                }
    
                $new = [
                    'title'       => $title,
                    'credentials' => $credentials,
                    'filters'     => array_values($filters_out),
                ];
    
                // Preserve token + connected_* if credentials unchanged
                if ( isset($previous['accounts'][$i]) ) {
                    $prev = $previous['accounts'][$i];
                    if ( ($prev['credentials'] ?? '') === $credentials ) {
                        if ( isset($prev['token']) )          { $new['token'] = $prev['token']; }
                        if ( isset($prev['connected_email']) ){ $new['connected_email'] = $prev['connected_email']; }
                        if ( isset($prev['connected_at']) )   { $new['connected_at'] = $prev['connected_at']; }
                    }
                }
    
                $safe_accounts[] = $new;
            }
        }
    
        $to_save = [
            'parser'   => [ 'start_date' => $start_date ],
            'accounts' => array_values($safe_accounts),
        ];
        FrmGmailParserHelper::updateSettings($to_save);
    
        // (Optional) Warn if credentials JSON looks invalid in some blocks
        if ( function_exists('FrmGmailParserHelper::findInvalidJsonRows') || method_exists('FrmGmailParserHelper','findInvalidJsonRows') ) {
            $invalids = FrmGmailParserHelper::findInvalidJsonRows($safe_accounts);
            if (!empty($invalids)) {
                set_transient('frm_gmail_notice', [
                    'type' => 'warning',
                    'msg'  => sprintf(
                        __('Saved, but credentials JSON looks invalid in block(s): %s', 'frm-gmail'),
                        implode(', ', $invalids)
                    ),
                ], 30);
            } else {
                set_transient('frm_gmail_notice', [
                    'type' => 'success',
                    'msg'  => __('Settings saved.', 'frm-gmail'),
                ], 30);
            }
        } else {
            set_transient('frm_gmail_notice', [
                'type' => 'success',
                'msg'  => __('Settings saved.', 'frm-gmail'),
            ], 30);
        }
    
        wp_safe_redirect( add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php')) );
        exit;
    }
    

    /** Render admin page */
    public static function render_page(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(__('You do not have permission to view this page.', 'frm-gmail'));
        }

        // Start OAuth popup
        if ( isset($_GET['frm_gmail_auth']) ) {
            $idx = (int) $_GET['frm_gmail_auth'];
            self::start_oauth_for_index($idx);
            exit;
        }

        $settings = FrmGmailParserHelper::getSettings();
        $notice   = get_transient('frm_gmail_notice');
        if ($notice) { delete_transient('frm_gmail_notice'); }

        ?>
        <style>
            .frm-gmail-grid{display:flex;flex-wrap:wrap;gap:16px;margin-top:8px;}
            .frm-gmail-card{flex:1 1 calc(50% - 16px);min-width:360px;border:1px solid #ddd;border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .frm-gmail-card .card-hd{padding:12px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center}
            .frm-gmail-card .card-bd{padding:16px}
            .frm-gmail-row{margin-bottom:12px}
            .frm-gmail-row label{display:block;font-weight:600;margin-bottom:4px}
            .frm-gmail-row .regular-text{width:100%}
            .frm-gmail-row textarea{width:100%}
            .frm-gmail-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .frm-gmail-status{font-weight:600}
            .frm-gmail-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px}
            .frm-gmail-badge.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9}
            .frm-gmail-badge.no{background:#fff3e0;color:#e65100;border:1px solid #ffe0b2}
            .frm-gmail-warn{color:#e65100}
            .frm-gmail-help{font-size:12px;color:#666;margin-top:6px}
            .frm-gmail-top-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:end}
            .frm-gmail-test-results{padding-top:12px}
            .frm-gmail-test-results .email-item{padding:8px 10px;border:1px solid #eee;border-radius:8px;margin-bottom:8px;background:#fafafa}
            .frm-gmail-test-results .email-item .subject{font-weight:600}
            .frm-gmail-test-results .email-item .from{color:#555;font-size:12px}
            .frm-gmail-test-results .email-item .mid{color:#888;font-size:11px;font-family:monospace}
            .frm-gmail-test-results .email-item .status{margin-top:4px;font-size:12px}
            .frm-gmail-test-results.error{border-color:#fbe9e7;background:#fff3e0;padding:12px;border-radius:8px}
            .frm-gmail-filter-box{margin-top:10px;padding:12px;border:1px dashed #ddd;border-radius:8px;background:#fbfbfb}
            .frm-gmail-filter-box h4{margin:0 0 10px 0;font-size:14px}
            .frm-gmail-filter-grid{display:flex;flex-direction:column;gap:12px}
            .frm-gmail-filter{padding:12px;border:1px solid #eee;border-radius:8px;background:#fff}
            .frm-gmail-filter .filter-actions{display:flex;gap:8px;align-items:center;margin-top:8px}
            .frm-gmail-filter .filter-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
            .frm-gmail-filter .filter-head strong{font-size:13px}
            /* Light-green “Test this filter” button */
.button.frm-test-filter {
  background: #eaffea;
  border-color: #8bd48b;
  color: #1f6f1f;
}
.button.frm-test-filter:hover,
.button.frm-test-filter:focus {
  background: #d9ffd9;
  border-color: #6bc76b;
  color: #145c14;
}

            @media (max-width: 1100px){ .frm-gmail-card{flex:1 1 100%} .frm-gmail-top-grid{grid-template-columns:1fr} }
        </style>

        <div class="wrap frm-gmail-wrap">
            <h1><?php echo esc_html__('Gmail parser', 'frm-gmail'); ?></h1>

            <?php if ( $notice ): ?>
                <div class="<?php echo $notice['type'] === 'success' ? 'notice notice-success' : 'notice notice-warning'; ?> is-dismissible">
                    <p><?php echo esc_html($notice['msg']); ?></p>
                </div>
            <?php endif; ?>

            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post" class="frm-gmail-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE); ?>">

                <div class="frm-gmail-top-grid">
                    <div>
                        <h2 class="title"><?php echo esc_html__('Parser settings', 'frm-gmail'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="frm-gmail-start-date"><?php esc_html_e('Start date', 'frm-gmail'); ?></label>
                                    </th>
                                    <td>
                                        <input
                                            type="date"
                                            id="frm-gmail-start-date"
                                            class="regular-text"
                                            name="frm_gmail[parser][start_date]"
                                            value="<?php echo esc_attr($settings['parser']['start_date'] ?? ''); ?>"
                                            placeholder="YYYY-MM-DD"
                                        />
                                        <p class="description"><?php esc_html_e('Takes emails from this date (first run).', 'frm-gmail'); ?></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h2 class="title"><?php echo esc_html__('Redirect URI', 'frm-gmail'); ?></h2>
                        <div class="notice inline notice-info">
                            <p style="margin:8px 0;">
                                <code><?php echo esc_html( self::oauth_redirect_uri() ); ?></code><br>
                                <span class="description"><?php esc_html_e('Add this exact URL to your Google OAuth client → Authorized redirect URIs.', 'frm-gmail'); ?></span>
                            </p>
                        </div>
                    </div>
                </div>

                <h2 class="title" style="margin-top: 16px;"><?php echo esc_html__('Gmail accounts', 'frm-gmail'); ?></h2>

                <div class="frm-gmail-grid" id="frm-gmail-accounts">
                <?php
                $rows = is_array($settings['accounts']) && $settings['accounts'] ? $settings['accounts'] : [[]];
                foreach ( $rows as $i => $row ):
                    $title        = $row['title']            ?? '';
                    $credentials  = $row['credentials']      ?? '';
                    $filters      = isset($row['filters']) && is_array($row['filters']) ? $row['filters'] : [[]];

                    $token       = $row['token']            ?? null;
                    $email       = $row['connected_email']  ?? '';
                    $connected   = is_array($token) && ( !empty(($token['access_token'] ?? '')) || !empty(($token['refresh_token'] ?? '')) );
                    $connected_at= $row['connected_at']     ?? '';
                ?>
                    <div class="frm-gmail-card" data-idx="<?php echo esc_attr($i); ?>">
                        <div class="card-hd">
                            <strong><?php echo esc_html( $title !== '' ? $title : sprintf(__('Account #%d', 'frm-gmail'), $i + 1) ); ?></strong>
                            <span class="frm-gmail-badge <?php echo $connected ? 'ok' : 'no'; ?>">
                                <?php echo $connected ? esc_html__('Connected', 'frm-gmail') : esc_html__('Not connected', 'frm-gmail'); ?>
                            </span>
                        </div>

                        <div class="card-bd">
                            <!-- General block: ONLY Title + Credentials -->
                            <div class="frm-gmail-row">
                                <label><?php esc_html_e('Account title', 'frm-gmail'); ?></label>
                                <input type="text" class="regular-text" 
                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][title]"
                                    value="<?php echo esc_attr($title); ?>"
                                    placeholder="<?php esc_attr_e('e.g. Support mailbox', 'frm-gmail'); ?>">
                            </div>

                            <div class="frm-gmail-row">
                                <label><?php esc_html_e('Credentials (JSON)', 'frm-gmail'); ?></label>
                                <textarea class="large-text code frm-credentials" rows="6"
                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][credentials]"
                                    placeholder='{"client_id":"","client_secret":""} OR full Google "web" JSON'><?php echo esc_textarea($credentials); ?></textarea>
                                <div class="frm-gmail-help">
                                    <?php esc_html_e('Paste either minimal {"client_id","client_secret"} or the full "web" JSON from Google Cloud. We unslash & keep raw JSON; refresh tokens will be stored here after Connect.', 'frm-gmail'); ?>
                                </div>
                            </div>

                            <!-- Button block (after Credentials) -->
                            <div class="frm-gmail-row frm-gmail-actions">
                                <?php if ( $connected ): ?>
                                    <span class="frm-gmail-status">
                                        <?php echo esc_html( $email ? sprintf(__('Signed in as %s', 'frm-gmail'), $email) : __('Signed in', 'frm-gmail') ); ?>
                                        <?php if ($connected_at): ?>
                                            <em style="margin-left:8px;color:#666;"><?php echo esc_html( sprintf(__('since %s', 'frm-gmail'), $connected_at) ); ?></em>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="frm-gmail-warn"><?php esc_html_e('Not connected. Click Connect to grant access.', 'frm-gmail'); ?></span>
                                <?php endif; ?>

                                <button type="button" class="button button-primary frm-gmail-connect" data-idx="<?php echo esc_attr($i); ?>">
                                    <?php echo $connected ? esc_html__('Reconnect', 'frm-gmail') : esc_html__('Connect', 'frm-gmail'); ?>
                                </button>

                                <?php if ( $connected ): ?>
                                    <button type="button" class="button frm-gmail-test-account" data-idx="<?php echo esc_attr($i); ?>">
                                        <?php esc_html_e('Test email list (no filter)', 'frm-gmail'); ?>
                                    </button>

                                    <button type="submit" class="button" name="frm_gmail_disconnect_idx" value="<?php echo esc_attr($i); ?>">
                                        <?php esc_html_e('Disconnect', 'frm-gmail'); ?>
                                    </button>
                                <?php endif; ?>

                                <button type="button" class="button frm-gmail-remove" aria-label="<?php esc_attr_e('Delete block', 'frm-gmail'); ?>">✕</button>
                            </div>

                            <!-- Filters (multiple per account) -->
                            <div class="frm-gmail-filter-box" data-idx="<?php echo esc_attr($i); ?>">
                                <h4><?php esc_html_e('Filters', 'frm-gmail'); ?></h4>
                                <div class="frm-gmail-filter-grid">
                                <?php
                                $filters = $filters ?: [[]];
                                foreach ($filters as $fi => $frow):
                                    $parser_code = $frow['parser_code'] ?? '';
                                    $title_filter= $frow['title_filter'] ?? '';
                                    $mask        = $frow['mask'] ?? '';
                                    $status_area = (array)($frow['status_search_area'] ?? []);
                                    $status_val  = $frow['status'] ?? '';
                                    $status_field= isset($frow['status_field_id']) ? (int)$frow['status_field_id'] : 0;
                                    $default_subject_selected = empty($status_area) || in_array('subject', $status_area, true);

                                    $order_id_area = $frow['order_id_search_area'] ?? 'subject';
                                    if (!in_array($order_id_area, ['to','from','subject'], true)) {
                                        $order_id_area = 'subject';
                                    }
                                ?>
                                    <div class="frm-gmail-filter" data-idx="<?php echo esc_attr($i); ?>" data-fidx="<?php echo esc_attr($fi); ?>">
                                        <div class="filter-head">
                                            <strong><?php echo esc_html( sprintf(__('Filter #%d', 'frm-gmail'), $fi + 1) ); ?></strong>
                                            <button type="button" class="button-link-delete frm-remove-filter" title="<?php esc_attr_e('Remove filter', 'frm-gmail'); ?>">✕</button>
                                        </div>

                                        <div class="frm-gmail-row">
                                            <label><?php esc_html_e('Parser code', 'frm-gmail'); ?></label>
                                            <input type="text" class="regular-text"
                                                name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][parser_code]"
                                                value="<?php echo esc_attr($parser_code); ?>"
                                                placeholder="<?php esc_attr_e('e.g. UPS, DHL, AMZ, CUSTOM', 'frm-gmail'); ?>">
                                        </div>

                                        <div class="frm-gmail-row">
                                            <label><?php esc_html_e('Title filter', 'frm-gmail'); ?></label>
                                            <input type="text" class="regular-text"
                                                name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][title_filter]"
                                                value="<?php echo esc_attr($title_filter); ?>"
                                                placeholder="<?php esc_attr_e('Substring to match in subject/title', 'frm-gmail'); ?>">
                                        </div>

                                        <!-- Order Id Search area BEFORE Order Id Mask -->
                                        <div class="frm-gmail-row">
                                            <label><?php esc_html_e('Order Id Search area', 'frm-gmail'); ?></label>
                                            <select class="regular-text frm-order-id-area"
                                                name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][order_id_search_area]">
                                                <option value="to"      <?php selected($order_id_area==='to'); ?>><?php esc_html_e('Email To', 'frm-gmail'); ?></option>
                                                <option value="from"    <?php selected($order_id_area==='from'); ?>><?php esc_html_e('Email From', 'frm-gmail'); ?></option>
                                                <option value="subject" <?php selected($order_id_area==='subject'); ?>><?php esc_html_e('Subject', 'frm-gmail'); ?></option>
                                            </select>
                                        </div>

                                        <div class="frm-gmail-row">
                                            <label><?php esc_html_e('Order Id Mask', 'frm-gmail'); ?></label>
                                            <input type="text" class="regular-text frm-mask"
                                                name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][mask]"
                                                value="<?php echo esc_attr($mask); ?>"
                                                placeholder="<?php esc_attr_e('e.g. order-{entry_id}', 'frm-gmail'); ?>">
                                            <div class="frm-gmail-help">
                                                <?php esc_html_e('Single mask pattern. Use {entry_id} placeholder.', 'frm-gmail'); ?>
                                            </div>
                                        </div>

                                        <div class="frm-gmail-row">
                                            <label><?php esc_html_e('Status search area', 'frm-gmail'); ?></label>
                                            <select multiple size="2" class="regular-text frm-status-area"
                                                name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][status_search_area][]">
                                                <option value="subject" <?php selected($default_subject_selected); ?>><?php esc_html_e('subject', 'frm-gmail'); ?></option>
                                                <option value="body"    <?php selected(in_array('body', $status_area, true));    ?>><?php esc_html_e('body', 'frm-gmail'); ?></option>
                                            </select>
                                            <div class="frm-gmail-help"><?php esc_html_e('Choose where to search for the status token.', 'frm-gmail'); ?></div>
                                        </div>

                                        <div class="frm-gmail-row">
                                            <label><?php esc_html_e('Status (comma-separated)', 'frm-gmail'); ?></label>
                                            <input type="text" class="regular-text frm-status"
                                                name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][status]"
                                                value="<?php echo esc_attr($status_val); ?>"
                                                placeholder="<?php esc_attr_e('e.g. Paid, Refunded, Cancelled', 'frm-gmail'); ?>">
                                        </div>

                                        <div class="frm-gmail-row">
                                            <label><?php esc_html_e('Status Field Id', 'frm-gmail'); ?></label>
                                            <input type="number" min="0" step="1" class="regular-text frm-status-field"
                                                name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][status_field_id]"
                                                value="<?php echo esc_attr( $status_field ); ?>"
                                                placeholder="<?php esc_attr_e('Formidable status field ID (e.g. 7)', 'frm-gmail'); ?>">
                                            <div class="frm-gmail-help">
                                                <?php esc_html_e('Optional. Formidable field ID where you store parsed status.', 'frm-gmail'); ?>
                                            </div>
                                        </div>

                                        <!-- Extra fields (multiple) -->
                                        <div class="frm-gmail-row">
                                        <label><strong><?php esc_html_e('Extra fields', 'frm-gmail'); ?></strong></label>
                                        <div class="frm-gmail-extra-grid">
                                            <?php
                                            $extra = isset($frow['extra_fields']) && is_array($frow['extra_fields']) ? $frow['extra_fields'] : [];
                                            if (!$extra) { $extra = [[]]; }
                                            foreach ($extra as $ei => $ef):
                                            ?>
                                            <div class="frm-gmail-extra" data-eidx="<?php echo esc_attr($ei); ?>" style="border:1px solid #eee;border-radius:8px;padding:10px;margin:8px 0;">
                                                <div class="frm-gmail-row">
                                                <label><?php esc_html_e('Title', 'frm-gmail'); ?></label>
                                                <input type="text" class="regular-text"
                                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][extra_fields][<?php echo esc_attr($ei); ?>][title]"
                                                    value="<?php echo esc_attr($ef['title'] ?? ''); ?>">
                                                </div>

                                                <div class="frm-gmail-row">
                                                <label><?php esc_html_e('Code', 'frm-gmail'); ?></label>
                                                <input type="text" class="regular-text"
                                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][extra_fields][<?php echo esc_attr($ei); ?>][code]"
                                                    value="<?php echo esc_attr($ef['code'] ?? ''); ?>">
                                                </div>

                                                <div class="frm-gmail-row">
                                                <label><?php esc_html_e('Mask', 'frm-gmail'); ?></label>
                                                <textarea class="large-text code" rows="2"
                                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][extra_fields][<?php echo esc_attr($ei); ?>][mask]"
                                                    placeholder="<?php esc_attr_e('Example: Your application locator number is {value}.', 'frm-gmail'); ?>"><?php echo esc_textarea($ef['mask'] ?? ''); ?></textarea>
                                                <div class="frm-gmail-help"><?php esc_html_e('Use {value} where the dynamic value appears.', 'frm-gmail'); ?></div>
                                                </div>

                                                <div class="frm-gmail-row">
                                                <label><?php esc_html_e('Search area', 'frm-gmail'); ?></label>
                                                <select class="regular-text"
                                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][extra_fields][<?php echo esc_attr($ei); ?>][search_area]">
                                                    <option value="subject" <?php selected(($ef['search_area'] ?? 'subject') === 'subject'); ?>><?php esc_html_e('Subject', 'frm-gmail'); ?></option>
                                                    <option value="body"    <?php selected(($ef['search_area'] ?? '') === 'body'); ?>><?php esc_html_e('Body', 'frm-gmail'); ?></option>
                                                </select>
                                                </div>

                                                <div class="frm-gmail-row">
                                                <label><?php esc_html_e('Entry Field Id', 'frm-gmail'); ?></label>
                                                <input type="number" min="0" step="1" class="regular-text"
                                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][filters][<?php echo esc_attr($fi); ?>][extra_fields][<?php echo esc_attr($ei); ?>][entry_field_id]"
                                                    value="<?php echo esc_attr((int)($ef['entry_field_id'] ?? 0)); ?>">
                                                </div>

                                                <button type="button" class="button-link-delete frm-remove-extra" title="<?php esc_attr_e('Remove extra field', 'frm-gmail'); ?>">✕</button>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <p><button type="button" class="button button-secondary frm-add-extra" data-idx="<?php echo esc_attr($i); ?>" data-fidx="<?php echo esc_attr($fi); ?>">
                                            <?php esc_html_e('Add extra field', 'frm-gmail'); ?></button></p>
                                        </div>

                                        <div class="filter-actions" style="display: block;">
                                            <button type="button" class="button frm-test-filter" data-idx="<?php echo esc_attr($i); ?>" data-fidx="<?php echo esc_attr($fi); ?>">
                                                <?php esc_html_e('Test this filter', 'frm-gmail'); ?>
                                            </button>
                                            <div class="frm-gmail-test-results" id="frm-gmail-test-results-<?php echo esc_attr($i); ?>-<?php echo esc_attr($fi); ?>"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>

                                <p style="margin-top:8px;">
                                    <button type="button" class="button button-secondary frm-add-filter" data-idx="<?php echo esc_attr($i); ?>"><?php esc_html_e('Add filter', 'frm-gmail'); ?></button>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>

                <p style="margin-top:12px;">
                    <button type="button" class="button button-secondary" id="frm-gmail-add"><?php esc_html_e('Add account', 'frm-gmail'); ?></button>
                </p>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Changes', 'frm-gmail'); ?></button>
                </p>
            </form>
        </div>

        <template id="frm-gmail-block-template">
            <div class="frm-gmail-card" data-idx="__index__">
                <div class="card-hd">
                    <strong><?php esc_html_e('New account', 'frm-gmail'); ?></strong>
                    <span class="frm-gmail-badge no"><?php esc_html_e('Not connected', 'frm-gmail'); ?></span>
                </div>
                <div class="card-bd">
                    <!-- General -->
                    <div class="frm-gmail-row">
                        <label><?php esc_html_e('Account title', 'frm-gmail'); ?></label>
                        <input type="text" class="regular-text" 
                            name="frm_gmail[accounts][__index__][title]"
                            placeholder="<?php esc_attr_e('e.g. Support mailbox', 'frm-gmail'); ?>">
                    </div>

                    <div class="frm-gmail-row">
                        <label><?php esc_html_e('Credentials (JSON)', 'frm-gmail'); ?></label>
                        <textarea class="large-text code frm-credentials" rows="6"
                            name="frm_gmail[accounts][__index__][credentials]"
                            placeholder='{"client_id":"","client_secret":""} OR full Google "web" JSON'></textarea>
                        <div class="frm-gmail-help">
                            <?php esc_html_e('We will unslash and keep raw JSON.', 'frm-gmail'); ?>
                        </div>
                    </div>

                    <!-- Button block -->
                    <div class="frm-gmail-row frm-gmail-actions">
                        <span class="frm-gmail-warn"><strong><?php esc_html_e('Save changes to establish connection', 'frm-gmail'); ?></strong></span>
                        <button type="button" class="button frm-gmail-remove" aria-label="<?php esc_attr_e('Delete block', 'frm-gmail'); ?>">✕</button>
                    </div>

                    <!-- Filters -->
                    <div class="frm-gmail-filter-box" data-idx="__index__">
                        <h4><?php esc_html_e('Filters', 'frm-gmail'); ?></h4>
                        <div class="frm-gmail-filter-grid">
                            <!-- one empty filter to start -->
                            <div class="frm-gmail-filter" data-idx="__index__" data-fidx="0">
                                <div class="filter-head">
                                    <strong><?php esc_html_e('Filter #1', 'frm-gmail'); ?></strong>
                                    <button type="button" class="button-link-delete frm-remove-filter" title="<?php esc_attr_e('Remove filter', 'frm-gmail'); ?>">✕</button>
                                </div>

                                <div class="frm-gmail-row">
                                    <label><?php esc_html_e('Parser code', 'frm-gmail'); ?></label>
                                    <input type="text" class="regular-text"
                                        name="frm_gmail[accounts][__index__][filters][0][parser_code]"
                                        placeholder="<?php esc_attr_e('e.g. UPS, DHL, AMZ, CUSTOM', 'frm-gmail'); ?>">
                                </div>

                                <div class="frm-gmail-row">
                                    <label><?php esc_html_e('Title filter', 'frm-gmail'); ?></label>
                                    <input type="text" class="regular-text"
                                        name="frm_gmail[accounts][__index__][filters][0][title_filter]"
                                        placeholder="<?php esc_attr_e('Substring to match in subject/title', 'frm-gmail'); ?>">
                                </div>

                                <!-- Order Id Search area BEFORE Order Id Mask -->
                                <div class="frm-gmail-row">
                                    <label><?php esc_html_e('Order Id Search area', 'frm-gmail'); ?></label>
                                    <select class="regular-text frm-order-id-area"
                                        name="frm_gmail[accounts][__index__][filters][0][order_id_search_area]">
                                        <option value="to"><?php esc_html_e('Email To', 'frm-gmail'); ?></option>
                                        <option value="from"><?php esc_html_e('Email From', 'frm-gmail'); ?></option>
                                        <option value="subject" selected><?php esc_html_e('Subject', 'frm-gmail'); ?></option>
                                    </select>
                                </div>

                                <div class="frm-gmail-row">
                                    <label><?php esc_html_e('Order Id Mask', 'frm-gmail'); ?></label>
                                    <input type="text" class="regular-text frm-mask"
                                        name="frm_gmail[accounts][__index__][filters][0][mask]"
                                        placeholder="<?php esc_attr_e('e.g. order-{entry_id}', 'frm-gmail'); ?>">
                                    <div class="frm-gmail-help">
                                        <?php esc_html_e('Single mask pattern. Use {entry_id} placeholder.', 'frm-gmail'); ?>
                                    </div>
                                </div>

                                <div class="frm-gmail-row">
                                    <label><?php esc_html_e('Status search area', 'frm-gmail'); ?></label>
                                    <select multiple size="2" class="regular-text frm-status-area"
                                        name="frm_gmail[accounts][__index__][filters][0][status_search_area][]">
                                        <option value="subject" selected><?php esc_html_e('subject', 'frm-gmail'); ?></option>
                                        <option value="body"><?php esc_html_e('body', 'frm-gmail'); ?></option>
                                    </select>
                                </div>

                                <div class="frm-gmail-row">
                                    <label><?php esc_html_e('Status (comma-separated)', 'frm-gmail'); ?></label>
                                    <input type="text" class="regular-text frm-status"
                                        name="frm_gmail[accounts][__index__][filters][0][status]"
                                        placeholder="<?php esc_attr_e('e.g. Paid, Refunded, Cancelled', 'frm-gmail'); ?>">
                                </div>

                                <div class="frm-gmail-row">
                                    <label><?php esc_html_e('Status Field Id', 'frm-gmail'); ?></label>
                                    <input type="number" min="0" step="1" class="regular-text frm-status-field"
                                        name="frm_gmail[accounts][__index__][filters][0][status_field_id]"
                                        value="0"
                                        placeholder="<?php esc_attr_e('Formidable status field ID (e.g. 7)', 'frm-gmail'); ?>">
                                </div>

                                <div class="filter-actions" style="display: block;">
                                    <button type="button" class="button frm-test-filter" data-idx="__index__" data-fidx="0">
                                        <?php esc_html_e('Test this filter', 'frm-gmail'); ?>
                                    </button>
                                    <div class="frm-gmail-test-results" id="frm-gmail-test-results-__index__-0"></div>
                                </div>
                            </div>
                        </div>

                        <p style="margin-top:8px;">
                            <button type="button" class="button button-secondary frm-add-filter" data-idx="__index__"><?php esc_html_e('Add filter', 'frm-gmail'); ?></button>
                        </p>
                    </div>
                </div>
            </div>
        </template>

        <template id="frm-gmail-filter-template">
            <div class="frm-gmail-filter" data-idx="__idx__" data-fidx="__fidx__">
                <div class="filter-head">
                    <strong><?php esc_html_e('Filter', 'frm-gmail'); ?> #<span class="filter-number">__num__</span></strong>
                    <button type="button" class="button-link-delete frm-remove-filter" title="<?php esc_attr_e('Remove filter', 'frm-gmail'); ?>">✕</button>
                </div>

                <div class="frm-gmail-row">
                    <label><?php esc_html_e('Parser code', 'frm-gmail'); ?></label>
                    <input type="text" class="regular-text"
                        name="frm_gmail[accounts][__idx__][filters][__fidx__][parser_code]"
                        placeholder="<?php esc_attr_e('e.g. UPS, DHL, AMZ, CUSTOM', 'frm-gmail'); ?>">
                </div>

                <div class="frm-gmail-row">
                    <label><?php esc_html_e('Title filter', 'frm-gmail'); ?></label>
                    <input type="text" class="regular-text"
                        name="frm_gmail[accounts][__idx__][filters][__fidx__][title_filter]"
                        placeholder="<?php esc_attr_e('Substring to match in subject/title', 'frm-gmail'); ?>">
                </div>

                <!-- Order Id Search area BEFORE Order Id Mask -->
                <div class="frm-gmail-row">
                    <label><?php esc_html_e('Order Id Search area', 'frm-gmail'); ?></label>
                    <select class="regular-text frm-order-id-area"
                        name="frm_gmail[accounts][__idx__][filters][__fidx__][order_id_search_area]">
                        <option value="to"><?php esc_html_e('Email To', 'frm-gmail'); ?></option>
                        <option value="from"><?php esc_html_e('Email From', 'frm-gmail'); ?></option>
                        <option value="subject" selected><?php esc_html_e('Subject', 'frm-gmail'); ?></option>
                    </select>
                </div>

                <div class="frm-gmail-row">
                    <label><?php esc_html_e('Order Id Mask', 'frm-gmail'); ?></label>
                    <input type="text" class="regular-text frm-mask"
                        name="frm_gmail[accounts][__idx__][filters][__fidx__][mask]"
                        placeholder="<?php esc_attr_e('e.g. order-{entry_id}', 'frm-gmail'); ?>">
                    <div class="frm-gmail-help">
                        <?php esc_html_e('Single mask pattern. Use {entry_id} placeholder.', 'frm-gmail'); ?>
                    </div>
                </div>

                <div class="frm-gmail-row">
                    <label><?php esc_html_e('Status search area', 'frm-gmail'); ?></label>
                    <select multiple size="2" class="regular-text frm-status-area"
                        name="frm_gmail[accounts][__idx__][filters][__fidx__][status_search_area][]">
                        <option value="subject" selected><?php esc_html_e('subject', 'frm-gmail'); ?></option>
                        <option value="body"><?php esc_html_e('body', 'frm-gmail'); ?></option>
                    </select>
                </div>

                <div class="frm-gmail-row">
                    <label><?php esc_html_e('Status (single string)', 'frm-gmail'); ?></label>
                    <input type="text" class="regular-text frm-status"
                        name="frm_gmail[accounts][__idx__][filters][__fidx__][status]"
                        placeholder="<?php esc_attr_e('e.g. Paid, Refunded, Cancelled', 'frm-gmail'); ?>">
                </div>

                <div class="frm-gmail-row">
                    <label><?php esc_html_e('Status Field Id', 'frm-gmail'); ?></label>
                    <input type="number" min="0" step="1" class="regular-text frm-status-field"
                        name="frm_gmail[accounts][__idx__][filters][__fidx__][status_field_id]"
                        value="0"
                        placeholder="<?php esc_attr_e('Formidable status field ID (e.g. 7)', 'frm-gmail'); ?>">
                </div>

                <div class="filter-actions">
                    <button type="button" class="button frm-test-filter" data-idx="__idx__" data-fidx="__fidx__">
                        <?php esc_html_e('Test this filter', 'frm-gmail'); ?>
                    </button>
                    <div class="frm-gmail-test-results" id="frm-gmail-test-results-__idx__-__fidx__"></div>
                </div>
            </div>
        </template>

        <template id="frm-gmail-extra-template">
            <div class="frm-gmail-extra" data-eidx="__eidx__" style="border:1px solid #eee;border-radius:8px;padding:10px;margin:8px 0;">
                <div class="frm-gmail-row">
                <label>Title</label>
                <input type="text" class="regular-text" name="__name_prefix__[extra_fields][__eidx__][title]">
                </div>
                <div class="frm-gmail-row">
                <label>Code</label>
                <input type="text" class="regular-text" name="__name_prefix__[extra_fields][__eidx__][code]">
                </div>
                <div class="frm-gmail-row">
                <label>Mask</label>
                <textarea class="large-text code" rows="2" name="__name_prefix__[extra_fields][__eidx__][mask]" placeholder="Example: Your application locator number is {value}."></textarea>
                </div>
                <div class="frm-gmail-row">
                <label>Search area</label>
                <select class="regular-text" name="__name_prefix__[extra_fields][__eidx__][search_area]">
                    <option value="subject" selected>Subject</option>
                    <option value="body">Body</option>
                </select>
                </div>
                <div class="frm-gmail-row">
                <label>Entry Field Id</label>
                <input type="number" min="0" step="1" class="regular-text" name="__name_prefix__[extra_fields][__eidx__][entry_field_id]" value="0">
                </div>
                <button type="button" class="button-link-delete frm-remove-extra" title="Remove extra field">✕</button>
            </div>
        </template>


        <script>
        (function(){
            const container   = document.getElementById('frm-gmail-accounts');
            const addAccountBtn = document.getElementById('frm-gmail-add');
            const accountTpl  = document.getElementById('frm-gmail-block-template').innerHTML;
            const filterTpl   = document.getElementById('frm-gmail-filter-template').innerHTML;
            const ajaxurl     = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
            const savingUrl   = "<?php echo esc_js( admin_url('admin-post.php') ); ?>";

            function replaceAccountIndex(name, newIdx){
                return name.replace(/(\[accounts\])\[\d+\]/, '$1['+newIdx+']');
            }
            function replaceFilterIndex(name, newFidx){
                return name.replace(/(\[filters\])\[\d+\]/, '$1['+newFidx+']');
            }

            const extraTpl = document.getElementById('frm-gmail-extra-template')?.innerHTML || '';

            function addExtraBlock(filterEl){
                const idx  = filterEl.closest('.frm-gmail-card').dataset.idx;
                const fidx = filterEl.dataset.fidx;
                const grid = filterEl.querySelector('.frm-gmail-extra-grid');
                const eidx = grid.querySelectorAll('.frm-gmail-extra').length;

                // name prefix for this filter
                const namePrefix = `frm_gmail[accounts][${idx}][filters][${fidx}]`;
                let html = extraTpl.replaceAll('__eidx__', eidx).replaceAll('__name_prefix__', namePrefix);

                const temp = document.createElement('div');
                temp.innerHTML = html.trim();
                grid.appendChild(temp.firstElementChild);
                }

                function reindexExtrasWithin(filterEl){
                const idx  = filterEl.closest('.frm-gmail-card').dataset.idx;
                const fidx = filterEl.dataset.fidx;
                const grid = filterEl.querySelector('.frm-gmail-extra-grid');
                const namePrefix = `frm_gmail[accounts][${idx}][filters][${fidx}]`;

                grid.querySelectorAll('.frm-gmail-extra').forEach((extraEl, ei) => {
                    extraEl.dataset.eidx = ei;
                    extraEl.querySelectorAll('input[name], textarea[name], select[name]').forEach(el => {
                    // rewrite [extra_fields][old][...] -> [extra_fields][ei][...]
                    el.name = el.name.replace(/(\[extra_fields\])\[\d+\]/, `$1[${ei}]`)
                                    .replace(/frm_gmail\[accounts]\[\d+]\[filters]\[\d+]/, namePrefix);
                    });
                });
            }


            function reindexAccounts(){
                const cards = container.querySelectorAll('.frm-gmail-card');
                cards.forEach((card, idx) => {
                    card.dataset.idx = idx;
                    card.querySelectorAll('input[name], textarea[name], select[name]').forEach(el => {
                        el.name = replaceAccountIndex(el.name, idx);
                    });
                    card.querySelectorAll('.frm-gmail-filter').forEach((f, i) => {
                        f.dataset.idx = idx;
                        f.querySelectorAll('input[name], textarea[name], select[name]').forEach(el => {
                            el.name = replaceAccountIndex(el.name, idx);
                        });
                        const results = f.querySelector('.frm-gmail-test-results');
                        if (results) { results.id = 'frm-gmail-test-results-' + idx + '-' + i; }
                        const testBtn = f.querySelector('.frm-test-filter');
                        if (testBtn) { testBtn.dataset.idx = idx; testBtn.dataset.fidx = i; }
                        const num = f.querySelector('.filter-number');
                        if (num) { num.textContent = (i+1); }
                    });

                    const accountTestBtn = card.querySelector('.frm-gmail-test-account');
                    if (accountTestBtn) accountTestBtn.dataset.idx = idx;

                    const addFilterBtn = card.querySelector('.frm-add-filter');
                    if (addFilterBtn) addFilterBtn.dataset.idx = idx;
                });
            }

            function reindexFiltersWithin(card){
                const idx = card.dataset.idx;
                const filters = card.querySelectorAll('.frm-gmail-filter');
                filters.forEach((f, i) => {
                    f.dataset.fidx = i;
                    f.querySelectorAll('input[name], textarea[name], select[name]').forEach(el => {
                        el.name = replaceAccountIndex(el.name, idx);
                        el.name = replaceFilterIndex(el.name, i);
                    });
                    const results = f.querySelector('.frm-gmail-test-results');
                    if (results) { results.id = 'frm-gmail-test-results-' + idx + '-' + i; }
                    const testBtn = f.querySelector('.frm-test-filter');
                    if (testBtn) { testBtn.dataset.idx = idx; testBtn.dataset.fidx = i; }
                    const num = f.querySelector('.filter-number');
                    if (num) { num.textContent = (i+1); }

                    // keep extra fields indices in sync for each filter
                    reindexExtrasWithin(f);
                });
            }

            function addAccount(){
                const idx = container.querySelectorAll('.frm-gmail-card').length;
                const html = accountTpl.replaceAll('__index__', idx);
                const temp = document.createElement('div');
                temp.innerHTML = html.trim();
                const card = temp.firstElementChild;
                container.appendChild(card);
                reindexAccounts();
            }

            function addFilter(card){
                const idx = card.dataset.idx;
                const grid = card.querySelector('.frm-gmail-filter-grid');
                const fidx = grid.querySelectorAll('.frm-gmail-filter').length;
                let html = filterTpl
                    .replaceAll('__idx__', idx)
                    .replaceAll('__fidx__', fidx)
                    .replaceAll('__num__', (fidx+1));
                const temp = document.createElement('div');
                temp.innerHTML = html.trim();
                grid.appendChild(temp.firstElementChild);
                reindexFiltersWithin(card);
            }

            function collectExtras(filterEl){
                const out = [];
                filterEl.querySelectorAll('.frm-gmail-extra-grid .frm-gmail-extra').forEach(extraEl => {
                    const get = sel => { const el = extraEl.querySelector(sel); return el ? el.value : ''; };
                    out.push({
                    title: get('input[name$="[title]"]'),
                    code: get('input[name$="[code]"]'),
                    mask: get('textarea[name$="[mask]"]'),
                    search_area: get('select[name$="[search_area]"]') || 'subject',
                    entry_field_id: get('input[name$="[entry_field_id]"]') || 0
                    });
                });
                return out;
            }

            function collectFilterParams(filterEl){
                const get = (sel)=>{ const el=filterEl.querySelector(sel); return el ? el.value : ''; };
                const getMulti = (sel)=> {
                    const el = filterEl.querySelector(sel);
                    if (!el) return [];
                    const selected = Array.from(el.options).filter(o => o.selected).map(o => o.value);
                    return selected.length ? selected : ['subject'];
                };
                const getOrderArea = (sel)=>{
                    const el = filterEl.querySelector(sel);
                    const val = el ? el.value : 'subject';
                    return ['to','from','subject'].includes(val) ? val : 'subject';
                };
                return {
                    parser_code: get('input[name*="[parser_code]"]'),
                    title_filter: get('input[name*="[title_filter]"]'),
                    order_id_search_area: getOrderArea('select[name*="[order_id_search_area]"]'),
                    mask: get('input[name*="[mask]"]'), // single value
                    status_search_area: getMulti('select[name*="[status_search_area]"]'),
                    status: get('input[name*="[status]"]'),
                    status_field_id: get('input[name*="[status_field_id]"]'),
                    extra_fields: collectExtras(filterEl)
                };
            }

            function saveSettingsAJAX(){
                const form = document.querySelector('.frm-gmail-form');
                const fd = new FormData(form);
                fd.set('action', '<?php echo esc_js(self::ACTION_SAVE); ?>');
                fd.append('frm_gmail_ajax', '1');
                return fetch(savingUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(r => { if (!r.ok) throw new Error('Save failed'); return r.text(); });
            }

            function runAccountTest(idx, targetEl){
                if (targetEl) targetEl.innerHTML = '<em><?php echo esc_js(__('Loading latest 5 emails…', 'frm-gmail')); ?></em>';
                const params = new URLSearchParams({ action: '<?php echo esc_js(self::AJAX_TEST_ACTION); ?>', idx: idx });
                return fetch(ajaxurl + '?' + params.toString(), { credentials: 'same-origin' })
                    .then(r => r.text())
                    .then(html => { if (targetEl) targetEl.innerHTML = html; });
            }

            function runFilterTest(card, filterEl){
                const idx  = card.dataset.idx;
                const fidx = filterEl.dataset.fidx;
                const results = filterEl.querySelector('.frm-gmail-test-results');
                if (results) results.innerHTML = '<em><?php echo esc_js(__('Loading latest 5 emails…', 'frm-gmail')); ?></em>';

                const f = collectFilterParams(filterEl);
                const params = new URLSearchParams({
                    action: '<?php echo esc_js(self::AJAX_TEST_ACTION); ?>',
                    idx: idx,
                    fidx: fidx, // tell server this is a sub-filter test
                    parser_code: f.parser_code || '',
                    title_filter: f.title_filter || '',
                    order_id_search_area: f.order_id_search_area || 'subject',
                    mask: f.mask || '',
                    status: f.status || '',
                    status_field_id: f.status_field_id || ''
                });
                (f.status_search_area || ['subject']).forEach(v => params.append('status_search_area[]', v));

                return fetch(ajaxurl + '?' + params.toString(), { credentials: 'same-origin' })
                    .then(r => r.text())
                    .then(html => { if (results) results.innerHTML = html; });
            }

            // Delegated events
            container.addEventListener('click', function(e){
                const connectBtn = e.target.closest('.frm-gmail-connect');
                if (connectBtn) {
                    e.preventDefault();
                    const idx = connectBtn.dataset.idx;
                    const url = '<?php echo esc_js( admin_url('admin.php?page=' . self::MENU_SLUG) ); ?>&frm_gmail_auth=' + encodeURIComponent(idx);
                    const w = 600, h = 700;
                    const y = window.top.outerHeight/2 + window.top.screenY - ( h/2);
                    const x = window.top.outerWidth/2  + window.top.screenX - ( w/2);
                    const popup = window.open(url, 'frm_gmail_oauth_'+idx, `width=${w},height=${h},left=${x},top=${y}`);
                    window.addEventListener('message', function(ev){
                        if (ev && ev.data && ev.data.type === 'frm_gmail_connected') {
                            window.location.reload();
                        }
                    });
                    return;
                }

                const removeAccount = e.target.closest('.frm-gmail-remove');
                if (removeAccount) {
                    e.preventDefault();
                    const card = removeAccount.closest('.frm-gmail-card');
                    card.parentNode.removeChild(card);
                    reindexAccounts();
                    return;
                }

                const addFilterBtn = e.target.closest('.frm-add-filter');
                if (addFilterBtn) {
                    e.preventDefault();
                    const card = addFilterBtn.closest('.frm-gmail-card');
                    addFilter(card);
                    return;
                }

                const removeFilterBtn = e.target.closest('.frm-remove-filter');
                if (removeFilterBtn) {
                    e.preventDefault();
                    const card = removeFilterBtn.closest('.frm-gmail-card');
                    const filter = removeFilterBtn.closest('.frm-gmail-filter');
                    filter.parentNode.removeChild(filter);
                    reindexFiltersWithin(card);
                    return;
                }

                const testAccountBtn = e.target.closest('.frm-gmail-test-account');
                if (testAccountBtn) {
                    e.preventDefault();
                    const card = testAccountBtn.closest('.frm-gmail-card');
                    const idx  = testAccountBtn.dataset.idx;
                    let res = card.querySelector('.frm-gmail-test-results-account');
                    if (!res) {
                        res = document.createElement('div');
                        res.className = 'frm-gmail-test-results frm-gmail-test-results-account';
                        card.querySelector('.card-bd').appendChild(res);
                    }
                    res.innerHTML = '<em><?php echo esc_js(__('Saving settings…', 'frm-gmail')); ?></em>';
                    saveSettingsAJAX()
                        .then(() => runAccountTest(idx, res))
                        .catch(err => { res.innerHTML = '<div class="frm-gmail-test-results error"><p>' + (err?.message || 'Error') + '</p></div>'; });
                    return;
                }

                const testFilterBtn = e.target.closest('.frm-test-filter');
                if (testFilterBtn) {
                    e.preventDefault();
                    const card   = testFilterBtn.closest('.frm-gmail-card');
                    const filter = testFilterBtn.closest('.frm-gmail-filter');
                    const results= filter.querySelector('.frm-gmail-test-results');
                    if (results) results.innerHTML = '<em><?php echo esc_js(__('Saving settings…', 'frm-gmail')); ?></em>';

                    // Save the current settings, then run test strictly with this block’s params
                    saveSettingsAJAX()
                        .then(() => runFilterTest(card, filter))
                        .catch(err => {
                            if (results) results.innerHTML = '<div class="frm-gmail-test-results error"><p>' + (err?.message || 'Error') + '</p></div>';
                        });
                    return;
                }

                const addExtraBtn = e.target.closest('.frm-add-extra');
                if (addExtraBtn) {
                    e.preventDefault();
                    const filterEl = addExtraBtn.closest('.frm-gmail-filter');
                    addExtraBlock(filterEl);
                    reindexExtrasWithin(filterEl);
                    return;
                }

                const removeExtraBtn = e.target.closest('.frm-remove-extra');
                if (removeExtraBtn) {
                    e.preventDefault();
                    const filterEl = removeExtraBtn.closest('.frm-gmail-filter');
                    const extraEl  = removeExtraBtn.closest('.frm-gmail-extra');
                    if (extraEl) extraEl.remove();
                    reindexExtrasWithin(filterEl);
                    return;
                }

            });

            if (addAccountBtn) {
                addAccountBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    addAccount();
                });
            }
        })();
        </script>
        <?php
    }

    /** Start OAuth for a given account index (called from admin page via popup) */
    private static function start_oauth_for_index(int $idx): void {
        $creds = FrmGmailParserHelper::getCredsByOption($idx);
        if ($creds === null) {
            wp_die(__('Account index not found.', 'frm-gmail'));
        }

        $api    = new FrmGmailApi($creds, null);
        $client = $api->makeClient();
        if (!$client) {
            wp_die(__('Invalid or missing credentials JSON for this account.', 'frm-gmail'));
        }

        $client->setApplicationName('Gmail Parser (WP) - Account #' . ($idx + 1));
        $client->setScopes([ \Google\Service\Gmail::GMAIL_READONLY ]);
        $client->setRedirectUri( self::oauth_redirect_uri() );
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $nonce = wp_generate_password(20, false, false);
        set_transient(self::STATE_PREFIX . $nonce, ['idx' => $idx, 't' => time()], 15 * MINUTE_IN_SECONDS);
        $client->setState($nonce);

        wp_redirect( $client->createAuthUrl() );
        exit;
    }

    /** AJAX OAuth callback: store token + connected email */
    public static function oauth_callback(): void {
        FrmGmailApi::ensureGoogleLib();

        if ( isset($_GET['error']) ) {
            wp_die('Google OAuth error: ' . esc_html($_GET['error']));
        }

        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $info  = $state ? get_transient(self::STATE_PREFIX . $state) : null;
        if ( ! $info || ! isset($info['idx']) ) {
            wp_die('Invalid or expired state.');
        }
        delete_transient(self::STATE_PREFIX . $state);

        $idx  = (int) $info['idx'];
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        if ( ! $code ) {
            wp_die('Missing authorization code.');
        }

        $settings = FrmGmailParserHelper::getSettings();
        if ( ! isset($settings['accounts'][$idx]) ) {
            wp_die('Account index missing.');
        }

        $creds  = $settings['accounts'][$idx]['credentials'] ?? '';
        $api    = new FrmGmailApi($creds, null);
        $client = $api->makeClient();
        if (!$client) {
            wp_die('Invalid credentials for account.');
        }

        $client->setApplicationName('Gmail Parser (WP) - Account #' . ($idx + 1));
        $client->setScopes([ \Google\Service\Gmail::GMAIL_READONLY ]);
        $client->setRedirectUri( self::oauth_redirect_uri() );
        $client->setAccessType('offline');

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if ( isset($token['error']) ) {
            wp_die('Failed to fetch access token: ' . esc_html($token['error']));
        }

        // Merge missing refresh_token if needed
        $token = FrmGmailParserHelper::mergeRefreshTokenIfMissing($idx, $token);
        FrmGmailParserHelper::setTokenForAccount($idx, $token);

        // Save profile email (if available)
        $email = '';
        try {
            $gmail  = new \Google\Service\Gmail($client);
            $profile= $gmail->users->getProfile('me');
            $email  = $profile->getEmailAddress();
        } catch (\Throwable $e) {}

        if ($email) {
            FrmGmailParserHelper::setConnectedEmailAndTime($idx, $email);
        } else {
            FrmGmailParserHelper::setConnectedEmailAndTime($idx, '');
        }

        echo '<script>window.opener && window.opener.postMessage({type:"frm_gmail_connected"}, "*"); window.close();</script>';
        exit;
    }

    /**
     * Admin AJAX → call parser to render the HTML and echo it.
     * Accepts optional filter params (parser_code, title_filter, order_id_search_area, mask, status_search_area[], status, status_field_id)
     * If none provided, returns generic latest emails for the account.
     * Normalizes `status` (single string) → `statuses[]`.
     * Defaults status_search_area to ['subject'] if none provided.
     * IMPORTANT: If `fidx` is present, ONLY use params from this request (no fallback to saved settings).
     */
    public static function ajax_test_list(): void {
        if ( ! current_user_can(self::CAPABILITY) ) { wp_die('Forbidden'); }

        $idx  = isset($_GET['idx'])  ? (int) $_GET['idx']  : -1;
        $fidx = isset($_GET['fidx']) ? (int) $_GET['fidx'] : null; // which sub-filter (optional)

        // Collect sub parameters
        $filters = [
            'parser_code'           => isset($_GET['parser_code']) ? sanitize_text_field((string) $_GET['parser_code']) : '',
            'title_filter'          => isset($_GET['title_filter']) ? sanitize_text_field((string) $_GET['title_filter']) : '',
            'order_id_search_area'  => 'subject',
            'mask'                  => isset($_GET['mask']) ? sanitize_text_field((string) $_GET['mask']) : '',
            'status'                => isset($_GET['status']) ? sanitize_text_field((string) $_GET['status']) : '',
            'status_field_id'       => isset($_GET['status_field_id']) ? absint((string) $_GET['status_field_id']) : 0,
            'status_search_area'    => [],
        ];

        if ( isset($_GET['order_id_search_area']) ) {
            $o = sanitize_text_field((string) $_GET['order_id_search_area']);
            if (in_array($o, ['to','from','subject'], true)) {
                $filters['order_id_search_area'] = $o;
            }
        }

        if ( isset($_GET['status_search_area']) ) {
            $areas = (array) $_GET['status_search_area'];
            foreach ($areas as $a) {
                $a = sanitize_text_field($a);
                if (in_array($a, ['body','subject'], true)) {
                    $filters['status_search_area'][] = $a;
                }
            }
        }

        // Default to subject if none selected
        if (empty($filters['status_search_area'])) {
            $filters['status_search_area'] = ['subject'];
        }

        // Build statuses[] strictly from GET when fidx present; otherwise allow account-level fallback
        $statuses_raw = '';
        if ( isset($_GET['statuses']) ) {
            $statuses_raw = (string) $_GET['statuses']; // comma/newline supported (legacy)
        } elseif ( $filters['status'] !== '' ) {
            $statuses_raw = $filters['status'];
        }

        $statuses = [];
        if ($statuses_raw !== '') {
            $parts = preg_split('/[\n,]+/', $statuses_raw);
            $parts = array_map('trim', $parts);
            $statuses = array_values(array_filter($parts, static function($s){ return $s !== ''; }));
        }

        if (empty($statuses) && $fidx === null) {
            $settings = FrmGmailParserHelper::getSettings();
            if ( isset($settings['accounts'][$idx]) ) {
                $acc = $settings['accounts'][$idx];
                if (empty($statuses) && !empty($acc['statuses'])) {
                    $raw = (string) $acc['statuses'];
                    $parts = preg_split('/[\n,]+/', $raw);
                    $parts = array_map('trim', $parts);
                    $statuses = array_values(array_filter($parts, static function($s){ return $s !== ''; }));
                }
            }
        }

        $filters['statuses'] = $statuses;

        if ($fidx !== null) {
            $filters['fidx'] = $fidx; // forward for the parser if it cares
        }

        if (method_exists('FrmGmailParser', 'renderTestListHtml')) {
            $html = FrmGmailParser::renderTestListHtml($idx, self::oauth_redirect_uri(), $filters);
        } else {
            $html = '<div class="frm-gmail-test-results error"><p>Parser method missing.</p></div>';
        }

        wp_die($html);
    }
}

// Bootstrap admin
FrmGmailAdminPage::bootstrap();
