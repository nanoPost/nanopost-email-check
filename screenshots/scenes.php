<?php
/**
 * Demo state for the screenshots. Fake data only: the repo is public.
 *
 * Run inside wp-env:
 *   wp eval-file wp-content/plugins/nanopost-email-check/screenshots/scenes.php <scene>
 *
 * Scenes: main, failed, stale, reset. Times are relative to now, so "x ago" texts stay the
 * same between runs on one day.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Dev tool, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

$scene = isset( $args[0] ) ? $args[0] : 'main';

if ( 'reset' === $scene ) {
	delete_option( NanoPost_Email_Check_Log::OPTION );
	delete_option( NanoPost_Email_Check_Webhook::STATE_OPTION );
	delete_option( NanoPost_Email_Check_Settings::OPTION );
	delete_option( NanoPost_Email_Check_Scheduler::STATE_OPTION );
	delete_user_option( 1, NanoPost_Email_Check_Alerts::DISMISSED_META );
	NanoPost_Email_Check_Scheduler::reschedule();
	WP_CLI::success( 'Scene reset.' );
	return;
}

update_option( 'blogname', 'Example Shop' );
update_option( 'admin_email', 'owner@example.com' );
update_user_meta( 1, 'show_welcome_panel', 0 );
delete_user_option( 1, NanoPost_Email_Check_Alerts::DISMISSED_META );

// Save the settings without the sanitize callback's URL check (no DNS lookup needed).
remove_all_filters( 'sanitize_option_' . NanoPost_Email_Check_Settings::OPTION );
update_option(
	NanoPost_Email_Check_Settings::OPTION,
	array(
		'recipient'   => '',
		'interval'    => 'daily',
		'webhook_url' => 'https://hooks.slack.com/services/T0000000/B0000000/XXXXXXXXXXXXXXXXXXXX',
	)
);

$now        = time();
$day        = DAY_IN_SECONDS;
$hour       = HOUR_IN_SECONDS;
$last       = $now - 3 * $hour;
$to         = 'owner@example.com';
$smtp_error = 'SMTP Error: Could not authenticate.';

// Oldest first: a week of passes, a failure three days ago, a failed manual retry, the fix,
// then passes again.
$rows = array();
for ( $d = 10; $d >= 4; $d-- ) {
	$rows[] = array( 'scheduled', true, '', $last - $d * $day );
}
$rows[] = array( 'scheduled', false, $smtp_error, $last - 3 * $day );
$rows[] = array( 'manual', false, $smtp_error, $last - 3 * $day + 2 * $hour + 42 * MINUTE_IN_SECONDS );
$rows[] = array( 'manual', true, '', $last - 3 * $day + 2 * $hour + 58 * MINUTE_IN_SECONDS );
for ( $d = 2; $d >= 0; $d-- ) {
	$rows[] = array( 'scheduled', true, '', $last - $d * $day );
}
if ( 'failed' === $scene ) {
	$rows[] = array( 'scheduled', false, $smtp_error, $now - 20 * MINUTE_IN_SECONDS );
}
if ( 'stale' === $scene ) {
	// The last scheduled check ran two days ago; nothing since.
	$rows = array_slice( $rows, 0, -2 );
}

delete_option( NanoPost_Email_Check_Log::OPTION );
foreach ( $rows as $row ) {
	NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', $row[0], $row[1], $row[2], $to, $row[3] ) );
}

update_option(
	NanoPost_Email_Check_Webhook::STATE_OPTION,
	array(
		'last_status'   => array( 'wp_mail' => 'pass' ),
		'last_delivery' => array(
			'time'   => $last - 3 * $day + 2 * $hour + 58 * MINUTE_IN_SECONDS,
			'ok'     => true,
			'detail' => '200 OK',
		),
		'url_hash'      => md5( 'https://hooks.slack.com/services/T0000000/B0000000/XXXXXXXXXXXXXXXXXXXX' ),
	),
	false
);

wp_clear_scheduled_hook( NanoPost_Email_Check_Scheduler::HOOK );
$next = 'stale' === $scene ? $last - $day : $last + $day;
wp_schedule_event( $next, 'daily', NanoPost_Email_Check_Scheduler::HOOK );

// The last finished scheduled check: the newest scheduled row in the log.
update_option(
	NanoPost_Email_Check_Scheduler::STATE_OPTION,
	array(
		'since'          => $last - 11 * $day,
		'last_completed' => 'stale' === $scene ? $last - 2 * $day : $last,
	)
);

WP_CLI::success( "Scene $scene ready." );
