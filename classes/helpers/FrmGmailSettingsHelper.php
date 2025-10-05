<?php

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Helper to access Gmail parser settings.
 *
 * Option name: frm_gmail
 *
 * Structure:
 * [
 *   'parser' => [
 *       'start_date' => '2025-10-05',
 *   ],
 *   'accounts' => [
 *       [
 *           'title'       => 'Main Store',
 *           'credentials' => '{"client_id":"","client_secret":"","refresh_token":""}',
 *           'mask'        => 'order-*',
 *           'statuses'    => 'Paid, Refunded',
 *       ],
 *       ...
 *   ]
 * ]
 */
final class FrmGmailSettingsHelper {

    private const OPTION_NAME = 'frm_gmail';

    /** Cached settings */
    private ?array $settings = null;

    /** Constructor optionally loads settings immediately */
    public function __construct(bool $autoload = true) {
        if ( $autoload ) {
            $this->settings = $this->getAllSettings();
        }
    }

    /**
     * Retrieve all settings from wp_options safely.
     */
    public function getAllSettings(): array {
        $opts = get_option(self::OPTION_NAME, []);
        if ( ! is_array($opts) ) {
            $opts = [];
        }

        // Ensure consistent structure
        return wp_parse_args(
            $opts,
            [
                'parser' => [
                    'start_date' => '',
                ],
                'accounts' => [],
            ]
        );
    }

    /**
     * Get parser settings array.
     * @return array ['start_date' => 'YYYY-MM-DD']
     */
    public function getParserSettings(): array {
        $settings = $this->settings ?? $this->getAllSettings();
        return $settings['parser'] ?? [];
    }

    /**
     * Get parser start date (YYYY-MM-DD string)
     */
    public function getParserStartDate(): string {
        $parser = $this->getParserSettings();
        return $parser['start_date'] ?? '';
    }

    /**
     * Get all Gmail accounts.
     * Each account: ['title', 'credentials', 'mask', 'statuses']
     */
    public function getAccounts(): array {
        $settings = $this->settings ?? $this->getAllSettings();
        return isset($settings['accounts']) && is_array($settings['accounts'])
            ? $settings['accounts']
            : [];
    }

    /**
     * Get account by title (case-insensitive match)
     */
    public function getAccountByTitle(string $title): ?array {
        foreach ( $this->getAccounts() as $acc ) {
            if ( isset($acc['title']) && strcasecmp($acc['title'], $title) === 0 ) {
                return $acc;
            }
        }
        return null;
    }

    /**
     * Get decoded credentials for an account by title.
     * Returns associative array or null.
     */
    public function getCredentialsByTitle(string $title): ?array {
        $acc = $this->getAccountByTitle($title);
        if ( empty($acc['credentials']) ) {
            return null;
        }

        $decoded = json_decode($acc['credentials'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get Gmail mask for a given account title.
     */
    public function getMaskByTitle(string $title): ?string {
        $acc = $this->getAccountByTitle($title);
        return $acc['mask'] ?? null;
    }

    /**
     * Get statuses (array of trimmed items) for an account title.
     */
    public function getStatusesByTitle(string $title): array {
        $acc = $this->getAccountByTitle($title);
        if ( empty($acc['statuses']) ) {
            return [];
        }

        $raw = str_replace(["\r\n", "\r"], "\n", $acc['statuses']);
        $list = preg_split('/[\n,]+/', $raw);
        return array_filter(array_map('trim', $list));
    }
}
