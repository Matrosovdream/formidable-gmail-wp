<?php
/**
 * Admin: Gmail parser (under Formidable)
 *
 * - Menu: Formidable → Gmail parser
 * - URL:  /wp-admin/admin.php?page=frm-gmail
 * - Option: frm_gmail
 *
 * Place this file inside your plugin and require/include it on load.
 */

if ( ! defined('ABSPATH') ) { exit; }

final class FrmGmailAdminPage {
    private const OPTION_NAME     = 'frm_gmail';
    private const CAPABILITY      = 'manage_options';
    private const MENU_PARENT     = 'formidable';     // Formidable's parent slug (falls back to Tools if not found)
    private const MENU_SLUG       = 'frm-gmail';
    private const ACTION_SAVE     = 'frm_gmail_save_accounts';
    private const NONCE_ACTION    = 'frm_gmail_nonce_action';
    private const NONCE_NAME      = 'frm_gmail_nonce';

    // OAuth callback + state
    private const AJAX_OAUTH_ACTION = 'frm_gmail_oauth';
    private const AJAX_TEST_ACTION  = 'frm_gmail_test_list';
    private const STATE_PREFIX      = 'frm_gmail_state_'; // transient key prefix

    /** Optional override: define('FRM_GML_REDIRECT_OVERRIDE', 'https://your.tld/wp-admin/admin-ajax.php?action=frm_gmail_oauth'); */

    private static function base_url(): string {
        return defined('FRM_GMAIL_URL') ? rtrim(FRM_GMAIL_URL, '/').'/' : plugin_dir_url(__FILE__);
    }
    private static function base_path(): string {
        return defined('FRM_GMAIL_PATH') ? rtrim(FRM_GMAIL_PATH, '/').'/' : plugin_dir_path(__FILE__);
    }

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

