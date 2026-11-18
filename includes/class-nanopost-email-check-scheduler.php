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

	const HOOK = 'nanopost_email_check_run';

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
	 * Schedule the event if it is missing. The first run is one interval from now.
	 */
	public static function ensure_scheduled() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$interval = NanoPost_Email_Check_Settings::interval();
		wp_schedule_event( time() + self::interval_seconds( $interval ), $interval, self::HOOK );
	}

	/**
	 * Clear and schedule again, for example after the interval changed.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		self::ensure_scheduled();
	}

	/**
	 * After the settings are saved: reschedule only when the event's schedule differs from
	 * the chosen interval, so saving other settings does not move the next run.
	 */
	public static function sync_schedule() {
		$event = wp_get_scheduled_event( self::HOOK );

		if ( ! $event ) {
			self::ensure_scheduled();
		} elseif ( NanoPost_Email_Check_Settings::interval() !== $event->schedule ) {
			self::reschedule();
		}
	}

	/**
	 * Cron callback.
	 */
	public static function run() {
		NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_SCHEDULED );
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
