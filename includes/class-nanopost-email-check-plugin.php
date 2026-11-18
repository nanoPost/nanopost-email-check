<?php
/**
 * Plugin bootstrap and check runner.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the parts together and runs the checks.
 */
class NanoPost_Email_Check_Plugin {

	/**
	 * Register hooks. Called once from the main plugin file.
	 */
	public static function boot() {
		add_action( 'init', array( 'NanoPost_Email_Check_Settings', 'register' ) );
		NanoPost_Email_Check_Scheduler::init();
		NanoPost_Email_Check_Webhook::init();

		if ( is_admin() ) {
			NanoPost_Email_Check_Admin::init();
			NanoPost_Email_Check_Alerts::init();
		}
	}

	/**
	 * The checks to run.
	 *
	 * @return NanoPost_Email_Check_Check[]
	 */
	public static function checks() {
		$checks = array( new NanoPost_Email_Check_Mail_Check() );

		/**
		 * Filters the checks that run on each scheduled or manual run.
		 *
		 * @param NanoPost_Email_Check_Check[] $checks Check objects.
		 */
		$checks = apply_filters( 'nanopost_email_check_checks', $checks );

		return array_values(
			array_filter(
				(array) $checks,
				static function ( $check ) {
					return $check instanceof NanoPost_Email_Check_Check;
				}
			)
		);
	}

	/**
	 * Run every check once, log each result and announce it.
	 *
	 * @param string $trigger NanoPost_Email_Check_Result::TRIGGER_SCHEDULED or TRIGGER_MANUAL.
	 * @return NanoPost_Email_Check_Result[]
	 */
	public static function run_checks( $trigger ) {
		$results = array();

		foreach ( self::checks() as $check ) {
			$result = $check->run( $trigger );
			NanoPost_Email_Check_Log::add( $result );

			/**
			 * Fires after a check has run and its result is logged.
			 *
			 * @param NanoPost_Email_Check_Result $result Result.
			 */
			do_action( 'nanopost_email_check_result', $result );

			$results[] = $result;
		}

		return $results;
	}
}
