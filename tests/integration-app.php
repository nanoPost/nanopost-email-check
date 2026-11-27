<?php
/**
 * Integration tests for the connection to the nanoPost app (step 2). Included by
 * integration.php, inside its database transaction, so it uses that file's helpers.
 *
 * The app is faked with the pre_http_request filter: no test calls a real server. Each
 * test can set the next answers; every call is recorded.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Files.FileName, WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery, Squiz.Commenting.FunctionComment.Missing -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

$GLOBALS['np_app_calls'] = array();
$GLOBALS['np_app_next']  = array();

/**
 * Queue the next answer of the fake app.
 *
 * @param int   $code    HTTP status, or 0 for a network error.
 * @param array $body    JSON body.
 * @param array $headers Headers.
 */
function np_app_answer( $code, $body = array(), $headers = array() ) {
	$GLOBALS['np_app_next'][] = array( $code, $body, $headers );
}

/**
 * The recorded calls, and forget them.
 *
 * @return array[] method, path, headers, body (decoded), timeout.
 */
function np_app_take() {
	$calls                   = $GLOBALS['np_app_calls'];
	$GLOBALS['np_app_calls'] = array();
	return $calls;
}

/**
 * Store a connection as "Turn on Email Canary" would.
 *
 * @param array $extra Values to change.
 */
function np_app_connect( $extra = array() ) {
	update_option(
		NanoPost_Email_Check_Connection::OPTION,
		array_merge(
			array(
				'token'        => 'tok-123',
				'address'      => 'canary+k7f3q9x2@nanopost.example',
				'check_id'     => '01JD',
				'check_url'    => 'https://app.example/checks/01JD',
				'home_url'     => home_url(),
				'connected_at' => time(),
			),
			$extra
		),
		false
	);
}

/**
 * A check block for the fake app's answers.
 *
 * @param string      $watching on, unconfirmed, stopped, over_limit.
 * @param string      $email    Alert address.
 * @param string      $state    confirmed or pending.
 * @param string|null $reason   stopped_reason.
 * @return array
 */
function np_app_block( $watching, $email, $state, $reason = null ) {
	return array(
		'observed_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'watching'       => $watching,
		'stopped_reason' => $reason,
		'arrival'        => 'waiting',
		'sending'        => 'ok',
		'receive_path'   => 'ok',
		'alerts_to'      => array(
			array(
				'email' => $email,
				'state' => $state,
			),
		),
		'check_url'      => 'https://app.example/checks/01JE',
	);
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$base = NanoPost_Email_Check_App::base_url();
		if ( 0 !== strpos( $url, $base ) ) {
			return $pre;
		}
		$path                      = substr( $url, strlen( $base ) );
		$GLOBALS['np_app_calls'][] = array(
			'method'  => $args['method'],
			'path'    => $path,
			'headers' => $args['headers'],
			'ua'      => isset( $args['user-agent'] ) ? $args['user-agent'] : '',
			'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
			'timeout' => $args['timeout'],
		);

		if ( $GLOBALS['np_app_next'] ) {
			list( $code, $body, $headers ) = array_shift( $GLOBALS['np_app_next'] );
		} elseif ( '/connect' === $path ) {
			list( $code, $body, $headers ) = array(
				201,
				array(
					'address'   => 'canary+abcd1234@nanopost.example',
					'check_url' => 'https://app.example/checks/01JE',
					'check'     => array(
						'observed_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'watching'     => 'unconfirmed',
						'confirm_by'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
						'confirm_mail' => array(
							'state'           => 'sent',
							'next_allowed_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
						),
						'arrival'      => 'waiting',
						'sending'      => 'ok',
						'receive_path' => 'ok',
						'alerts_to'    => array(
							array(
								'email' => isset( $args['body'] ) ? (string) ( json_decode( $args['body'], true )['alert_email'] ?? '' ) : '',
								'state' => 'pending',
							),
						),
						'check_url'    => 'https://app.example/checks/01JE',
					),
				),
				array(),
			);
		} elseif ( '/announce' === $path ) {
			list( $code, $body, $headers ) = array( 202, array( 'expect_by' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 ) ), array() );
		} elseif ( '/connection' === $path ) {
			list( $code, $body, $headers ) = array( 204, array(), array() );
		} elseif ( 0 === strpos( $path, '/tests/' ) ) {
			list( $code, $body, $headers ) = array(
				200,
				array(
					'test_id' => substr( $path, 7 ),
					'status'  => 'waiting',
				),
				array(),
			);
		} else {
			list( $code, $body, $headers ) = array( 200, is_array( $args['body'] ?? null ) ? array() : (array) json_decode( (string) ( $args['body'] ?? '' ), true ), array() );
		}

		if ( 0 === $code ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => array() === $body ? '' : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	},
	20,
	3
);

// ---------------------------------------------------------------------------------------
np_section( 'App: not connected' );
np_reset();
np_app_take();
// The dev site may point at the staging app with NANOPOST_EMAIL_CHECK_APP_URL.
$np_app_base = defined( 'NANOPOST_EMAIL_CHECK_APP_URL' ) ? untrailingslashit( NANOPOST_EMAIL_CHECK_APP_URL ) : 'https://app.nanopo.st/api/v1';
np_assert( NanoPost_Email_Check_App::base_url() === $np_app_base, 'base URL: the production API, or the constant', NanoPost_Email_Check_App::base_url() );
np_assert( ! NanoPost_Email_Check_Connection::is_connected() && ! NanoPost_Email_Check_Connection::calls_allowed(), 'no connection, no calls' );
$np = NanoPost_Email_Check_App::announce( 'abcdefabcdef', time(), 'manual', true, '' );
np_assert( ! $np['ok'] && 'not_connected' === $np['error'] && array() === np_app_take(), 'announce without a connection makes no call', $np );
np_assert( null === NanoPost_Email_Check_App::sync_site() && array() === np_app_take(), 'site settings without a connection make no call' );
add_filter( 'pre_wp_mail', '__return_true' );
$np_results = NanoPost_Email_Check_Plugin::run_checks( 'manual' );
np_assert( '' === $np_results[0]->test_id && '' === $np_results[0]->announce && array() === np_app_take(), 'a test without a connection: no test ID, no call' );
remove_filter( 'pre_wp_mail', '__return_true' );


