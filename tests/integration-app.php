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
 * Store a connection as the exchange would.
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
		} elseif ( '/connect/exchange' === $path ) {
			list( $code, $body, $headers ) = array(
				200,
				array(
					'token'     => 'tok-new',
					'check_id'  => '01JE',
					'address'   => 'canary+abcd1234@nanopost.example',
					'interval'  => 'daily',
					'check_url' => 'https://app.example/checks/01JE',
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
$np_app_page = preg_replace( '#/api/v1$#', '', $np_app_base ) . '/connect?';
np_assert( NanoPost_Email_Check_App::base_url() === $np_app_base, 'base URL: the production API, or the constant', NanoPost_Email_Check_App::base_url() );
np_assert( ! NanoPost_Email_Check_Connection::is_connected() && ! NanoPost_Email_Check_Connection::calls_allowed(), 'no connection, no calls' );
$np = NanoPost_Email_Check_App::announce( 'abcdefabcdef', time(), 'manual', true, '' );
np_assert( ! $np['ok'] && 'not_connected' === $np['error'] && array() === np_app_take(), 'announce without a connection makes no call', $np );
np_assert( null === NanoPost_Email_Check_App::sync_site() && array() === np_app_take(), 'site settings without a connection make no call' );
add_filter( 'pre_wp_mail', '__return_true' );
$np_results = NanoPost_Email_Check_Plugin::run_checks( 'manual' );
np_assert( '' === $np_results[0]->test_id && '' === $np_results[0]->announce && array() === np_app_take(), 'a test without a connection: no test ID, no call' );
remove_filter( 'pre_wp_mail', '__return_true' );

$np_url = NanoPost_Email_Check_App::connect_url( 'https://example.org/wp-admin/admin-post.php?action=x', 'st&te' );
np_assert( 0 === strpos( $np_url, $np_app_page ), 'connect page: the API base without /api/v1', $np_url );
parse_str( (string) wp_parse_url( $np_url, PHP_URL_QUERY ), $np_q );
np_assert( home_url() === $np_q['site'] && 'https://example.org/wp-admin/admin-post.php?action=x' === $np_q['return'] && 'st&te' === $np_q['state'], 'connect page: site, return and state survive the URL', $np_q );

// ---------------------------------------------------------------------------------------
np_section( 'App: exchange the code' );
np_reset();
np_app_take();
update_option( NanoPost_Email_Check_Webhook::STATE_OPTION, array( 'last_status' => array( 'wp_mail' => 'fail' ) ) );
$np       = NanoPost_Email_Check_App::exchange( 'code-1' );
$np_calls = np_app_take();
np_assert( $np['ok'] && 1 === count( $np_calls ), 'exchange: one call, ok', $np );
$np_call = $np_calls[0];
np_assert( 'POST' === $np_call['method'] && '/connect/exchange' === $np_call['path'] && 10 === $np_call['timeout'], 'exchange: POST /connect/exchange, 10 seconds', $np_call );
np_assert( ! isset( $np_call['headers']['Authorization'] ), 'exchange: no Authorization header' );
np_assert( NANOPOST_EMAIL_CHECK_VERSION === $np_call['headers']['X-Plugin-Version'] && 'application/json' === $np_call['headers']['Accept'], 'exchange: version and Accept headers', $np_call['headers'] );
np_assert( 'nanoPost-Email-Check/' . NANOPOST_EMAIL_CHECK_VERSION === $np_call['ua'], 'exchange: User-Agent names only the plugin and its version', $np_call['ua'] );
np_assert(
	array(
		'code'     => 'code-1',
		'site_url' => home_url(),
	) === $np_call['body'],
	'exchange: body has the code and the site URL only',
	$np_call['body']
);
$np_conn = NanoPost_Email_Check_Connection::get();
np_assert( NanoPost_Email_Check_Connection::is_connected() && 'tok-new' === $np_conn['token'] && 'canary+abcd1234@nanopost.example' === $np_conn['address'] && home_url() === $np_conn['home_url'], 'exchange: the connection is stored', $np_conn );
global $wpdb;
np_assert( 'off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", NanoPost_Email_Check_Connection::OPTION ) ) || 'no' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", NanoPost_Email_Check_Connection::OPTION ) ), 'exchange: the token is not autoloaded' );
np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_App::SYNC_HOOK ), 'exchange: the site settings call is booked in WP-Cron' );
np_assert( ! isset( NanoPost_Email_Check_Webhook::state()['last_status']['wp_mail'] ), 'exchange: the webhook state for wp_mail is reset' );

np_reset();
np_app_answer(
	400,
	array(
		'error'   => 'invalid_code',
		'message' => 'The code is wrong, used or expired.',
	)
);
$np = NanoPost_Email_Check_App::exchange( 'bad' );
np_assert( ! $np['ok'] && 'invalid_code' === $np['error'] && 'The code is wrong, used or expired.' === $np['message'] && ! NanoPost_Email_Check_Connection::is_connected(), 'exchange 400: invalid_code, not connected', $np );
np_app_answer( 200, array( 'check_id' => 'x' ) );
$np = NanoPost_Email_Check_App::exchange( 'odd' );
np_assert( ! $np['ok'] && 'bad_answer' === $np['error'] && ! NanoPost_Email_Check_Connection::is_connected(), 'exchange 200 without a token: refused, not connected', $np );
np_app_answer(
	200,
	array(
		'token'   => 't',
		'address' => 'not an address',
	)
);
$np = NanoPost_Email_Check_App::exchange( 'odd' );
np_assert( ! $np['ok'] && ! NanoPost_Email_Check_Connection::is_connected(), 'exchange 200 with a bad address: refused' );
np_app_answer( 0 );
$np = NanoPost_Email_Check_App::exchange( 'x' );
np_assert( ! $np['ok'] && 'network' === $np['error'] && 0 === $np['code'], 'exchange network error: not connected', $np );
np_assert( 4 === count( NanoPost_Email_Check_App::failed_calls() ), 'failed calls are logged', NanoPost_Email_Check_App::failed_calls() );
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

// 401: the connection ended (a move); no fallback alert for the refused test.
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
np_assert( array( 'site_url', 'plugin_version', 'wp_version', 'php_version', 'interval', 'webhook_url' ) === array_keys( $np_calls[0]['body'] ), 'site settings: exactly the contract fields', $np_calls[0]['body'] );
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
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-connection"]//input[@name="action" and @value="nanopost_email_check_connect"]' )->length, 'not connected: a Connect form' );
$np_text = $np_x->query( '//div[@id="nanopost-email-check-connection"]' )->item( 0 )->textContent;
foreach ( array( 'site address', 'plugin, WordPress and PHP', 'check interval', 'webhook URL', 'ID, the time, what started it', 'wp_mail() succeeded', 'error text', '30 days', 'Nothing is sent before you connect' ) as $np_part ) {
	np_assert( false !== strpos( $np_text, $np_part ), 'consent text names: ' . $np_part );
}
np_assert( 1 === $np_x->query( '//a[@href="' . NanoPost_Email_Check_Help::TERMS_URL . '"]' )->length && 1 === $np_x->query( '//a[@href="' . NanoPost_Email_Check_Help::PRIVACY_URL . '"]' )->length, 'consent: terms and privacy links' );

// Connect: an editor is refused; the admin is sent to the app with a state.
wp_set_current_user( $np_editor_id );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::CONNECT_ACTION ) );
np_assert( 'die:403' === np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect' ) ), 'connect: an editor is refused' );
wp_set_current_user( $np_admin_id );
np_nonce( null );
np_assert( 0 === strpos( np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect' ) ), 'die:' ), 'connect: no nonce is refused' );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::CONNECT_ACTION ) );
$np_out = np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect' ) );
np_assert( 0 === strpos( $np_out, 'redirect:' . $np_app_page ), 'connect: redirect to the app connect page', $np_out );
parse_str( (string) wp_parse_url( substr( $np_out, 9 ), PHP_URL_QUERY ), $np_q );
$np_state = get_transient( NanoPost_Email_Check_Admin::STATE_PREFIX . $np_admin_id );
np_assert( is_string( $np_state ) && 32 === strlen( $np_state ) && $np_state === $np_q['state'], 'connect: a 32-character state is stored for the user and sent', $np_q );
np_assert( 0 === strpos( $np_q['return'], admin_url( 'admin-post.php' ) ) && false !== strpos( $np_q['return'], 'action=nanopost_email_check_connect_return' ) && home_url() === $np_q['site'], 'connect: return is the admin-post action, site is home_url', $np_q );
np_app_take();

// Return with a wrong state: no exchange.
$_GET   = array(
	'state' => 'wrong',
	'code'  => 'code-1',
);
$np_out = np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect_return' ) );
np_assert( 0 === strpos( $np_out, 'redirect:' . NanoPost_Email_Check_Admin::page_url() ) && array() === np_app_take() && ! NanoPost_Email_Check_Connection::is_connected(), 'return: a wrong state makes no call and does not connect' );
np_assert( array( 'outcome' => 'connect-state' ) === get_transient( 'nanopost_email_check_sent_' . $np_admin_id ), 'return: the state notice' );
np_assert( false === get_transient( NanoPost_Email_Check_Admin::STATE_PREFIX . $np_admin_id ), 'return: the state is used up, also on a mismatch' );

// Cancel in nanoPost: the right state, error=cancelled, no code: no call, no notice.
delete_transient( 'nanopost_email_check_sent_' . $np_admin_id );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::CONNECT_ACTION ) );
$np_out = np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect' ) );
parse_str( (string) wp_parse_url( substr( $np_out, 9 ), PHP_URL_QUERY ), $np_q );
$_GET   = array(
	'action' => 'nanopost_email_check_connect_return',
	'error'  => 'cancelled',
	'state'  => $np_q['state'],
);
$np_out = np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect_return' ) );
np_assert( 0 === strpos( $np_out, 'redirect:' . NanoPost_Email_Check_Admin::page_url() ) && array() === np_app_take() && ! NanoPost_Email_Check_Connection::is_connected(), 'cancel: back to the screen, no call, not connected', $np_out );
np_assert( false === get_transient( 'nanopost_email_check_sent_' . $np_admin_id ), 'cancel: no notice, as if nothing happened' );
np_assert( false === get_transient( NanoPost_Email_Check_Admin::STATE_PREFIX . $np_admin_id ), 'cancel: the state is used up' );
$_GET = array(
	'error' => 'cancelled',
	'state' => 'stale',
);
np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect_return' ) );
np_assert( array( 'outcome' => 'connect-state' ) === get_transient( 'nanopost_email_check_sent_' . $np_admin_id ), 'cancel with a bad state: the usual "request expired" notice' );

// Return with the right state.
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::CONNECT_ACTION ) );
$np_out = np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect' ) );
parse_str( (string) wp_parse_url( substr( $np_out, 9 ), PHP_URL_QUERY ), $np_q );
$_GET   = array(
	'state' => $np_q['state'],
	'code'  => 'code-1',
);
$np_out = np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect_return' ) );
np_assert( NanoPost_Email_Check_Connection::is_connected() && 1 === count( np_app_take() ), 'return: the right state exchanges the code and connects' );
$np_x = np_xpath( np_render_screen() );
np_assert( false !== strpos( $np_x->query( '//div[contains(@class,"notice-success")]' )->item( 0 )->textContent, 'connected to nanoPost' ), 'return: the "connected" notice' );
np_assert( 1 === $np_x->query( '//input[@name="action" and @value="nanopost_email_check_disconnect"]' )->length, 'connected: a Disconnect form' );
np_assert( 1 === $np_x->query( '//a[@href="https://app.example/checks/01JE"]' )->length, 'connected: a link to the check' );
np_assert( 1 === $np_x->query( '//p[contains(@class,"nanopost-email-check-connected-note")]' )->length, 'connected: the recipient field says tests go to nanoPost' );
$_GET = array(
	'state' => $np_q['state'],
	'code'  => 'code-1',
);
np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_connect_return' ) );
np_assert( array() === np_app_take(), 'return: the same state cannot be used twice' );

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

