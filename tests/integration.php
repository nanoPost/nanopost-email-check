<?php
/**
 * Integration tests. Run inside wp-env with tests/run.sh (npm test).
 *
 * Uses the real WordPress, the real plugin and Mailpit (host "mailpit") as the SMTP server.
 * Saves the plugin's options before the run and puts them back after it.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, WordPress.Files.FileName, Universal.Files.SeparateFunctionsFromOO, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

$GLOBALS['np_failures'] = 0;
$GLOBALS['np_count']    = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Must be true.
 * @param string $label     What is checked.
 * @param mixed  $detail    Shown when it fails.
 */
function np_assert( $condition, $label, $detail = null ) {
	++$GLOBALS['np_count'];
	if ( $condition ) {
		WP_CLI::log( '  ok    ' . $label );
		return;
	}
	++$GLOBALS['np_failures'];
	WP_CLI::log( '  FAIL  ' . $label . ( null === $detail ? '' : ' -- got: ' . wp_json_encode( $detail ) ) );
}

/**
 * Print a section title.
 *
 * @param string $title Title.
 */
function np_section( $title ) {
	WP_CLI::log( '' );
	WP_CLI::log( $title );
}

/**
 * Call the Mailpit API.
 *
 * @param string $method HTTP method.
 * @param string $path   Path after /api/v1/.
 * @return array|null Decoded JSON.
 */
function np_mailpit( $method, $path ) {
	$response = wp_remote_request(
		'http://mailpit:8025/api/v1/' . $path,
		array(
			'method'  => $method,
			'timeout' => 5,
		)
	);
	return is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
}

/**
 * Send all wp_mail() through Mailpit, or through a port where nothing listens.
 *
 * @param bool $working Use the working server.
 */
function np_smtp( $working ) {
	remove_all_actions( 'phpmailer_init' );
	add_action(
		'phpmailer_init',
		static function ( $mailer ) use ( $working ) {
			$mailer->isSMTP();
			$mailer->Host        = 'mailpit'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Port        = $working ? 1025 : 1; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->SMTPAuth    = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->SMTPAutoTLS = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Timeout     = 5; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	);
}

/**
 * Reset the plugin's stored state.
 */
function np_reset() {
	delete_option( NanoPost_Email_Check_Settings::OPTION );
	delete_option( NanoPost_Email_Check_Log::OPTION );
	delete_option( NanoPost_Email_Check_Webhook::STATE_OPTION );
	delete_option( NanoPost_Email_Check_Scheduler::STATE_OPTION );
	wp_clear_scheduled_hook( NanoPost_Email_Check_Scheduler::HOOK );
	global $wpdb;
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . NanoPost_Email_Check_Alerts::DISMISSED_META, '', true );
}

/**
 * A fingerprint of the state the tests touch: every option, user meta and the cron array.
 *
 * @return string
 */
function np_fingerprint() {
	global $wpdb;
	$options = $wpdb->get_results( "SELECT option_name, option_value FROM $wpdb->options WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' ORDER BY option_name", ARRAY_N );
	$meta    = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM $wpdb->usermeta ORDER BY umeta_id", ARRAY_N );
	$users   = $wpdb->get_col( "SELECT ID FROM $wpdb->users ORDER BY ID" );
	return md5( wp_json_encode( array( $options, $meta, $users ) ) );
}

// Isolation: everything runs in one database transaction that is rolled back at the end.
// If PHP dies before that, MySQL rolls back when the connection closes.
global $wpdb;
$np_engines = $wpdb->get_col( "SELECT DISTINCT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('$wpdb->options', '$wpdb->usermeta', '$wpdb->users')" );
if ( array( 'InnoDB' ) !== $np_engines ) {
	WP_CLI::error( 'Tests need InnoDB tables for the rollback. Found: ' . implode( ', ', $np_engines ) );
}
wp_cache_flush();
$np_state_before = np_fingerprint();
$wpdb->query( 'START TRANSACTION' );

// Capture webhook requests. Mailpit API calls pass through.
$GLOBALS['np_requests'] = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		// Mailpit API calls, and tests of the real request, pass through.
		if ( 0 === strpos( $url, 'http://mailpit:' ) || ! empty( $GLOBALS['np_http_passthrough'] ) ) {
			return $pre;
		}
		$GLOBALS['np_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( ! empty( $GLOBALS['np_http_error'] ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		$code = isset( $GLOBALS['np_http_code'] ) ? $GLOBALS['np_http_code'] : 204;
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $code,
				'message' => 204 === $code ? 'No Content' : 'Server Error',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);

