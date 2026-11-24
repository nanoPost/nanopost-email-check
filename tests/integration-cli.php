<?php
/**
 * Integration tests for the WP-CLI command (wp email-check). Included by integration.php,
 * inside its database transaction, so it uses that file's helpers and state.
 *
 * The commands run in this process with WP_CLI::runcommand( ..., launch false ), so they
 * see the uncommitted test data. With exit_error false, WP_CLI::error() and halt() throw
 * instead of ending the process, and the exit code is returned.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Files.FileName -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

/**
 * Run a wp email-check command in this process.
 *
 * @param string $command Command after "wp email-check ".
 * @return object stdout, stderr, return_code.
 */
function np_cli( $command ) {
	return WP_CLI::runcommand(
		'email-check ' . $command,
		array(
			'launch'     => false,
			'return'     => 'all',
			'exit_error' => false,
		)
	);
}

/**
 * Find a registered command by its words, such as "email-check settings set".
 *
 * @param string $path Command words.
 * @return object|null WP-CLI command, or null.
 */
function np_cli_command( $path ) {
	$command = WP_CLI::get_root_command();
	foreach ( explode( ' ', $path ) as $name ) {
		$args    = array( $name );
		$command = $command->find_subcommand( $args );
		if ( ! $command ) {
			return null;
		}
	}
	return $command;
}

// ---------------------------------------------------------------------------------------
np_section( 'WP-CLI: commands are registered' );

foreach ( array( 'email-check', 'email-check run', 'email-check status', 'email-check test-alert', 'email-check settings', 'email-check settings get', 'email-check settings set' ) as $np_path ) {
	$np_command = np_cli_command( $np_path );
	np_assert( null !== $np_command, 'registered: wp ' . $np_path );
	if ( $np_command && method_exists( $np_command, 'get_synopsis' ) ) {
		WP_CLI::log( '        wp ' . $np_path . ( '' !== (string) $np_command->get_synopsis() ? ' ' . $np_command->get_synopsis() : '' ) . ' -- ' . $np_command->get_shortdesc() );
	}
}
$np_command = np_cli_command( 'email-check status' );
np_assert( $np_command && '[--format=<format>]' === $np_command->get_synopsis(), 'status takes --format', $np_command ? $np_command->get_synopsis() : null );
$np_command = np_cli_command( 'email-check settings set' );
np_assert( $np_command && '<key> <value>' === $np_command->get_synopsis(), 'settings set takes <key> <value>', $np_command ? $np_command->get_synopsis() : null );
$np_command = np_cli_command( 'email-check' );
np_assert( $np_command && false !== strpos( $np_command->get_longdesc(), 'EXIT CODES' ), 'wp help email-check lists the exit codes' );

// ---------------------------------------------------------------------------------------
np_section( 'WP-CLI: one validator for the screen and the command' );
np_reset();

np_assert( 'owner@example.com' === NanoPost_Email_Check_Settings::validate( 'recipient', ' owner@example.com ' ), 'validate: address is trimmed' );
np_assert( '' === NanoPost_Email_Check_Settings::validate( 'recipient', '' ), 'validate: empty address is allowed' );
foreach ( array( 'not-an-email', 'first last@example.org' ) as $np_value ) {
	$np = NanoPost_Email_Check_Settings::validate( 'recipient', $np_value );
	np_assert( is_wp_error( $np ) && 'invalid_recipient' === $np->get_error_code(), 'validate: address refused: ' . $np_value );
}
np_assert( 'weekly' === NanoPost_Email_Check_Settings::validate( 'interval', 'Weekly' ), 'validate: interval as a key' );
$np = NanoPost_Email_Check_Settings::validate( 'interval', 'hourly' );
np_assert( is_wp_error( $np ) && false !== strpos( $np->get_error_message(), 'twicedaily, daily, weekly' ), 'validate: unknown interval is an error that lists the choices', $np );
$np = NanoPost_Email_Check_Settings::validate( 'webhook_url', 'http://10.0.0.5/x' );
np_assert( is_wp_error( $np ) && 'invalid_webhook_url' === $np->get_error_code(), 'validate: private webhook URL refused' );
np_assert( 'https://hooks.slack.com/services/T/B/x' === NanoPost_Email_Check_Settings::validate( 'webhook_url', 'https://hooks.slack.com/services/T/B/x' ), 'validate: public webhook URL accepted' );
$np = NanoPost_Email_Check_Settings::validate( 'colour', 'blue' );
np_assert( is_wp_error( $np ) && 'unknown_setting' === $np->get_error_code(), 'validate: unknown setting' );
$np = NanoPost_Email_Check_Settings::validate( 'recipient', array( 'a@example.com' ) );
np_assert( is_wp_error( $np ), 'validate: array value refused' );

