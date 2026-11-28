<?php
/**
 * Integration tests for the account key (#58). Included by integration.php after
 * integration-canary.php, inside the same database transaction; it uses the fake app and
 * the helpers of the earlier files.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Files.FileName, WordPress.DB.DirectDatabaseQuery, Squiz.Commenting.FunctionComment.Missing -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

$GLOBALS['np_key'] = '';
add_filter(
	'nanopost_email_check_account_key',
	static function ( $key ) {
		return '' !== $GLOBALS['np_key'] ? $GLOBALS['np_key'] : $key;
	}
);
// The key connects only from a public site address; the test site may be localhost.
$np_public_home = static function () {
	return 'https://shop.example.com';
};
add_filter( 'home_url', $np_public_home );

/**
 * A block for a key connect: watching on at once, alerts to the account's address.
 *
 * @param string $watching on or over_limit.
 * @return array
 */
function np_key_block( $watching = 'on' ) {
	return array(
		'observed_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'watching'     => $watching,
		'confirm_by'   => 'over_limit' === $watching ? gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ) : null,
		'arrival'      => 'waiting',
		'sending'      => 'ok',
		'receive_path' => 'ok',
		'alerts_to'    => array(
			array(
				'email' => 'agency@example.com',
				'state' => 'confirmed',
			),
		),
		'check_url'    => 'https://app.example/canaries/KEY1',
	);
}

/**
 * The answer of a key connect.
 *
 * @param string $watching on or over_limit.
 */
function np_key_ok( $watching = 'on' ) {
	np_app_answer(
		201,
		array(
			'address'   => 'canary+key1@nanopost.example',
			'check_url' => 'https://app.example/canaries/KEY1',
			'check'     => np_key_block( $watching ),
		)
	);
}

function np_key_reset() {
	np_reset();
	NanoPost_Email_Check_Key::forget();
	$GLOBALS['np_key'] = '';
	np_app_take();
}

// ---------------------------------------------------------------------------------------
np_section( 'Key: from the constant, by itself' );
np_key_reset();
NanoPost_Email_Check_Key::maybe_queue();
np_assert( 'no_key' === NanoPost_Email_Check_Key::blocked_reason() && false === wp_next_scheduled( NanoPost_Email_Check_Key::HOOK ), 'no key: nothing is booked' );

$GLOBALS['np_key'] = 'npk_good_1';
NanoPost_Email_Check_Key::maybe_queue();
np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Key::HOOK ) && array() === np_app_take(), 'a key: one WP-Cron event, no call in the request' );
np_key_ok();
do_action( NanoPost_Email_Check_Key::HOOK );
wp_clear_scheduled_hook( NanoPost_Email_Check_Key::HOOK ); // WP-Cron removes a single event when it runs it.
$np_calls = np_app_take();
np_assert( 1 === count( $np_calls ) && 'POST' === $np_calls[0]['method'] && '/connect' === $np_calls[0]['path'], 'the event: POST /connect', $np_calls );
np_assert( 'npk_good_1' === $np_calls[0]['body']['account_key'] && ! isset( $np_calls[0]['body']['alert_email'] ), 'the body has account_key, and no alert_email (exactly one of the two)', $np_calls[0]['body'] );
np_assert( array( 'site_url', 'plugin_version', 'wp_version', 'php_version', 'interval', 'webhook_url', 'timezone', 'account_key' ) === array_keys( $np_calls[0]['body'] ), 'the body: the site settings and the key, nothing else', $np_calls[0]['body'] );
np_assert( 1 === preg_match( '/^Bearer [A-Za-z0-9_-]{43}$/', $np_calls[0]['headers']['Authorization'] ), 'its own token as Bearer' );
np_assert( NanoPost_Email_Check_Connection::is_connected() && 'on' === NanoPost_Email_Check_Canary::block()['watching'], 'connected, watching on at once' );
np_assert( 'agency@example.com' === NanoPost_Email_Check_Admin::alert_address( NanoPost_Email_Check_Canary::block() ), 'alerts go to the account\'s address' );
np_assert( '' === NanoPost_Email_Check_Connection::alert_email(), 'no alert address is stored for a key connect' );
NanoPost_Email_Check_Key::maybe_queue();
np_assert( 'connected' === NanoPost_Email_Check_Key::blocked_reason() && false === wp_next_scheduled( NanoPost_Email_Check_Key::HOOK ), 'connected: nothing more is booked' );

// Never stored: not in any option, not in the log of failed calls.
global $wpdb;
$np_found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_value LIKE %s", '%' . $wpdb->esc_like( 'npk_good_1' ) . '%' ) );
np_assert( 0 === $np_found, 'the key is in no option', $np_found );

