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
    private const MENU_PARENT     = 'formidable';     // Formidable's parent slug
    private const MENU_SLUG       = 'frm-gmail';
    private const ACTION_SAVE     = 'frm_gmail_save_accounts';
    private const NONCE_ACTION    = 'frm_gmail_nonce_action';
    private const NONCE_NAME      = 'frm_gmail_nonce';

    /** You may define these constants in your bootstrap:
     *   define('FRM_GMAIL_URL', plugin_dir_url(__FILE__));
     *   define('FRM_GMAIL_PATH', plugin_dir_path(__FILE__));
     * Or adjust the fallback logic below.
     */
    private static function base_url(): string {
        if ( defined('FRM_GMAIL_URL') ) {
            return rtrim(FRM_GMAIL_URL, '/').'/';
        }
        // Fallback based on this file
        return plugin_dir_url(__FILE__);
    }
    private static function base_path(): string {
        if ( defined('FRM_GMAIL_PATH') ) {
            return rtrim(FRM_GMAIL_PATH, '/').'/';
        }
        return plugin_dir_path(__FILE__);
    }

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_' . self::ACTION_SAVE, [__CLASS__, 'handle_save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /** Add submenu under Formidable */
    public static function register_menu(): void {
        add_submenu_page(
            self::MENU_PARENT,
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
        // Loads only on our screen id (submenu under Formidable uses page query)
        if ( ! isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG ) {
            return;
        }

        $ver = '1.0.0';
        wp_enqueue_style(
            'frm-gmail-admin',
            FRM_GML_BASE_PATH . 'assets/css/frm-gmail-admin.css?t=' . time(),
            [],
            $ver
        );
        wp_enqueue_script(
            'frm-gmail-admin',
            FRM_GML_BASE_PATH . 'assets/js/frm-gmail-admin.js?t=' . time(),
            ['jquery'],
            $ver,
            true
        );

        // Pass some strings to JS if needed
        wp_localize_script('frm-gmail-admin', 'FrmGmailAdmin', [
            'addRow'    => __('Add row', 'frm-gmail'),
            'removeRow' => __('Remove', 'frm-gmail'),
        ]);
    }

    /** Read settings safely */
    private static function get_settings(): array {
        $opts = get_option(self::OPTION_NAME, []);
        $defaults = [
            'parser'   => [
                'start_date' => '', // YYYY-MM-DD
            ],
            'accounts' => [], // each: ['title'=>'','credentials'=>'','mask'=>'','statuses'=>'']
        ];
        if ( ! is_array($opts) ) { $opts = []; }
        // Merge shallowly; ensure both keys exist
        $opts = array_merge($defaults, $opts);
        // Make sure sub-arrays exist
        if ( ! isset($opts['parser']) || ! is_array($opts['parser']) ) {
            $opts['parser'] = $defaults['parser'];
        } else {
            $opts['parser'] = array_merge($defaults['parser'], $opts['parser']);
        }
        if ( ! isset($opts['accounts']) || ! is_array($opts['accounts']) ) {
            $opts['accounts'] = [];
        }
        return $opts;
    }

    /** Save handler (admin-post) */
    public static function handle_save(): void {
        if ( ! current_user_can(self::CAPABILITY) ) {
            wp_die(__('You do not have permission to perform this action.', 'frm-gmail'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $raw = $_POST['frm_gmail'] ?? [];

        // --- Parser settings ---
        $start_date = '';
        if ( isset($raw['parser']['start_date']) ) {
            $candidate = sanitize_text_field( (string) $raw['parser']['start_date'] );
            // Accept only simple YYYY-MM-DD; otherwise save empty string
            if ( preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) ) {
                $start_date = $candidate;
            }
        }

        // --- Accounts table ---
        $safe_accounts = [];
        if ( isset($raw['accounts']) && is_array($raw['accounts']) ) {
            foreach ( $raw['accounts'] as $row ) {
                // Normalize row keys
                $title       = isset($row['title']) ? sanitize_text_field($row['title']) : '';
                $credentials = isset($row['credentials']) ? self::sanitize_textarea_keep_json($row['credentials']) : '';
                $mask        = isset($row['mask']) ? sanitize_text_field($row['mask']) : '';
                $statuses    = isset($row['statuses']) ? self::sanitize_textarea_simple($row['statuses']) : '';

                // Skip completely empty rows
                if ($title === '' && $credentials === '' && $mask === '' && $statuses === '') {
                    continue;
                }

                $safe_accounts[] = [
                    'title'       => $title,
                    'credentials' => $credentials, // keep raw JSON text (sanitized but not decoded)
                    'mask'        => $mask,
                    'statuses'    => $statuses,    // CSV or multiline
                ];
            }
        }

        $to_save = [
            'parser'   => [
                'start_date' => $start_date,
            ],
            'accounts' => $safe_accounts,
        ];

        update_option(self::OPTION_NAME, $to_save, false);

        // Optional JSON validation check → admin notice via transient
        $invalids = self::find_invalid_json_rows($safe_accounts);
        if ( ! empty($invalids) ) {
            set_transient('frm_gmail_notice', [
                'type' => 'warning',
                'msg'  => sprintf(
                    /* translators: %s: row numbers */
                    __('Saved, but credentials JSON looks invalid in row(s): %s', 'frm-gmail'),
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

        $settings = self::get_settings();
        $notice   = get_transient('frm_gmail_notice');
        if ( $notice ) {
            delete_transient('frm_gmail_notice');
        }
        ?>
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

                <!-- ===== Parser settings ===== -->
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
                                <p class="description"><?php esc_html_e('takes emails from date', 'frm-gmail'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- ===== Gmail accounts ===== -->
                <h2 class="title"><?php echo esc_html__('Gmail accounts', 'frm-gmail'); ?></h2>

                <div class="frm-gmail-table-wrap">

                    <table class="widefat fixed striped frm-gmail-table" id="frm-gmail-accounts">
                        <thead>
                            <tr>
                                <th style="width:45%"><?php esc_html_e('Account title / Credentials (JSON)', 'frm-gmail'); ?></th>
                                <th style="width:25%"><?php esc_html_e('Gmail mask with Order Id', 'frm-gmail'); ?></th>
                                <th style="width:22%"><?php esc_html_e('Statuses (in subject)', 'frm-gmail'); ?><br><small><?php esc_html_e('comma separated or one per line', 'frm-gmail'); ?></small></th>
                                <th style="width:8%"  class="frm-gmail-actions-head"><?php esc_html_e('Actions', 'frm-gmail'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $rows = ! empty($settings['accounts']) ? $settings['accounts'] : [[]];
                        foreach ( $rows as $i => $row ):
                            $title       = $row['title']       ?? '';
                            $credentials = $row['credentials'] ?? '';
                            $mask        = $row['mask']        ?? '';
                            $statuses    = $row['statuses']    ?? '';
                        ?>
                            <tr class="frm-gmail-row">
                                <td class="frm-gmail-account">
                                    <label>
                                        <input type="text" class="regular-text" 
                                            name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][title]" 
                                            value="<?php echo esc_attr($title); ?>" 
                                            placeholder="<?php esc_attr_e('Account title', 'frm-gmail'); ?>">
                                    </label>
                                    <textarea class="large-text code frm-credentials" rows="6" 
                                        name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][credentials]" 
                                        placeholder='{"client_id":"","client_secret":"","refresh_token":""}'><?php echo esc_textarea($credentials); ?></textarea>
                                </td>
                                <td>
                                    <input type="text" class="regular-text" 
                                        name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][mask]" 
                                        value="<?php echo esc_attr($mask); ?>" 
                                        placeholder="<?php esc_attr_e('e.g. order-* or ORD-{id}', 'frm-gmail'); ?>">
                                </td>
                                <td>
                                    <textarea class="large-text code" rows="6" 
                                        name="frm_gmail[accounts][<?php echo esc_attr($i); ?>][statuses]" 
                                        placeholder="<?php esc_attr_e('e.g. Paid, Refunded, Cancelled', 'frm-gmail'); ?>"><?php echo esc_textarea($statuses); ?></textarea>
                                </td>
                                <td class="frm-gmail-actions">
                                    <button type="button" class="button frm-eap-del-row frm-gmail-remove" aria-label="Delete row">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4">
                                    <button type="button" class="button button-secondary" id="frm-gmail-add"><?php esc_html_e('Add row', 'frm-gmail'); ?></button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>

                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Changes', 'frm-gmail'); ?></button>
                </p>
            </form>
        </div>

        <!-- Template row (cloned by JS) -->
        <template id="frm-gmail-row-template">
            <tr class="frm-gmail-row">
                <td class="frm-gmail-account">
                    <input type="text" class="regular-text" name="__name__[title]" placeholder="<?php esc_attr_e('Account title', 'frm-gmail'); ?>">
                    <textarea class="large-text code frm-credentials" rows="6" name="__name__[credentials]" placeholder='{"client_id":"","client_secret":"","refresh_token":""}'></textarea>
                </td>
                <td>
                    <input type="text" class="regular-text" name="__name__[mask]" placeholder="<?php esc_attr_e('e.g. order-* or ORD-{id}', 'frm-gmail'); ?>">
                </td>
                <td>
                    <textarea class="large-text code" rows="6" name="__name__[statuses]" placeholder="<?php esc_attr_e('e.g. Paid, Refunded, Cancelled', 'frm-gmail'); ?>"></textarea>
                </td>
                <td class="frm-gmail-actions">
                    <button type="button" class="button frm-eap-del-row frm-gmail-remove" aria-label="Delete row">✕</button>
                </td>
            </tr>
        </template>

        <?php
    }

    /** Keep JSON text but strip tags & normalize newlines */
    private static function sanitize_textarea_keep_json(string $text): string {
        $text = wp_kses_post($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim($text);
    }

    /** Simple textarea sanitize for CSV / lines */
    private static function sanitize_textarea_simple(string $text): string {
        $text = wp_kses_post($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim($text);
    }

    /** Return 1-based row indexes with invalid JSON in 'credentials' */
    private static function find_invalid_json_rows(array $accounts): array {
        $bad = [];
        foreach ($accounts as $idx => $row) {
            $txt = $row['credentials'] ?? '';
            if ($txt === '') { continue; }
            json_decode($txt, true);
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $bad[] = $idx + 1; // 1-based for UI
            }
        }
        return $bad;
    }
}

// Bootstrap
add_action('plugins_loaded', function () {
    if ( is_admin() ) {
        FrmGmailAdminPage::init();
    }
});
