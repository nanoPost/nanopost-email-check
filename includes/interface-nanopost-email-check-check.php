<?php
/**
 * Check interface.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * One way of checking that the site can send email.
 *
 * Each check type is its own class. The plugin runs every registered check on each
 * scheduled or manual run and logs one result per check.
 */
interface NanoPost_Email_Check_Check {

	/**
	 * Short, stable machine name. Stored in the log.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Name shown to the site owner.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Run the check once.
	 *
	 * @param string $trigger NanoPost_Email_Check_Result::TRIGGER_SCHEDULED, TRIGGER_MANUAL or TRIGGER_CLI.
	 * @return NanoPost_Email_Check_Result
	 */
	public function run( $trigger );
}
