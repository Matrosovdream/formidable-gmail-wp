<?php

abstract class FrmGmailAbstractCron {

    /** Add a 5-minute recurrence to WP-Cron */
    public static function add_five_min_schedule( array $schedules ): array {
        if ( ! isset( $schedules['five_minutes'] ) ) {
            $schedules['five_minutes'] = [
                'interval' => 300, // 5 minutes
                'display'  => __( 'Every 5 Minutes', 'easypost-wp' ),
            ];
        }
        return $schedules;
    }

    /** Called on plugin activation */
    public static function activate(): void {
        // Make sure our schedule exists early
        add_filter( 'cron_schedules', [ __CLASS__, 'add_five_min_schedule' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'five_minutes', self::HOOK );
        }
    }

    /** Called on plugin deactivation */
    public static function deactivate(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

}