// ---------------------------------------------------------------------------------------
np_section( 'App: turn on Email Canary (POST /connect)' );
np_reset();
np_app_take();
update_option( NanoPost_Email_Check_Webhook::STATE_OPTION, array( 'last_status' => array( 'wp_mail' => 'fail' ) ) );
$np       = NanoPost_Email_Check_App::connect( 'owner@example.com' );
$np_calls = np_app_take();
np_assert( $np['ok'] && 1 === count( $np_calls ), 'connect: one call, ok', $np );
$np_call  = $np_calls[0];
$np_token = substr( $np_call['headers']['Authorization'], 7 );
np_assert( 'POST' === $np_call['method'] && '/connect' === $np_call['path'] && 10 === $np_call['timeout'], 'connect: POST /connect, 10 seconds', $np_call );
np_assert( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $np_token ), 'connect: a token the plugin made, 32 random bytes in base64url, as Bearer', $np_token );
np_assert( NANOPOST_EMAIL_CHECK_VERSION === $np_call['headers']['X-Plugin-Version'] && 'application/json' === $np_call['headers']['Accept'], 'connect: version and Accept headers', $np_call['headers'] );
np_assert( 'nanoPost-Email-Check/' . NANOPOST_EMAIL_CHECK_VERSION === $np_call['ua'], 'connect: User-Agent names only the plugin and its version', $np_call['ua'] );
np_assert( array( 'site_url', 'plugin_version', 'wp_version', 'php_version', 'interval', 'webhook_url', 'timezone', 'alert_email' ) === array_keys( $np_call['body'] ), 'connect: exactly the contract fields', $np_call['body'] );
np_assert( 'owner@example.com' === $np_call['body']['alert_email'] && home_url() === $np_call['body']['site_url'] && wp_timezone_string() === $np_call['body']['timezone'], 'connect: the alert address, the site and the time zone', $np_call['body'] );
$np_conn = NanoPost_Email_Check_Connection::get();
np_assert( NanoPost_Email_Check_Connection::is_connected() && $np_token === $np_conn['token'] && 'canary+abcd1234@nanopost.example' === $np_conn['address'] && home_url() === $np_conn['home_url'] && 'owner@example.com' === $np_conn['alert_email'], 'connect: the connection is stored with the plugin\'s token', $np_conn );
global $wpdb;
np_assert( in_array( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", NanoPost_Email_Check_Connection::OPTION ) ), array( 'off', 'no' ), true ), 'connect: the token is not autoloaded' );
np_assert( 'unconfirmed' === NanoPost_Email_Check_Canary::block()['watching'], 'connect: the block of the answer is stored' );
$np_body = NanoPost_Email_Check_App::site_body();
np_assert( md5( (string) wp_json_encode( $np_body ) ) === $np_conn['site_hash'] && null === NanoPost_Email_Check_App::sync_site() && array() === np_app_take(), 'connect: the answer sets the site settings hash, so no PUT /site follows' );
np_assert( ! isset( NanoPost_Email_Check_Webhook::state()['last_status']['wp_mail'] ), 'connect: the webhook state for wp_mail is reset' );
$np = NanoPost_Email_Check_App::connect( 'owner@example.com' );
np_assert( ! $np['ok'] && 'connected' === $np['error'] && array() === np_app_take(), 'connect while connected: no call' );

// A lost answer: the retry sends the same token.
np_reset();
np_app_answer( 0 );
$np = NanoPost_Email_Check_App::connect( 'owner@example.com' );
np_assert( ! $np['ok'] && 'network' === $np['error'] && ! NanoPost_Email_Check_Connection::is_connected(), 'connect, network error: not connected', $np );
$np_first = substr( np_app_take()[0]['headers']['Authorization'], 7 );
NanoPost_Email_Check_App::connect( 'owner@example.com' );
$np_second = substr( np_app_take()[0]['headers']['Authorization'], 7 );
np_assert( $np_first === $np_second && NanoPost_Email_Check_Connection::is_connected(), 'retry after a lost answer: the same token, then connected' );
np_reset();
np_app_answer( 503 );
NanoPost_Email_Check_App::connect( 'owner@example.com' );
$np_first = substr( np_app_take()[0]['headers']['Authorization'], 7 );
np_app_answer( 503 );
NanoPost_Email_Check_App::connect( 'other@example.com' );
$np_second = substr( np_app_take()[0]['headers']['Authorization'], 7 );
np_assert( $np_first !== $np_second, 'another address: a new token (the same token with another address is refused by the app)' );
np_reset();
$np_t1 = NanoPost_Email_Check_Connection::begin( 'owner@example.com' );
$np_t2 = NanoPost_Email_Check_Connection::begin( 'owner@example.com' );
np_assert( '' !== $np_t1 && $np_t1 === $np_t2, 'two tabs at once: one token' );

np_reset();
np_app_answer(
	422,
	array(
		'error'   => 'invalid_request',
		'message' => 'The alert_email field is not valid.',
	)
);
$np = NanoPost_Email_Check_App::connect( 'owner@example.com' );
np_assert( ! $np['ok'] && 'invalid_request' === $np['error'] && 'The alert_email field is not valid.' === $np['message'] && ! NanoPost_Email_Check_Connection::is_connected(), 'connect 422: the message, not connected', $np );
np_app_answer( 201, array( 'check_url' => 'x' ) );
$np = NanoPost_Email_Check_App::connect( 'owner@example.com' );
np_assert( ! $np['ok'] && 'bad_answer' === $np['error'] && ! NanoPost_Email_Check_Connection::is_connected(), 'connect 201 with no canary address: refused, not connected', $np );
np_app_answer(
	429,
	array( 'error' => 'rate_limited' ),
	array( 'retry-after' => '120' )
);
NanoPost_Email_Check_App::connect( 'owner@example.com' );
np_app_take();
$np = NanoPost_Email_Check_App::connect( 'owner@example.com' );
np_assert( ! $np['ok'] && 'waiting' === $np['error'] && array() === np_app_take(), 'connect 429: no call until Retry-After, the pending token stays', $np );
np_assert( 3 <= count( NanoPost_Email_Check_App::failed_calls() ), 'failed calls are logged', NanoPost_Email_Check_App::failed_calls() );
np_app_take();

// ---------------------------------------------------------------------------------------
np_section( 'App: test address and announce' );
np_reset();
np_app_connect();
np_app_take();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'recipient' => 'owner@example.com' ) );
np_assert( 'canary+k7f3q9x2.abc123def456@nanopost.example' === NanoPost_Email_Check_Connection::test_address( 'abc123def456' ), 'test address: .<test_id> after the part after +' );
np_assert( 1 === preg_match( '/^[a-z0-9]{12}$/', NanoPost_Email_Check_Connection::new_test_id() ), 'test ID: 12 characters, a-z and 0-9' );