// ---------------------------------------------------------------------------------------
np_section( 'WP-CLI: settings' );
np_reset();
NanoPost_Email_Check_Scheduler::ensure_scheduled();

$np_out = np_cli( 'settings set recipient owner@example.com' );
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, 'Success: Saved recipient: "owner@example.com".' ), 'set recipient: exit 0 and a success line', $np_out );
np_assert( 'owner@example.com' === NanoPost_Email_Check_Settings::get()['recipient'], 'set recipient: saved' );

$np_before = get_option( NanoPost_Email_Check_Settings::OPTION );
foreach ( array( 'not-an-email', "'first last@example.org'" ) as $np_value ) {
	$np_out = np_cli( 'settings set recipient ' . $np_value );
	np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'The email address is not valid.' ), 'set recipient: invalid address exits 1 with the screen message: ' . $np_value, $np_out );
}
np_assert( get_option( NanoPost_Email_Check_Settings::OPTION ) === $np_before, 'set recipient: an invalid address changes nothing' );

$np_out   = np_cli( 'settings set interval weekly' );
$np_event = wp_get_scheduled_event( NanoPost_Email_Check_Scheduler::HOOK );
np_assert( 0 === $np_out->return_code && 'weekly' === NanoPost_Email_Check_Settings::interval(), 'set interval: saved', $np_out );
np_assert( $np_event && 'weekly' === $np_event->schedule, 'set interval: the checks are rescheduled', $np_event );
np_assert( 'owner@example.com' === NanoPost_Email_Check_Settings::get()['recipient'], 'set interval: other settings are kept' );

$np_before = get_option( NanoPost_Email_Check_Settings::OPTION );
$np_next   = wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK );
$np_out    = np_cli( 'settings set interval hourly' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'The interval is not valid.' ), 'set interval: unknown interval exits 1', $np_out );
np_assert( get_option( NanoPost_Email_Check_Settings::OPTION ) === $np_before && wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ) === $np_next, 'set interval: an unknown interval changes nothing (not even to daily)' );

$np_out = np_cli( 'settings set webhook_url http://127.0.0.1/hook' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'The webhook URL is not valid.' ), 'set webhook_url: private address exits 1', $np_out );
np_assert( '' === NanoPost_Email_Check_Settings::webhook_url(), 'set webhook_url: nothing saved' );
$np_out = np_cli( 'settings set webhook_url https://hooks.slack.com/services/T/B/x' );
np_assert( 0 === $np_out->return_code && 'https://hooks.slack.com/services/T/B/x' === NanoPost_Email_Check_Settings::webhook_url(), 'set webhook_url: saved', $np_out );
$np_out = np_cli( 'settings set webhook_url ""' );
np_assert( 0 === $np_out->return_code && '' === NanoPost_Email_Check_Settings::webhook_url(), 'set webhook_url "": cleared', $np_out );

$np_out = np_cli( 'settings set colour blue' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'Unknown setting "colour"' ), 'set: unknown setting exits 1', $np_out );

$np_out = np_cli( 'settings get recipient' );
np_assert( 0 === $np_out->return_code && 'owner@example.com' === $np_out->stdout, 'get recipient: the plain value', $np_out );
$np_out = np_cli( 'settings get --format=json' );
np_assert(
	array(
		'recipient'   => 'owner@example.com',
		'interval'    => 'weekly',
		'webhook_url' => '',
	) === json_decode( $np_out->stdout, true ),
	'get --format=json: all settings as an object',
	$np_out
);
$np_out = np_cli( 'settings get' );
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, 'webhook_url' ) && false !== strpos( $np_out->stdout, 'owner@example.com' ), 'get: a table of all settings', $np_out );
$np_out = np_cli( 'settings get colour' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'Unknown setting' ), 'get: unknown setting exits 1', $np_out );

