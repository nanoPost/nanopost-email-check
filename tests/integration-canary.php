<?php
/**
 * Integration tests for the state of Email Canary in wp-admin (the "check" block, the
 * fetch after the deadline, notices, Site Health, the log). Included by integration.php
 * after integration-app.php, inside the same database transaction; it uses the fake app
 * and the helpers of both files.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Files.FileName, WordPress.Security.NonceVerification, Squiz.Commenting.FunctionComment.Missing -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

/**
 * A check block as the app sends it, all well, with values to change.
 *
 * @param array $extra Values to change.
 * @return array
 */
function np_block( $extra = array() ) {
	$now = time();
	return array_merge(
		array(
			'observed_at'      => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'watching'         => 'on',
			'confirm_by'       => null,
			'stopped_reason'   => null,
			'confirm_mail'     => null,
			'arrival'          => 'arrived',
			'next_expected_by' => gmdate( 'Y-m-d\TH:i:s\Z', $now + DAY_IN_SECONDS ),
			'sending'          => 'ok',
			'receive_path'     => 'ok',
			'last_arrival'     => array(
				'received_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now - 60 ),
				'test_id'     => 'aaaaaaaaaaaa',
				'spf'         => 'pass',
				'dkim'        => 'none',
				'dmarc'       => 'pass',
			),
			'last_test'        => null,
			'alerts_to'        => array(
				array(
					'email' => 'owner@example.com',
					'state' => 'confirmed',
				),
			),
			'check_url'        => 'https://app.example/checks/01JD',
		),
		$extra
	);
}

/**
 * The fetch events in the cron array: test ID => array( time, try ).
 *
 * @return array
 */
function np_fetches() {
	$found = array();
	foreach ( (array) _get_cron_array() as $time => $hooks ) {
		if ( isset( $hooks[ NanoPost_Email_Check_Canary::FETCH_HOOK ] ) ) {
			foreach ( $hooks[ NanoPost_Email_Check_Canary::FETCH_HOOK ] as $event ) {
				$found[ $event['args'][0] ] = array( (int) $time, (int) $event['args'][1] );
			}
		}
	}
	return $found;
}

/**
 * Render the notices of a wp-admin page other than the plugin screen.
 *
 * @return string
 */
function np_notices() {
	set_current_screen( 'dashboard' );
	ob_start();
	NanoPost_Email_Check_Alerts::render();
	return (string) ob_get_clean();
}

$np_admin = (int) get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0];
wp_set_current_user( $np_admin );

// ---------------------------------------------------------------------------------------
np_section( 'Canary: the check block' );
np_reset();
np_app_connect();
np_assert( null === NanoPost_Email_Check_Canary::block(), 'no block before the app sent one' );
np_assert( false === NanoPost_Email_Check_Canary::take( array( 'expect_by' => 'x' ) ) && null === NanoPost_Email_Check_Canary::block(), 'an answer with no block stores nothing' );

$np_raw = np_block(
	array(
		'watching'  => 'snoozing',
		'alerts_to' => array(
			array(
				'email' => 'new@example.com',
				'state' => 'pending',
			),
			array( 'email' => 'not an address' ),
			'junk',
		),
		'last_test' => array(
			'test_id'  => 'bbbbbbbbbbbb',
			'arrival'  => 'missing',
			'reason'   => 'refused_spam',
			'help_url' => 'https://evil.example/phish',
		),
	)
);
np_assert( true === NanoPost_Email_Check_Canary::take( array( 'check' => $np_raw ) ), 'an answer with a block: stored' );
$np_b = NanoPost_Email_Check_Canary::block();
np_assert( 'snoozing' === $np_b['watching'] && strtotime( $np_raw['observed_at'] ) === $np_b['observed_at'], 'an unknown value stays as it came; times become Unix times', $np_b );
np_assert( 1 === count( $np_b['alerts_to'] ) && 'new@example.com' === $np_b['alerts_to'][0]['email'] && 'pending' === $np_b['alerts_to'][0]['state'], 'alerts_to: only valid addresses, with their state', $np_b['alerts_to'] );
np_assert( '' === $np_b['last_test']['help_url'] && 'refused_spam' === $np_b['last_test']['reason'], 'a help link off nanopo.st is dropped', $np_b['last_test'] );
$np_t = NanoPost_Email_Check_Canary::normalize_test( array( 'help_url' => 'https://nanopo.st/email-check-plugin/canary/#x' ) );
np_assert( 'https://nanopo.st/email-check-plugin/canary/#x' === $np_t['help_url'], 'a help link on nanopo.st is kept' );
$np_t = NanoPost_Email_Check_Canary::normalize_test( array( 'help_url' => 'http://nanopo.st/x' ) );
np_assert( '' === $np_t['help_url'], 'a help link over plain http is dropped' );

