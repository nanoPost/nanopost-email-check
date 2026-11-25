<?php
/**
 * Integration tests: settings from code (constants and the nanopost_email_check_settings
 * filter). Included from tests/integration.php, which provides the helpers and the
 * transaction.
 *
 * Constants cannot be undefined within one PHP run, and a real define() here would also
 * change every test after this file. So the constants are simulated through the
 * nanopost_email_check_constant filter, the seam through which the plugin reads them:
 * $GLOBALS['np_constants'] maps a constant name to the value it would have.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Files.FileName, WordPress.WP.GlobalVariablesOverride -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

// ---------------------------------------------------------------------------------------
np_section( 'Settings from code' );
np_reset();

$GLOBALS['np_constants'] = array();
$np_constant_seam        = static function ( $value, $name ) {
	return array_key_exists( $name, $GLOBALS['np_constants'] ) ? $GLOBALS['np_constants'][ $name ] : $value;
};
add_filter( 'nanopost_email_check_constant', $np_constant_seam, 10, 2 );

$np_saved = array(
	'recipient'   => 'saved@example.com',
	'interval'    => 'daily',
	'webhook_url' => 'https://hooks.slack.com/services/T/B/saved',
);
update_option( NanoPost_Email_Check_Settings::OPTION, $np_saved );

np_assert( NanoPost_Email_Check_Settings::get() === $np_saved, 'nothing in code: the stored settings apply', NanoPost_Email_Check_Settings::get() );
np_assert( 'option' === NanoPost_Email_Check_Settings::source( 'recipient' ) && ! NanoPost_Email_Check_Settings::locked( 'recipient' ), 'nothing in code: source is the option, not locked' );
np_assert( array() === NanoPost_Email_Check_Settings::errors(), 'nothing in code: no errors' );
np_assert( 'NANOPOST_EMAIL_CHECK_INTERVAL' === NanoPost_Email_Check_Settings::constant_name( 'interval' ), 'constant name for a key' );

// Valid constants win.
$GLOBALS['np_constants'] = array(
	'NANOPOST_EMAIL_CHECK_RECIPIENT'   => ' code@example.com ',
	'NANOPOST_EMAIL_CHECK_INTERVAL'    => 'weekly',
	'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' => 'https://hooks.slack.com/services/T/B/code',
);
np_assert( 'code@example.com' === NanoPost_Email_Check_Settings::recipient(), 'constant recipient wins, trimmed', NanoPost_Email_Check_Settings::recipient() );
np_assert( 'weekly' === NanoPost_Email_Check_Settings::interval(), 'constant interval wins' );
np_assert( 'https://hooks.slack.com/services/T/B/code' === NanoPost_Email_Check_Settings::webhook_url(), 'constant webhook URL wins' );
foreach ( array( 'recipient', 'interval', 'webhook_url' ) as $np_key ) {
	np_assert( 'constant' === NanoPost_Email_Check_Settings::source( $np_key ) && NanoPost_Email_Check_Settings::locked( $np_key ), 'constant: ' . $np_key . ' is locked, source constant' );
}
np_assert( NanoPost_Email_Check_Settings::stored() === $np_saved, 'constants do not change the stored settings' );
np_assert( array() === NanoPost_Email_Check_Settings::errors(), 'valid constants: no errors' );

// An empty recipient or webhook URL is valid: admin email, no webhook.
$GLOBALS['np_constants'] = array(
	'NANOPOST_EMAIL_CHECK_RECIPIENT'   => '',
	'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' => '',
);
np_assert( get_option( 'admin_email' ) === NanoPost_Email_Check_Settings::recipient(), 'empty recipient constant: site admin email' );
np_assert( '' === NanoPost_Email_Check_Settings::webhook_url() && NanoPost_Email_Check_Settings::locked( 'webhook_url' ), 'empty webhook constant: no webhook, locked' );

// A comma list in the constant, with the same rules as the screen.
$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_RECIPIENT' => 'me@example.com,canary@example.net' );
np_assert( 'me@example.com, canary@example.net' === NanoPost_Email_Check_Settings::recipient() && NanoPost_Email_Check_Settings::locked( 'recipient' ), 'constant comma list: used, locked', NanoPost_Email_Check_Settings::recipient() );
$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_RECIPIENT' => 'a@example.com, b@example.com, c@example.com, d@example.com' );
$np_errors               = NanoPost_Email_Check_Settings::errors();
np_assert( NanoPost_Email_Check_Settings::get()['recipient'] === $np_saved['recipient'] && 1 === count( $np_errors ) && false !== strpos( $np_errors[0]['message'], 'at most 3' ), 'constant with 4 addresses: ignored and reported', $np_errors );

// Invalid constants are ignored and reported.
$GLOBALS['np_constants'] = array(
	'NANOPOST_EMAIL_CHECK_RECIPIENT'   => 'first last@example.org',
	'NANOPOST_EMAIL_CHECK_INTERVAL'    => 'hourly',
	'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' => 'http://127.0.0.1/x',
);
np_assert( NanoPost_Email_Check_Settings::get() === $np_saved, 'invalid constants: the stored settings apply', NanoPost_Email_Check_Settings::get() );
np_assert( ! NanoPost_Email_Check_Settings::locked( 'recipient' ) && 'option' === NanoPost_Email_Check_Settings::source( 'interval' ), 'invalid constants: not locked' );
$np_errors = NanoPost_Email_Check_Settings::errors();
np_assert( array( 'recipient', 'interval', 'webhook_url' ) === wp_list_pluck( $np_errors, 'key' ), 'invalid constants: one error each', $np_errors );
np_assert( array( 'constant', 'constant', 'constant' ) === wp_list_pluck( $np_errors, 'source' ), 'invalid constants: source constant' );
np_assert( 'NANOPOST_EMAIL_CHECK_RECIPIENT' === $np_errors[0]['name'] && 0 === strpos( $np_errors[0]['message'], 'NANOPOST_EMAIL_CHECK_RECIPIENT is ignored.' ), 'error names the constant', $np_errors[0] );
np_assert( false !== strpos( $np_errors[1]['message'], 'twicedaily, daily, weekly' ), 'interval error lists the choices', $np_errors[1]['message'] );

$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_RECIPIENT' => array( 'a@example.com' ) );
np_assert( 'saved@example.com' === NanoPost_Email_Check_Settings::recipient() && 1 === count( NanoPost_Email_Check_Settings::errors() ), 'array constant: ignored and reported' );
$GLOBALS['np_constants'] = array();

// The filter.
$np_filter_values = array();
$np_filter        = static function ( $settings ) use ( &$np_filter_values ) {
	return array_merge( $settings, $np_filter_values );
};
add_filter( 'nanopost_email_check_settings', $np_filter );

$np_filter_values = array( 'interval' => 'twicedaily' );
np_assert( 'twicedaily' === NanoPost_Email_Check_Settings::interval(), 'filter value wins' );
np_assert( 'filter' === NanoPost_Email_Check_Settings::source( 'interval' ) && NanoPost_Email_Check_Settings::locked( 'interval' ), 'filter: locked, source filter' );
np_assert( 'option' === NanoPost_Email_Check_Settings::source( 'recipient' ), 'filter: other keys keep their source' );

$np_filter_values = array( 'interval' => 'daily' );
np_assert( 'option' === NanoPost_Email_Check_Settings::source( 'interval' ) && ! NanoPost_Email_Check_Settings::locked( 'interval' ), 'filter returns the stored value: not locked' );

$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_RECIPIENT' => 'code@example.com' );
$np_seen                 = null;
$np_spy                  = static function ( $settings ) use ( &$np_seen ) {
	$np_seen = $settings;
	return $settings;
};
add_filter( 'nanopost_email_check_settings', $np_spy, 5 );
$np_filter_values = array( 'recipient' => 'filter@example.com' );
NanoPost_Email_Check_Settings::get();
np_assert( is_array( $np_seen ) && 'code@example.com' === $np_seen['recipient'], 'the filter gets the value from the constant' );
np_assert( 'filter@example.com' === NanoPost_Email_Check_Settings::recipient() && 'filter' === NanoPost_Email_Check_Settings::source( 'recipient' ), 'the filter runs after the constants' );
remove_filter( 'nanopost_email_check_settings', $np_spy, 5 );

$np_filter_values = array( 'recipient' => 'not-an-email' );
$np_errors        = NanoPost_Email_Check_Settings::errors();
np_assert( 'code@example.com' === NanoPost_Email_Check_Settings::recipient() && 'constant' === NanoPost_Email_Check_Settings::source( 'recipient' ), 'invalid filter value: the constant still applies' );
np_assert( 1 === count( $np_errors ) && 'filter' === $np_errors[0]['source'] && 'nanopost_email_check_settings' === $np_errors[0]['name'], 'invalid filter value: reported', $np_errors );
$GLOBALS['np_constants'] = array();
remove_filter( 'nanopost_email_check_settings', $np_filter );

$np_not_array = static function () {
	return 'nonsense';
};
add_filter( 'nanopost_email_check_settings', $np_not_array );
np_assert( NanoPost_Email_Check_Settings::get() === $np_saved && array() === NanoPost_Email_Check_Settings::errors(), 'filter returns a non-array: ignored' );
remove_filter( 'nanopost_email_check_settings', $np_not_array );

// ---------------------------------------------------------------------------------------
np_section( 'Settings from code: plugin screen' );
wp_set_current_user( $np_admin_id );
NanoPost_Email_Check_Admin::register_settings();
set_current_screen( 'dashboard' );

$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_RECIPIENT' => 'code@example.com' );
$np_filter_values        = array( 'interval' => 'weekly' );
add_filter( 'nanopost_email_check_settings', $np_filter );
$np_x = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//input[@id="nanopost_email_check_recipient" and @disabled and @value="code@example.com"]' )->length, 'constant: recipient field is disabled and shows the value from code' );
np_assert( 1 === $np_x->query( '//p[contains(@class, "nanopost-email-check-locked") and normalize-space(.)="Set in code (NANOPOST_EMAIL_CHECK_RECIPIENT)."]' )->length, 'constant: the note names the constant' );
np_assert( 1 === $np_x->query( '//select[@id="nanopost_email_check_interval" and @disabled]/option[@selected and @value="weekly"]' )->length, 'filter: interval field is disabled and shows the value from code' );
np_assert( 1 === $np_x->query( '//p[contains(@class, "nanopost-email-check-locked") and normalize-space(.)="Set in code (nanopost_email_check_settings filter)."]' )->length, 'filter: the note names the filter' );
np_assert( 1 === $np_x->query( '//input[@id="nanopost_email_check_webhook_url" and not(@disabled) and @value="' . $np_saved['webhook_url'] . '"]' )->length, 'a field not set in code stays editable' );
np_assert( 2 === $np_x->query( '//p[contains(@class, "nanopost-email-check-locked")]' )->length, 'one note per field set in code' );
np_assert( 0 === $np_x->query( '//div[contains(@class, "nanopost-email-check-code-error")]' )->length, 'valid values from code: no error notice' );
remove_filter( 'nanopost_email_check_settings', $np_filter );

$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' => 'not a url' );
$np_x                    = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//div[contains(@class, "notice-error") and contains(@class, "nanopost-email-check-code-error") and contains(., "NANOPOST_EMAIL_CHECK_WEBHOOK_URL is ignored.")]' )->length, 'invalid constant: reported on the screen' );
np_assert( 1 === $np_x->query( '//input[@id="nanopost_email_check_webhook_url" and not(@disabled) and @value="' . $np_saved['webhook_url'] . '"]' )->length, 'invalid constant: the field is editable and shows the stored value' );

$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' => 'https://hooks.slack.com/services/T/B/code' );
$np_x                    = np_xpath( np_render_screen() );
np_assert( 1 === $np_x->query( '//button[not(@disabled) and contains(., "Send test alert")]' )->length && 1 === $np_x->query( '//form[@id="nanopost-email-check-test-alert"]' )->length, 'webhook URL from a constant: "Send test alert" is active' );

// ---------------------------------------------------------------------------------------
np_section( 'Settings from code: saving the form' );
update_option( NanoPost_Email_Check_Settings::OPTION, $np_saved );
$GLOBALS['np_constants']       = array( 'NANOPOST_EMAIL_CHECK_RECIPIENT' => 'code@example.com' );
$GLOBALS['wp_settings_errors'] = array();

// A disabled field is not sent: the form posts only the other fields.
update_option(
	NanoPost_Email_Check_Settings::OPTION,
	array(
		'interval'    => 'twicedaily',
		'webhook_url' => '',
	)
);
$np_stored = get_option( NanoPost_Email_Check_Settings::OPTION );
np_assert( 'saved@example.com' === $np_stored['recipient'], 'saving keeps the stored value of a locked field', $np_stored );
np_assert( 'twicedaily' === $np_stored['interval'] && '' === $np_stored['webhook_url'], 'saving stores the other fields', $np_stored );

// A crafted or stale form that still sends the locked field changes nothing and shows no error.
$np = NanoPost_Email_Check_Settings::sanitize( array( 'recipient' => 'other@example.com' ) );
np_assert( 'saved@example.com' === $np['recipient'], 'a posted value for a locked field is not stored', $np );
$np = NanoPost_Email_Check_Settings::sanitize( array( 'recipient' => 'not-an-email' ) );
np_assert( 'saved@example.com' === $np['recipient'] && array() === get_settings_errors( NanoPost_Email_Check_Settings::OPTION ), 'an invalid posted value for a locked field reports no error', get_settings_errors( NanoPost_Email_Check_Settings::OPTION ) );

// The saved value of a filter-set field is kept too, and the stored value is the base.
$np_filter_values = array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/filter' );
add_filter( 'nanopost_email_check_settings', $np_filter );
$np = NanoPost_Email_Check_Settings::sanitize( array( 'interval' => 'weekly' ) );
np_assert( '' === $np['webhook_url'] && 'saved@example.com' === $np['recipient'] && 'weekly' === $np['interval'], 'sanitize builds on the stored values, not the values from code', $np );
remove_filter( 'nanopost_email_check_settings', $np_filter );
$GLOBALS['np_constants'] = array();

// Unlocked fields still report invalid input.
$np = NanoPost_Email_Check_Settings::sanitize( array( 'recipient' => 'not-an-email' ) );
np_assert( 'saved@example.com' === $np['recipient'] && 1 === count( get_settings_errors( NanoPost_Email_Check_Settings::OPTION ) ), 'unlocked field: invalid input still reported' );
$GLOBALS['wp_settings_errors'] = array();

// ---------------------------------------------------------------------------------------
np_section( 'Settings from code: schedule' );
np_reset();
NanoPost_Email_Check_Scheduler::ensure_scheduled();
np_assert( 'daily' === wp_get_schedule( NanoPost_Email_Check_Scheduler::HOOK ), 'starts daily' );

$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_INTERVAL' => 'weekly' );
NanoPost_Email_Check_Scheduler::ensure_scheduled();
$np_event = wp_get_scheduled_event( NanoPost_Email_Check_Scheduler::HOOK );
np_assert( $np_event && 'weekly' === $np_event->schedule, 'interval constant changed: rescheduled to follow it', $np_event );
np_assert( $np_event && abs( $np_event->timestamp - ( time() + WEEK_IN_SECONDS ) ) < 60, 'rescheduled: first run one interval from now' );
$np_events = 0;
foreach ( _get_cron_array() as $np_hooks ) {
	$np_events += isset( $np_hooks[ NanoPost_Email_Check_Scheduler::HOOK ] ) ? count( $np_hooks[ NanoPost_Email_Check_Scheduler::HOOK ] ) : 0;
}
np_assert( 1 === $np_events, 'rescheduled: only one event', $np_events );

$np_before = $np_event->timestamp;
NanoPost_Email_Check_Scheduler::ensure_scheduled();
np_assert( wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ) === $np_before, 'interval unchanged: the next run stays' );

// Saving the form while the interval is locked does not move the event.
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'interval' => 'twicedaily' ) );
np_assert( wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ) === $np_before && 'weekly' === wp_get_schedule( NanoPost_Email_Check_Scheduler::HOOK ), 'saving with a locked interval keeps the event' );

// The constant is removed: back to the stored interval on the next request.
$GLOBALS['np_constants'] = array();
np_sched_wp_loaded();
np_assert( 'daily' === wp_get_schedule( NanoPost_Email_Check_Scheduler::HOOK ), 'constant removed: the next request goes back to the stored interval' );

// A scheduled run follows a changed interval too, without an admin page load.
$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_INTERVAL' => 'twicedaily' );
add_filter( 'pre_wp_mail', '__return_true' );
NanoPost_Email_Check_Scheduler::run();
remove_filter( 'pre_wp_mail', '__return_true' );
np_assert( 'twicedaily' === wp_get_schedule( NanoPost_Email_Check_Scheduler::HOOK ), 'a scheduled run reschedules to the interval from code' );
$GLOBALS['np_constants'] = array();

// ---------------------------------------------------------------------------------------
np_section( 'Settings from code: webhook' );
np_reset();
$np_code_url             = 'https://hooks.slack.com/services/T/B/code';
$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' => $np_code_url );
$np_n                    = count( $GLOBALS['np_requests'] );
NanoPost_Email_Check_Webhook::on_result( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', false, 'boom' ) );
np_assert( count( $GLOBALS['np_requests'] ) === $np_n + 1 && end( $GLOBALS['np_requests'] )['url'] === $np_code_url, 'a failure is sent to the URL from the constant' );
$np_state = NanoPost_Email_Check_Webhook::state();
np_assert( md5( $np_code_url ) === $np_state['url_hash'] && 'fail' === $np_state['last_status']['wp_mail'], 'webhook state belongs to the URL from the constant', $np_state );

// A new URL in code starts fresh, as a newly saved URL does: no recovery for the old failure.
$GLOBALS['np_constants'] = array( 'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' => 'https://hooks.slack.com/services/T/B/new' );
$np_n                    = count( $GLOBALS['np_requests'] );
NanoPost_Email_Check_Webhook::on_result( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', true ) );
np_assert( count( $GLOBALS['np_requests'] ) === $np_n, 'changed URL in code: no recovery message for a failure sent elsewhere' );
// ---------------------------------------------------------------------------------------
np_section( 'Settings from code: WP-CLI and the schedule' );
np_reset();
update_option(
	NanoPost_Email_Check_Settings::OPTION,
	array(
		'recipient'   => 'stored@example.com',
		'interval'    => 'daily',
		'webhook_url' => '',
	)
);
$GLOBALS['np_constants'] = array(
	'NANOPOST_EMAIL_CHECK_RECIPIENT' => 'code@example.com',
	'NANOPOST_EMAIL_CHECK_INTERVAL'  => 'weekly',
);

$np_out = np_cli( 'settings set recipient other@example.com' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'NANOPOST_EMAIL_CHECK_RECIPIENT' ), 'settings set: a key set in code is refused with exit 1 and names the constant', $np_out->stderr );
np_assert( 'stored@example.com' === NanoPost_Email_Check_Settings::stored()['recipient'], 'settings set: a refused key leaves the stored value' );

$np_out = np_cli( 'settings set webhook_url ""' );
$np_raw = get_option( NanoPost_Email_Check_Settings::OPTION );
np_assert( 0 === $np_out->return_code && 'stored@example.com' === $np_raw['recipient'] && 'daily' === $np_raw['interval'], 'settings set: values set in code are not copied into the stored option', $np_raw );

// CSV goes straight to STDOUT and is not captured in-process. Without a terminal, the
// table is printed as tab-separated lines.
$np_out = np_cli( 'settings get' );
np_assert( false !== strpos( $np_out->stdout, "recipient\tcode@example.com\tconstant" ) && false !== strpos( $np_out->stdout, "webhook_url\t\toption" ), 'settings get: the table shows the source of each value', $np_out->stdout );

// The schedule check runs on every request and needs only the interval: it must not read
// (and check) a webhook URL from code, because that check can look up the host name.
$np_names = array();
$np_spy   = static function ( $value, $name ) use ( &$np_names ) {
	$np_names[] = $name;
	return $value;
};
add_filter( 'nanopost_email_check_constant', $np_spy, 20, 2 );
NanoPost_Email_Check_Settings::interval();
remove_filter( 'nanopost_email_check_constant', $np_spy, 20 );
np_assert( array( 'NANOPOST_EMAIL_CHECK_INTERVAL' ) === $np_names, 'interval(): reads only the interval constant', $np_names );
np_assert( 'weekly' === NanoPost_Email_Check_Settings::interval(), 'interval(): the constant wins' );

// status shows where each setting comes from, values from code that were ignored, and the
// mailer of the last check. It never prints the webhook URL.
$GLOBALS['np_constants']['NANOPOST_EMAIL_CHECK_WEBHOOK_URL'] = 'not a url';
np_cli( 'run' );
list( , $np_status ) = np_cli_status();
np_assert(
	array(
		'recipient'   => 'constant',
		'interval'    => 'constant',
		'webhook_url' => 'option',
	) === $np_status['settings'],
	'status: the source of each setting',
	$np_status['settings']
);
np_assert( 1 === count( $np_status['code_errors'] ) && false !== strpos( $np_status['code_errors'][0], 'NANOPOST_EMAIL_CHECK_WEBHOOK_URL' ), 'status: an ignored value from code is listed', $np_status['code_errors'] );
np_assert( is_array( $np_status['mailer'] ) && array_key_exists( 'type', $np_status['mailer'] ), 'status: the mailer of the last check', $np_status['mailer'] );
$np_out = np_cli( 'status' );
np_assert( false !== strpos( $np_out->stdout, "Set in code\trecipient, interval" ), 'status table: the settings set in code', $np_out->stdout );
np_assert( false !== strpos( $np_out->stdout, "Ignored\tNANOPOST_EMAIL_CHECK_WEBHOOK_URL" ), 'status table: the ignored value from code', $np_out->stdout );

$GLOBALS['np_constants'] = array();

remove_filter( 'nanopost_email_check_constant', $np_constant_seam, 10 );
wp_set_current_user( 0 );
np_reset();