if ( $np_mailpit_up ) {
	np_mailpit( 'DELETE', 'messages' );
	np_smtp( true );
	$np_results = NanoPost_Email_Check_Plugin::run_checks( 'manual' );
	$np_r       = $np_results[0];
	$np_list    = np_mailpit( 'GET', 'messages' );
	$np_to      = isset( $np_list['messages'][0]['To'] ) ? wp_list_pluck( $np_list['messages'][0]['To'], 'Address' ) : array();
	np_assert( $np_r->passed && 1 === preg_match( '/^[a-z0-9]{12}$/', $np_r->test_id ), 'connected: the test passes and has a test ID', $np_r->to_array() );
	np_assert( array( 'canary+k7f3q9x2.' . $np_r->test_id . '@nanopost.example' ) === $np_to, 'connected: one email, only to the test address (not the recipient)', $np_to );
	np_assert( $np_to[0] === $np_r->recipient, 'connected: the log shows the address the test went to' );

	$np_calls = np_app_take();
	np_assert( 1 === count( $np_calls ) && 'POST' === $np_calls[0]['method'] && '/announce' === $np_calls[0]['path'] && 5 === $np_calls[0]['timeout'], 'announce: one POST /announce, 5 seconds', $np_calls );
	np_assert( 'Bearer tok-123' === $np_calls[0]['headers']['Authorization'], 'announce: the token in the Authorization header' );
	$np_body = $np_calls[0]['body'];
	np_assert( array( 'test_id', 'sent_at', 'trigger', 'wp_mail' ) === array_keys( $np_body ), 'announce: exactly the contract fields', $np_body );
	np_assert( $np_r->test_id === $np_body['test_id'] && 'manual' === $np_body['trigger'] && gmdate( 'Y-m-d\TH:i:s\Z', $np_r->time ) === $np_body['sent_at'], 'announce: test ID, trigger, sent_at in UTC with Z', $np_body );
	np_assert(
		array(
			'ok'    => true,
			'error' => null,
		) === $np_body['wp_mail'],
		'announce: wp_mail ok, error null',
		$np_body['wp_mail']
	);
	np_assert( NanoPost_Email_Check_Result::ANNOUNCE_OK === $np_r->announce, 'announce: recorded as taken' );
	$np_stored = NanoPost_Email_Check_Log::latest();
	np_assert( $np_r->test_id === $np_stored->test_id && 'ok' === $np_stored->announce, 'the log keeps the test ID and the announce outcome' );

	np_smtp( false );
	$np_r    = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
	$np_body = np_app_take()[0]['body'];
	np_assert( ! $np_r->passed && false === $np_body['wp_mail']['ok'] && is_string( $np_body['wp_mail']['error'] ) && '' !== $np_body['wp_mail']['error'], 'announce after a failed send: ok false, with the error text', $np_body );
	np_assert( 'scheduled' === $np_body['trigger'], 'announce: the scheduled trigger' );
	remove_all_actions( 'phpmailer_init' );
}

$np_err = NanoPost_Email_Check_App::announce_error( str_repeat( 'x', 600 ), 'sendmail program not found' );
np_assert( 500 === mb_strlen( $np_err ) && '(sendmail program not found)' === substr( $np_err, -28 ), 'announce error: 500 characters, the cause kept whole at the end', mb_strlen( $np_err ) );
np_assert( 'short' === NanoPost_Email_Check_App::announce_error( 'short', '' ), 'announce error: short text without a cause is kept' );
np_assert( 500 === mb_strlen( NanoPost_Email_Check_App::announce_error( str_repeat( 'y', 900 ), '' ) ), 'announce error: long text without a cause is cut to 500' );

// A failed announce never fails the test.
add_filter( 'pre_wp_mail', '__return_true' );
np_app_answer( 503, array( 'error' => 'server_error' ) );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'manual' )[0];
np_assert( $np_r->passed && NanoPost_Email_Check_Result::ANNOUNCE_FAILED === $np_r->announce && NanoPost_Email_Check_Connection::is_connected(), 'announce 503: the test still passes, connection kept, outcome failed' );
np_app_answer( 0 );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'manual' )[0];
np_assert( $np_r->passed && NanoPost_Email_Check_Result::ANNOUNCE_FAILED === $np_r->announce, 'announce timeout: the test still passes' );
np_app_take();
remove_filter( 'pre_wp_mail', '__return_true' );

// ---------------------------------------------------------------------------------------
np_section( 'App: failure rules' );
np_reset();
np_app_connect();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'recipient' => 'owner@example.com' ) );
add_filter( 'pre_wp_mail', '__return_true' );

// 429: wait for Retry-After, then call again.
np_app_answer( 429, array( 'error' => 'rate_limited' ), array( 'Retry-After' => '120' ) );
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
$np_until = NanoPost_Email_Check_Connection::get()['retry_until'];
np_assert( $np_until >= time() + 115 && $np_until <= time() + 125 && ! NanoPost_Email_Check_Connection::calls_allowed(), '429: calls wait for Retry-After', $np_until );
np_app_take();
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'manual' )[0];
np_assert( array() === np_app_take() && 'failed' === $np_r->announce && 0 === strpos( $np_r->recipient, 'canary+' ), '429: during the wait no call, the test still goes to nanoPost', $np_r->to_array() );
np_app_connect();

// 410: stop calls for this version; the test still goes to nanoPost.
np_app_answer(
	410,
	array(
		'error'   => 'plugin_too_old',
		'message' => 'Update nanoPost Email Check to 1.9.0 or later.',
	)
);
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
np_app_take();
np_assert( 'Update nanoPost Email Check to 1.9.0 or later.' === NanoPost_Email_Check_Connection::too_old_message() && ! NanoPost_Email_Check_Connection::calls_allowed() && NanoPost_Email_Check_Connection::is_connected(), '410: calls stop, the connection stays, the app message is kept' );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'manual' )[0];
np_assert( array() === np_app_take() && 0 === strpos( $np_r->recipient, 'canary+' ), '410: no call, the test still goes to nanoPost' );
$np_c                 = get_option( NanoPost_Email_Check_Connection::OPTION );
$np_c['too_old_from'] = '0.0.1';
update_option( NanoPost_Email_Check_Connection::OPTION, $np_c, false );
np_assert( '' === NanoPost_Email_Check_Connection::too_old_message() && NanoPost_Email_Check_Connection::calls_allowed(), '410: after a plugin update, calls start again' );

// 401: the connection ends, the recipient is used again, "Reconnect" shows.
np_app_answer(
	401,
	array(
		'error'   => 'invalid_token',
		'message' => 'Token revoked.',
	)
);
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
np_app_take();
np_assert( ! NanoPost_Email_Check_Connection::is_connected() && NanoPost_Email_Check_Connection::ENDED_REVOKED === NanoPost_Email_Check_Connection::ended_reason(), '401: disconnected, reason revoked' );
np_assert( false === get_option( NanoPost_Email_Check_Connection::OPTION ), '401: the token is deleted' );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'manual' )[0];
np_assert( 'owner@example.com' === $np_r->recipient && '' === $np_r->test_id && array() === np_app_take(), '401: the next test goes to the recipient, no call', $np_r->to_array() );

