<?php
/**
 * The connection to the nanoPost app.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the connection the owner made (opt-in) and decides whether calls to the app are
 * allowed. One connection per site: on multisite each site has its own option.
 *
 * Without a connection, the plugin makes no calls to the app and sends tests to the
 * recipient setting, as before.
 */
class NanoPost_Email_Check_Connection {

	/**
	 * The stored connection. Not autoloaded: it holds the token.
	 */
	const OPTION = 'nanopost_email_check_connection';

	/**
	 * Why the connection ended without the owner's click. Shown once as "Reconnect".
	 */
	const NOTICE_OPTION = 'nanopost_email_check_connection_notice';

	const ENDED_REVOKED      = 'revoked';
	const ENDED_SITE_CHANGED = 'site_changed';

	/**
	 * The stored connection, with every key present.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$defaults = array(
			'token'        => '',
			'address'      => '',
			'check_id'     => '',
			'check_url'    => '',
			'home_url'     => '',
			'connected_at' => 0,
			'too_old'      => '',
			'too_old_from' => '',
			'retry_until'  => 0,
			'site_hash'    => '',
		);

		$connection = array();
		foreach ( $defaults as $key => $default ) {
			$value              = isset( $stored[ $key ] ) ? $stored[ $key ] : $default;
			$connection[ $key ] = is_int( $default ) ? (int) $value : ( is_scalar( $value ) ? (string) $value : $default );
		}
		return $connection;
	}

	/**
	 * Store the connection from the answer to "Exchange the code".
	 *
	 * @param array $answer Decoded answer: token, check_id, address, check_url.
	 * @return bool False when the answer lacks a token or a valid address.
	 */
	public static function save_from_exchange( $answer ) {
		$answer  = is_array( $answer ) ? $answer : array();
		$token   = isset( $answer['token'] ) && is_string( $answer['token'] ) ? $answer['token'] : '';
		$address = isset( $answer['address'] ) && is_string( $answer['address'] ) ? $answer['address'] : '';
		$url     = isset( $answer['check_url'] ) && is_string( $answer['check_url'] ) ? esc_url_raw( $answer['check_url'], array( 'https', 'http' ) ) : '';

		if ( '' === $token || ! is_email( $address ) ) {
			return false;
		}

		update_option(
			self::OPTION,
			array(
				'token'        => $token,
				'address'      => $address,
				'check_id'     => isset( $answer['check_id'] ) && is_scalar( $answer['check_id'] ) ? (string) $answer['check_id'] : '',
				'check_url'    => $url,
				'home_url'     => home_url(),
				'connected_at' => time(),
			),
			false
		);
		delete_option( self::NOTICE_OPTION );
		return true;
	}

	/**
	 * True while the site is connected. The test then goes to the check's address. A
	 * changed home URL (a cloned or moved site) ends the connection here, so a copy of
	 * the site never sends into the live site's check.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$connection = self::get();
		if ( '' === $connection['token'] || '' === $connection['address'] ) {
			return false;
		}
		if ( '' !== $connection['home_url'] && untrailingslashit( $connection['home_url'] ) !== untrailingslashit( home_url() ) ) {
			self::end( self::ENDED_SITE_CHANGED );
			return false;
		}
		return true;
	}

	/**
	 * True when a call to the app may go out now: connected, the plugin is not too old,
	 * and no "Retry-After" wait is running.
	 *
	 * @return bool
	 */
	public static function calls_allowed() {
		if ( ! self::is_connected() ) {
			return false;
		}
		$connection = self::get();
		if ( '' !== self::too_old_message() ) {
			return false;
		}
		return $connection['retry_until'] <= time();
	}

	/**
	 * The app's "update the plugin" text while calls are stopped for this version, or an
	 * empty string. A plugin update starts the calls again.
	 *
	 * @return string
	 */
	public static function too_old_message() {
		$connection = self::get();
		if ( '' === $connection['too_old'] ) {
			return '';
		}
		if ( NANOPOST_EMAIL_CHECK_VERSION !== $connection['too_old_from'] ) {
			self::update(
				array(
					'too_old'      => '',
					'too_old_from' => '',
				)
			);
			return '';
		}
		return $connection['too_old'];
	}

	/**
	 * Stop the calls for this plugin version (answer 410). The connection stays: the test
	 * still goes to the check's address.
	 *
	 * @param string $message The app's message.
	 */
	public static function mark_too_old( $message ) {
		$message = '' === trim( (string) $message ) ? __( 'This version of nanoPost Email Check is too old for nanoPost. Update the plugin.', 'nanopost-email-check' ) : (string) $message;
		self::update(
			array(
				'too_old'      => $message,
				'too_old_from' => NANOPOST_EMAIL_CHECK_VERSION,
			)
		);
	}

	/**
	 * Skip calls until a time (answer 429).
	 *
	 * @param int $seconds Seconds from now.
	 */
	public static function wait( $seconds ) {
		self::update( array( 'retry_until' => time() + max( 1, (int) $seconds ) ) );
	}

	/**
	 * Remember the hash of the last site settings the app took.
	 *
	 * @param string $hash Hash.
	 */
	public static function set_site_hash( $hash ) {
		self::update( array( 'site_hash' => (string) $hash ) );
	}

	/**
	 * End the connection. Deletes the token and the address, so the test goes to the
	 * recipient setting again. With a reason, the plugin screen shows "Reconnect" once.
	 *
	 * @param string $reason ENDED_REVOKED, ENDED_SITE_CHANGED, or '' for the owner's own
	 *                       disconnect.
	 */
	public static function end( $reason = '' ) {
		$was_connected = '' !== self::get()['token'];
		delete_option( self::OPTION );
		if ( '' !== $reason && $was_connected ) {
			update_option( self::NOTICE_OPTION, $reason, false );
		} elseif ( '' === $reason ) {
			delete_option( self::NOTICE_OPTION );
		}
		wp_clear_scheduled_hook( NanoPost_Email_Check_App::SYNC_HOOK );
		NanoPost_Email_Check_Webhook::reset_check( 'wp_mail' );
	}

	/**
	 * Why the connection ended, for the "Reconnect" notice, or an empty string.
	 *
	 * @return string
	 */
	public static function ended_reason() {
		$reason = get_option( self::NOTICE_OPTION, '' );
		return is_string( $reason ) ? $reason : '';
	}

	/**
	 * Forget the "Reconnect" notice.
	 */
	public static function clear_ended_reason() {
		delete_option( self::NOTICE_OPTION );
	}

	/**
	 * A new test ID: 12 characters, a-z and 0-9.
	 *
	 * @return string
	 */
	public static function new_test_id() {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id    = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$id .= $chars[ random_int( 0, 35 ) ];
		}
		return $id;
	}

	/**
	 * The address for one test: the check's address with ".<test_id>" added to the part
	 * after "+". Empty when not connected.
	 *
	 * @param string $test_id Test ID.
	 * @return string
	 */
	public static function test_address( $test_id ) {
		$address = self::get()['address'];
		$at      = strrpos( $address, '@' );
		if ( false === $at ) {
			return '';
		}
		return substr( $address, 0, $at ) . '.' . $test_id . substr( $address, $at );
	}

	/**
	 * Change some stored values.
	 *
	 * @param array $values Values.
	 */
	private static function update( $values ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['token'] ) ) {
			return;
		}
		update_option( self::OPTION, array_merge( $stored, $values ), false );
	}
}