NanoPost_Email_Check_Canary::take( array( 'check' => np_block( array( 'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 600 ) ) ) ) );
np_assert( 'snoozing' === NanoPost_Email_Check_Canary::block()['watching'], 'an older block (answers in the wrong order) does not replace a newer one' );
NanoPost_Email_Check_Canary::take( array( 'check' => np_block( array( 'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 5 ) ) ) ) );
np_assert( 'on' === NanoPost_Email_Check_Canary::block()['watching'], 'a newer block replaces it' );

wp_schedule_single_event( time() + 900, NanoPost_Email_Check_Canary::FETCH_HOOK, array( 'cccccccccccc', 1 ) );
NanoPost_Email_Check_Connection::end();
np_assert( null === NanoPost_Email_Check_Canary::block() && array() === np_fetches(), 'the end of the connection forgets the block and the pending fetches' );

// ---------------------------------------------------------------------------------------
np_section( 'Canary: the announce answer and the fetch after the deadline' );
np_reset();
np_app_connect();
np_app_take();
// The app's clock is one hour ahead of the site's: the delay comes from the app's own times.
$np_app_now = time() + HOUR_IN_SECONDS;
np_app_answer(
	202,
	array(
		'expect_by' => gmdate( 'Y-m-d\TH:i:s\Z', $np_app_now + 900 ),
		'check'     => np_block(),
	),
	array( 'date' => gmdate( 'D, d M Y H:i:s', $np_app_now ) . ' GMT' )
);
add_filter( 'pre_wp_mail', '__return_true' );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
np_app_take();
$np_f = np_fetches();
np_assert( isset( $np_f[ $np_r->test_id ] ) && 1 === $np_f[ $np_r->test_id ][1] && abs( $np_f[ $np_r->test_id ][0] - ( time() + 900 + 120 ) ) <= 3, 'one fetch at expect_by + 2 minutes, by the app\'s clock', $np_f );
np_assert( NanoPost_Email_Check_Result::ANNOUNCE_OK === $np_r->announce && 'on' === NanoPost_Email_Check_Canary::block()['watching'], 'announce 2xx with watching on: "ok", the block is stored', $np_r->to_array() );

wp_unschedule_hook( NanoPost_Email_Check_Canary::FETCH_HOOK );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
np_app_take();
$np_f = np_fetches();
np_assert( isset( $np_f[ $np_r->test_id ] ) && abs( $np_f[ $np_r->test_id ][0] - ( time() + 1020 ) ) <= 3, 'with no Date header: the fetch after 17 minutes', $np_f );
np_assert( NanoPost_Email_Check_Result::ANNOUNCE_OK === $np_r->announce, 'an answer with no block (an app before the block) counts as watching' );
remove_filter( 'pre_wp_mail', '__return_true' );

wp_unschedule_hook( NanoPost_Email_Check_Canary::FETCH_HOOK );
add_filter( 'pre_wp_mail', '__return_false' );
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
np_app_take();
np_assert( array() === np_fetches(), 'a test the site could not send: no fetch (no email to wait for)' );
np_app_answer( 503 );
remove_filter( 'pre_wp_mail', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
np_app_take();
np_assert( array() === np_fetches(), 'a failed announce call: no fetch' );
remove_filter( 'pre_wp_mail', '__return_true' );

// ---------------------------------------------------------------------------------------
np_section( 'Canary: the fetch' );
np_reset();
np_app_connect();
add_filter( 'pre_wp_mail', '__return_true' );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
remove_filter( 'pre_wp_mail', '__return_true' );
np_app_take();
wp_unschedule_hook( NanoPost_Email_Check_Canary::FETCH_HOOK );
$np_id = $np_r->test_id;

np_app_answer(
	200,
	array(
		'test_id' => $np_id,
		'status'  => 'waiting',
	)
);
NanoPost_Email_Check_Canary::fetch_test( $np_id, 1 );
$np_calls = np_app_take();
$np_f     = np_fetches();
np_assert( 1 === count( $np_calls ) && '/tests/' . $np_id === $np_calls[0]['path'], 'fetch: GET /tests/<id>', $np_calls );
np_assert( isset( $np_f[ $np_id ] ) && 2 === $np_f[ $np_id ][1] && abs( $np_f[ $np_id ][0] - ( time() + 600 ) ) <= 3, 'still waiting: one more try 10 minutes later', $np_f );
wp_unschedule_hook( NanoPost_Email_Check_Canary::FETCH_HOOK );

np_app_answer(
	200,
	array(
		'test_id' => $np_id,
		'status'  => 'waiting',
	)
);
NanoPost_Email_Check_Canary::fetch_test( $np_id, 2 );
np_app_take();
np_assert( array() === np_fetches(), 'the second try books no third' );

np_app_answer(
	200,
	array(
		'test_id' => $np_id,
		'status'  => 'missing',
		'reason'  => null,
	)
);
NanoPost_Email_Check_Canary::fetch_test( $np_id, 1 );
np_app_take();
np_assert( 1 === count( np_fetches() ), 'did not arrive, no reason yet: one more try', np_fetches() );
wp_unschedule_hook( NanoPost_Email_Check_Canary::FETCH_HOOK );

np_app_answer(
	200,
	array(
		'test_id'  => $np_id,
		'status'   => 'missing',
		'reason'   => 'refused_dmarc',
		'help_url' => 'https://nanopo.st/dns-checker/',
		'check'    => np_block(
			array(
				'arrival'          => 'missing',
				'next_expected_by' => null,
			)
		),
	)
);
NanoPost_Email_Check_Canary::fetch_test( $np_id, 1 );
np_app_take();
$np_row = NanoPost_Email_Check_Log::latest();
np_assert( array() === np_fetches(), 'did not arrive, with a reason: no more tries' );
np_assert( 'missing' === $np_row->canary['arrival'] && 'refused_dmarc' === $np_row->canary['reason'] && 'https://nanopo.st/dns-checker/' === $np_row->canary['help_url'], 'the log row keeps the arrival, the reason and the help link', $np_row->canary );
np_assert( 'missing' === NanoPost_Email_Check_Canary::block()['arrival'], 'the block of the answer is stored' );

np_app_answer(
	200,
	array(
		'test_id'    => $np_id,
		'status'     => 'arrived',
		'arrived_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 ),
		'spf'        => 'pass',
		'dkim'       => 'none',
		'dmarc'      => 'fail',
	)
);
NanoPost_Email_Check_Canary::fetch_test( $np_id, 1 );
np_app_take();
$np_row = NanoPost_Email_Check_Log::latest();
np_assert( array() === np_fetches() && 'arrived' === $np_row->canary['arrival'] && 'fail' === $np_row->canary['dmarc'] && time() - 30 === $np_row->canary['arrived_at'], 'arrived: the row has the time and the results, no more tries', $np_row->canary );

np_app_answer(
	200,
	array(
		'test_id' => $np_id,
		'status'  => 'unknown',
	)
);
NanoPost_Email_Check_Canary::fetch_test( $np_id, 2 );
np_app_take();
np_assert( 'arrived' === NanoPost_Email_Check_Log::latest()->canary['arrival'], 'an "unknown" answer (our own outage) does not replace a known result' );

np_app_answer( 0 );
NanoPost_Email_Check_Canary::fetch_test( $np_id, 1 );
np_app_take();
np_assert( array() === np_fetches() && 'arrived' === NanoPost_Email_Check_Log::latest()->canary['arrival'], 'a failed fetch changes nothing and books nothing' );
NanoPost_Email_Check_Canary::fetch_test( 'BAD', 1 );
np_assert( array() === np_app_take(), 'a bad test ID makes no call' );

// ---------------------------------------------------------------------------------------
np_section( 'Canary: problems and notices' );
np_reset();
np_app_connect();
$np_sent    = time() - 1800;
$np_set     = static function ( $extra ) {
	update_option(
		NanoPost_Email_Check_Canary::OPTION,
		array(
			'block'  => np_block( $extra ),
			'got_at' => time(),
		)
	);
};
$np_missing = array(
	'arrival'          => 'missing',
	'next_expected_by' => null,
	'last_test'        => array(
		'test_id' => 'dddddddddddd',
		'sent_at' => gmdate( 'Y-m-d\TH:i:s\Z', $np_sent ),
		'arrival' => 'missing',
		'reason'  => 'refused_blocklist',
	),
);
$np_set( $np_missing );
$np_html = np_notices();
np_assert( false !== strpos( $np_html, 'Email Canary: the test email of ' . NanoPost_Email_Check_Admin::format_date( $np_sent ) . ' did not arrive.' ), 'did not arrive: a notice on other wp-admin pages, with the time of the test', $np_html );
np_assert( false !== strpos( $np_html, 'blocklist' ), 'the notice names the reason', $np_html );
np_assert( isset( NanoPost_Email_Check_Alerts::active()[ 'canary-missing:dddddddddddd' . $np_sent ] ), 'the key changes with each new problem', NanoPost_Email_Check_Alerts::active() );

$np_set( array_merge( $np_missing, array( 'receive_path' => 'unknown' ) ) );
np_assert( array() === NanoPost_Email_Check_Canary::problems( NanoPost_Email_Check_Canary::block() ), 'our own outage: no problem, never "did not arrive"' );
$np_set( array_merge( $np_missing, array( 'arrival' => 'unknown' ) ) );
np_assert( array() === NanoPost_Email_Check_Canary::problems( NanoPost_Email_Check_Canary::block() ), 'arrival unknown: no problem' );
$np_set( array_merge( $np_missing, array( 'watching' => 'unconfirmed' ) ) );
np_assert( array() === NanoPost_Email_Check_Canary::problems( NanoPost_Email_Check_Canary::block() ), 'not watching: no problem notice (no alert email either)' );
$np_set( array_merge( $np_missing, array( 'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS ) ) ) );
np_assert( NanoPost_Email_Check_Canary::is_old( NanoPost_Email_Check_Canary::block() ) && array() === NanoPost_Email_Check_Canary::problems( NanoPost_Email_Check_Canary::block() ), 'an old block (no deadline, older than twice the interval): no problem notice' );
$np_set( array( 'next_expected_by' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ) ) );
np_assert( NanoPost_Email_Check_Canary::is_old( NanoPost_Email_Check_Canary::block() ), 'a block whose deadline passed is old' );

$np_set(
	array(
		'last_arrival' => array(
			'received_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ),
			'test_id'     => 'eeeeeeeeeeee',
			'spf'         => 'fail',
			'dkim'        => 'none',
			'dmarc'       => 'fail',
		),
	)
);
$np_html = np_notices();
np_assert( false !== strpos( $np_html, 'Email Canary: the test email arrived, but did not pass SPF, DMARC.' ), 'did not pass: one notice naming each method that failed', $np_html );
np_assert( false === strpos( $np_html, 'DKIM' ), '"none" (DKIM not signed) is not a problem', $np_html );
$np_set( $np_missing );
$np_p = NanoPost_Email_Check_Canary::problems( NanoPost_Email_Check_Canary::block() );
np_assert( 1 === count( $np_p ) && 'missing' === $np_p[0]['type'], 'all well with the last arrival, the next one missing: one problem' );
$np_set(
	array_merge(
		$np_missing,
		array(
			'last_arrival' => array(
				'received_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
				'spf'         => 'pass',
				'dkim'        => 'pass',
				'dmarc'       => 'fail',
			),
		)
	)
);
np_assert( 2 === count( NanoPost_Email_Check_Canary::problems( NanoPost_Email_Check_Canary::block() ) ), 'did not pass and did not arrive: both open at once' );

$np_set(
	array(
		'watching'   => 'unconfirmed',
		'confirm_by' => gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ),
		'alerts_to'  => array(
			array(
				'email' => 'owner@example.com',
				'state' => 'pending',
			),
		),
	)
);
$np_html = np_notices();
np_assert( false !== strpos( $np_html, 'Email Canary: alerts are OFF until you confirm.' ) && false !== strpos( $np_html, 'We sent an email to owner@example.com.' ), 'not confirmed: a notice that stays on other wp-admin pages', $np_html );

np_app_connect( array( 'home_url' => 'https://elsewhere.example' ) );
np_assert( false === strpos( np_notices(), 'Email Canary' ), 'not connected: no Email Canary notice' );

// ---------------------------------------------------------------------------------------
np_section( 'Canary: the plugin webhook when Email Canary does not watch' );
np_reset();
np_app_connect();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/x' ) );
$np_hooks = static function () {
	return count(
		array_filter(
			$GLOBALS['np_requests'],
			static function ( $r ) {
				return 0 === strpos( $r['url'], 'https://hooks.slack.com/' );
			}
		)
	);
};
$np_n     = $np_hooks();
foreach ( array( 'unconfirmed', 'over_limit' ) as $np_state ) {
	np_app_answer( 202, array( 'check' => np_block( array( 'watching' => $np_state ) ) ) );
	add_filter( 'pre_wp_mail', '__return_false' );
	$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
	remove_filter( 'pre_wp_mail', '__return_false' );
	np_app_take();
	np_assert( NanoPost_Email_Check_Result::ANNOUNCE_UNWATCHED === $np_r->announce && 'unwatched' === NanoPost_Email_Check_Log::latest()->announce, 'announce 2xx, ' . $np_state . ': "unwatched", kept in the log' );
	np_assert( $np_hooks() === $np_n + 1, 'announce 2xx, ' . $np_state . ', the site could not send: the plugin webhook alerts' );
	add_filter( 'pre_wp_mail', '__return_true' );
	np_app_answer( 202, array( 'check' => np_block( array( 'watching' => $np_state ) ) ) );
	NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	np_app_take();
	np_assert( $np_hooks() === $np_n + 2, 'then a pass: the plugin sends its recovery' );
	$np_n += 2;
}
np_app_answer( 202, array( 'check' => np_block() ) );
add_filter( 'pre_wp_mail', '__return_false' );
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
np_assert( array() === $GLOBALS['np_app_next'], 'every queued answer was used' );
remove_filter( 'pre_wp_mail', '__return_false' );
np_app_take();
np_assert( $np_hooks() === $np_n, 'announce 2xx, watching on: the plugin stays quiet (Email Canary alerts)' );
wp_unschedule_hook( NanoPost_Email_Check_Canary::FETCH_HOOK );

// ---------------------------------------------------------------------------------------
np_section( 'Canary: the plugin screen and the log' );
np_reset();
np_app_connect();
np_app_take();
$np_x = np_xpath( np_render_screen() );
np_assert( array() === np_app_take(), 'the screen makes no call in the page load' );
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-canary"]' )->length && false !== strpos( $np_x->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent, 'no state from nanoPost yet' ), 'no block yet: says so', $np_x->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent ?? '' );
np_assert( wp_script_is( 'nanopost-email-check-canary', 'enqueued' ), 'connected: the state script is enqueued' );

add_filter( 'pre_wp_mail', '__return_true' );
np_app_answer( 202, array( 'check' => np_block() ) );
$np_r = NanoPost_Email_Check_Plugin::run_checks( 'scheduled' )[0];
remove_filter( 'pre_wp_mail', '__return_true' );
np_app_take();
wp_unschedule_hook( NanoPost_Email_Check_Canary::FETCH_HOOK );
NanoPost_Email_Check_Log::update_test(
	$np_r->test_id,
	array(
		'arrival'    => 'arrived',
		'arrived_at' => time() - 20,
		'spf'        => 'pass',
		'dkim'       => 'none',
		'dmarc'      => 'pass',
	)
);
$np_x    = np_xpath( np_render_screen() );
$np_area = $np_x->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'Email Canary is on' ) && false !== strpos( $np_area, 'owner@example.com' ) && false !== strpos( $np_area, 'Expected by' ), 'on: the state, the alert address in full, the next deadline', $np_area );
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-canary"]//span[contains(@class,"nanopost-email-check-auth-warn") and contains(., "DKIM not signed")]' )->length, 'the last arrival: a warning label "DKIM not signed", with a mark' );
np_assert( 1 === $np_x->query( '//div[@id="nanopost-email-check-log"]//th[.="Email Canary"]' )->length, 'the log has an Email Canary column' );
$np_cell = $np_x->query( '//td[contains(@class,"nanopost-email-check-canary-cell")]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_cell, 'Arrived' ) && false !== strpos( $np_cell, 'SPF' ), 'the log row: arrived, with the labels', $np_cell );

$np_set( $np_missing );
$np_area = np_xpath( np_render_screen() )->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'did not arrive' ) && false !== strpos( $np_area, 'blocklist' ), 'a problem shows on the plugin screen too', $np_area );
$np_set( array( 'receive_path' => 'unknown' ) );
$np_area = np_xpath( np_render_screen() )->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'Email Canary cannot tell now.' ) && false === strpos( $np_area, 'did not arrive' ), 'our own outage: "cannot tell now"', $np_area );
$np_set(
	array(
		'receive_path'   => 'unknown',
		'watching'       => 'stopped',
		'stopped_reason' => 'owner',
	)
);
$np_area = np_xpath( np_render_screen() )->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'Email Canary is off.' ) && false === strpos( $np_area, 'cannot tell' ), 'stopped during our own outage: the stop shows, not "cannot tell"', $np_area );
$np_set( array( 'next_expected_by' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 ) ) );
$np_area = np_xpath( np_render_screen() )->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'This is the state as of' ), 'an old block says how old it is', $np_area );

