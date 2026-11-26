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
		// Put the event back if it is missing: after a network activation, or if something
		// removed it. On every request (front end, wp-admin, WP-CLI), so a site whose admin
		// area no one opens is scheduled too. It reads the cron option, which is autoloaded.
		add_action( 'wp_loaded', array( __CLASS__, 'ensure_scheduled' ) );
		// After core has set up the new site's tables (priority 10).
		add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_site' ), 100 );
	}

	/**
	 * Activation hook. On a network activation, schedule the event on every site.
	 *
	 * @param bool $network_wide True when activated for the whole network.
	 */
	public static function activate( $network_wide = false ) {
		// On a large network the loop could time out; wp_loaded schedules each site on its next request.
		if ( ! is_multisite() || ! $network_wide || wp_is_large_network() ) {
			self::ensure_scheduled();
			return;
		}

		foreach ( self::site_ids() as $site_id ) {
			switch_to_blog( $site_id );
			self::ensure_scheduled();
			restore_current_blog();
		}
	}

	/**
	 * A new site on the network. Schedule it when the plugin is network-active.
	 *
	 * @param WP_Site $site New site.
	 */
	public static function initialize_site( $site ) {
		if ( ! $site instanceof WP_Site || ! self::is_network_active() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::ensure_scheduled();
		restore_current_blog();
	}

	/**
	 * Whether the plugin is active for the whole network. is_plugin_active_for_network() is
	 * loaded only in wp-admin, so read the network option directly.
	 *
	 * @return bool
	 */
	private static function is_network_active() {
		if ( ! is_multisite() ) {
			return false;
		}
		$plugins = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $plugins ) && isset( $plugins[ plugin_basename( NANOPOST_EMAIL_CHECK_FILE ) ] );
	}

	/**
	 * IDs of all sites on the network.
	 *
	 * @return int[]
	 */
	private static function site_ids() {
		return get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
	}

	/**
	 * Deactivation hook. On a network deactivation, clear the event on every site.
	 *
	 * @param bool $network_wide True when deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( ! is_multisite() || ! $network_wide ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_clear_scheduled_hook( NanoPost_Email_Check_App::SYNC_HOOK );
			return;
		}

		foreach ( self::site_ids() as $site_id ) {
			switch_to_blog( $site_id );
			wp_clear_scheduled_hook( self::HOOK );
			wp_clear_scheduled_hook( NanoPost_Email_Check_App::SYNC_HOOK );
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
		// While connected: send the site settings when they changed (values from code,
		// WordPress and PHP updates). Does nothing when they did not.
		NanoPost_Email_Check_App::sync_site();
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