$np_out = np_cli( 'settings set recipient ""' );
np_assert( 0 === $np_out->return_code && '' === NanoPost_Email_Check_Settings::get()['recipient'] && false !== strpos( $np_out->stdout, 'site admin email' ), 'set recipient "": cleared, falls back to the admin email', $np_out );

// ---------------------------------------------------------------------------------------
np_section( 'WP-CLI: run' );
np_reset();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'recipient' => 'cli@example.com' ) );

$GLOBALS['np_cli_mail'] = array();
$np_mail_ok             = static function ( $pre, $atts ) {
	$GLOBALS['np_cli_mail'][] = $atts;
	return true;
};
$np_mail_fail           = static function ( $pre, $atts ) {
	$GLOBALS['np_cli_mail'][] = $atts;
	do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: Could not authenticate.' ) );
	return false;
};
$np_announced           = array();
$np_on_result           = static function ( $result ) use ( &$np_announced ) {
	$np_announced[] = $result->trigger;
};
add_action( 'nanopost_email_check_result', $np_on_result );

// The "Send test now" lock does not apply to WP-CLI.
set_transient( NanoPost_Email_Check_Admin::SEND_LOCK, 1, MINUTE_IN_SECONDS );
add_filter( 'pre_wp_mail', $np_mail_ok, 10, 2 );
$np_out = np_cli( 'run' );
np_assert( 0 === $np_out->return_code, 'run: exit 0 on pass', $np_out );
np_assert( false !== strpos( $np_out->stdout, 'Success: WordPress accepted the test email for cli@example.com.' ), 'run: says the email was accepted', $np_out->stdout );
$np_latest = NanoPost_Email_Check_Log::latest();
np_assert( $np_latest && NanoPost_Email_Check_Result::TRIGGER_CLI === $np_latest->trigger && 'cli' === $np_latest->trigger, 'run: logged with the trigger "cli"', $np_latest ? $np_latest->to_array() : null );
np_assert( $np_latest && 'WP-CLI' === $np_latest->trigger_label(), 'run: the trigger is named "WP-CLI"' );
np_assert( array( 'cli' ) === $np_announced, 'run: the result action fires once, with trigger cli', $np_announced );
$np_mail = end( $GLOBALS['np_cli_mail'] );
np_assert( $np_mail && false !== strpos( $np_mail['subject'], 'Email check: WP-CLI test' ), 'run: the subject names a WP-CLI test', $np_mail ? $np_mail['subject'] : null );
np_assert( $np_mail && false !== strpos( $np_mail['message'], 'Started by: WP-CLI' ), 'run: the body says WP-CLI started it' );

$np_out = np_cli( 'run' );
np_assert( 0 === $np_out->return_code && 2 === count( $GLOBALS['np_cli_mail'] ) && 2 === count( NanoPost_Email_Check_Log::all() ), 'run: twice in a row, no once-a-minute lock', count( $GLOBALS['np_cli_mail'] ) );
remove_filter( 'pre_wp_mail', $np_mail_ok, 10 );
delete_transient( NanoPost_Email_Check_Admin::SEND_LOCK );

