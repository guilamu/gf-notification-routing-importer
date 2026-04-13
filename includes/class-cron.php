<?php
/**
 * Cron handler for auto-syncing Google Sheets routing rules.
 *
 * Periodically reads connected Google Sheets and updates the
 * notification routing rules in Gravity Forms.
 *
 * @package GF_Notification_Routing_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * GFNRI_Cron class.
 */
class GFNRI_Cron {

    /**
     * WP-Cron hook name.
     *
     * @var string
     */
    const HOOK = 'gfnri_sync_gsheets_routing';

    /**
     * Custom cron schedule name.
     *
     * @var string
     */
    const SCHEDULE_NAME = 'gfnri_every_five_minutes';

    /**
     * Register hooks and manage cron schedule.
     *
     * @return void
     */
    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
        add_action( self::HOOK, array( __CLASS__, 'run_sync' ) );

        // Defer scheduling to 'init' so wp_schedule_event() doesn't trigger
        // cron_schedules filters (which may call __()) before translations load.
        add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );

        // Fallback: if WP-Cron is disabled or loopback fails, run overdue
        // syncs during admin page loads instead.
        add_action( 'admin_init', array( __CLASS__, 'maybe_run_sync_fallback' ) );
    }

    /**
     * Schedule or unschedule the cron event based on active connections.
     *
     * Runs on the 'init' action so that wp_get_schedules() (called internally
     * by wp_schedule_event) won't trigger translations too early.
     *
     * @return void
     */
    public static function maybe_schedule() {
        $connections = get_option( 'gfnri_gsheets_connections', array() );
        $next        = wp_next_scheduled( self::HOOK );

        if ( ! empty( $connections ) ) {
            if ( ! $next ) {
                wp_schedule_event( time(), self::SCHEDULE_NAME, self::HOOK );
            }
        } elseif ( $next ) {
            wp_clear_scheduled_hook( self::HOOK );
        }
    }

    /**
     * Fallback sync trigger for environments where WP-Cron doesn't fire.
     *
     * Runs on admin_init; checks if any connection is overdue and triggers
     * run_sync() directly. Lightweight: only reads a single option and a
     * transient before deciding.
     *
     * @return void
     */
    public static function maybe_run_sync_fallback() {
        $connections = get_option( 'gfnri_gsheets_connections', array() );

        if ( empty( $connections ) ) {
            return;
        }

        // Throttle: only attempt once per 60 seconds per admin session.
        if ( get_transient( 'gfnri_fallback_throttle' ) ) {
            return;
        }
        set_transient( 'gfnri_fallback_throttle', true, MINUTE_IN_SECONDS );

        // Check if any connection is overdue.
        $overdue = false;

        foreach ( $connections as $conn ) {
            $form = GFAPI::get_form( $conn['form_id'] );

            if ( ! $form || empty( $form['notifications'][ $conn['nid'] ] ) ) {
                continue;
            }

            $notification = $form['notifications'][ $conn['nid'] ];
            $interval     = isset( $notification['gfnri_gsheets_sync_interval'] ) ? (int) $notification['gfnri_gsheets_sync_interval'] : 0;
            $last_sync    = isset( $notification['gfnri_gsheets_last_sync'] ) ? (int) $notification['gfnri_gsheets_last_sync'] : 0;

            if ( $interval > 0 && ( 0 === $last_sync || ( time() - $last_sync ) >= ( $interval * 60 ) ) ) {
                $overdue = true;
                break;
            }
        }

        if ( $overdue ) {
            self::run_sync();
        }
    }

    /**
     * Add the 5-minute cron schedule.
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public static function add_schedule( $schedules ) {
        // Intentionally not using __() here: the cron_schedules filter can fire
        // before translations are loaded (e.g. during wp_schedule_event at init).
        $schedules[ self::SCHEDULE_NAME ] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 minutes (GFNRI)',
        );

        return $schedules;
    }

    /**
     * Remove the scheduled cron event (on plugin deactivation).
     *
     * @return void
     */
    public static function unschedule() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Main cron callback: iterate all active connections and sync when due.
     *
     * @return void
     */
    public static function run_sync() {
        if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GFNRI_Import_Handler' ) ) {
            return;
        }

        if ( ! class_exists( '\GC_Google_Sheets\Accounts\Google_Accounts' )
            || ! class_exists( '\GC_Google_Sheets\Spreadsheets\Spreadsheet' )
            || ! function_exists( 'gcgs_get_spreadsheet_id_from_url' ) ) {
            return;
        }

        $connections = get_option( 'gfnri_gsheets_connections', array() );

        if ( empty( $connections ) ) {
            return;
        }

        // Simple lock to prevent overlapping runs.
        if ( get_transient( 'gfnri_sync_lock' ) ) {
            return;
        }
        set_transient( 'gfnri_sync_lock', true, 4 * MINUTE_IN_SECONDS );

        $handler = new GFNRI_Import_Handler();

        foreach ( $connections as $key => $conn ) {
            try {
                self::sync_single( $handler, $conn['form_id'], $conn['nid'] );
            } catch ( \Exception $e ) {
                self::update_sync_status( $conn['form_id'], $conn['nid'], 'error', $e->getMessage() );
            }
        }

        delete_transient( 'gfnri_sync_lock' );
    }

    /**
     * Sync a single notification's routing from its connected Google Sheet.
     *
     * @param GFNRI_Import_Handler $handler  The import handler instance.
     * @param int                  $form_id  The form ID.
     * @param string               $nid      The notification ID.
     * @return void
     */
    private static function sync_single( $handler, $form_id, $nid ) {
        $form = GFAPI::get_form( $form_id );

        if ( ! $form || empty( $form['notifications'][ $nid ] ) ) {
            self::remove_connection( $form_id, $nid );
            return;
        }

        $notification = $form['notifications'][ $nid ];

        $account_email = isset( $notification['gfnri_gsheets_account'] ) ? $notification['gfnri_gsheets_account'] : '';
        $sheet_url     = isset( $notification['gfnri_gsheets_url'] ) ? $notification['gfnri_gsheets_url'] : '';
        $sheet_id      = isset( $notification['gfnri_gsheets_sheet_id'] ) ? $notification['gfnri_gsheets_sheet_id'] : '';
        $interval      = isset( $notification['gfnri_gsheets_sync_interval'] ) ? (int) $notification['gfnri_gsheets_sync_interval'] : 0;

        if ( empty( $account_email ) || empty( $sheet_url ) || '' === $sheet_id || $interval <= 0 ) {
            return;
        }

        // Check if the configured interval has elapsed since last sync.
        $last_sync = isset( $notification['gfnri_gsheets_last_sync'] ) ? (int) $notification['gfnri_gsheets_last_sync'] : 0;
        $elapsed   = time() - $last_sync;
        $threshold = $interval * 60;

        if ( $last_sync > 0 && $elapsed < $threshold ) {
            return;
        }

        $spreadsheet_id = gcgs_get_spreadsheet_id_from_url( $sheet_url );

        if ( empty( $spreadsheet_id ) ) {
            self::update_sync_status( $form_id, $nid, 'error', __( 'Invalid sheet URL.', 'gf-notification-routing-importer' ) );
            return;
        }

        // Find Google account.
        $google_account = null;
        $accounts       = \GC_Google_Sheets\Accounts\Google_Accounts::get_all();

        foreach ( $accounts as $acct ) {
            if ( $acct->get_email() === $account_email && $acct->is_token_healthy() ) {
                $google_account = $acct;
                break;
            }
        }

        if ( ! $google_account ) {
            self::update_sync_status( $form_id, $nid, 'error', __( 'Google account not found or unhealthy.', 'gf-notification-routing-importer' ) );
            return;
        }

        $spreadsheet = \GC_Google_Sheets\Spreadsheets\Spreadsheet::get( $spreadsheet_id, $sheet_id, $google_account );

        if ( ! $spreadsheet || ! $spreadsheet->has_spreadsheet() ) {
            $error = $spreadsheet ? $spreadsheet->get_error() : __( 'Could not connect.', 'gf-notification-routing-importer' );
            self::update_sync_status( $form_id, $nid, 'error', $error );
            return;
        }

        $sheet_name = $spreadsheet->get_sheet_name();
        $range      = $sheet_name . '!A:Z';
        $rows       = $spreadsheet->read_range( $range );

        if ( false === $rows || empty( $rows ) || count( $rows ) < 2 ) {
            self::update_sync_status( $form_id, $nid, 'error', __( 'Sheet is empty or has no data rows.', 'gf-notification-routing-importer' ) );
            return;
        }

        $result = $handler->process_rows( $rows, $form );

        if ( is_wp_error( $result ) ) {
            self::update_sync_status( $form_id, $nid, 'error', $result->get_error_message() );
            return;
        }

        if ( empty( $result['routing'] ) ) {
            $msg = __( 'No valid routing rules found.', 'gf-notification-routing-importer' );
            if ( ! empty( $result['warnings'] ) ) {
                $msg .= ' ' . implode( '; ', array_slice( $result['warnings'], 0, 3 ) );
            }
            self::update_sync_status( $form_id, $nid, 'error', $msg );
            return;
        }

        // Re-read the form to minimize stale-data risk.
        $form = GFAPI::get_form( $form_id );

        if ( ! $form || empty( $form['notifications'][ $nid ] ) ) {
            return;
        }

        $form['notifications'][ $nid ]['routing']                      = $result['routing'];
        $form['notifications'][ $nid ]['gfnri_gsheets_last_sync']      = time();
        $form['notifications'][ $nid ]['gfnri_gsheets_sync_status']    = 'ok';
        $form['notifications'][ $nid ]['gfnri_gsheets_sync_message']   = sprintf(
            /* translators: %d: number of rules */
            __( '%d rule(s) synced.', 'gf-notification-routing-importer' ),
            $result['count']
        );

        GFAPI::update_form( $form );
    }

    /**
     * Update sync status metadata on a notification.
     *
     * Only writes when the status actually changed to avoid redundant saves.
     *
     * @param int    $form_id The form ID.
     * @param string $nid     The notification ID.
     * @param string $status  'ok' or 'error'.
     * @param string $message Status message.
     * @return void
     */
    private static function update_sync_status( $form_id, $nid, $status, $message ) {
        $form = GFAPI::get_form( $form_id );

        if ( ! $form || ! isset( $form['notifications'][ $nid ] ) ) {
            return;
        }

        $notification = $form['notifications'][ $nid ];

        // Skip if nothing changed.
        $old_status  = isset( $notification['gfnri_gsheets_sync_status'] ) ? $notification['gfnri_gsheets_sync_status'] : '';
        $old_message = isset( $notification['gfnri_gsheets_sync_message'] ) ? $notification['gfnri_gsheets_sync_message'] : '';

        if ( $old_status === $status && $old_message === $message ) {
            return;
        }

        $form['notifications'][ $nid ]['gfnri_gsheets_last_sync']    = time();
        $form['notifications'][ $nid ]['gfnri_gsheets_sync_status']  = $status;
        $form['notifications'][ $nid ]['gfnri_gsheets_sync_message'] = $message;

        GFAPI::update_form( $form );
    }

    /**
     * Remove a stale entry from the connections index.
     *
     * @param int    $form_id The form ID.
     * @param string $nid     The notification ID.
     * @return void
     */
    private static function remove_connection( $form_id, $nid ) {
        $connections = get_option( 'gfnri_gsheets_connections', array() );
        $key         = $form_id . '_' . $nid;

        if ( isset( $connections[ $key ] ) ) {
            unset( $connections[ $key ] );
            update_option( 'gfnri_gsheets_connections', $connections, true );
        }
    }
}