// An alert address state that 1.8.0 does not know, and a failing one (bounces, #63).
$np_set(
	array(
		'alerts_to' => array(
			array(
				'email' => 'owner@example.com',
				'state' => 'failing',
			),
			array(
				'email' => 'other@example.com',
				'state' => 'paused',
			),
		),
	)
);
$np_html = np_notices();
np_assert( false !== strpos( $np_html, 'Email Canary: alerts do not reach owner@example.com.' ) && false === strpos( $np_html, 'other@example.com' ), 'a failing alert address: a notice on other wp-admin pages too', $np_html );
$np_area = np_xpath( np_render_screen() )->query( '//div[@id="nanopost-email-check-canary"]' )->item( 0 )->textContent;
np_assert( false !== strpos( $np_area, 'Email Canary: alerts do not reach owner@example.com.' ) && false !== strpos( $np_area, 'other@example.com (Open nanoPost to see.)' ), 'alert address states: "failing" says so, an unknown one says "Open nanoPost to see"', $np_area );

// The cells for the other states.
NanoPost_Email_Check_Log::update_test( $np_r->test_id, array( 'arrival' => 'unknown' ) );
np_assert( false !== strpos( np_xpath( np_render_screen() )->query( '//td[contains(@class,"nanopost-email-check-canary-cell")]' )->item( 0 )->textContent, 'cannot tell now' ), 'log row: "cannot tell now"' );
NanoPost_Email_Check_Log::update_test( $np_r->test_id, array( 'arrival' => 'waiting' ) );
np_assert( 'No result yet' === np_xpath( np_render_screen() )->query( '//td[contains(@class,"nanopost-email-check-canary-cell")]' )->item( 0 )->textContent, 'log row: "No result yet", never "missing"' );
NanoPost_Email_Check_Log::update_test( $np_r->test_id, array( 'arrival' => 'eaten' ) );
np_assert( 'Open nanoPost to see.' === np_xpath( np_render_screen() )->query( '//td[contains(@class,"nanopost-email-check-canary-cell")]' )->item( 0 )->textContent, 'log row: an unknown value' );