// A failure exits 1, shows the error and a help link, and sends the webhook alert.
update_option(
	NanoPost_Email_Check_Settings::OPTION,
	array(
		'recipient'   => 'cli@example.com',
		'webhook_url' => 'https://hooks.slack.com/services/T/B/x',
	)
);
$np_n = count( $GLOBALS['np_requests'] );
add_filter( 'pre_wp_mail', $np_mail_fail, 10, 2 );
$np_out = np_cli( 'run' );
remove_filter( 'pre_wp_mail', $np_mail_fail, 10 );
np_assert( 1 === $np_out->return_code, 'run: exit 1 on fail', $np_out );
np_assert( false !== strpos( $np_out->stderr, 'Error: The test email to cli@example.com failed.' ), 'run: says the email failed', $np_out->stderr );
np_assert( false !== strpos( $np_out->stdout, 'Error text: SMTP Error: Could not authenticate.' ), 'run: prints the error text', $np_out->stdout );
np_assert( false !== strpos( $np_out->stdout, 'What this error means: https://nanopo.st/email-check-plugin/error/?utm_source=wp-admin&utm_medium=email-check&utm_content=cli#error=' ), 'run: prints the help link', $np_out->stdout );
np_assert( count( $GLOBALS['np_requests'] ) === $np_n + 1, 'run: a failure sends the webhook alert as usual' );
$np_body = json_decode( end( $GLOBALS['np_requests'] )['args']['body'], true );
np_assert( 'fail' === $np_body['status'] && 'SMTP Error: Could not authenticate.' === $np_body['error'], 'run: the alert holds the error', $np_body );
np_assert( 'cli' === NanoPost_Email_Check_Log::latest()->trigger && ! NanoPost_Email_Check_Log::latest()->passed, 'run: the failure is logged' );
remove_action( 'nanopost_email_check_result', $np_on_result );

// The log on the plugin screen shows "WP-CLI" in the "Started by" column.
wp_set_current_user( $np_admin_id );
$np_x = np_xpath( np_render_screen() );
np_assert( 3 === $np_x->query( '//div[@id="nanopost-email-check-log"]//td[normalize-space(.)="WP-CLI"]' )->length, 'log screen: "Started by" shows WP-CLI' );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------------------
np_section( 'WP-CLI: status' );
np_reset();

/**
 * Run "status --format=json".
 *
 * @return array{0:int, 1:array|null} Exit code and decoded JSON.
 */
function np_cli_status() {
	$out = np_cli( 'status --format=json' );
	return array( $out->return_code, json_decode( $out->stdout, true ) );
}

list( $np_code, $np_data ) = np_cli_status();
np_assert( 2 === $np_code && 'not-scheduled' === $np_data['state'], 'status: no event is not-scheduled, exit 2', array( $np_code, $np_data ) );
np_assert( null === $np_data['next_run'] && null === $np_data['last_check'], 'status: no next run, no last check', $np_data );

NanoPost_Email_Check_Scheduler::ensure_scheduled();
list( $np_code, $np_data ) = np_cli_status();
np_assert( 0 === $np_code && 'ok' === $np_data['state'], 'status: scheduled, nothing failed: ok, exit 0', array( $np_code, $np_data ) );
np_assert( 'daily' === $np_data['interval'] && (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $np_data['next_run'] ), 'status: interval and next run (ISO 8601 UTC)', $np_data );
np_assert( array( 'state', 'last_check', 'failed_check', 'interval', 'next_run', 'stopped_since', 'webhook' ) === array_keys( $np_data ), 'status: JSON fields', array_keys( $np_data ) );
np_assert(
	array(
		'url_set'       => false,
		'last_delivery' => null,
	) === $np_data['webhook'],
	'status: no webhook',
	$np_data['webhook']
);

$np_t = time() - 60;
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'cli', false, 'boom', 'a@example.com', $np_t ) );
list( $np_code, $np_data ) = np_cli_status();
np_assert( 1 === $np_code && 'failing' === $np_data['state'], 'status: last check failed: failing, exit 1', array( $np_code, $np_data ) );
np_assert(
	array(
		'time'      => gmdate( 'c', $np_t ),
		'trigger'   => 'cli',
		'passed'    => false,
		'error'     => 'boom',
		'recipient' => 'a@example.com',
	) === $np_data['last_check'],
	'status: last check time, trigger, pass and error',
	$np_data['last_check']
);
np_assert( 'boom' === $np_data['failed_check']['error'] && 'wp_mail' === $np_data['failed_check']['check'], 'status: the failed check', $np_data['failed_check'] );
np_assert( NanoPost_Email_Check_Help::error( 'boom', NanoPost_Email_Check_Help::PLACE_CLI ) === $np_data['failed_check']['help'], 'status: the failed check has its help link', $np_data['failed_check'] );