// ---------------------------------------------------------------------------------------
np_section( 'Settings' );
np_reset();

$np = NanoPost_Email_Check_Settings::sanitize(
	array(
		'recipient' => ' owner@example.com ',
		'interval'  => 'weekly',
	)
);
np_assert( 'owner@example.com' === $np['recipient'], 'valid recipient is kept, trimmed', $np );
np_assert( 'weekly' === $np['interval'], 'valid interval is kept', $np );

update_option( NanoPost_Email_Check_Settings::OPTION, $np );
$np = NanoPost_Email_Check_Settings::sanitize( array( 'recipient' => 'not-an-email' ) );
np_assert( 'owner@example.com' === $np['recipient'], 'invalid recipient keeps the saved one', $np );
$np = NanoPost_Email_Check_Settings::sanitize( array( 'recipient' => 'first last@example.org' ) );
np_assert( 'owner@example.com' === $np['recipient'], 'address with a space is refused, not changed', $np );
$np = NanoPost_Email_Check_Settings::sanitize( array( 'recipient' => 'first.last+tag@example.org' ) );
np_assert( 'first.last+tag@example.org' === $np['recipient'], 'valid address with + is kept as typed', $np );

$np = NanoPost_Email_Check_Settings::sanitize( array( 'interval' => 'hourly' ) );
np_assert( 'daily' === $np['interval'], 'unknown interval becomes daily', $np );

$np = NanoPost_Email_Check_Settings::sanitize( array( 'recipient' => '' ) );
np_assert( '' === $np['recipient'], 'empty recipient is allowed', $np );
update_option( NanoPost_Email_Check_Settings::OPTION, $np );
np_assert( get_option( 'admin_email' ) === NanoPost_Email_Check_Settings::recipient(), 'empty recipient falls back to admin email' );

foreach ( array( 'http://127.0.0.1/x', 'http://10.0.0.5/x', 'ftp://example.com/x', 'nonsense', 'https://hooks.slack.com/serv ices/x', 'hooks.slack.com/services/x' ) as $np_url ) {
	$np = NanoPost_Email_Check_Settings::sanitize( array( 'webhook_url' => $np_url ) );
	np_assert( '' === $np['webhook_url'], 'webhook URL refused: ' . $np_url, $np['webhook_url'] );
}
$np = NanoPost_Email_Check_Settings::sanitize( array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/x' ) );
np_assert( 'https://hooks.slack.com/services/T/B/x' === $np['webhook_url'], 'public https webhook URL accepted', $np );
$np = NanoPost_Email_Check_Settings::sanitize( array( 'webhook_url' => 'https://chat.googleapis.com/v1/spaces/X/messages?key=k&token=t' ) );
np_assert( 'https://chat.googleapis.com/v1/spaces/X/messages?key=k&token=t' === $np['webhook_url'], 'URL with a query string is kept as typed', $np );

// Simulate bad stored values (the sanitize callback would stop them on a normal save).
$np_bad = static function () {
	return array(
		'recipient' => array( 'x' ),
		'interval'  => array( 'daily' ),
	);
};
add_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_bad );
np_assert( 'daily' === NanoPost_Email_Check_Settings::interval() && get_option( 'admin_email' ) === NanoPost_Email_Check_Settings::recipient(), 'stored arrays do not break the getters' );
remove_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_bad );
$np = NanoPost_Email_Check_Settings::sanitize(
	array(
		'recipient'   => array( 'a@example.com' ),
		'webhook_url' => array( 'x' ),
	)
);
np_assert( '' === $np['webhook_url'], 'array input is ignored', $np );
delete_option( NanoPost_Email_Check_Settings::OPTION );
update_option( NanoPost_Email_Check_Webhook::STATE_OPTION, array( 'last_status' => 'oops' ) );
np_assert( array() === NanoPost_Email_Check_Webhook::state()['last_status'], 'bad webhook state is reset to an array' );
delete_option( NanoPost_Email_Check_Webhook::STATE_OPTION );

// ---------------------------------------------------------------------------------------
np_section( 'Schedule' );
np_reset();

NanoPost_Email_Check_Scheduler::activate();
$np_event = wp_get_scheduled_event( NanoPost_Email_Check_Scheduler::HOOK );
np_assert( $np_event && 'daily' === $np_event->schedule, 'activation schedules a daily event', $np_event );
np_assert( $np_event && abs( $np_event->timestamp - ( time() + DAY_IN_SECONDS ) ) < 60, 'first run is one interval from now' );

