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
	 * Add what Email Canary says about one test to its row. Does nothing when no row has
	 * this test ID (for example, it is older than the last MAX results).
	 *
	 * @param string $test_id Test ID.
	 * @param array  $fields  Values for the row's "canary" part.
	 * @return bool True when a row changed.
	 */
	public static function update_test( $test_id, $fields ) {
		if ( '' === $test_id ) {
			return false;
		}
		$log = self::raw();
		foreach ( $log as $index => $row ) {
			if ( is_array( $row ) && isset( $row['test_id'] ) && $test_id === $row['test_id'] ) {
				$old                     = isset( $row['canary'] ) && is_array( $row['canary'] ) ? $row['canary'] : array();
				$log[ $index ]['canary'] = array_merge( $old, (array) $fields );
				if ( $log[ $index ]['canary'] === $old ) {
					return false;
				}
				update_option( self::OPTION, $log, false );
				return true;
			}
		}
		return false;
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
