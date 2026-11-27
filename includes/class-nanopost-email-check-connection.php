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
			'alert_email'  => '',
			'pending_at'   => 0,
		);

		$connection = array();
		foreach ( $defaults as $key => $default ) {
			$value              = isset( $stored[ $key ] ) ? $stored[ $key ] : $default;
			$connection[ $key ] = is_int( $default ) ? (int) $value : ( is_scalar( $value ) ? (string) $value : $default );
		}
		return $connection;
	}

	/**
	 * Start "Turn on Email Canary": the token the plugin makes for the connect call. It is
	 * stored before the call, so a retry sends the same token and the app does not make a
	 * second canary. A pending token for the same address is used again; another address
	 * gets a new token. Two requests at the same time get the same token (add_option()).
	 *
	 * @param string $email Alert address.
	 * @return string The token, or '' while a connection with an address exists.
	 */
	public static function begin( $email ) {
		$current = self::get();
		if ( '' !== $current['address'] && '' !== $current['token'] ) {
			return '';
		}
		if ( '' !== $current['token'] && $email === $current['alert_email'] ) {
			return $current['token'];
		}
		$pending = array(
			'token'       => self::new_token(),
			'alert_email' => (string) $email,
			'pending_at'  => time(),
			'home_url'    => home_url(),
		);
		if ( '' === $current['token'] && ! add_option( self::OPTION, $pending, '', false ) ) {
			// Another request stored a token a moment ago: use it when it is for this address.
			wp_cache_delete( self::OPTION, 'options' );
			$current = self::get();
			if ( $email === $current['alert_email'] && '' !== $current['token'] ) {
				return $current['token'];
			}
		}
		update_option( self::OPTION, $pending, false );
		return $pending['token'];
	}

	/**
	 * A new token: 32 random bytes, base64url without padding (43 characters).
	 *
	 * @return string
	 */
	public static function new_token() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a random token, nothing is hidden.
	}

	/**
	 * Store the connection from the answer to "POST /connect". The token is the one the
	 * plugin made (begin()).
	 *
	 * @param array  $answer    Decoded answer: address, check_url, check.
	 * @param string $site_hash Hash of the site settings the connect call carried.
	 * @return bool False when the answer lacks a valid address or no token is pending.
	 */
	public static function save_from_connect( $answer, $site_hash ) {
		$answer  = is_array( $answer ) ? $answer : array();
		$current = self::get();
		$address = isset( $answer['address'] ) && is_string( $answer['address'] ) ? $answer['address'] : '';
		if ( '' === $current['token'] || ! is_email( $address ) ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'token'        => $current['token'],
				'address'      => $address,
				'check_id'     => '',
				'check_url'    => isset( $answer['check_url'] ) && is_string( $answer['check_url'] ) ? esc_url_raw( $answer['check_url'], array( 'https', 'http' ) ) : '',
				'home_url'     => home_url(),
				'connected_at' => time(),
				'alert_email'  => $current['alert_email'],
				'site_hash'    => (string) $site_hash,
			),
			false
		);
		delete_option( self::NOTICE_OPTION );
		return true;
	}

	/**
	 * The alert address the owner last typed, or ''.
	 *
	 * @return string
	 */
	public static function alert_email() {
		return self::get()['alert_email'];
	}

	/**
	 * Remember the alert address the owner typed (after "Change").
	 *
	 * @param string $email Address.
	 */
	public static function set_alert_email( $email ) {
		self::update( array( 'alert_email' => (string) $email ) );
	}

	/**
	 * True while connected and Email Canary does not say it stopped watching. The test then
	 * goes to the canary address. While stopped, it goes to the recipient setting, and the
	 * plugin calls nanoPost only to start again or to end.
	 *
	 * @return bool
	 */
	public static function canary_active() {
		if ( ! self::is_connected() ) {
			return false;
		}
		$block = NanoPost_Email_Check_Canary::block();
		return null === $block || 'stopped' !== $block['watching'];
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
		NanoPost_Email_Check_Canary::forget();
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