// 400, 404, 422 and 5xx keep the connection.
foreach ( array( 400, 404, 422, 500, 502, 418 ) as $np_code ) {
	np_app_connect();
	np_app_answer( $np_code, array( 'error' => 'x' ) );
	NanoPost_Email_Check_Plugin::run_checks( 'manual' );
	np_assert( NanoPost_Email_Check_Connection::calls_allowed(), $np_code . ': the connection stays and calls go on' );
}
np_app_take();

// A changed home URL (a clone or a moved site) ends the connection before any call.
np_app_connect( array( 'home_url' => 'https://live.example' ) );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'manual' )[0];
np_assert( ! NanoPost_Email_Check_Connection::is_connected() && NanoPost_Email_Check_Connection::ENDED_SITE_CHANGED === NanoPost_Email_Check_Connection::ended_reason(), 'clone: disconnected, reason site changed' );
np_assert( array() === np_app_take() && 'owner@example.com' === $np_r->recipient, 'clone: no call, the test goes to the recipient' );
remove_filter( 'pre_wp_mail', '__return_true' );

// ---------------------------------------------------------------------------------------
np_section( 'App: webhook while connected' );
np_reset();
np_app_connect();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/x' ) );
$np_hooks  = static function () {
	return count(
		array_filter(
			$GLOBALS['np_requests'],
			static function ( $r ) {
				return 0 === strpos( $r['url'], 'https://hooks.slack.com/' );
			}
		)
	);
};
$np_status = static function () {
	$s = NanoPost_Email_Check_Webhook::state()['last_status'];
	return isset( $s['wp_mail'] ) ? $s['wp_mail'] : '';
};
$np_run    = static function ( $pass, $answer = null ) {
	if ( null !== $answer ) {
		np_app_answer( $answer );
	}
	add_filter( 'pre_wp_mail', $pass ? '__return_true' : '__return_false' );
	NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
	remove_filter( 'pre_wp_mail', $pass ? '__return_true' : '__return_false' );
	np_app_take();
};

$np_n = $np_hooks();
$np_run( false );
np_assert( $np_hooks() === $np_n && '' === $np_status(), 'announce 2xx, failed: no plugin alert (nanoPost alerts), no state' );
$np_run( true );
np_assert( $np_hooks() === $np_n, 'announce 2xx, passed after it: no plugin recovery (nanoPost sends it)' );

$np_run( false, 503 );
np_assert( $np_hooks() === $np_n + 1 && 'fail' === $np_status(), 'announce failed, failed: the plugin sends a fallback alert' );
$np_run( true );
np_assert( $np_hooks() === $np_n + 2 && 'pass' === $np_status(), 'after its own fallback alert, the plugin sends the recovery' );

$np_run( false, 0 );
np_assert( $np_hooks() === $np_n + 3 && 'fail' === $np_status(), 'announce timeout, failed: fallback alert' );
$np_run( false );
np_assert( $np_hooks() === $np_n + 3 && '' === $np_status(), 'then announce 2xx, failed: no alert, the plugin forgets its state (nanoPost owns it)' );
$np_run( true );
np_assert( $np_hooks() === $np_n + 3, 'then announce 2xx, passed: no second recovery from the plugin' );

// 401: the connection ended (disconnected in nanoPost); no fallback alert for the refused test.
$np_run( false, 503 );
np_assert( $np_hooks() === $np_n + 4 && 'fail' === $np_status(), 'fallback alert again (state fail)' );
np_app_answer(
	401,
	array(
		'error'   => 'invalid_token',
		'message' => 'x',
	)
);
add_filter( 'pre_wp_mail', '__return_false' );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
remove_filter( 'pre_wp_mail', '__return_false' );
np_app_take();
np_assert( NanoPost_Email_Check_Result::ANNOUNCE_ENDED === $np_r->announce && $np_hooks() === $np_n + 4 && '' === $np_status(), 'announce 401, failed: no fallback alert, state cleared (the connection ended)', $np_r->to_array() );
np_assert( 'ended' === NanoPost_Email_Check_Log::latest()->announce, 'the log keeps the "ended" outcome' );
np_app_connect();

// Other checks keep the plugin webhook.
add_filter(
	'nanopost_email_check_checks',
	$np_other = static function ( $checks ) {
		$checks[] = new class() implements NanoPost_Email_Check_Check {
			public function id() {
				return 'other';
			}
			public function label() {
				return 'Other';
			}
			public function run( $trigger ) {
				return new NanoPost_Email_Check_Result( 'other', $trigger, false, 'broken' );
			}
		};
		return $checks;
	}
);
$np_run( true );
remove_filter( 'nanopost_email_check_checks', $np_other );
np_assert( $np_hooks() === $np_n + 5, 'another check that fails still alerts through the plugin webhook' );

// Disconnect resets the wp_mail state.
$np_run( false, 503 );
np_assert( 'fail' === $np_status(), 'fallback alert sets the state' );
NanoPost_Email_Check_App::disconnect();
np_assert( '' === $np_status(), 'disconnect resets the webhook state for wp_mail' );
np_app_take();

// ---------------------------------------------------------------------------------------
np_section( 'App: disconnect' );
np_reset();
np_app_connect();
$np       = NanoPost_Email_Check_App::disconnect();
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && 'DELETE' === $np_calls[0]['method'] && '/connection' === $np_calls[0]['path'] && 'Bearer tok-123' === $np_calls[0]['headers']['Authorization'], 'disconnect: DELETE /connection with the token', $np_calls );
np_assert( $np['ok'] && ! NanoPost_Email_Check_Connection::is_connected() && '' === NanoPost_Email_Check_Connection::ended_reason(), 'disconnect: deleted here, no "Reconnect" notice' );
np_app_connect();
np_app_answer( 0 );
NanoPost_Email_Check_App::disconnect();
np_assert( ! NanoPost_Email_Check_Connection::is_connected(), 'disconnect: deleted here also when the call fails' );
np_app_connect(
	array(
		'too_old'      => 'old',
		'too_old_from' => NANOPOST_EMAIL_CHECK_VERSION,
	)
);
np_app_take();
NanoPost_Email_Check_App::disconnect();
np_assert( 1 === count( np_app_take() ), 'disconnect: the call goes out also while other calls are stopped' );