// The state from the screen: admin-ajax, GET /check at most once a minute.
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
$np_state = static function ( $nonce = null ) {
	$_POST    = array();
	$_REQUEST = array( '_ajax_nonce' => null === $nonce ? wp_create_nonce( NanoPost_Email_Check_Admin::STATE_ACTION ) : $nonce );
	ob_start();
	np_outcome( array( 'NanoPost_Email_Check_Admin', 'ajax_canary_state' ) );
	return json_decode( (string) ob_get_clean(), true );
};
delete_transient( NanoPost_Email_Check_Canary::SCREEN_LOCK );
np_app_answer( 200, array( 'check' => np_block( array( 'watching' => 'stopped' ) ) ) );
$np_json  = $np_state();
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && 'GET' === $np_calls[0]['method'] && '/check' === $np_calls[0]['path'] && 5 === $np_calls[0]['timeout'], 'screen: GET /check, 5 seconds', $np_calls );
np_assert( ! empty( $np_json['data']['changed'] ) && false !== strpos( $np_json['data']['canary'], 'Email Canary is off.' ) && isset( $np_json['data']['log'] ), 'a new state: the new HTML of the area and the log', $np_json );
np_assert( false === strpos( wp_json_encode( $np_json ), 'tok-' ), 'the answer never has the token' );
$np_json = $np_state();
np_assert( array() === np_app_take() && empty( $np_json['data']['changed'] ), 'a second ask within a minute makes no call' );
delete_transient( NanoPost_Email_Check_Canary::SCREEN_LOCK );
np_app_answer( 200, array( 'check' => np_block( array( 'watching' => 'stopped' ) ) ) );
$np_json = $np_state();
np_app_take();
np_assert( empty( $np_json['data']['changed'] ), 'the same state: "changed" is false, no HTML' );
delete_transient( NanoPost_Email_Check_Canary::SCREEN_LOCK );
$np_state( 'bad-nonce' );
np_assert( array() === np_app_take(), 'a bad nonce makes no call' );
wp_set_current_user( $np_editor_id );
$np_state();
np_assert( array() === np_app_take(), 'an editor makes no call' );
wp_set_current_user( $np_admin );
remove_filter( 'wp_doing_ajax', '__return_true' );
remove_filter( 'wp_die_ajax_handler', $np_ajax_die, 999 );
$_POST    = array();
$_REQUEST = array();

