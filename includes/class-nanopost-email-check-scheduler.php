<?php
/**
 * WP-Cron schedule.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one recurring WP-Cron event on the interval the owner chose.
 */
class NanoPost_Email_Check_Scheduler {

	const HOOK         = 'nanopost_email_check_run';
	const STATE_OPTION = 'nanopost_email_check_schedule_state';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'add_option_' . NanoPost_Email_Check_Settings::OPTION, array( __CLASS__, 'sync_schedule' ), 10, 0 );
		add_action( 'update_option_' . NanoPost_Email_Check_Settings::OPTION, array( __CLASS__, 'sync_schedule' ), 10, 0 );
		// Put the event back if something removed it, without the old "deactivate and reactivate" step.
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::ensure_scheduled();
	}

	/**
	 * Deactivation hook. On a network deactivation, clear the event on every site.
	 *
	 * @param bool $network_wide True when deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( ! is_multisite() || ! $network_wide ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( $site_id );
			wp_clear_scheduled_hook( self::HOOK );
			restore_current_blog();
		}
	}

	/**
	 * Schedule the event if it is missing, or schedule it again when its recurrence differs
	 * from the effective interval (for example after a constant changed). The first run is
	 * one interval from now. When the event matches, this only reads the cron array.
	 */
	public static function ensure_scheduled() {
		$interval = NanoPost_Email_Check_Settings::interval();
		$event    = wp_get_scheduled_event( self::HOOK );

		if ( $event && $interval === $event->schedule ) {
			return;
		}
		if ( $event ) {
			wp_clear_scheduled_hook( self::HOOK );
		}

		if ( wp_schedule_event( time() + self::interval_seconds( $interval ), $interval, self::HOOK ) ) {
			// Start of the grace period before the first scheduled check is due.
			self::update_state( 'since', time() );
		}
	}

	/**
	 * Clear and schedule again.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		self::ensure_scheduled();
	}

	/**
	 * After the settings are saved: reschedule only when the event's schedule differs from
	 * the effective interval, so saving other settings does not move the next run.
	 */
	public static function sync_schedule() {
		self::ensure_scheduled();
	}

	/**
	 * Cron callback.
	 */
	public static function run() {
		NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_SCHEDULED );
		// Only reached when the checks finished. WP-Cron has already booked the next run,
		// so a crash here leaves a future event but no new time.
		self::update_state( 'last_completed', time() );
		// Follow an interval set in code that changed since the event was booked, also on
		// sites where nobody opens the admin area.
		self::ensure_scheduled();
	}

	/**
	 * Unix time of the last scheduled run that finished, or 0.
	 *
	 * @return int
	 */
	public static function last_completed() {
		return self::state()['last_completed'];
	}

	/**
	 * Unix time the current schedule started, or 0.
	 *
	 * @return int
	 */
	public static function scheduled_since() {
		return self::state()['since'];
	}

	/**
	 * Stored schedule state.
	 *
	 * @return array{since:int, last_completed:int}
	 */
	private static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'since'          => isset( $state['since'] ) ? (int) $state['since'] : 0,
			'last_completed' => isset( $state['last_completed'] ) ? (int) $state['last_completed'] : 0,
		);
	}

	/**
	 * Set one value in the schedule state.
	 *
	 * @param string $key   "since" or "last_completed".
	 * @param int    $value Unix time.
	 */
	private static function update_state( $key, $value ) {
		$state         = self::state();
		$state[ $key ] = (int) $value;
		update_option( self::STATE_OPTION, $state );
	}

	/**
	 * Unix time of the next scheduled run, or false.
	 *
	 * @return int|false
	 */
	public static function next_run() {
		return wp_next_scheduled( self::HOOK );
	}

	/**
	 * Length of the scheduled event's interval in seconds, or of the chosen interval when no
	 * event exists.
	 *
	 * @return int
	 */
	public static function current_interval_seconds() {
		$event = wp_get_scheduled_event( self::HOOK );
		if ( $event && ! empty( $event->interval ) ) {
			return (int) $event->interval;
		}
		return self::interval_seconds( NanoPost_Email_Check_Settings::interval() );
	}

	/**
	 * Length of a schedule in seconds.
	 *
	 * @param string $interval Schedule name.
	 * @return int
	 */
	public static function interval_seconds( $interval ) {
		$schedules = wp_get_schedules();
		return isset( $schedules[ $interval ]['interval'] ) ? (int) $schedules[ $interval ]['interval'] : DAY_IN_SECONDS;
	}
}