// ---------------------------------------------------------------------------------------
np_section( 'App: site settings' );
np_reset();
np_app_connect();
update_option(
	NanoPost_Email_Check_Settings::OPTION,
	array(
		'interval'    => 'weekly',
		'webhook_url' => '',
	)
);
np_app_take();
$np       = NanoPost_Email_Check_App::sync_site();
$np_calls = np_app_take();
np_assert( $np['ok'] && 1 === count( $np_calls ) && 'PUT' === $np_calls[0]['method'] && '/site' === $np_calls[0]['path'] && 10 === $np_calls[0]['timeout'], 'site settings: PUT /site, 10 seconds', $np_calls );
np_assert( array( 'site_url', 'plugin_version', 'wp_version', 'php_version', 'interval', 'webhook_url', 'timezone' ) === array_keys( $np_calls[0]['body'] ), 'site settings: exactly the contract fields', $np_calls[0]['body'] );
np_assert( 'weekly' === $np_calls[0]['body']['interval'] && null === $np_calls[0]['body']['webhook_url'] && PHP_VERSION === $np_calls[0]['body']['php_version'], 'site settings: interval, no webhook as null, PHP version', $np_calls[0]['body'] );
np_assert( null === NanoPost_Email_Check_App::sync_site() && array() === np_app_take(), 'site settings: unchanged, no second call' );
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'interval' => 'daily' ) );
np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_App::SYNC_HOOK ), 'saving settings while connected books the call in WP-Cron' );
np_assert( array() === np_app_take(), 'saving settings makes no call in the request' );
np_app_answer( 500, array( 'error' => 'server_error' ) );
NanoPost_Email_Check_App::sync_site();
NanoPost_Email_Check_App::sync_site();
np_assert( 2 === count( np_app_take() ), 'site settings: after a failed call, the next run tries again' );
$np_code_hook = static function ( $settings ) {
	$settings['webhook_url'] = 'https://hooks.slack.com/services/T/B/code';
	return $settings;
};
add_filter( 'nanopost_email_check_settings', $np_code_hook );
NanoPost_Email_Check_App::sync_site();
$np_calls = np_app_take();
remove_filter( 'nanopost_email_check_settings', $np_code_hook );
np_assert( 1 === count( $np_calls ) && 'https://hooks.slack.com/services/T/B/code' === $np_calls[0]['body']['webhook_url'], 'site settings: a value set in code reaches the app', $np_calls );

NanoPost_Email_Check_Connection::end();
wp_clear_scheduled_hook( NanoPost_Email_Check_App::SYNC_HOOK );
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'interval' => 'weekly' ) );
np_assert( false === wp_next_scheduled( NanoPost_Email_Check_App::SYNC_HOOK ), 'not connected: saving books no call' );

// ---------------------------------------------------------------------------------------
np_section( 'App: screen and handlers' );
np_reset();
wp_set_current_user( $np_admin_id );
$np_x = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-connection"]//input[@name="action" and @value="nanopost_email_check_turn_on"]' )->length, 'off: a "Turn on Email Canary" form' );
np_assert( wp_get_current_user()->user_email === $np_x->query( '//input[@id="nanopost_email_check_alert_email"]' )->item( 0 )->getAttribute( 'value' ), 'off: the alert address is the logged-in user\'s email' );
$np_text = $np_x->query( '//div[@id="nanopost-email-check-connection"]' )->item( 0 )->textContent;
foreach ( array( 'the site address', 'the alert address above', 'time zone', 'test interval', 'webhook URL', 'plugin, WordPress and PHP', 'test ID, the time, how the test started', 'whether WordPress accepted the email', 'error text', '30 days' ) as $np_part ) {
	np_assert( false !== strpos( $np_text, $np_part ), 'consent text names: ' . $np_part );
}
np_assert( 1 === $np_x->query( '//a[@href="' . NanoPost_Email_Check_Help::TERMS_URL . '"]' )->length && 1 === $np_x->query( '//a[@href="' . NanoPost_Email_Check_Help::PRIVACY_URL . '"]' )->length, 'consent: terms and privacy links' );
np_assert( 1 === $np_x->query( '//a[contains(@href,"/email-check-plugin/canary/") and contains(@href,"#what-is-sent-to-nanopost")]' )->length, 'consent: the "What is sent to nanoPost" link' );
$np_public = static function () {
	return 'http://localhost:8888';
};
add_filter( 'home_url', $np_public );
np_assert( ! NanoPost_Email_Check_Admin::site_is_public() && false !== strpos( np_render_screen(), 'This site address is not public.' ), 'a local site address: a warning' );
remove_filter( 'home_url', $np_public );
foreach ( array( 'http://shop.test', 'http://192.168.1.5', 'http://[::1]', 'http://wp.local' ) as $np_u ) {
	$np_public = static function () use ( $np_u ) {
		return $np_u;
	};
	add_filter( 'home_url', $np_public );
	np_assert( ! NanoPost_Email_Check_Admin::site_is_public(), 'not public: ' . $np_u );
	remove_filter( 'home_url', $np_public );
}

$np_post   = static function ( $handler, $action, $post = array() ) {
	$_POST = $post;
	np_nonce( wp_create_nonce( $action ) );
	$out = np_outcome( array( 'NanoPost_Email_Check_Admin', $handler ) );
	np_nonce( null );
	$_POST = array();
	return $out;
};
$np_notice = static function () use ( $np_admin_id ) {
	$n = get_transient( 'nanopost_email_check_sent_' . $np_admin_id );
	delete_transient( 'nanopost_email_check_sent_' . $np_admin_id );
	return is_array( $n ) ? $n['outcome'] : $n;
};

// Turn on: an editor and a missing nonce are refused; a bad address makes no call.
wp_set_current_user( $np_editor_id );
np_assert( 'die:403' === $np_post( 'handle_turn_on', NanoPost_Email_Check_Admin::TURN_ON_ACTION, array( 'alert_email' => 'a@example.com' ) ), 'turn on: an editor is refused' );
wp_set_current_user( $np_admin_id );
$_POST = array( 'alert_email' => 'a@example.com' );
np_assert( 0 === strpos( np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_turn_on' ) ), 'die:' ), 'turn on: no nonce is refused' );
$_POST = array();
$np_post( 'handle_turn_on', NanoPost_Email_Check_Admin::TURN_ON_ACTION, array( 'alert_email' => 'not an address' ) );
np_assert( array() === np_app_take() && 'canary-email' === $np_notice(), 'turn on: a bad address makes no call' );

// Turn on: POST /connect, then a test at once, with the proof.
add_filter( 'pre_wp_mail', '__return_true' );
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );
$np_out   = $np_post( 'handle_turn_on', NanoPost_Email_Check_Admin::TURN_ON_ACTION, array( 'alert_email' => 'owner@example.com' ) );
$np_calls = np_app_take();
np_assert( 0 === strpos( $np_out, 'redirect:' . NanoPost_Email_Check_Admin::page_url() ) && 'canary-on' === get_transient( 'nanopost_email_check_sent_' . $np_admin_id )['outcome'], 'turn on: back to the screen, "turned on"', $np_out );
np_assert( 2 === count( $np_calls ) && '/connect' === $np_calls[0]['path'] && '/announce' === $np_calls[1]['path'], 'turn on: POST /connect, then the first test is announced', wp_list_pluck( $np_calls, 'path' ) );
np_assert( false !== strpos( NanoPost_Email_Check_Log::latest()->recipient, 'canary+abcd1234.' ), 'turn on: the first test goes to the canary address' );
$np_x = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-proof"]' )->length, 'turn on: the proof box shows' );
$np_area = $np_x->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'Alerts are OFF until you confirm.' ) && false !== strpos( $np_area, 'We sent an email to owner@example.com.' ), 'not confirmed: alerts are OFF, with the address', $np_area );
np_assert( 1 === $np_x->query( '//form[contains(@class,"nanopost-email-check-resend")]' )->length && 1 === $np_x->query( '//details[contains(@class,"nanopost-email-check-change")]' )->length, 'not confirmed: Resend and Change' );
np_assert( 1 === $np_x->query( '//input[@name="action" and @value="nanopost_email_check_turn_off"]' )->length, 'not confirmed: Turn off' );
np_assert( 1 === $np_x->query( '//p[contains(@class,"nanopost-email-check-connected-note")]' )->length, 'on: the recipient field says it is not used' );
remove_filter( 'pre_wp_mail', '__return_true' );
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );

