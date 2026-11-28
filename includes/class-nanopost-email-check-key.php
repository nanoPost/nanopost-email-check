<?php
/**
 * The nanoPost account key, for agencies with many sites.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns Email Canary on with an account key in place of an alert address: from the
 * NANOPOST_EMAIL_CHECK_KEY constant (by itself, through one WP-Cron event) or from
 * `wp email-check connect --key=...`. The canary watches at once, with alerts to the
 * account's address; no confirm email.
 *
 * The key is never stored, logged or shown. The plugin keeps only a salted hash of the key
 * and the site address, to connect by itself only once per key and site address.
 */
class NanoPost_Email_Check_Key {

	const CONSTANT = 'NANOPOST_EMAIL_CHECK_KEY';

	/**
	 * What the plugin keeps about the key: tried (hash of key and site address, after a
	 * definite answer), error (invalid_key or canary_exists, with the hash it belongs to),
	 * next_try (Unix time, after a failure that may pass). Not autoloaded.
	 */
	const OPTION = 'nanopost_email_check_key_state';

	/**
	 * Single WP-Cron event that connects with the key from the constant.
	 */
	const HOOK = 'nanopost_email_check_key_connect';

	/**
	 * Wait after a failure that may pass (network, 5xx, 429), before the constant tries again.
	 */
	const RETRY_AFTER = HOUR_IN_SECONDS;

	const ERROR_INVALID = 'invalid_key';
	const ERROR_EXISTS  = 'canary_exists';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'connect_from_constant' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_queue' ) );
	}

	/**
	 * The key from the constant, or ''.
	 *
	 * @return string
	 */
	public static function constant_key() {
		$key = defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ) ? trim( (string) constant( self::CONSTANT ) ) : '';

		/**
		 * Filters the account key that turns Email Canary on by itself. For tests and for
		 * tools that keep configuration outside wp-config.php. The key is never stored.
		 *
		 * @param string $key Key from NANOPOST_EMAIL_CHECK_KEY, or ''.
		 */
		$key = apply_filters( 'nanopost_email_check_account_key', $key );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * A salted hash of a key and the current site address. Never the key itself.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function fingerprint( $key ) {
		return wp_hash( $key . '|' . untrailingslashit( home_url() ), 'nonce' );
	}

	/**
	 * The stored state, with every key present.
	 *
	 * @return array
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'tried'    => isset( $stored['tried'] ) && is_string( $stored['tried'] ) ? $stored['tried'] : '',
			'error'    => isset( $stored['error'] ) && is_string( $stored['error'] ) ? $stored['error'] : '',
			'next_try' => isset( $stored['next_try'] ) ? (int) $stored['next_try'] : 0,
		);
	}

	/**
	 * Why the constant does not turn Email Canary on by itself now, or '' when it would.
	 *
	 * @return string no_key, connected, not_public, not_main_site, tried, ended, waiting.
	 */
	public static function blocked_reason() {
		$key = self::constant_key();
		if ( '' === $key ) {
			return 'no_key';
		}
		if ( NanoPost_Email_Check_Connection::is_connected() ) {
			return 'connected';
		}
		// A pending "Turn on" with an address (an owner's click whose answer was lost) is the
		// owner's: the key does not replace it. A pending key connect retries with its token.
		$pending = NanoPost_Email_Check_Connection::get();
		if ( '' !== $pending['token'] && 'key:' . self::fingerprint( $key ) !== $pending['pending_for'] ) {
			return 'connected';
		}
		if ( ! NanoPost_Email_Check_Admin::site_is_public() ) {
			return 'not_public';
		}
		if ( is_multisite() && ! is_main_site() ) {
			return 'not_main_site';
		}
		$state = self::state();
		if ( self::fingerprint( $key ) === $state['tried'] ) {
			return 'tried';
		}
		// A connection that ended without the owner's click (a 401, or a clone whose site
		// address changed): the owner turns it on again, not the constant.
		if ( '' !== NanoPost_Email_Check_Connection::ended_reason() ) {
			return 'ended';
		}
		if ( $state['next_try'] > time() ) {
			return 'waiting';
		}
		return '';
	}

	/**
	 * Book the single WP-Cron event when the constant may connect. Runs on each request, so
	 * it only reads options (the cron array and this plugin's not-autoloaded state).
	 */
	public static function maybe_queue() {
		if ( '' === self::constant_key() ) {
			return;
		}
		if ( '' === self::blocked_reason() && false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * WP-Cron: connect with the key from the constant, when it still may.
	 */
	public static function connect_from_constant() {
		if ( '' !== self::blocked_reason() ) {
			return;
		}
		self::connect( self::constant_key() );
	}

	/**
	 * Turn Email Canary on with a key (the constant, or WP-CLI). Records the try: after a
	 * definite answer the constant does not try this key on this site address again; after
	 * a failure that may pass, it waits RETRY_AFTER.
	 *
	 * @param string $key Account key.
	 * @return array Call result (see NanoPost_Email_Check_App::request()).
	 */
	public static function connect( $key ) {
		$call  = NanoPost_Email_Check_App::connect( '', $key );
		$print = self::fingerprint( $key );

		if ( $call['ok'] ) {
			$state = array( 'tried' => $print );
		} elseif ( in_array( $call['error'], array( self::ERROR_INVALID, self::ERROR_EXISTS ), true ) || in_array( $call['code'], array( 400, 403, 409, 422 ), true ) ) {
			$state = array(
				'tried' => $print,
				'error' => in_array( $call['error'], array( self::ERROR_INVALID, self::ERROR_EXISTS ), true ) ? $call['error'] : 'invalid_request',
			);
			// A key that is not taken leaves no pending token behind.
			if ( ! NanoPost_Email_Check_Connection::is_connected() ) {
				delete_option( NanoPost_Email_Check_Connection::OPTION );
			}
		} else {
			$state = array( 'next_try' => time() + self::RETRY_AFTER );
		}
		update_option( self::OPTION, $state, false );
		return $call;
	}

	/**
	 * The error of the last key connect for the key in the constant, or ''. An error for
	 * another key (or another site address) does not count: the owner changed the key.
	 *
	 * @return string
	 */
	public static function error() {
		$key   = self::constant_key();
		$state = self::state();
		return '' !== $key && '' !== $state['error'] && self::fingerprint( $key ) === $state['tried'] ? $state['error'] : '';
	}

	/**
	 * The plugin's text for a key error.
	 *
	 * @param string $error Error code.
	 * @return string
	 */
	public static function error_text( $error ) {
		if ( self::ERROR_INVALID === $error ) {
			return __( 'Email Canary: the account key is not valid. It may be wrong, replaced or removed in nanoPost. Change it in wp-config.php.', 'nanopost-email-check' );
		}
		if ( self::ERROR_EXISTS === $error ) {
			return __( 'Email Canary: this site already has a canary in nanoPost. Remove it there, or turn on with an email address.', 'nanopost-email-check' );
		}
		return __( 'Email Canary: nanoPost did not accept the account key.', 'nanopost-email-check' );
	}

	/**
	 * Forget the state. Uninstall and tests.
	 */
	public static function forget() {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::HOOK );
	}
}
