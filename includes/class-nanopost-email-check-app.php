<?php
/**
 * Calls to the nanoPost app.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one place that talks to the app (https://app.nanopo.st/api/v1). Every call needs a
 * connection the owner made, or the token of "Turn on Email Canary". A failed call never
 * fails a test and never blocks a page: the timeouts are short, and a call runs only in
 * WP-Cron, WP-CLI, or a request the owner started with a click.
 */
class NanoPost_Email_Check_App {

	const DEFAULT_URL = 'https://app.nanopo.st/api/v1';

	/**
	 * Single WP-Cron event that sends the site settings.
	 */
	const SYNC_HOOK = 'nanopost_email_check_site_sync';

	/**
	 * Single WP-Cron event after an activation: "start" with trigger "activate".
	 */
	const START_HOOK = 'nanopost_email_check_start';

	/**
	 * A deactivation stops the canary on each site until this many seconds have passed,
	 * so a large network does not time out.
	 */
	const DEACTIVATE_BUDGET = 20;

	/**
	 * Recent failed calls, for support. Not autoloaded.
	 */
	const LOG_OPTION = 'nanopost_email_check_app_log';
	const LOG_MAX    = 20;

	const TIMEOUT_LONG  = 10;
	const TIMEOUT_SHORT = 5;

	/**
	 * Most characters of the wp_mail() error text in the announce call.
	 */
	const MAX_ANNOUNCE_ERROR = 500;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync_site' ) );
		add_action( self::START_HOOK, array( __CLASS__, 'start_after_activate' ) );
		add_action( 'update_option_' . NanoPost_Email_Check_Settings::OPTION, array( __CLASS__, 'queue_sync' ), 10, 0 );
		add_action( 'add_option_' . NanoPost_Email_Check_Settings::OPTION, array( __CLASS__, 'queue_sync' ), 10, 0 );
	}

	/**
	 * Base URL of the API, without a trailing slash. The NANOPOST_EMAIL_CHECK_APP_URL
	 * constant changes it, for tests against a staging app only.
	 *
	 * @return string
	 */
	public static function base_url() {
		if ( defined( 'NANOPOST_EMAIL_CHECK_APP_URL' ) && is_string( NANOPOST_EMAIL_CHECK_APP_URL ) && '' !== NANOPOST_EMAIL_CHECK_APP_URL ) {
			return untrailingslashit( NANOPOST_EMAIL_CHECK_APP_URL );
		}
		return self::DEFAULT_URL;
	}

	/**
	 * "Turn on Email Canary": make the canary with the token the plugin made, and store the
	 * connection. The owner's click (admin-post) or WP-CLI.
	 *
	 * With an account key in place of the address (#58), the canary watches at once, with
	 * alerts to the account's address. Exactly one of the two is sent. The key is sent in
	 * this call only, and never stored.
	 *
	 * @param string $email Alert address, or '' with a key.
	 * @param string $key   Account key, or ''.
	 * @return array Call result (see request()). error "bad_answer" when the answer lacks
	 *               a valid canary address; "connected" when a connection already exists.
	 */
	public static function connect( $email, $key = '' ) {
		$identity = '' !== $key ? 'key:' . NanoPost_Email_Check_Key::fingerprint( $key ) : (string) $email;
		$token    = NanoPost_Email_Check_Connection::begin( $identity, '' === $key ? (string) $email : '' );
		if ( '' === $token ) {
			return self::not_sent( 'connected' );
		}
		$body = self::site_body();
		$hash = md5( (string) wp_json_encode( $body ) );

		if ( '' !== $key ) {
			$body['account_key'] = (string) $key;
		} else {
			$body['alert_email'] = (string) $email;
		}
		$result = self::request( 'POST', '/connect', $body, self::TIMEOUT_LONG, true, false, $token );

		if ( $result['ok'] && ! NanoPost_Email_Check_Connection::save_from_connect( $result['body'], $hash ) ) {
			$result['ok']    = false;
			$result['error'] = 'bad_answer';
			self::log( 'POST', '/connect', $result );
		}
		if ( $result['ok'] ) {
			NanoPost_Email_Check_Canary::forget();
			NanoPost_Email_Check_Canary::take( $result['body'] );
			NanoPost_Email_Check_Webhook::reset_check( 'wp_mail' );
		}
		return $result;
	}

	/**
	 * "Resend" (the same address) or "Change" (another address) of the alert address.
	 *
	 * @param string $email Address.
	 * @return array Call result.
	 */
	public static function set_alert_email( $email ) {
		$result = self::request( 'PUT', '/alert-email', array( 'email' => (string) $email ), self::TIMEOUT_SHORT, true, true );
		if ( $result['ok'] ) {
			NanoPost_Email_Check_Canary::take( $result['body'] );
			NanoPost_Email_Check_Connection::set_alert_email( $email );
		}
		return $result;
	}

	/**
	 * Stop watching: "Turn Email Canary off" (turn_off) or a deactivation (deactivate). The
	 * token is kept, so "Turn on" needs no new confirm.
	 *
	 * @param string $trigger turn_off or deactivate.
	 * @param int    $timeout Timeout in seconds.
	 * @return array Call result.
	 */
	public static function stop( $trigger, $timeout = self::TIMEOUT_SHORT ) {
		$result = self::request( 'POST', '/stop', array( 'trigger' => (string) $trigger ), $timeout, true, true );
		if ( $result['ok'] ) {
			NanoPost_Email_Check_Canary::take( $result['body'] );
		}
		return $result;
	}

	/**
	 * Watch again: "Turn on" with the same address (turn_on) or an activation (activate).
	 *
	 * @param string $trigger turn_on or activate.
	 * @return array Call result.
	 */
	public static function start( $trigger ) {
		$result = self::request( 'POST', '/start', array( 'trigger' => (string) $trigger ), self::TIMEOUT_SHORT, true, true );
		if ( $result['ok'] ) {
			NanoPost_Email_Check_Canary::take( $result['body'] );
			self::queue_sync();
		}
		return $result;
	}

	/**
	 * Deactivation: tell nanoPost to stop watching (best effort), so it sends no "missing"
	 * alert for a plugin that was turned off on purpose. The token stays, so an activation
	 * can start it again. The stop link in each alert email is the second way.
	 *
	 * @param int $deadline Unix time after which no more calls go out (network loops).
	 */
	public static function stop_on_deactivate( $deadline ) {
		if ( time() >= $deadline || ! NanoPost_Email_Check_Connection::canary_active() ) {
			return;
		}
		$block = NanoPost_Email_Check_Canary::block();
		if ( null !== $block && 'unconfirmed' === $block['watching'] ) {
			return;
		}
		self::stop( 'deactivate', min( self::TIMEOUT_SHORT, max( 1, $deadline - time() ) ) );
	}

	/**
	 * Activation: book the "start" call in WP-Cron, not in the activation request.
	 */
	public static function queue_start() {
		if ( NanoPost_Email_Check_Connection::is_connected() && false === wp_next_scheduled( self::START_HOOK ) ) {
			wp_schedule_single_event( time(), self::START_HOOK );
		}
	}

	/**
	 * WP-Cron after an activation. nanoPost starts watching again only when the stop came
	 * from a deactivation; for any other stop it answers 409, and the canary stays off.
	 */
	public static function start_after_activate() {
		if ( NanoPost_Email_Check_Connection::is_connected() ) {
			self::start( 'activate' );
		}
	}

	/**
	 * End the connection: tell the app (best effort), then delete it here, also when the
	 * call fails.
	 *
	 * @param int $timeout Timeout in seconds.
	 * @return array Call result.
	 */
	public static function disconnect( $timeout = self::TIMEOUT_SHORT ) {
		$token  = NanoPost_Email_Check_Connection::get()['token'];
		$result = '' !== $token && NanoPost_Email_Check_Connection::is_connected()
			? self::request( 'DELETE', '/connection', null, $timeout, true, true )
			: self::not_sent( 'not_connected' );
		NanoPost_Email_Check_Connection::end();
		return $result;
	}

	/**
	 * Tell the app about a test right after wp_mail() returned.
	 *
	 * @param string $test_id Test ID.
	 * @param int    $time    Unix time of the send.
	 * @param string $trigger Trigger.
	 * @param bool   $ok      What wp_mail() returned.
	 * @param string $error   Error text, at most MAX_ANNOUNCE_ERROR characters. Empty for none.
	 * @return array Call result.
	 */
	public static function announce( $test_id, $time, $trigger, $ok, $error ) {
		return self::request(
			'POST',
			'/announce',
			array(
				'test_id' => (string) $test_id,
				'sent_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $time ),
				'trigger' => (string) $trigger,
				'wp_mail' => array(
					'ok'    => (bool) $ok,
					'error' => '' === $error ? null : (string) $error,
				),
			),
			self::TIMEOUT_SHORT
		);
	}

	/**
	 * Ask the app whether a test mail arrived.
	 *
	 * @param string $test_id Test ID.
	 * @return array Call result.
	 */
	public static function test_result( $test_id ) {
		return self::request( 'GET', '/tests/' . rawurlencode( (string) $test_id ), null, self::TIMEOUT_SHORT );
	}

	/**
	 * Ask the app for the state of the canary ("GET /check"). For the plugin screen only,
	 * through admin-ajax.
	 *
	 * @return array Call result.
	 */
	public static function check_state() {
		return self::request( 'GET', '/check', null, self::TIMEOUT_SHORT );
	}

	/**
	 * The body of the site settings call.
	 *
	 * @return array
	 */
	public static function site_body() {
		$webhook = NanoPost_Email_Check_Settings::webhook_url();
		return array(
			'site_url'       => home_url(),
			'plugin_version' => NANOPOST_EMAIL_CHECK_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'interval'       => NanoPost_Email_Check_Settings::interval(),
			'webhook_url'    => '' === $webhook ? null : $webhook,
			'timezone'       => wp_timezone_string(),
		);
	}

	/**
	 * Send the site settings when they differ from the last ones the app took. Runs from
	 * WP-Cron only: the single event after connect or a save, and each scheduled run.
	 *
	 * @return array|null Call result, or null when nothing changed or no call is allowed.
	 */
	public static function sync_site() {
		if ( ! NanoPost_Email_Check_Connection::calls_allowed() || ! NanoPost_Email_Check_Connection::canary_active() ) {
			return null;
		}
		$body = self::site_body();
		$hash = md5( (string) wp_json_encode( $body ) );
		if ( NanoPost_Email_Check_Connection::get()['site_hash'] === $hash ) {
			return null;
		}
		$result = self::request( 'PUT', '/site', $body, self::TIMEOUT_LONG );
		if ( $result['ok'] ) {
			NanoPost_Email_Check_Connection::set_site_hash( $hash );
		}
		return $result;
	}

	/**
	 * Book the single WP-Cron event that sends the site settings, while connected.
	 */
	public static function queue_sync() {
		if ( NanoPost_Email_Check_Connection::is_connected() && false === wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_single_event( time(), self::SYNC_HOOK );
		}
	}

	/**
	 * Cut a wp_mail() error for the announce call. The cause in brackets at the end, from
	 * NanoPost_Email_Check_Mail_Check::add_cause(), stays whole; the text before it is cut.
	 *
	 * @param string $error Error text, without the cause.
	 * @param string $cause Cause, or an empty string.
	 * @return string
	 */
	public static function announce_error( $error, $cause ) {
		return NanoPost_Email_Check_Mail_Check::add_cause( (string) $error, (string) $cause, self::MAX_ANNOUNCE_ERROR );
	}

	/**
	 * Make one call and apply the failure rules of the contract.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path after the base URL.
	 * @param array|null $body    JSON body, or null.
	 * @param int        $timeout Timeout in seconds.
	 * @param bool       $auth    Send the token. Calls with the token need a connection.
	 * @param bool       $always  Make the call also while Email Canary is off (the calls
	 *                            that start, stop or end it, and the alert address).
	 * @param string     $token   A token for a site with no connection yet (POST /connect).
	 * @return array ok (bool), code (HTTP status, 0 when no answer), error (stable code),
	 *               message (the app's text, may be shown), body (decoded JSON array),
	 *               date (the Date header of the answer, or '').
	 */
	public static function request( $method, $path, $body = null, $timeout = self::TIMEOUT_SHORT, $auth = true, $always = false, $token = '' ) {
		$headers = array(
			'Accept'           => 'application/json',
			'X-Plugin-Version' => NANOPOST_EMAIL_CHECK_VERSION,
		);

		if ( $auth && '' !== $token ) {
			$connection = NanoPost_Email_Check_Connection::get();
			if ( '' !== NanoPost_Email_Check_Connection::too_old_message() ) {
				return self::not_sent( 'too_old' );
			}
			if ( $connection['retry_until'] > time() ) {
				return self::not_sent( 'waiting' );
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		} elseif ( $auth ) {
			if ( ! NanoPost_Email_Check_Connection::is_connected() ) {
				return self::not_sent( 'not_connected' );
			}
			// The disconnect always goes out: it ends the connection here too.
			$ending = 'DELETE' === $method;
			if ( ! $ending && '' !== NanoPost_Email_Check_Connection::too_old_message() ) {
				return self::not_sent( 'too_old' );
			}
			if ( ! $ending && NanoPost_Email_Check_Connection::get()['retry_until'] > time() ) {
				return self::not_sent( 'waiting' );
			}
			if ( ! $always && ! NanoPost_Email_Check_Connection::canary_active() && 'GET /check' !== $method . ' ' . $path ) {
				return self::not_sent( 'canary_off' );
			}
			$headers['Authorization'] = 'Bearer ' . NanoPost_Email_Check_Connection::get()['token'];
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'nanoPost-Email-Check/' . NANOPOST_EMAIL_CHECK_VERSION,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			$result = array(
				'ok'      => false,
				'code'    => 0,
				'error'   => 'network',
				'message' => $response->get_error_message(),
				'body'    => array(),
				'date'    => '',
			);
			self::log( $method, $path, $result );
			return $result;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		$ok      = $code >= 200 && $code < 300;

		$result = array(
			'ok'      => $ok,
			'code'    => $code,
			'error'   => $ok ? '' : ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ? $decoded['error'] : 'http_' . $code ),
			'message' => ! $ok && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : '',
			'body'    => $decoded,
			'date'    => (string) wp_remote_retrieve_header( $response, 'date' ),
		);

		if ( $ok ) {
			return $result;
		}

		if ( 401 === $code && $auth ) {
			NanoPost_Email_Check_Connection::end( NanoPost_Email_Check_Connection::ENDED_REVOKED );
		} elseif ( 409 === $code && 'canary_stopped' === $result['error'] ) {
			// Email Canary stopped watching (for example "Stop watching" in an alert email).
			// The block says why; the next tests go to the recipient setting.
			NanoPost_Email_Check_Canary::take( $decoded );
		} elseif ( 410 === $code ) {
			NanoPost_Email_Check_Connection::mark_too_old( $result['message'] );
		} elseif ( 429 === $code ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			NanoPost_Email_Check_Connection::wait( $retry > 0 ? $retry : 60 );
		}

		self::log( $method, $path, $result );
		return $result;
	}

	/**
	 * Result for a call that was not made.
	 *
	 * @param string $reason not_connected or stopped.
	 * @return array
	 */
	private static function not_sent( $reason ) {
		return array(
			'ok'      => false,
			'code'    => 0,
			'error'   => $reason,
			'message' => '',
			'body'    => array(),
			'date'    => '',
		);
	}

	/**
	 * Record a failed call. Keeps the last LOG_MAX.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param array  $result Call result.
	 */
	private static function log( $method, $path, $result ) {
		$log   = self::failed_calls();
		$log[] = array(
			'time'    => time(),
			'call'    => $method . ' ' . $path,
			'code'    => $result['code'],
			'error'   => $result['error'],
			'message' => function_exists( 'mb_substr' ) ? mb_substr( $result['message'], 0, 300 ) : substr( $result['message'], 0, 300 ),
		);
		update_option( self::LOG_OPTION, array_slice( $log, -self::LOG_MAX ), false );
	}

	/**
	 * Recent failed calls, oldest first.
	 *
	 * @return array[]
	 */
	public static function failed_calls() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}
}