update_option(
	NanoPost_Email_Check_Settings::OPTION,
	array(
		'recipient' => '',
		'interval'  => 'twicedaily',
	)
);
$np_event = wp_get_scheduled_event( NanoPost_Email_Check_Scheduler::HOOK );
np_assert( $np_event && 'twicedaily' === $np_event->schedule, 'changing the interval reschedules', $np_event );

$np_before = $np_event->timestamp;
update_option(
	NanoPost_Email_Check_Settings::OPTION,
	array(
		'recipient' => 'other@example.com',
		'interval'  => 'twicedaily',
	)
);
np_assert( wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ) === $np_before, 'saving other settings keeps the next run' );

wp_clear_scheduled_hook( NanoPost_Email_Check_Scheduler::HOOK );
delete_option( NanoPost_Email_Check_Settings::OPTION );
NanoPost_Email_Check_Scheduler::activate();
$np_before = wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK );
add_option( NanoPost_Email_Check_Settings::OPTION, array( 'recipient' => 'first@example.com' ) );
np_assert( wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ) === $np_before, 'first save with the default interval keeps the next run' );

NanoPost_Email_Check_Scheduler::deactivate();
np_assert( false === wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'deactivation removes the event' );

NanoPost_Email_Check_Scheduler::ensure_scheduled();
np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'a missing event is restored' );

// ---------------------------------------------------------------------------------------
np_section( 'Real send through Mailpit' );
np_reset();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'recipient' => 'check@example.com' ) );

// On a site at localhost, WordPress uses wordpress@localhost as From, and PHPMailer refuses it.
add_filter(
	'wp_mail_from',
	static function () {
		return 'wordpress@example.com';
	}
);

$np_mailpit_up = null !== np_mailpit( 'GET', 'info' );
np_assert( $np_mailpit_up, 'Mailpit is reachable' );

if ( $np_mailpit_up ) {
	np_mailpit( 'DELETE', 'messages' );
	np_smtp( true );

	$np_results = NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_SCHEDULED );
	np_assert( 1 === count( $np_results ) && $np_results[0]->passed, 'check passes', $np_results );

	$np_list = np_mailpit( 'GET', 'messages' );
	$np_msg  = isset( $np_list['messages'][0] ) ? $np_list['messages'][0] : array();
	np_assert( 1 === (int) ( $np_list['total'] ?? 0 ), 'one email arrived in Mailpit', $np_list['total'] ?? null );
	np_assert( isset( $np_msg['To'][0]['Address'] ) && 'check@example.com' === $np_msg['To'][0]['Address'], 'sent to the recipient', $np_msg['To'] ?? null );
	np_assert( isset( $np_msg['Subject'] ) && false !== strpos( $np_msg['Subject'], 'Email check: scheduled test' ), 'subject names a scheduled test', $np_msg['Subject'] ?? null );

	if ( isset( $np_msg['ID'] ) ) {
		$np_full = np_mailpit( 'GET', 'message/' . $np_msg['ID'] );
		np_assert( false !== strpos( $np_full['Text'] ?? '', 'tools.php?page=nanopost-email-check' ), 'body links to the plugin screen' );
	}

	np_smtp( false );
	$np_results = NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_MANUAL );
	np_assert( ! $np_results[0]->passed, 'check fails when SMTP is down' );
	np_assert( false !== stripos( $np_results[0]->error, 'connect' ), 'error text comes from wp_mail_failed', $np_results[0]->error );
	np_assert( 0 === strpos( NanoPost_Email_Check_Help::for_failure( $np_results[0] ), 'https://nanopo.st/email-check-plugin/error/#error=' ), 'failure with text links to the decoder' );

	remove_all_actions( 'phpmailer_init' );
}

// ---------------------------------------------------------------------------------------
np_section( 'Failure with no error text' );
np_reset();
add_filter( 'pre_wp_mail', '__return_false' );
$np_results = NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_MANUAL );
remove_filter( 'pre_wp_mail', '__return_false' );
np_assert( ! $np_results[0]->passed && '' === $np_results[0]->error, 'stores an empty error', $np_results[0]->to_array() );
np_assert( '' !== $np_results[0]->error_message(), 'shows a fallback message' );
np_assert( NanoPost_Email_Check_Help::send_failed() === NanoPost_Email_Check_Help::for_failure( $np_results[0] ), 'links to the send-failed page' );