// ---------------------------------------------------------------------------------------
np_section( 'Canary: Site Health' );
np_reset();
np_assert( ! isset( apply_filters( 'site_status_tests', array( 'direct' => array() ) )['direct']['nanopost_email_check_canary'] ), 'not connected: no Site Health test' );
np_app_connect();
np_assert( isset( apply_filters( 'site_status_tests', array( 'direct' => array() ) )['direct']['nanopost_email_check_canary'] ), 'connected: a Site Health test' );
$np_set( array() );
$np_h = NanoPost_Email_Check_Canary::site_health();
np_assert( 'good' === $np_h['status'] && 0 === strpos( $np_h['label'], 'Test email arrived at nanoPost 1 min' ), 'all well: "Test email arrived at nanoPost ... ago"', $np_h );
$np_set( $np_missing );
$np_h = NanoPost_Email_Check_Canary::site_health();
np_assert( 'recommended' === $np_h['status'] && 0 === strpos( $np_h['label'], 'Email Canary: the test email of ' ), 'a problem: recommended, with the notice sentence', $np_h );
$np_set( array( 'receive_path' => 'unknown' ) );
np_assert( 'recommended' === NanoPost_Email_Check_Canary::site_health()['status'], 'cannot tell: recommended, not good' );
np_reset();

// ---------------------------------------------------------------------------------------
// The uninstall test in integration.php runs next, on a connected site.
np_section( 'App: uninstall tells nanoPost' );
np_reset();
np_app_connect();
np_app_take();