// Turn off, then the connection ends: the constant does not connect again by itself.
NanoPost_Email_Check_App::disconnect();
np_app_take();
NanoPost_Email_Check_Key::maybe_queue();
np_assert( 'tried' === NanoPost_Email_Check_Key::blocked_reason() && false === wp_next_scheduled( NanoPost_Email_Check_Key::HOOK ), 'once per key and site address: no second connect by itself' );
$GLOBALS['np_key'] = 'npk_good_2';
np_assert( '' === NanoPost_Email_Check_Key::blocked_reason(), 'another key: it may connect again' );

// A connection that ended without the owner (a 401, or a clone): not by itself.
np_key_reset();
$GLOBALS['np_key'] = 'npk_good_1';
update_option( NanoPost_Email_Check_Connection::NOTICE_OPTION, NanoPost_Email_Check_Connection::ENDED_SITE_CHANGED, false );
np_assert( 'ended' === NanoPost_Email_Check_Key::blocked_reason(), 'after a clone or a 401: not by itself' );
delete_option( NanoPost_Email_Check_Connection::NOTICE_OPTION );

// A site address that is not public.
remove_filter( 'home_url', $np_public_home );
$np_local = static function () {
	return 'http://localhost:8888';
};
add_filter( 'home_url', $np_local );
np_assert( 'not_public' === NanoPost_Email_Check_Key::blocked_reason(), 'a site address that is not public: not by itself' );
np_assert( false !== strpos( np_render_screen(), 'this site address is not public, so the key does not turn Email Canary on by itself' ), 'the screen says why' );
remove_filter( 'home_url', $np_local );
add_filter( 'home_url', $np_public_home );

// An owner's pending "Turn on" with an address is not replaced by the key.
np_app_answer( 0 );
NanoPost_Email_Check_App::connect( 'owner@example.com' );
np_app_take();
np_assert( 'connected' === NanoPost_Email_Check_Key::blocked_reason(), 'a pending turn-on with an address: the key waits' );

// ---------------------------------------------------------------------------------------
np_section( 'Key: answers' );
np_key_reset();
$GLOBALS['np_key'] = 'npk_wrong';
np_app_answer(
	403,
	array(
		'error'   => 'invalid_key',
		'message' => 'The account key is not valid.',
	)
);
do_action( 'wp_loaded' );
do_action( NanoPost_Email_Check_Key::HOOK );
wp_clear_scheduled_hook( NanoPost_Email_Check_Key::HOOK );
np_app_take();
np_assert( 'invalid_key' === NanoPost_Email_Check_Key::error() && ! NanoPost_Email_Check_Connection::is_connected() && '' === NanoPost_Email_Check_Connection::get()['token'], '403 invalid_key: the error is kept, no token left' );
NanoPost_Email_Check_Key::maybe_queue();
np_assert( 'tried' === NanoPost_Email_Check_Key::blocked_reason() && false === wp_next_scheduled( NanoPost_Email_Check_Key::HOOK ), '403: no retry with the same key' );
np_assert( false !== strpos( np_render_screen(), 'the account key is not valid' ), '403: the screen says so' );
np_assert( false === strpos( wp_json_encode( NanoPost_Email_Check_App::failed_calls() ), 'npk_wrong' ), 'the key is not in the log of failed calls' );
$GLOBALS['np_key'] = 'npk_new';
np_assert( '' === NanoPost_Email_Check_Key::error() && '' === NanoPost_Email_Check_Key::blocked_reason(), 'a new key: the error goes, and it may connect' );

np_key_reset();
$GLOBALS['np_key'] = 'npk_exists';
np_app_answer(
	409,
	array(
		'error'   => 'canary_exists',
		'message' => 'This site already has a canary.',
	)
);
NanoPost_Email_Check_Key::connect_from_constant();
np_app_take();
np_assert( 'canary_exists' === NanoPost_Email_Check_Key::error() && null === NanoPost_Email_Check_Canary::block(), '409 canary_exists: the error is kept, and it is not read as a stop' );
np_assert( false !== strpos( np_render_screen(), 'this site already has a canary in nanoPost. Remove it there, or turn on with an email address.' ), '409: the screen says so' );