// ---------------------------------------------------------------------------------------
np_section( 'Log' );
np_reset();
for ( $np_i = 1; $np_i <= 35; $np_i++ ) {
	NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'manual', true, '', 'a@example.com', 1000 + $np_i ) );
}
$np_all = NanoPost_Email_Check_Log::all();
np_assert( 30 === count( $np_all ), 'keeps 30 results', count( $np_all ) );
np_assert( 1035 === $np_all[0]->time && 1006 === $np_all[29]->time, 'newest first, oldest dropped' );
np_assert( 1035 === NanoPost_Email_Check_Log::latest()->time, 'latest() is the newest' );
global $wpdb;
$np_autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", NanoPost_Email_Check_Log::OPTION ) );
np_assert( in_array( $np_autoload, array( 'no', 'off' ), true ), 'log option does not autoload', $np_autoload );

// ---------------------------------------------------------------------------------------
np_section( 'Alerts' );
np_reset();
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', false, 'boom' ) );
np_assert( null !== NanoPost_Email_Check_Alerts::failed_result(), 'failed latest result is an alert' );
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', true ) );
np_assert( null === NanoPost_Email_Check_Alerts::failed_result(), 'a pass clears it' );

/**
 * Set the schedule state directly.
 *
 * @param int $since          Schedule start.
 * @param int $last_completed Last finished scheduled check.
 */
function np_schedule_state( $since, $last_completed ) {
	update_option(
		NanoPost_Email_Check_Scheduler::STATE_OPTION,
		array(
			'since'          => $since,
			'last_completed' => $last_completed,
		)
	);
}

NanoPost_Email_Check_Alerts::remember_cron_lock();
wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', NanoPost_Email_Check_Scheduler::HOOK );
np_schedule_state( time() - 9 * DAY_IN_SECONDS, time() - 23 * HOUR_IN_SECONDS );
np_assert( null === NanoPost_Email_Check_Alerts::stale_since(), 'last check finished within the interval: not stale' );
np_schedule_state( time() - 9 * DAY_IN_SECONDS, time() - 47 * HOUR_IN_SECONDS );
np_assert( null === NanoPost_Email_Check_Alerts::stale_since(), 'last check under twice the interval ago: not stale' );
np_schedule_state( time() - 9 * DAY_IN_SECONDS, time() - 3 * DAY_IN_SECONDS );
np_assert( null !== NanoPost_Email_Check_Alerts::stale_since(), 'checks crash: event still in the future, but stale' );
np_assert( time() - 3 * DAY_IN_SECONDS === NanoPost_Email_Check_Alerts::stale_since(), 'stale since the last finished check' );
np_assert( 1 === count( NanoPost_Email_Check_Alerts::active() ), 'one active alert', NanoPost_Email_Check_Alerts::active() );

np_schedule_state( time() - DAY_IN_SECONDS, 0 );
np_assert( null === NanoPost_Email_Check_Alerts::stale_since(), 'grace period before the first scheduled check' );
np_schedule_state( time() - 3 * DAY_IN_SECONDS, 0 );
np_assert( null !== NanoPost_Email_Check_Alerts::stale_since(), 'first check never finished: stale after twice the interval' );

np_schedule_state( time() - 9 * DAY_IN_SECONDS, time() - 3 * DAY_IN_SECONDS );
NanoPost_Email_Check_Alerts::remember_cron_lock();
np_assert( null !== NanoPost_Email_Check_Alerts::stale_since(), 'an old WP-Cron lock does not hide it' );
set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) ) );
np_assert( null === NanoPost_Email_Check_Alerts::stale_since(), 'not stale on the page load that started WP-Cron' );
NanoPost_Email_Check_Alerts::remember_cron_lock();
np_assert( null !== NanoPost_Email_Check_Alerts::stale_since(), 'stale again on the next page load if WP-Cron did not run' );
delete_transient( 'doing_cron' );

add_filter( 'pre_wp_mail', '__return_true' );
NanoPost_Email_Check_Scheduler::run();
remove_filter( 'pre_wp_mail', '__return_true' );
np_assert( abs( NanoPost_Email_Check_Scheduler::last_completed() - time() ) < 5, 'a finished scheduled run records its time' );
np_assert( null === NanoPost_Email_Check_Alerts::stale_since(), 'a finished run clears the stale state' );

