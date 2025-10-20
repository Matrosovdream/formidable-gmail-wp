<?php

if ( ! defined('ABSPATH') ) { exit; }

class FrmGmailUpdateEntriesCron extends FrmGmailAbstractCron {

    /** Dispatcher that runs hourly and enqueues per-account/filter single events */
    public const HOOK          = 'frm_update_entries_gmail_cron_dispatch';
    /** Single-run hook that actually processes one account/filter */
    public const RUN_ONE_HOOK  = 'frm_update_entries_gmail_cron_run_one';

    /** How far to start the first single event from "now" (sec) */
    private const START_DELAY_SEC   = 60;
    /** Stagger between each single event (sec) — 3 minutes */
    private const STAGGER_SEC       = 180;

    /**
     * Register schedules + callbacks. Safe to call multiple times.
     */
    public static function init(): void {

        // Dispatcher: runs hourly; it just schedules the per-account/filter single events
        add_action( self::HOOK, [ __CLASS__, 'run_update_entries' ] );

        // Single runner: handles one specific (account idx, filter idx) pair
        add_action( self::RUN_ONE_HOOK, [ __CLASS__, 'run_one' ], 10, 2 );

        // Ensure the hourly dispatcher is queued
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'hourly', self::HOOK );
        }
    }

    /**
     * Dispatcher: find all active accounts/filters and schedule one single event for each,
     * staggered by 3 minutes between runs.
     */
    public static function run_update_entries(): void {

        // All accounts
        $accounts = FrmGmailParserHelper::getAllAccounts();

        $offset = self::START_DELAY_SEC; // start after 60s
        foreach ( $accounts as $idx => $account ) {

            // skip if cron not active on this account
            if ( empty($account['active_cron']) ) {
                continue;
            }

            $filters = (isset($account['filters']) && is_array($account['filters'])) ? $account['filters'] : [];
            foreach ( $filters as $fi => $filter ) {

                // Avoid duplicate scheduling for the same args if one already exists in the future
                if ( ! wp_next_scheduled( self::RUN_ONE_HOOK, [ (int)$idx, (int)$fi ] ) ) {
                    wp_schedule_single_event( time() + $offset, self::RUN_ONE_HOOK, [ (int)$idx, (int)$fi ] );
                }

                // Stagger next one by 3 minutes
                $offset += self::STAGGER_SEC;
            }
        }
    }

    /**
     * Single-runner: executes one account/filter update.
     *
     * @param int $idx Account index
     * @param int $fi  Filter index
     */
    public static function run_one( int $idx, int $fi ): void {

        try {

            // After successful run → save today's date for this account/filter
            $option_name = sprintf( 'gmail_last_date_update_account_%d_filter_%d', $idx, $fi );
            $today = date( 'Y-m-d', current_time( 'timestamp' ) );

            $opts = [
                'start_date' => get_option( $option_name, '' ),
                //'print_results' => true,
                //'mode' => 'test', // Just show the list for update,
                //'slice_size' => 3
            ];

            // Run the update process
            $res = FrmGmailParserHelper::updateEntriesByAccountFilter( $idx, $fi, $opts, 50, 300 );

            echo "<pre>";
            print_r($res);
            echo "</pre>";

            // Save the new last update date
            //update_option( $option_name, $today, false );
    
        } catch ( \Throwable $e ) {
            if ( function_exists( 'error_log' ) ) {
                error_log( '[FrmGmailUpdateEntriesCron] run_one error: ' . $e->getMessage() );
            }
        }

    }

}