// Resend and Change.
np_app_answer( 200, array( 'check' => np_app_block( 'unconfirmed', 'owner@example.com', 'pending' ) ) );
$np_post( 'handle_alert_email', NanoPost_Email_Check_Admin::ALERT_EMAIL_ACTION, array( 'alert_email' => 'owner@example.com' ) );
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && 'PUT' === $np_calls[0]['method'] && '/alert-email' === $np_calls[0]['path'] && array( 'email' => 'owner@example.com' ) === $np_calls[0]['body'] && 5 === $np_calls[0]['timeout'], 'resend: PUT /alert-email with the same address, 5 seconds', $np_calls );
np_assert( 'email-resent' === $np_notice(), 'resend: the notice' );
np_app_answer( 200, array( 'check' => np_app_block( 'unconfirmed', 'new@example.com', 'pending' ) ) );
$np_post( 'handle_alert_email', NanoPost_Email_Check_Admin::ALERT_EMAIL_ACTION, array( 'alert_email' => 'new@example.com' ) );
np_app_take();
np_assert( 'email-changed' === $np_notice() && 'new@example.com' === NanoPost_Email_Check_Connection::alert_email(), 'change: the notice, the address is kept' );
$np_lim                 = np_app_block( 'unconfirmed', 'new@example.com', 'pending' );
$np_lim['confirm_mail'] = array(
	'state'           => 'limited',
	'next_allowed_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
);
np_app_answer( 200, array( 'check' => $np_lim ) );
$np_post( 'handle_alert_email', NanoPost_Email_Check_Admin::ALERT_EMAIL_ACTION, array( 'alert_email' => 'new@example.com' ) );
np_app_take();
np_assert( 'email-limited' === $np_notice(), 'a limit stops the confirm email: "try again tomorrow"' );
np_assert( false !== strpos( np_render_screen(), 'Try again tomorrow. Proof of arrival still works.' ), 'limited: the screen says it, with no Resend' );
np_app_answer( 0 );
$np_post( 'handle_alert_email', NanoPost_Email_Check_Admin::ALERT_EMAIL_ACTION, array( 'alert_email' => 'x@example.com' ) );
np_app_take();
np_assert( 'email-failed' === $np_notice() && 'new@example.com' === NanoPost_Email_Check_Connection::alert_email(), 'a failed change keeps the address' );

// Turn off before the confirm: nothing is kept, so DELETE /connection.
$np_post( 'handle_turn_off', NanoPost_Email_Check_Admin::TURN_OFF_ACTION );
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && 'DELETE' === $np_calls[0]['method'] && ! NanoPost_Email_Check_Connection::is_connected() && 'canary-off' === $np_notice(), 'turn off while not confirmed: DELETE /connection, off', $np_calls );

// On: Turn off is a stop that keeps the token.
np_app_connect();
update_option( NanoPost_Email_Check_Canary::OPTION, array( 'block' => NanoPost_Email_Check_Canary::normalize( np_app_block( 'on', 'owner@example.com', 'confirmed' ) ) ) );
$np_area = np_xpath( np_render_screen() )->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'Email Canary is on' ) && false !== strpos( $np_area, 'owner@example.com' ) && false !== strpos( $np_area, 'Webhook' ), 'on: the state, the alert address in full, the webhook', $np_area );
np_assert( false === strpos( np_render_screen(), 'canary+k7f3q9x2' ), 'the screen never shows the canary address' );
np_app_answer( 0 );
$np_post( 'handle_turn_off', NanoPost_Email_Check_Admin::TURN_OFF_ACTION );
np_app_take();
np_assert( 'canary-off-failed' === $np_notice() && NanoPost_Email_Check_Connection::canary_active(), 'turn off, nanoPost not reached: still on, says so' );
np_app_answer( 200, array( 'check' => np_app_block( 'stopped', 'owner@example.com', 'confirmed', 'owner' ) ) );
$np_post( 'handle_turn_off', NanoPost_Email_Check_Admin::TURN_OFF_ACTION );
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && 'POST' === $np_calls[0]['method'] && '/stop' === $np_calls[0]['path'] && array( 'trigger' => 'turn_off' ) === $np_calls[0]['body'], 'turn off: POST /stop with trigger turn_off', $np_calls );
np_assert( 'canary-off' === $np_notice() && NanoPost_Email_Check_Connection::is_connected() && ! NanoPost_Email_Check_Connection::canary_active(), 'turn off: the token is kept, Email Canary is off' );
add_filter( 'pre_wp_mail', '__return_true' );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
remove_filter( 'pre_wp_mail', '__return_true' );
np_assert( array() === np_app_take() && '' === $np_r->test_id && false === strpos( $np_r->recipient, 'canary' ), 'off: the test goes to the recipient setting, no call' );
np_assert( null === NanoPost_Email_Check_App::sync_site() && array() === np_app_take(), 'off: no site settings call' );
$np_x = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-canary"]//input[@name="action" and @value="nanopost_email_check_turn_on"]' )->length && 'owner@example.com' === $np_x->query( '//input[@id="nanopost_email_check_alert_email"]' )->item( 0 )->getAttribute( 'value' ), 'off: "Turn on" with the alert address' );