wp_clear_scheduled_hook( NanoPost_Email_Check_Scheduler::HOOK );
delete_option( NanoPost_Email_Check_Scheduler::STATE_OPTION );
NanoPost_Email_Check_Scheduler::ensure_scheduled();
np_assert( abs( NanoPost_Email_Check_Scheduler::scheduled_since() - time() ) < 5, 'scheduling records the start of the grace period' );

np_reset();
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'other_check', 'scheduled', false, 'broken' ) );
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', true ) );
$np_failed = NanoPost_Email_Check_Alerts::failed_result();
np_assert( null !== $np_failed && 'other_check' === $np_failed->check, 'a failure of one check is not hidden by a pass of another' );

$np_editor = wp_insert_user(
	array(
		'user_login' => 'np_test_editor',
		'user_pass'  => wp_generate_password(),
		'role'       => 'editor',
	)
);
wp_set_current_user( $np_editor );
ob_start();
NanoPost_Email_Check_Alerts::render();
np_assert( '' === ob_get_clean(), 'editors see no notice' );
wp_set_current_user( 0 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $np_editor );

// ---------------------------------------------------------------------------------------
np_section( 'Webhook' );
np_reset();
add_filter( 'pre_wp_mail', '__return_false' );
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
np_assert( 0 === count( $GLOBALS['np_requests'] ), 'no URL: no request' );

update_option( NanoPost_Email_Check_Settings::OPTION, array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/x' ) );
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
np_assert( 2 === count( $GLOBALS['np_requests'] ), 'every failure sends', count( $GLOBALS['np_requests'] ) );

$np_req  = $GLOBALS['np_requests'][0];
$np_body = json_decode( $np_req['args']['body'], true );
np_assert( 'POST' === $np_req['args']['method'], 'method is POST' );
np_assert( 0 === strpos( $np_req['args']['headers']['Content-Type'], 'application/json' ), 'JSON content type' );
np_assert( array( 'text', 'site', 'status', 'error', 'time' ) === array_keys( $np_body ), 'fields: text, site, status, error, time', array_keys( $np_body ) );
np_assert( 'fail' === $np_body['status'] && home_url() === $np_body['site'], 'status fail, site is home URL', $np_body );
np_assert( 0 === strpos( $np_body['text'], 'Email check failed on ' . home_url() . ': ' ), 'fail text', $np_body['text'] );
np_assert( (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $np_body['time'] ), 'time is ISO 8601 UTC', $np_body['time'] );

remove_filter( 'pre_wp_mail', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
np_assert( 3 === count( $GLOBALS['np_requests'] ), 'one recovery message, then quiet', count( $GLOBALS['np_requests'] ) );
$np_body = json_decode( $GLOBALS['np_requests'][2]['args']['body'], true );
np_assert( 'pass' === $np_body['status'] && '' === $np_body['error'], 'recovery: status pass, empty error', $np_body );

$np_state = NanoPost_Email_Check_Webhook::state();
np_assert( ! empty( $np_state['last_delivery']['ok'] ) && '204 No Content' === $np_state['last_delivery']['detail'], 'last delivery recorded', $np_state['last_delivery'] );

// A recovery message that does not arrive is sent again on the next pass.
remove_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_wp_mail', '__return_false' );
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
remove_filter( 'pre_wp_mail', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
$np_n                    = count( $GLOBALS['np_requests'] );
$GLOBALS['np_http_code'] = 500;
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
$GLOBALS['np_http_code'] = 204;
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
np_assert( 2 === count( $GLOBALS['np_requests'] ) - $np_n, 'failed recovery message is retried', count( $GLOBALS['np_requests'] ) - $np_n );
np_assert( 'pass' === json_decode( end( $GLOBALS['np_requests'] )['args']['body'], true )['status'], 'retry is the recovery message' );

// A failure sent to one URL gets no recovery message at a new URL.
remove_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_wp_mail', '__return_false' );
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
remove_filter( 'pre_wp_mail', '__return_false' );
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'webhook_url' => 'https://hooks.slack.com/services/T/B/other' ) );
add_filter( 'pre_wp_mail', '__return_true' );
$np_n = count( $GLOBALS['np_requests'] );
NanoPost_Email_Check_Plugin::run_checks( 'scheduled' );
remove_filter( 'pre_wp_mail', '__return_true' );
np_assert( count( $GLOBALS['np_requests'] ) === $np_n, 'new URL: no recovery message for an old failure' );

update_option( NanoPost_Email_Check_Settings::OPTION, array( 'webhook_url' => 'https://chat.googleapis.com/v1/spaces/X/messages?key=k' ) );
add_filter( 'pre_wp_mail', '__return_false' );
NanoPost_Email_Check_Plugin::run_checks( 'manual' );
remove_filter( 'pre_wp_mail', '__return_false' );
$np_body = json_decode( end( $GLOBALS['np_requests'] )['args']['body'], true );
np_assert( array( 'text' ) === array_keys( $np_body ), 'Google Chat gets only text', $np_body );

// ---------------------------------------------------------------------------------------
np_section( 'Security: capabilities and nonces' );
np_reset();

/**
 * Thrown in place of wp_die() and redirects, which would end the test run.
 */
class NP_Test_Stop extends Exception {}

add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message, $title = '', $args = array() ) {
			$code = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;
			throw new NP_Test_Stop( 'die:' . $code );
		};
	},
	999
);
add_filter(
	'wp_redirect',
	static function ( $location ) {
		throw new NP_Test_Stop( 'redirect:' . $location );
	},
	999
);

