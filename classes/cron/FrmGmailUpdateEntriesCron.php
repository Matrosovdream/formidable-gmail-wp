<?php

class FrmGmailUpdateEntriesCron extends FrmGmailAbstractCron {

    public const HOOK = 'frm_update_entries_gmail_cron_hook';

    public static function run_update_entries(): void {
        
        FrmGmailParserHelper::updateEntryStatuses();

    }

    /** Register schedule + callback. Safe to call multiple times. */
    public static function init(): void {

        add_action( self::HOOK, [ __CLASS__, 'run_update_entries' ] );

        // Ensure an event is queued (in case activation hook was missed on deploy)
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // schedule to run hourly
            wp_schedule_event( time() + 60, 'hourly', self::HOOK );
        }
    }

}