    /** Add submenu under Formidable (fallback to Tools if Formidable menu missing) */
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
        $ver = '1.1.2';
        // These two lines assume FRM_GML_BASE_PATH holds a URL (as in your main plugin). If not, swap to self::base_url().
        wp_enqueue_style(
            'frm-gmail-admin',
            (defined('FRM_GML_BASE_PATH') ? FRM_GML_BASE_PATH : self::base_url()) . 'assets/css/frm-gmail-admin.css?t=' . time(),
            [],
            $ver
        );
        wp_enqueue_script(
            'frm-gmail-admin',
            (defined('FRM_GML_BASE_PATH') ? FRM_GML_BASE_PATH : self::base_url()) . 'assets/js/frm-gmail-admin.js?t=' . time(),
            ['jquery'],
            $ver,
            true
        );
        wp_localize_script('frm-gmail-admin', 'FrmGmailAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    /** Read settings safely */
    private static function get_settings(): array {
        $opts = get_option(self::OPTION_NAME, []);
        $defaults = [
            'parser'   => [ 'start_date' => '' ],
            'accounts' => [], // row: ['title','credentials','mask','statuses','token','connected_email','connected_at']
        ];
        if ( ! is_array($opts) ) { $opts = []; }
        $opts = array_merge($defaults, $opts);

        $opts['parser'] = isset($opts['parser']) && is_array($opts['parser'])
            ? array_merge($defaults['parser'], $opts['parser'])
            : $defaults['parser'];

        $opts['accounts'] = isset($opts['accounts']) && is_array($opts['accounts'])
            ? $opts['accounts']
            : [];

        return $opts;
    }

    /** Save handler (admin-post) */
    public static function handle_save(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(__('You do not have permission to perform this action.', 'frm-gmail'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $previous = self::get_settings();
        $raw = $_POST['frm_gmail'] ?? [];

        // Optional: per-row disconnect request
        if ( isset($_POST['frm_gmail_disconnect_idx']) ) {
            $idx = (int) $_POST['frm_gmail_disconnect_idx'];
            if ( isset($previous['accounts'][$idx]) ) {
                unset($previous['accounts'][$idx]['token'], $previous['accounts'][$idx]['connected_email'], $previous['accounts'][$idx]['connected_at']);
                update_option(self::OPTION_NAME, $previous, false);
                set_transient('frm_gmail_notice', [
                    'type' => 'success',
                    'msg'  => sprintf(__('Disconnected account #%d.', 'frm-gmail'), $idx + 1),
                ], 30);
            }
            wp_safe_redirect( add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php')) );
            exit;
        }

        // --- Parser settings ---
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
                $title       = isset($row['title']) ? sanitize_text_field($row['title']) : '';
                // IMPORTANT: keep raw JSON; unslash to remove WP added slashes; no kses here
                $credentials = isset($row['credentials']) ? self::sanitize_textarea_keep_json($row['credentials']) : '';
                $mask        = isset($row['mask']) ? sanitize_text_field($row['mask']) : '';
                $statuses    = isset($row['statuses']) ? self::sanitize_textarea_simple($row['statuses']) : '';

                // Skip completely empty blocks
                if ($title === '' && $credentials === '' && $mask === '' && $statuses === '') {
                    continue;
                }

                $new = [
                    'title'       => $title,
                    'credentials' => $credentials,
                    'mask'        => $mask,
                    'statuses'    => $statuses,
                ];

                // Preserve token + connected_email if credentials unchanged
                if ( isset($previous['accounts'][$i]) ) {
                    $prev = $previous['accounts'][$i];
                    if ( ($prev['credentials'] ?? '') === $credentials ) {
                        if ( isset($prev['token']) )            { $new['token'] = $prev['token']; }
                        if ( isset($prev['connected_email']) )   { $new['connected_email'] = $prev['connected_email']; }
                        if ( isset($prev['connected_at']) )      { $new['connected_at'] = $prev['connected_at']; }
                    }
                }

                $safe_accounts[] = $new;
            }
        }

        $to_save = [
            'parser'   => [ 'start_date' => $start_date ],
            'accounts' => array_values($safe_accounts),
        ];

        update_option(self::OPTION_NAME, $to_save, false);

        // JSON validation notice
        $invalids = self::find_invalid_json_rows($safe_accounts);
        if ( ! empty($invalids) ) {
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

        wp_safe_redirect( add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php')) );
        exit;
    }

    /** Render the page */
    public static function render_page(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(__('You do not have permission to view this page.', 'frm-gmail'));
        }

        // Start OAuth for a specific block (admin route)
        if ( isset($_GET['frm_gmail_auth']) ) {
            $idx = (int) $_GET['frm_gmail_auth'];
            self::start_oauth_for_index($idx);
            exit;
        }

        $settings = self::get_settings();
        $notice   = get_transient('frm_gmail_notice');
        if ( $notice ) { delete_transient('frm_gmail_notice'); }

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
            .frm-gmail-test-results{margin-top:12px;border-top:1px solid #eee;padding-top:12px}
            .frm-gmail-test-results .email-item{padding:8px 10px;border:1px solid #eee;border-radius:8px;margin-bottom:8px;background:#fafafa}
            .frm-gmail-test-results .email-item .subject{font-weight:600}
            .frm-gmail-test-results .email-item .from{color:#555;font-size:12px}
            .frm-gmail-test-results .email-item .mid{color:#888;font-size:11px;font-family:monospace}
            .frm-gmail-test-results.error{border-color:#fbe9e7;background:#fff3e0;padding:12px;border-radius:8px}
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
                    $title       = $row['title']            ?? '';
                    $credentials = $row['credentials']      ?? '';
                    $mask        = $row['mask']             ?? '';
                    $statuses    = $row['statuses']         ?? '';
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

                            <div class="frm-gmail-row">
                                <label><?php esc_html_e('Gmail mask with Order Id', 'frm-gmail'); ?></label>
                                <input type="text" class="regular-text"
                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][mask]"
                                    value="<?php echo esc_attr($mask); ?>"
                                    placeholder="<?php esc_attr_e('e.g. order-* or ORD-{id}', 'frm-gmail'); ?>">
                            </div>

                            <div class="frm-gmail-row">
                                <label><?php esc_html_e('Statuses (in subject)', 'frm-gmail'); ?></label>
                                <textarea class="large-text code" rows="4"
                                    name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][statuses]"
                                    placeholder="<?php esc_attr_e('e.g. Paid, Refunded, Cancelled (comma or new line)', 'frm-gmail'); ?>"><?php echo esc_textarea($statuses); ?></textarea>
                            </div>

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
                                    <button type="button" class="button frm-gmail-test" data-idx="<?php echo esc_attr($i); ?>">
                                        <?php esc_html_e('Test email list', 'frm-gmail'); ?>
                                    </button>

                                    <button type="submit" class="button" name="frm_gmail_disconnect_idx" value="<?php echo esc_attr($i); ?>">
                                        <?php esc_html_e('Disconnect', 'frm-gmail'); ?>
                                    </button>
                                <?php endif; ?>

                                <button type="button" class="button frm-gmail-remove" aria-label="<?php esc_attr_e('Delete block', 'frm-gmail'); ?>">✕</button>
                            </div>

                            <!-- Results container will be injected here after the actions row -->
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

                    <div class="frm-gmail-row">
                        <label><?php esc_html_e('Gmail mask with Order Id', 'frm-gmail'); ?></label>
                        <input type="text" class="regular-text"
                            name="frm_gmail[accounts][__index__][mask]"
                            placeholder="<?php esc_attr_e('e.g. order-* or ORD-{id}', 'frm-gmail'); ?>">
                    </div>

                    <div class="frm-gmail-row">
                        <label><?php esc_html_e('Statuses (in subject)', 'frm-gmail'); ?></label>
                        <textarea class="large-text code" rows="4"
                            name="frm_gmail[accounts][__index__][statuses]"
                            placeholder="<?php esc_attr_e('e.g. Paid, Refunded, Cancelled', 'frm-gmail'); ?>"></textarea>
                    </div>

                    <div class="frm-gmail-row frm-gmail-actions">
                        <span class="frm-gmail-warn"><strong><?php esc_html_e('Save changes to establish connection', 'frm-gmail'); ?></strong></span>
                    </div>
                </div>
            </div>
        </template>

        <script>
        (function(){
            const container = document.getElementById('frm-gmail-accounts');
            const addBtn    = document.getElementById('frm-gmail-add');
            const template  = document.getElementById('frm-gmail-block-template').innerHTML;

            function reindexCards(){
                const cards = container.querySelectorAll('.frm-gmail-card');
                cards.forEach((card, idx) => {
                    card.dataset.idx = idx;
                    card.querySelectorAll('input[name], textarea[name]').forEach(el => {
                        el.name = el.name.replace(/\[\d+\]/, '['+idx+']');
                    });
                    card.querySelectorAll('[data-idx]').forEach(el => el.dataset.idx = idx);
                });
            }

            function openPopupForIdx(idx){
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
            }

            function ensureResultsContainer(actionsRow, idx){
                let block = actionsRow.parentElement.querySelector('.frm-gmail-test-results');
                if (!block) {
                    block = document.createElement('div');
                    block.className = 'frm-gmail-test-results';
                    block.id = 'frm-gmail-test-results-' + idx;
                    actionsRow.insertAdjacentElement('afterend', block);
                }
                return block;
            }

            container.addEventListener('click', function(e){
                const btnConnect = e.target.closest('.frm-gmail-connect');
                if (btnConnect) {
                    e.preventDefault();
                    openPopupForIdx(btnConnect.dataset.idx);
                    return;
                }
                const btnRemove = e.target.closest('.frm-gmail-remove');
                if (btnRemove) {
                    e.preventDefault();
                    const card = btnRemove.closest('.frm-gmail-card');
                    card.parentNode.removeChild(card);
                    reindexCards();
                    return;
                }
                const btnTest = e.target.closest('.frm-gmail-test');
                if (btnTest) {
                    e.preventDefault();
                    const idx = btnTest.dataset.idx;
                    const card = btnTest.closest('.frm-gmail-card');
                    const actionsRow = card.querySelector('.frm-gmail-actions');
                    const block = ensureResultsContainer(actionsRow, idx);
                    block.innerHTML = '<em><?php echo esc_js(__('Loading latest 5 emails…', 'frm-gmail')); ?></em>';

                    fetch(ajaxurl + '?action=<?php echo esc_js(self::AJAX_TEST_ACTION); ?>&idx=' + encodeURIComponent(idx), {credentials:'same-origin'})
                        .then(r => r.text())
                        .then(t => {
                            block.innerHTML = t;
                        })
                        .catch(err => {
                            block.innerHTML = '<div class="frm-gmail-test-results error"><p>' + (err && err.message ? err.message : 'Error') + '</p></div>';
                        });
                    return;
                }
            });

            if (addBtn) {
                addBtn.addEventListener('click', function(){
                    const idx = container.querySelectorAll('.frm-gmail-card').length;
                    const html = template.replaceAll('__index__', idx);
                    const temp = document.createElement('div');
                    temp.innerHTML = html.trim();
                    container.appendChild(temp.firstElementChild);
                    reindexCards();
                });
            }
        })();
        </script>
        <?php
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

    /** Start OAuth for a given account index (called from admin page via popup) */
    private static function start_oauth_for_index(int $idx): void {
        $settings = self::get_settings();
        $accounts = $settings['accounts'] ?? [];
        if ( ! isset($accounts[$idx]) ) {
            wp_die(__('Account index not found.', 'frm-gmail'));
        }
        $creds = $accounts[$idx]['credentials'] ?? '';
        $client = self::make_google_client_from_credentials($creds);
        if ( ! $client ) {
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

    /** AJAX OAuth callback (Google redirects here). Saves token into the specific account row. */
    public static function oauth_callback(): void {
        self::ensure_google_lib();

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

        $settings = self::get_settings();
        if ( ! isset($settings['accounts'][$idx]) ) {
            wp_die('Account index missing.');
        }

        $creds  = $settings['accounts'][$idx]['credentials'] ?? '';
        $client = self::make_google_client_from_credentials($creds);
        if ( ! $client ) {
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

        // Merge prior refresh_token if Google didn't resend it on subsequent grants
        $prevTok = $settings['accounts'][$idx]['token'] ?? [];
        if ( empty($token['refresh_token']) && is_array($prevTok) && !empty($prevTok['refresh_token']) ) {
            $token['refresh_token'] = $prevTok['refresh_token'];
        }

        // Save token + profile email
        $settings['accounts'][$idx]['token'] = $token;
        $email = '';
        try {
            $gmail  = new \Google\Service\Gmail($client);
            $profile= $gmail->users->getProfile('me');
            $email  = $profile->getEmailAddress();
        } catch (\Throwable $e) { /* ignore */ }

        if ($email) { $settings['accounts'][$idx]['connected_email'] = $email; }
        $settings['accounts'][$idx]['connected_at'] = current_time('mysql');

        update_option(self::OPTION_NAME, $settings, false);

        echo '<script>window.opener && window.opener.postMessage({type:"frm_gmail_connected"}, "*"); window.close();</script>';
        exit;
    }

    /** Admin AJAX test: list a few messages for given account index — returns HTML snippet to inject under the actions row */
    public static function ajax_test_list(): void {
        if ( ! current_user_can(self::CAPABILITY) ) { wp_die('Forbidden'); }

        $idx = isset($_GET['idx']) ? (int) $_GET['idx'] : -1;

        $settings = self::get_settings();
        if ( ! isset($settings['accounts'][$idx]) ) {
            wp_die('<div class="frm-gmail-test-results error"><p>Account not found.</p></div>');
        }
        $row   = $settings['accounts'][$idx];
        $creds = $row['credentials'] ?? '';
        $tok   = $row['token'] ?? null;

        if ( empty($tok) || ! is_array($tok) ) {
            wp_die('<div class="frm-gmail-test-results error"><p>Not connected.</p></div>');
        }

        $client = self::make_google_client_from_credentials($creds);
        if ( ! $client ) {
            wp_die('<div class="frm-gmail-test-results error"><p>Invalid credentials JSON.</p></div>');
        }

        $client->setApplicationName('Gmail Parser (WP) - Account #' . ($idx + 1));
        $client->setScopes([ \Google\Service\Gmail::GMAIL_READONLY ]);
        $client->setRedirectUri( self::oauth_redirect_uri() );
        $client->setAccessToken($tok);

        if ( $client->isAccessTokenExpired() ) {
            $refresh = $client->getRefreshToken();
            if ( $refresh ) {
                $client->fetchAccessTokenWithRefreshToken($refresh);
                $newTok = $client->getAccessToken();
                if ( empty($newTok['refresh_token']) && !empty($tok['refresh_token']) ) {
                    $newTok['refresh_token'] = $tok['refresh_token'];
                }
                $settings['accounts'][$idx]['token'] = $newTok;
                update_option(self::OPTION_NAME, $settings, false);
            } else {
                wp_die('<div class="frm-gmail-test-results error"><p>Token expired and no refresh token present. Please Reconnect.</p></div>');
            }
        }

        try {
            $gmail = new \Google\Service\Gmail($client);
            $list  = $gmail->users_messages->listUsersMessages('me', ['maxResults'=>5, 'q'=>'']);
            $msgs  = $list->getMessages() ?: [];

            ob_start();
            if ( ! $msgs ) {
                echo '<div class="frm-gmail-test-results"><p><em>No messages.</em></p></div>';
            } else {
                echo '<div class="frm-gmail-test-results">';
                foreach ($msgs as $m) {
                    $msg = $gmail->users_messages->get('me', $m->getId(), ['format'=>'metadata','metadataHeaders'=>['From','Subject']]);
                    $headers = [];
                    foreach ($msg->getPayload()->getHeaders() as $h) { $headers[$h->getName()] = $h->getValue(); }
                    $from = $headers['From'] ?? '';
                    $subj = $headers['Subject'] ?? '';
                    echo '<div class="email-item">';
                    echo '<div class="subject">'. esc_html($subj) .'</div>';
                    echo '<div class="from">'. esc_html($from) .'</div>';
                    echo '<div class="mid">ID: '. esc_html($m->getId()) .'</div>';
                    echo '</div>';
                }
                echo '</div>';
            }
            wp_die( ob_get_clean() );
        } catch (\Throwable $e) {
            wp_die('<div class="frm-gmail-test-results error"><p>Error: '. esc_html($e->getMessage()) .'</p></div>');
        }
    }

    /** Build Google Client from a credentials JSON string (flat or full "web"/"installed"), robust to slashes/BOM */
    private static function make_google_client_from_credentials(string $json): ?\Google\Client {
        self::ensure_google_lib();
        $arr = self::decode_json_robust($json);
        if ( ! is_array($arr) ) {
            return null;
        }

        $client = new \Google\Client();

        // Full Google JSON format
        if (isset($arr['web']) || isset($arr['installed'])) {
            $conf = $arr['web'] ?? $arr['installed'];
            if (!empty($conf['client_id']))     { $client->setClientId($conf['client_id']); }
            if (!empty($conf['client_secret'])) { $client->setClientSecret($conf['client_secret']); }
        } else {
            // Minimal custom format: {"client_id":"","client_secret":""}
            if (!empty($arr['client_id']))     { $client->setClientId($arr['client_id']); }
            if (!empty($arr['client_secret'])) { $client->setClientSecret($arr['client_secret']); }
        }

        // If still missing either, fail
        if ( ! $client->getClientId() || ! $client->getClientSecret() ) {
            return null;
        }

        return $client;
    }

    /** Robust JSON decode: unslash, strip BOM, try stripslashes fallback */
    private static function decode_json_robust(string $json) {
        $txt = trim((string) $json);
        if ($txt === '') { return null; }

        // If somehow already slashed (from old saves), try unslash variants
        $candidates = [];

        // 1) raw (as-is)
        $candidates[] = $txt;

        // 2) wp_unslash (handles WP added slashes)
        if ( function_exists('wp_unslash') ) {
            $candidates[] = wp_unslash($txt);
        }

        // 3) plain stripslashes
        $candidates[] = stripslashes($txt);

        foreach ($candidates as $cand) {
            // Remove UTF-8 BOM if present
            if (substr($cand, 0, 3) === "\xEF\xBB\xBF") {
                $cand = substr($cand, 3);
            }
            $arr = json_decode($cand, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
                return $arr;
            }
        }
        return null;
    }

    /** Ensure Google API Client is available (use filesystem paths, not URLs) */
    private static function ensure_google_lib(): void {
        if (class_exists('\Google\Client')) { return; }
        $vendor1 = FRM_GML_BASE_URL . 'vendor/autoload.php';
        $vendor2 = FRM_GML_BASE_URL . '/vendor/autoload.php';
        if ( file_exists($vendor1) ) { require_once $vendor1; return; }
        if ( file_exists($vendor2) ) { require_once $vendor2; return; }
    }

    /** Keep JSON text raw; just unslash + normalize newlines */
    private static function sanitize_textarea_keep_json($text): string {
        $text = is_string($text) ? $text : '';
        // remove WP-added slashes; do NOT kses on JSON
        if ( function_exists('wp_unslash') ) {
            $text = wp_unslash($text);
        } else {
            $text = stripslashes($text);
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim($text);
    }

    /** Simple textarea sanitize for CSV / lines */
    private static function sanitize_textarea_simple($text): string {
        $text = is_string($text) ? $text : '';
        // these are user text inputs; kses is fine here
        $text = wp_kses_post($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim($text);
    }

    /** Return 1-based block indexes with invalid JSON in 'credentials' (robust check) */
    private static function find_invalid_json_rows(array $accounts): array {
        $bad = [];
        foreach ($accounts as $idx => $row) {
            $txt = $row['credentials'] ?? '';
            if ($txt === '') { continue; }
            if ( ! is_array(self::decode_json_robust($txt)) ) {
                $bad[] = $idx + 1;
            }
        }
        return $bad;
    }
}

// Bootstrap
FrmGmailAdminPage::bootstrap();