np_key_reset();
$GLOBALS['np_key'] = 'npk_retry';
np_app_answer( 0 );
NanoPost_Email_Check_Key::connect_from_constant();
$np_first = np_app_take()[0]['headers']['Authorization'];
np_assert( 'waiting' === NanoPost_Email_Check_Key::blocked_reason() && NanoPost_Email_Check_Key::state()['next_try'] > time() + HOUR_IN_SECONDS - 10, 'a network error: wait an hour', NanoPost_Email_Check_Key::state() );
update_option( NanoPost_Email_Check_Key::OPTION, array( 'next_try' => time() - 1 ), false );
np_key_ok();
NanoPost_Email_Check_Key::connect_from_constant();
$np_second = np_app_take()[0]['headers']['Authorization'];
np_assert( $np_first === $np_second && NanoPost_Email_Check_Connection::is_connected(), 'the retry sends the same token (a repeat), then connected' );

np_key_reset();
$GLOBALS['np_key'] = 'npk_full';
np_key_ok( 'over_limit' );
NanoPost_Email_Check_Key::connect_from_constant();
np_app_take();
np_assert( 'over_limit' === NanoPost_Email_Check_Canary::block()['watching'] && NanoPost_Email_Check_Canary::block()['confirm_by'] > 0, 'no room: over_limit with confirm_by' );

// ---------------------------------------------------------------------------------------
np_section( 'Key: WP-CLI connect' );
np_key_reset();
add_filter( 'pre_wp_mail', '__return_true' );
np_key_ok();
$np_out   = np_cli( 'connect --key=npk_cli_1' );
$np_calls = np_app_take();
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, 'Email Canary is on. Alerts go to agency@example.com.' ), 'connect --key: on, with the account\'s address', $np_out );
np_assert( 'npk_cli_1' === $np_calls[0]['body']['account_key'] && '/announce' === $np_calls[1]['path'], 'connect --key: POST /connect with the key, then a test', wp_list_pluck( $np_calls, 'path' ) );
np_assert( false === strpos( $np_out->stdout . $np_out->stderr, 'npk_cli_1' ), 'the key is not printed' );
$np_out = np_cli( 'connect --key=npk_cli_2' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'already set up' ) && array() === np_app_take(), 'connect while on: refused, no call', $np_out );

np_key_reset();
np_app_answer(
	403,
	array(
		'error'   => 'invalid_key',
		'message' => 'x',
	)
);
$np_out = np_cli( 'connect --key=npk_cli_bad' );
np_app_take();
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'the account key is not valid' ), 'connect --key, 403: the plugin\'s text, exit 1', $np_out );
$np_out = np_cli( 'connect --key=a --email=b@example.com' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'not both' ) && array() === np_app_take(), 'both --key and --email: refused' );
$np_out = np_cli( 'connect' );
np_assert( 1 === $np_out->return_code && array() === np_app_take(), 'no key and no address: refused' );
$GLOBALS['np_key'] = 'npk_const';
np_key_ok();
$np_out = np_cli( 'connect' );
np_assert( 0 === $np_out->return_code && 'npk_const' === np_app_take()[0]['body']['account_key'], 'connect with no option: the key from the constant' );

np_key_reset();
$np_out = np_cli( 'connect --email=owner@example.com' );
np_app_take();
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, 'We sent a confirm email to owner@example.com.' ), 'connect --email: waits for the confirm', $np_out );
remove_filter( 'pre_wp_mail', '__return_true' );

// ---------------------------------------------------------------------------------------
np_section( 'Key: the screen line and multisite' );
np_key_reset();
$GLOBALS['np_key'] = 'npk_screen';
np_assert( false !== strpos( np_render_screen(), 'An account key is set in wp-config.php. Email Canary turns on by itself in a moment' ), 'a key, not on yet: one line on the screen' );
np_assert( false === strpos( np_render_screen(), 'npk_screen' ), 'the screen never shows the key' );
np_assert( 1 === np_xpath( np_render_screen() )->query( '//p[contains(@class,"nanopost-email-check-key")]//a[contains(@href,"/email-check-plugin/canary/") and contains(@href,"#turn-on-with-an-account-key")]' )->length, 'the key line links to the help page' );
np_assert( false !== strpos( np_render_screen(), 'If an account key is set, the site sends it once' ), 'the consent text names the key' );
if ( is_multisite() ) {
	$np_other = (int) get_sites(
		array(
			'fields'       => 'ids',
			'site__not_in' => array( get_main_site_id() ),
			'number'       => 1,
		)
	)[0];
	switch_to_blog( $np_other );
	np_assert( 'not_main_site' === NanoPost_Email_Check_Key::blocked_reason(), 'multisite: another site does not connect by itself' );
	restore_current_blog();
}

np_key_reset();
remove_filter( 'home_url', $np_public_home );
np_reset();
np_app_connect();
np_app_take();