$np_out = np_cli( 'status' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stdout, 'failing' ) && false !== strpos( $np_out->stdout, 'started by WP-CLI' ) && false !== strpos( $np_out->stdout, 'boom' ), 'status: the table shows the state, trigger and error', $np_out->stdout );
np_assert( false !== strpos( $np_out->stdout, 'utm_content=cli#error=boom' ), 'status: the table links to the error help', $np_out->stdout );

// Stopped wins over failing: the last result may be old.
$np_t = time() - 3 * DAY_IN_SECONDS;
np_schedule_state( time() - 9 * DAY_IN_SECONDS, $np_t );
list( $np_code, $np_data ) = np_cli_status();
np_assert( 2 === $np_code && 'stopped' === $np_data['state'], 'status: stale: stopped, exit 2', array( $np_code, $np_data ) );
np_assert( gmdate( 'c', $np_t ) === $np_data['stopped_since'], 'status: stopped since the last finished check', $np_data['stopped_since'] );
$np_out = np_cli( 'status' );
np_assert( 2 === $np_out->return_code && false !== strpos( $np_out->stdout, 'Stopped.' ) && false !== strpos( $np_out->stdout, 'cron/?utm_source=wp-admin&utm_medium=email-check&utm_content=cli' ), 'status: the table says stopped and links to the cron help', $np_out->stdout );

// The page-load WP-Cron guard is not used under WP-CLI: wp_loaded records no lock there.
np_assert( false === has_action( 'wp_loaded', array( 'NanoPost_Email_Check_Alerts', 'remember_cron_lock' ) ), 'status: the WP-Cron guard is not hooked under WP-CLI' );
$np_lock = new ReflectionProperty( 'NanoPost_Email_Check_Alerts', 'cron_lock_before' );
if ( PHP_VERSION_ID < 80100 ) {
	$np_lock->setAccessible( true ); // phpcs:ignore Generic.PHP.DeprecatedFunctions -- Needed before PHP 8.1 only.
}
$np_lock->setValue( null, null );
set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) ) );
list( $np_code, $np_data ) = np_cli_status();
np_assert( 'stopped' === $np_data['state'], 'status: a fresh WP-Cron lock does not hide "stopped" under WP-CLI', $np_data['state'] );
delete_transient( 'doing_cron' );

wp_clear_scheduled_hook( NanoPost_Email_Check_Scheduler::HOOK );
list( $np_code, $np_data ) = np_cli_status();
np_assert( 2 === $np_code && 'not-scheduled' === $np_data['state'], 'status: no event: not-scheduled, exit 2', $np_data['state'] );
$np_out = np_cli( 'status' );
np_assert( false !== strpos( $np_out->stdout, 'Not scheduled.' ) && false === strpos( $np_out->stdout, 'Stopped.' ), 'status: the table says not scheduled, as the JSON does', $np_out->stdout );

// Webhook: the last delivery, and whether it was a test.
np_reset();
NanoPost_Email_Check_Scheduler::ensure_scheduled();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/x' ) );
list( $np_code, $np_data ) = np_cli_status();
np_assert( true === $np_data['webhook']['url_set'] && null === $np_data['webhook']['last_delivery'], 'status: webhook set, nothing sent yet', $np_data['webhook'] );
NanoPost_Email_Check_Webhook::send_test();
list( $np_code, $np_data ) = np_cli_status();
$np_d                      = $np_data['webhook']['last_delivery'];
np_assert( 0 === $np_code && $np_d && true === $np_d['ok'] && true === $np_d['test'] && '204 No Content' === $np_d['detail'], 'status: webhook last delivery, marked as a test', $np_d );
np_assert( false === strpos( wp_json_encode( $np_data ), 'hooks.slack.com' ), 'status: the webhook URL is not printed' );
$np_out = np_cli( 'status' );
np_assert( false !== strpos( $np_out->stdout, 'Last webhook message (test alert):' ), 'status: the table names the test alert', $np_out->stdout );