/**
 * Call a handler and report how it ended: "die:<code>", "redirect:<url>" or "returned".
 *
 * @param callable $handler Handler.
 * @return string
 */
function np_outcome( $handler ) {
	try {
		$handler();
		return 'returned';
	} catch ( NP_Test_Stop $e ) {
		return $e->getMessage();
	}
}

/**
 * Set the request nonce, as a form or link would.
 *
 * @param string|null $nonce Nonce, or null for none.
 */
function np_nonce( $nonce ) {
	unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'], $_GET['_wpnonce'] );
	if ( null !== $nonce ) {
		$_REQUEST['_wpnonce'] = $nonce;
	}
}

$np_admin_id  = (int) get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0];
$np_editor_id = wp_insert_user(
	array(
		'user_login' => 'np_sec_editor',
		'user_pass'  => wp_generate_password(),
		'role'       => 'editor',
	)
);
add_filter( 'pre_wp_mail', '__return_true' );
$np_log_count = static function () {
	return count( NanoPost_Email_Check_Log::all() );
};

wp_set_current_user( $np_editor_id );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::SEND_ACTION ) );
np_assert( 'die:403' === np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_send' ) ), 'send: an editor is refused, even with a valid nonce' );
np_assert( 0 === $np_log_count(), 'send: no check ran for the editor' );

wp_set_current_user( $np_admin_id );
np_nonce( null );
np_assert( 0 === strpos( np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_send' ) ), 'die:' ), 'send: no nonce is refused' );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Alerts::DISMISS_ACTION ) );
np_assert( 0 === strpos( np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_send' ) ), 'die:' ), 'send: a nonce for another action is refused' );
np_assert( 0 === $np_log_count(), 'send: no check ran without a valid nonce' );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Admin::SEND_ACTION ) );
$np_out = np_outcome( array( 'NanoPost_Email_Check_Admin', 'handle_send' ) );
np_assert( 0 === strpos( $np_out, 'redirect:' . NanoPost_Email_Check_Admin::page_url() ), 'send: admin with a valid nonce runs and returns to the screen', $np_out );
np_assert( 1 === $np_log_count(), 'send: one check ran' );
remove_filter( 'pre_wp_mail', '__return_true' );

// Dismiss.
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', false, 'x' ) );
$np_key           = array_keys( NanoPost_Email_Check_Alerts::active() )[0];
$_GET['alert']    = $np_key;
$np_dismissed_for = static function ( $user_id ) {
	return get_user_option( NanoPost_Email_Check_Alerts::DISMISSED_META, $user_id );
};

wp_set_current_user( $np_editor_id );
np_nonce( wp_create_nonce( NanoPost_Email_Check_Alerts::DISMISS_ACTION ) );
np_assert( 'die:403' === np_outcome( array( 'NanoPost_Email_Check_Alerts', 'handle_dismiss' ) ), 'dismiss: an editor is refused' );
np_assert( false === $np_dismissed_for( $np_editor_id ), 'dismiss: nothing stored for the editor' );

wp_set_current_user( $np_admin_id );
np_nonce( 'bad' );
np_assert( 0 === strpos( np_outcome( array( 'NanoPost_Email_Check_Alerts', 'handle_dismiss' ) ), 'die:' ), 'dismiss: a bad nonce is refused' );
np_assert( false === $np_dismissed_for( $np_admin_id ), 'dismiss: nothing stored with a bad nonce' );

np_nonce( wp_create_nonce( NanoPost_Email_Check_Alerts::DISMISS_ACTION ) );
$_GET['alert'] = 'failed:1; <script>';
np_outcome( array( 'NanoPost_Email_Check_Alerts', 'handle_dismiss' ) );
np_assert( false === $np_dismissed_for( $np_admin_id ), 'dismiss: an unknown alert key is not stored' );
$_GET['alert'] = $np_key;
np_assert( 0 === strpos( np_outcome( array( 'NanoPost_Email_Check_Alerts', 'handle_dismiss' ) ), 'redirect:' ), 'dismiss: admin with a valid nonce goes back' );
np_assert( array( $np_key ) === $np_dismissed_for( $np_admin_id ), 'dismiss: the active alert is stored', $np_dismissed_for( $np_admin_id ) );
unset( $_GET['alert'] );
np_nonce( null );

np_assert( 'manage_options' === apply_filters( 'option_page_capability_' . NanoPost_Email_Check_Settings::GROUP, 'manage_options' ), 'settings: options.php requires manage_options' );
np_assert( ! user_can( $np_editor_id, 'manage_options' ), 'settings: an editor cannot save them' );
$np_registered = get_registered_settings();
np_assert( isset( $np_registered[ NanoPost_Email_Check_Settings::OPTION ] ) && NanoPost_Email_Check_Settings::GROUP === $np_registered[ NanoPost_Email_Check_Settings::OPTION ]['group'], 'settings: registered in the plugin group only' );

// ---------------------------------------------------------------------------------------
np_section( 'Security: hostile stored values are escaped' );
np_reset();
$np_evil         = '"><script>alert(1)</script><img src=x onerror=alert(1)>\'';
$np_bad_settings = static function () use ( $np_evil ) {
	return array(
		'recipient'   => $np_evil,
		'interval'    => 'daily',
		'webhook_url' => 'javascript:alert(1)//' . $np_evil,
	);
};
add_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_bad_settings );
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'manual', true, '', $np_evil ) );
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', false, $np_evil, $np_evil ) );
update_option(
	NanoPost_Email_Check_Webhook::STATE_OPTION,
	array(
		'last_delivery' => array(
			'time'   => time(),
			'ok'     => false,
			'detail' => $np_evil,
		),
	)
);
wp_set_current_user( $np_admin_id );
NanoPost_Email_Check_Admin::register_settings();
set_current_screen( 'dashboard' );
ob_start();
NanoPost_Email_Check_Alerts::render();
NanoPost_Email_Check_Admin::render();
$np_html = ob_get_clean();
remove_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_bad_settings );