// Disconnect handler.
np_app_connect();
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::DISCONNECT_ACTION ) );
np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_disconnect' ) );
np_assert( ! NanoPost_Email_Check_Connection::is_connected() && 1 === count( np_app_take() ), 'disconnect handler: one call, disconnected' );
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
np_assert( isset( $np_rows['nanoPost'] ) && false !== strpos( $np_rows['nanoPost'], 'Calls wait until' ), 'status table: the nanoPost row', $np_rows );

np_app_connect();
add_filter( 'pre_wp_mail', '__return_true' );
$np_out = np_cli( 'run' );
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, 'accepted the test email for nanoPost (test ' ) && false !== strpos( $np_out->stdout, 'https://app.example/checks/01JD' ), 'wp email-check run while connected: names nanoPost, the test ID and the check URL', $np_out );
np_assert( false !== strpos( $np_out->stdout, 'Sending a test email to nanoPost ...' ) && false === strpos( $np_out->stdout, 'owner@' ), 'wp email-check run while connected: does not name the recipient setting', $np_out );
np_app_answer( 503 );
$np_out = np_cli( 'run' );
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stderr, 'could not tell nanoPost' ), 'wp email-check run: a warning when the announce call failed', $np_out );
remove_filter( 'pre_wp_mail', '__return_true' );
np_app_take();
np_reset();

// ---------------------------------------------------------------------------------------
np_section( 'App: uninstall tells nanoPost' );
np_reset();
np_app_connect();
np_app_take();
