<?php
/**
 * Result log.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the most recent results in one option, newest first.
 */
class NanoPost_Email_Check_Log {

	const OPTION = 'nanopost_email_check_log';
	const MAX    = 30;

	/**
	 * Add a result to the front of the log and drop the oldest past MAX.
	 *
	 * @param NanoPost_Email_Check_Result $result Result.
	 */
	public static function add( NanoPost_Email_Check_Result $result ) {
		$log = self::raw();
		array_unshift( $log, $result->to_array() );
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/**
	 * All results, newest first.
	 *
	 * @return NanoPost_Email_Check_Result[]
	 */
	public static function all() {
		return array_map( array( 'NanoPost_Email_Check_Result', 'from_array' ), self::raw() );
	}

	/**
	 * The newest result, or null when the log is empty.
	 *
	 * @return NanoPost_Email_Check_Result|null
	 */
	public static function latest() {
		$log = self::raw();
		return empty( $log ) ? null : NanoPost_Email_Check_Result::from_array( $log[0] );
	}

	/**
	 * The newest result of each check, newest first.
	 *
	 * @return NanoPost_Email_Check_Result[] Keyed by check id.
	 */
	public static function latest_per_check() {
		$latest = array();
		foreach ( self::all() as $result ) {
			if ( ! isset( $latest[ $result->check ] ) ) {
				$latest[ $result->check ] = $result;
			}
		}
		return $latest;
	}

	/**
	 * Stored arrays.
	 *
	 * @return array
	 */
	private static function raw() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}
}