// ---------------------------------------------------------------------------------------
np_section( 'WP-CLI: test-alert' );
np_reset();

$np_n   = count( $GLOBALS['np_requests'] );
$np_out = np_cli( 'test-alert' );
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'No webhook URL is saved.' ), 'test-alert: no URL exits 1', $np_out );
np_assert( count( $GLOBALS['np_requests'] ) === $np_n, 'test-alert: no URL, no request' );

update_option( NanoPost_Email_Check_Settings::OPTION, array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/x' ) );
update_option(
	NanoPost_Email_Check_Webhook::STATE_OPTION,
	array(
		'last_status' => array( 'wp_mail' => 'fail' ),
		'url_hash'    => md5( 'https://hooks.slack.com/services/T/B/x' ),
	)
);
set_transient( NanoPost_Email_Check_Admin::TEST_ALERT_LOCK, 1, MINUTE_IN_SECONDS );
$np_out = np_cli( 'test-alert' );
np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, 'Success: The test alert was delivered (204 No Content).' ), 'test-alert: exit 0 when delivered', $np_out );
np_assert( count( $GLOBALS['np_requests'] ) === $np_n + 1, 'test-alert: one request, the button lock does not apply' );
$np_body = json_decode( end( $GLOBALS['np_requests'] )['args']['body'], true );
np_assert( true === $np_body['test'] && 'https://hooks.slack.com/services/T/B/x' === end( $GLOBALS['np_requests'] )['url'], 'test-alert: a test message to the saved URL', $np_body );
np_assert( array( 'wp_mail' => 'fail' ) === NanoPost_Email_Check_Webhook::state()['last_status'], 'test-alert: the alert status is not changed' );
$np_out = np_cli( 'test-alert' );
np_assert( 0 === $np_out->return_code && count( $GLOBALS['np_requests'] ) === $np_n + 2, 'test-alert: twice in a row, no once-a-minute lock' );
delete_transient( NanoPost_Email_Check_Admin::TEST_ALERT_LOCK );

$GLOBALS['np_http_code'] = 500;
$np_out                  = np_cli( 'test-alert' );
$GLOBALS['np_http_code'] = 204;
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'The test alert was not delivered (500 Server Error).' ), 'test-alert: HTTP 500 exits 1', $np_out );
$GLOBALS['np_http_error'] = true;
$np_out                   = np_cli( 'test-alert' );
$GLOBALS['np_http_error'] = false;
np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, 'Operation timed out' ), 'test-alert: a connection error exits 1', $np_out );

// Real HTTP to the local receiver.
if ( null !== np_receiver( 'GET' ) ) {
	$np_allow_receiver = static function ( $external, $host ) {
		return 'webhook' === $host ? true : $external;
	};
	$np_hook_url       = '';
	$np_url_opt        = static function () use ( &$np_hook_url ) {
		return array( 'webhook_url' => $np_hook_url );
	};
	add_filter( 'http_request_host_is_external', $np_allow_receiver, 10, 2 );
	add_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_url_opt );
	$GLOBALS['np_http_passthrough'] = true;

	np_receiver( 'DELETE' );
	$np_hook_url = 'http://webhook:8080/hook';
	$np_out      = np_cli( 'test-alert' );
	$np_got      = np_receiver( 'GET' );
	np_assert( 0 === $np_out->return_code && false !== strpos( $np_out->stdout, '(200 OK)' ), 'test-alert, real HTTP: exit 0 on 200', $np_out );
	np_assert( 1 === count( $np_got ) && true === json_decode( $np_got[0]['body'], true )['test'], 'test-alert, real HTTP: the receiver got the test message', $np_got );

	$np_hook_url = 'http://webhook:8080/status/404';
	$np_out      = np_cli( 'test-alert' );
	np_assert( 1 === $np_out->return_code && false !== strpos( $np_out->stderr, '(404' ), 'test-alert, real HTTP: exit 1 on 404', $np_out );

	$GLOBALS['np_http_passthrough'] = false;
	remove_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_url_opt );
	remove_filter( 'http_request_host_is_external', $np_allow_receiver, 10 );
}
np_reset();
