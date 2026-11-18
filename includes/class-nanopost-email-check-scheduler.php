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
		add_action( 'add_option_' . NanoPost_Email_Check_Settings::OPTION, array( __CLASS__, 'reschedule' ) );
		add_action( 'update_option_' . NanoPost_Email_Check_Settings::OPTION, array( __CLASS__, 'on_settings_update' ), 10, 2 );
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
	 * Deactivation hook.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
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
	 * Reschedule when the saved interval changes.
	 *
	 * @param mixed $old_value Old option value.
	 * @param mixed $new_value New option value.
	 */
	public static function on_settings_update( $old_value, $new_value ) {
		$old_interval = is_array( $old_value ) && isset( $old_value['interval'] ) ? $old_value['interval'] : '';
		$new_interval = is_array( $new_value ) && isset( $new_value['interval'] ) ? $new_value['interval'] : '';

		if ( $old_interval !== $new_interval ) {
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