np_assert( false !== strpos( $np_html, 'Recent results' ) && false !== strpos( $np_html, 'Email check failed.' ), 'screen and notice rendered' );
// Parse the HTML as a browser would, and look for what the hostile text tried to create.
$np_dom = new DOMDocument();
libxml_use_internal_errors( true );
$np_dom->loadHTML( '<!doctype html><html><body>' . $np_html . '</body></html>' );
libxml_clear_errors();
$np_xpath   = new DOMXPath( $np_dom );
$np_scripts = 0;
foreach ( $np_dom->getElementsByTagName( 'script' ) as $np_el ) {
	$np_scripts += false !== strpos( $np_el->textContent, 'alert(1)' ) ? 1 : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
np_assert( 0 === $np_scripts, 'no <script> element from stored values' );
np_assert( 0 === $np_xpath->query( '//img[@src="x"]' )->length, 'no <img> element from stored values' );
np_assert( 0 === $np_xpath->query( '//@*[starts-with(translate(name(), "ON", "on"), "on")]' )->length, 'no event-handler attribute on any element' );
np_assert( 0 === $np_xpath->query( '//a[starts-with(normalize-space(translate(@href, "JAVSCRIPT", "javscript")), "javascript:")]' )->length, 'no javascript: link' );
np_assert( false !== strpos( $np_html, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'the hostile text is shown escaped' );

// ---------------------------------------------------------------------------------------
np_section( 'Security: webhook safety when sending' );
np_reset();
$np_webhook_url  = '';
$np_fake_url_opt = static function () use ( &$np_webhook_url ) {
	return array( 'webhook_url' => $np_webhook_url );
};
add_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_fake_url_opt );
$np_fail = new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', false, 'boom' );

// The real request, no capture: wp_safe_remote_post() must refuse these at send time,
// even though the settings check was skipped.
$GLOBALS['np_http_passthrough'] = true;
foreach ( array( 'http://127.0.0.1/hook', 'http://mailpit:8025/api/v1/info' ) as $np_url ) {
	$np_webhook_url = $np_url;
	delete_option( NanoPost_Email_Check_Webhook::STATE_OPTION );
	NanoPost_Email_Check_Webhook::on_result( $np_fail );
	$np_d = NanoPost_Email_Check_Webhook::state()['last_delivery'];
	np_assert( empty( $np_d['ok'] ) && '' !== (string) ( $np_d['detail'] ?? '' ), 'refused at send time: ' . $np_url, $np_d );
	np_assert( array() === NanoPost_Email_Check_Webhook::state()['last_status'], 'a refused message is not counted as sent: ' . $np_url );
}
np_assert( null !== np_mailpit( 'GET', 'info' ), 'the same private host answers a normal request (so the refusal is the safety check)' );
$GLOBALS['np_http_passthrough'] = false;

// The request asks WordPress to refuse unsafe URLs, which core also applies to redirects.
$np_webhook_url = 'https://hooks.slack.com/services/T/B/x';
delete_option( NanoPost_Email_Check_Webhook::STATE_OPTION );
$np_n = count( $GLOBALS['np_requests'] );
NanoPost_Email_Check_Webhook::on_result( $np_fail );
$np_args = end( $GLOBALS['np_requests'] )['args'];
np_assert( ! empty( $np_args['reject_unsafe_urls'] ), 'request is made with reject_unsafe_urls (covers redirects)' );
np_assert( isset( $np_args['timeout'] ) && $np_args['timeout'] <= 5, 'request has a short timeout' );

// A network error is a failed delivery, and the message is sent again next time.
$GLOBALS['np_http_error'] = true;
delete_option( NanoPost_Email_Check_Webhook::STATE_OPTION );
NanoPost_Email_Check_Webhook::on_result( $np_fail );
$np_d = NanoPost_Email_Check_Webhook::state()['last_delivery'];
np_assert( empty( $np_d['ok'] ) && false !== strpos( $np_d['detail'], 'timed out' ), 'network error recorded as a failed delivery', $np_d );
np_assert( array() === NanoPost_Email_Check_Webhook::state()['last_status'], 'network error: not counted as sent' );
$GLOBALS['np_http_error'] = false;
remove_filter( 'pre_option_' . NanoPost_Email_Check_Settings::OPTION, $np_fake_url_opt );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------------------
np_section( 'Help links' );
np_assert( 'https://nanopo.st/email-check-plugin/error/#error=SMTP%20Error%3A%20x%26y' === NanoPost_Email_Check_Help::error( 'SMTP Error: x&y' ), 'error text is rawurlencoded after #' );
np_assert( strlen( rawurldecode( substr( NanoPost_Email_Check_Help::error( str_repeat( 'a', 5000 ) ), strlen( 'https://nanopo.st/email-check-plugin/error/#error=' ) ) ) ) === 4000, 'error text is cut at 4,000 characters' );

// ---------------------------------------------------------------------------------------
np_section( 'Uninstall' );
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'recipient' => 'x@example.com' ) );
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'manual', true ) );
update_option( NanoPost_Email_Check_Webhook::STATE_OPTION, array( 'last_status' => array() ) );
np_schedule_state( time(), time() );
update_user_option( 1, NanoPost_Email_Check_Alerts::DISMISSED_META, array( 'failed:1' ) );
NanoPost_Email_Check_Scheduler::ensure_scheduled();
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'nanopost-email-check/nanopost-email-check.php' );
}
require NANOPOST_EMAIL_CHECK_DIR . 'uninstall.php';
wp_cache_flush();
$np_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE 'nanopost\\_email\\_check%'" );
np_assert( 0 === $np_left, 'no options left', $np_left );
np_assert( false === get_user_option( NanoPost_Email_Check_Alerts::DISMISSED_META, 1 ), 'no dismissed-notice user option left' );
np_assert( false === wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'no scheduled event left' );

// ---------------------------------------------------------------------------------------
// ---------------------------------------------------------------------------------------
np_section( 'Isolation' );
$wpdb->query( 'ROLLBACK' );
wp_cache_flush();
np_assert( np_fingerprint() === $np_state_before, 'the site is exactly as before the run (options, user meta, users, cron)' );

WP_CLI::log( '' );
if ( $GLOBALS['np_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d of %d assertions failed.', $GLOBALS['np_failures'], $GLOBALS['np_count'] ) );
}
WP_CLI::success( sprintf( 'All %d assertions passed.', $GLOBALS['np_count'] ) );