// Turn on again with the same address: POST /start, no new confirm.
np_app_answer( 200, array( 'check' => np_app_block( 'on', 'owner@example.com', 'confirmed' ) ) );
add_filter( 'pre_wp_mail', '__return_true' );
$np_post( 'handle_turn_on', NanoPost_Email_Check_Admin::TURN_ON_ACTION, array( 'alert_email' => 'owner@example.com' ) );
remove_filter( 'pre_wp_mail', '__return_true' );
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );
$np_calls = np_app_take();
np_assert( '/start' === $np_calls[0]['path'] && array( 'trigger' => 'turn_on' ) === $np_calls[0]['body'] && '/announce' === $np_calls[1]['path'], 'turn on, same address: POST /start (turn_on), then a test', wp_list_pluck( $np_calls, 'path' ) );
np_assert( 'canary-on-again' === $np_notice() && NanoPost_Email_Check_Connection::canary_active(), 'turn on again: on' );

// Stopped from a mail link: the next announce answers 409.
$np_409 = array(
	'error'   => 'canary_stopped',
	'message' => 'Email Canary is off.',
	'check'   => np_app_block( 'stopped', 'owner@example.com', 'confirmed', 'mail_link' ),
);
np_app_answer( 409, $np_409 );
add_filter( 'pre_wp_mail', '__return_true' );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
remove_filter( 'pre_wp_mail', '__return_true' );
np_app_take();
np_assert( NanoPost_Email_Check_Result::ANNOUNCE_UNWATCHED === $np_r->announce && ! NanoPost_Email_Check_Connection::canary_active(), 'announce 409: "unwatched", and Email Canary is off here too' );
np_assert( false !== strpos( np_render_screen(), 'Email Canary is off (asked for by email).' ), 'off by mail link: the screen says why' );

// Turn on with another address: DELETE the old one, then a new canary.
add_filter( 'pre_wp_mail', '__return_true' );
$np_post( 'handle_turn_on', NanoPost_Email_Check_Admin::TURN_ON_ACTION, array( 'alert_email' => 'right@example.com' ) );
remove_filter( 'pre_wp_mail', '__return_true' );
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );
$np_calls = np_app_take();
np_assert( 'DELETE' === $np_calls[0]['method'] && 'Bearer tok-123' === $np_calls[0]['headers']['Authorization'] && '/connect' === $np_calls[1]['path'] && 'Bearer tok-123' !== $np_calls[1]['headers']['Authorization'], 'turn on, another address: DELETE the old canary, then POST /connect with a new token', wp_list_pluck( $np_calls, 'path' ) );

// Not confirmed in 24 hours: "Turn on" is POST /connect again, with the same token.
$np_tok = NanoPost_Email_Check_Connection::get()['token'];
update_option( NanoPost_Email_Check_Canary::OPTION, array( 'block' => NanoPost_Email_Check_Canary::normalize( np_app_block( 'stopped', 'right@example.com', 'pending', 'not_confirmed' ) ) ) );
np_assert( false !== strpos( np_render_screen(), 'right@example.com was not confirmed.' ), 'not confirmed in time: the screen says so' );
add_filter( 'pre_wp_mail', '__return_true' );
$np_post( 'handle_turn_on', NanoPost_Email_Check_Admin::TURN_ON_ACTION, array( 'alert_email' => 'right@example.com' ) );
remove_filter( 'pre_wp_mail', '__return_true' );
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );
$np_calls = np_app_take();
np_assert( '/connect' === $np_calls[0]['path'] && 'Bearer ' . $np_tok === $np_calls[0]['headers']['Authorization'], 'turn on after not_confirmed: POST /connect with the same token', $np_calls[0] );

// Over the limit, then stopped: POST /start when the account has room.
update_option( NanoPost_Email_Check_Canary::OPTION, array( 'block' => NanoPost_Email_Check_Canary::normalize( np_app_block( 'stopped', 'right@example.com', 'confirmed', 'over_limit' ) ) ) );
np_app_answer( 409, array_merge( $np_409, array( 'check' => np_app_block( 'stopped', 'right@example.com', 'confirmed', 'over_limit' ) ) ) );
$np_post( 'handle_turn_on', NanoPost_Email_Check_Admin::TURN_ON_ACTION, array( 'alert_email' => 'right@example.com' ) );
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && '/start' === $np_calls[0]['path'] && 'canary-still-off' === $np_notice(), 'over the limit, no room: POST /start answers 409, still off, says so', $np_calls );
wp_set_current_user( $np_admin_id );

// Deactivate and activate.
np_app_connect();
update_option( NanoPost_Email_Check_Canary::OPTION, array( 'block' => NanoPost_Email_Check_Canary::normalize( np_app_block( 'on', 'owner@example.com', 'confirmed' ) ) ) );
np_app_take();
np_app_answer( 200, array( 'check' => np_app_block( 'stopped', 'owner@example.com', 'confirmed', 'deactivated' ) ) );
NanoPost_Email_Check_Scheduler::deactivate( false );
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && '/stop' === $np_calls[0]['path'] && array( 'trigger' => 'deactivate' ) === $np_calls[0]['body'], 'deactivate: POST /stop with trigger deactivate', $np_calls );
np_assert( NanoPost_Email_Check_Connection::is_connected(), 'deactivate: the token is kept' );
NanoPost_Email_Check_Scheduler::activate( false );
np_assert( array() === np_app_take() && false !== wp_next_scheduled( NanoPost_Email_Check_App::START_HOOK ), 'activate: no call in the request, "start" is booked in WP-Cron' );
np_app_answer( 200, array( 'check' => np_app_block( 'on', 'owner@example.com', 'confirmed' ) ) );
do_action( NanoPost_Email_Check_App::START_HOOK );
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && '/start' === $np_calls[0]['path'] && array( 'trigger' => 'activate' ) === $np_calls[0]['body'] && NanoPost_Email_Check_Connection::canary_active(), 'after activation: POST /start with trigger activate, on again', $np_calls );
wp_clear_scheduled_hook( NanoPost_Email_Check_App::START_HOOK );
update_option( NanoPost_Email_Check_Canary::OPTION, array( 'block' => NanoPost_Email_Check_Canary::normalize( np_app_block( 'unconfirmed', 'owner@example.com', 'pending' ) ) ) );
NanoPost_Email_Check_Scheduler::deactivate( false );
np_assert( array() === np_app_take(), 'deactivate while not confirmed: no call (the 24 hours run on)' );
NanoPost_Email_Check_Scheduler::ensure_scheduled();
// The old browser connect return just shows the screen.
np_assert( 0 === strpos( np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect_return' ) ), 'redirect:' . NanoPost_Email_Check_Admin::page_url() ) && array() === np_app_take(), 'the 1.7 return URL: back to the screen, no call' );

np_reset();
np_app_connect();
// Send test now while connected: proof of arrival box with the test ID.
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );
add_filter( 'pre_wp_mail', '__return_true' );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::SEND_ACTION ) );
np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_send' ) );
remove_filter( 'pre_wp_mail', '__return_true' );
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );
np_app_take();
$np_test = NanoPost_Email_Check_Log::latest()->test_id;
$np_x    = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-proof" and @data-test-id="' . $np_test . '"]' )->length, 'send test now: the proof box with the test ID' );
np_assert( wp_script_is( 'nanopost-email-check-proof', 'enqueued' ), 'send test now: the proof script is enqueued' );
np_assert( 0 === np_xpath( np_render_screen() )->query( '//div[@id="nanopost-email-check-proof"]' )->length, 'the proof box shows once' );

// The proof answer comes from the app through admin-ajax; the token stays on the server.
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter(
	'wp_die_ajax_handler',
	$np_ajax_die = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new NP_Test_Stop( 'die:' . ( is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0 ) );
		};
	},
	999
);
$np_ajax = static function ( $test_id, $nonce = null ) {
	$_POST    = array( 'test_id' => $test_id );
	$_REQUEST = array( '_ajax_nonce' => null === $nonce ? wp_create_nonce( NanoPost_Email_Check_Admin::RESULT_ACTION ) : $nonce );
	ob_start();
	np_outcome( array( 'NanoPost_Email_Check_Admin', 'ajax_test_result' ) );
	return json_decode( (string) ob_get_clean(), true );
};
np_app_answer(
	200,
	array(
		'test_id'    => $np_test,
		'status'     => 'arrived',
		'arrived_at' => '2026-11-24T11:40:21Z',
		'spf'        => 'pass',
		'dkim'       => 'pass',
		'dmarc'      => 'fail',
		'check_url'  => 'https://app.example/checks/01JE',
		'secret'     => 'x',
	)
);
$np_json  = $np_ajax( $np_test );
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && 'GET' === $np_calls[0]['method'] && '/tests/' . $np_test === $np_calls[0]['path'] && 5 === $np_calls[0]['timeout'], 'proof: GET /tests/<id>, 5 seconds', $np_calls );
np_assert( ! empty( $np_json['success'] ) && 'arrived' === $np_json['data']['status'] && 'fail' === $np_json['data']['dmarc'], 'proof: the result goes to the browser', $np_json );
np_assert( NanoPost_Email_Check_Admin::format_date( strtotime( '2026-11-24T11:40:21Z' ) ) === $np_json['data']['arrived'], 'proof: the arrival time in the site format', $np_json['data'] );
np_assert( ! isset( $np_json['data']['secret'] ) && false === strpos( wp_json_encode( $np_json ), 'tok-' ), 'proof: only the known fields, never the token', $np_json );
np_app_answer( 404, array( 'error' => 'unknown_test' ) );
$np_json = $np_ajax( $np_test );
np_assert( empty( $np_json['success'] ) && 'unknown_test' === $np_json['data']['error'], 'proof: unknown test is passed on (the browser keeps waiting)', $np_json );
np_app_take();
$np_json = $np_ajax( 'NOT-AN-ID' );
np_assert( array() === np_app_take() && empty( $np_json['success'] ), 'proof: a bad test ID makes no call' );
$np_ajax( $np_test, 'bad-nonce' );
np_assert( array() === np_app_take(), 'proof: a bad nonce makes no call' );
wp_set_current_user( $np_editor_id );
$np_ajax( $np_test );
np_assert( array() === np_app_take(), 'proof: an editor makes no call' );
wp_set_current_user( $np_admin_id );
remove_filter( 'wp_doing_ajax', '__return_true' );
remove_filter( 'wp_die_ajax_handler', $np_ajax_die, 999 );
$_POST    = array();
$_REQUEST = array();

// 410 and "Reconnect" on the screen.
np_app_connect(
	array(
		'too_old'      => 'Update nanoPost Email Check to 1.9.0 or later.',
		'too_old_from' => NANOPOST_EMAIL_CHECK_VERSION,
	)
);
np_assert( false !== strpos( np_render_screen(), 'Update nanoPost Email Check to 1.9.0 or later.' ), 'screen: the app message for a too-old plugin' );
NanoPost_Email_Check_Connection::end( NanoPost_Email_Check_Connection::ENDED_REVOKED );
$np_x = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//div[contains(@class,"nanopost-email-check-reconnect")]' )->length, 'screen: "Reconnect" after the app ended the connection' );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::DISMISS_ENDED ) );
np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_dismiss_ended' ) );
np_assert( '' === NanoPost_Email_Check_Connection::ended_reason(), 'screen: "Reconnect" can be dismissed' );

wp_set_current_user( 0 );
$_GET = array();
np_nonce( null );

// ---------------------------------------------------------------------------------------
np_section( 'App: WP-CLI status' );
np_reset();
NanoPost_Email_Check_Scheduler::ensure_scheduled();
$np_data = NanoPost_Email_Check_CLI::status_data();
np_assert( false === $np_data['nanopost']['connected'] && 'not_connected' === $np_data['nanopost']['calls'], 'status: not connected', $np_data['nanopost'] );
np_app_connect();
$np_data = NanoPost_Email_Check_CLI::status_data();
np_assert( true === $np_data['nanopost']['connected'] && 'on' === $np_data['nanopost']['calls'] && 'https://app.example/checks/01JD' === $np_data['nanopost']['check_url'], 'status: connected, calls on, check URL', $np_data['nanopost'] );
np_assert( false === strpos( wp_json_encode( $np_data ), 'tok-123' ), 'status: never shows the token' );
NanoPost_Email_Check_Connection::wait( 300 );
np_assert( 'waiting' === NanoPost_Email_Check_CLI::status_data()['nanopost']['calls'], 'status: waiting after a 429' );
$np_rows = wp_list_pluck( NanoPost_Email_Check_CLI::status_rows( NanoPost_Email_Check_CLI::status_data() ), 'value', 'field' );
np_assert( isset( $np_rows['Email Canary'] ) && false !== strpos( $np_rows['Email Canary'], 'Calls wait until' ), 'status table: the Email Canary row', $np_rows );

np_app_connect();
add_filter( 'pre_wp_mail', '__return_true' );
$np_out = np_cli( 'run' );
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, 'accepted the test email for Email Canary (test ' ) && false !== strpos( $np_out->stdout, 'https://app.example/checks/01JD' ), 'wp email-check run while connected: names nanoPost, the test ID and the check URL', $np_out );
np_assert( false !== strpos( $np_out->stdout, 'Sending a test email to Email Canary ...' ) && false === strpos( $np_out->stdout, 'owner@' ), 'wp email-check run while connected: does not name the recipient setting', $np_out );
np_app_answer( 503 );
$np_out = np_cli( 'run' );
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stderr, 'could not tell Email Canary' ), 'wp email-check run: a warning when the announce call failed', $np_out );
remove_filter( 'pre_wp_mail', '__return_true' );
np_app_take();
np_reset();

// ---------------------------------------------------------------------------------------
