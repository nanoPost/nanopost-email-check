<?php
/**
 * Integration tests: mailer diagnostics. Required by tests/integration.php, which defines
 * the helpers (np_assert, np_section, np_reset, np_smtp, np_mailpit, np_xpath,
 * np_render_screen) and runs everything in one rolled-back transaction.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Files.FileName, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

// ---------------------------------------------------------------------------------------
np_section( 'Mailer diagnostics' );
np_reset();
update_option( NanoPost_Email_Check_Settings::OPTION, array( 'recipient' => 'check@example.com' ) );

/**
 * Run the checks once and return the first result.
 *
 * @return NanoPost_Email_Check_Result
 */
function np_diag_run() {
	$results = NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_MANUAL );
	return $results[0];
}

/**
 * Make PHPMailer refuse to send after phpmailer_init, so nothing leaves the site. The
 * mailer set up before stays as it is.
 *
 * @param callable|null $setup Optional setup of the mailer, run first.
 */
function np_diag_no_send( $setup = null ) {
	remove_all_actions( 'phpmailer_init' );
	add_action(
		'phpmailer_init',
		static function ( $mailer ) use ( $setup ) {
			if ( $setup ) {
				$setup( $mailer );
			}
			$mailer->clearAllRecipients();
		}
	);
}

// SMTP: host and port are recorded, credentials are not.
if ( null !== np_mailpit( 'GET', 'info' ) ) {
	np_mailpit( 'DELETE', 'messages' );
	np_smtp( true );
	// A plugin that runs earlier sets other values; the check records the final ones.
	add_action(
		'phpmailer_init',
		static function ( $mailer ) {
			$mailer->Host     = 'wrong.example';
			$mailer->Port     = 2525;
			$mailer->Username = 'np-secret-user';
			$mailer->Password = 'np-secret-pass';
		},
		5
	);
	$np = np_diag_run();
	np_assert( $np->passed, 'SMTP: check passes through Mailpit', $np->to_array() );
	np_assert(
		array(
			'type' => 'smtp',
			'host' => 'mailpit',
			'port' => 1025,
			'name' => '',
			'via'  => '',
		) === $np->mailer,
		'SMTP: the final host and port are recorded',
		$np->mailer
	);
	$np_raw = maybe_serialize( get_option( NanoPost_Email_Check_Log::OPTION ) );
	np_assert( false === strpos( $np_raw, 'np-secret' ), 'SMTP: no user name or password is stored', $np_raw );
	np_assert( false === strpos( wp_json_encode( $np->to_array() ), 'np-secret' ), 'SMTP: no user name or password in the result' );
	np_assert( 'Sends through SMTP mailpit:1025' === NanoPost_Email_Check_Mailer::describe( $np->mailer ), 'SMTP: status text', NanoPost_Email_Check_Mailer::describe( $np->mailer ) );
	np_assert( 'SMTP mailpit:1025' === NanoPost_Email_Check_Mailer::short_label( $np->mailer ), 'SMTP: log text', NanoPost_Email_Check_Mailer::short_label( $np->mailer ) );

	np_smtp( false );
	$np = np_diag_run();
	np_assert( ! $np->passed && 'smtp' === $np->mailer['type'] && 1 === $np->mailer['port'], 'SMTP down: the mailer is recorded with the failure', $np->mailer );
	np_assert( false === strpos( $np->error, 'sendmail' ), 'SMTP down: no sendmail cause is added', $np->error );

	remove_all_actions( 'phpmailer_init' );
	$GLOBALS['phpmailer']->Username = '';
	$GLOBALS['phpmailer']->Password = '';
}

// PHP mail(): recorded when nothing sets up SMTP. The sendmail command is filtered, so the
// test does not depend on the server's sendmail.
$np_cmd_label = null;
$np_cmd       = static function ( $command, $label ) use ( &$np_cmd_label ) {
	$np_cmd_label = $label;
	return '/nonexistent/np-sendmail -t -i';
};
add_filter( 'nanopost_email_check_sendmail_command', $np_cmd, 10, 2 );
np_diag_no_send();
$np = np_diag_run();
np_assert( ! $np->passed && 'mail' === $np->mailer['type'], 'mail(): recorded when no SMTP is set up', $np->to_array() );
np_assert( 'sendmail_path' === $np_cmd_label, 'mail(): the command comes from sendmail_path', $np_cmd_label );
np_assert(
	1 === preg_match( '/^You must provide .+\. \(sendmail_path "\/nonexistent\/np-sendmail -t -i": \/nonexistent\/np-sendmail not found\)$/', $np->error ),
	'mail(): a missing sendmail program is added to the error text',
	$np->error
);
np_assert( false !== strpos( NanoPost_Email_Check_Help::for_failure( $np ), rawurlencode( '/nonexistent/np-sendmail not found)' ) ), 'mail(): the decoder link carries the cause after #' );
np_assert( 'Sends through PHP mail()' === NanoPost_Email_Check_Mailer::describe( $np->mailer ), 'mail(): status text' );
np_assert( 'PHP mail()' === NanoPost_Email_Check_Mailer::short_label( $np->mailer ), 'mail(): log text' );
remove_filter( 'nanopost_email_check_sendmail_command', $np_cmd, 10 );

// sendmail: the command is PHPMailer's Sendmail.
np_diag_no_send(
	static function ( $mailer ) {
		$mailer->isSendmail();
		$mailer->Sendmail = '/nonexistent/sendmail';
	}
);
$np = np_diag_run();
np_assert( 'sendmail' === $np->mailer['type'], 'sendmail: recorded', $np->mailer );
np_assert( false !== strpos( $np->error, '(Sendmail "/nonexistent/sendmail": /nonexistent/sendmail not found)' ), 'sendmail: a missing program is added to the error text', $np->error );
remove_all_actions( 'phpmailer_init' );

// The program check itself.
np_assert( 'sendmail_path "/nonexistent/x -t": /nonexistent/x not found' === NanoPost_Email_Check_Mailer::sendmail_cause( '/nonexistent/x -t' ), 'program check: missing' );
np_assert( 'sendmail_path "' . __FILE__ . ' -t": ' . __FILE__ . ' not executable' === NanoPost_Email_Check_Mailer::sendmail_cause( __FILE__ . ' -t' ), 'program check: not executable', NanoPost_Email_Check_Mailer::sendmail_cause( __FILE__ . ' -t' ) );
np_assert( '' !== NanoPost_Email_Check_Mailer::sendmail_cause( __DIR__ ), 'program check: a directory is not executable' );
np_assert( '' === NanoPost_Email_Check_Mailer::sendmail_cause( '/bin/sh -c true' ), 'program check: an executable program gives no cause' );
np_assert( '' === NanoPost_Email_Check_Mailer::sendmail_cause( 'sendmail -t -i' ), 'program check: a relative program is not guessed at' );
np_assert( '' === NanoPost_Email_Check_Mailer::sendmail_cause( '' ), 'program check: an empty command gives no cause' );

// The cause fits within the 4,000-character cap; the error text is cut, not the cause.
$np_cause  = 'sendmail_path "/x": /x not found';
$np_suffix = ' (sendmail_path "/x": /x not found)';
$np_long   = NanoPost_Email_Check_Mail_Check::add_cause( str_repeat( 'é', 3990 ), $np_cause );
np_assert( NanoPost_Email_Check_Result::MAX_ERROR_LENGTH === mb_strlen( $np_long ) && mb_substr( $np_long, -mb_strlen( $np_suffix ) ) === $np_suffix, 'cause: the whole stays within 4,000 characters and keeps the cause', mb_strlen( $np_long ) );
np_assert( '(sendmail_path "/x": /x not found)' === NanoPost_Email_Check_Mail_Check::add_cause( '', $np_cause ), 'cause: alone when there is no error text' );
np_assert( 'x' === NanoPost_Email_Check_Mail_Check::add_cause( 'x', '' ), 'cause: nothing added when there is none' );

// pre_wp_mail short-circuit: recorded as another plugin, named when only one callback is hooked.
global $wp_filter;
$np_saved_pre = isset( $wp_filter['pre_wp_mail'] ) ? $wp_filter['pre_wp_mail'] : null;
remove_all_filters( 'pre_wp_mail' );
$np_pre = static function () {
	return true;
};
add_filter( 'pre_wp_mail', $np_pre );
$np = np_diag_run();
np_assert( $np->passed, 'pre_wp_mail: the check passes with the filter value' );
np_assert( 'plugin' === $np->mailer['type'] && 'pre_wp_mail' === $np->mailer['via'], 'pre_wp_mail: recorded as handled by a plugin', $np->mailer );
// This file is inside the plugin folder, so the closure belongs to this plugin.
np_assert( 'nanoPost Email Check' === $np->mailer['name'], 'pre_wp_mail: the one callback is named by its plugin', $np->mailer );
np_assert( 'Sending is handled by nanoPost Email Check, through the pre_wp_mail filter' === NanoPost_Email_Check_Mailer::describe( $np->mailer ), 'pre_wp_mail: status text', NanoPost_Email_Check_Mailer::describe( $np->mailer ) );
np_assert( 'Plugin: nanoPost Email Check' === NanoPost_Email_Check_Mailer::short_label( $np->mailer ), 'pre_wp_mail: log text' );

add_filter( 'pre_wp_mail', '__return_null', 5 );
$np = np_diag_run();
np_assert( 'plugin' === $np->mailer['type'] && '' === $np->mailer['name'], 'pre_wp_mail: no name when more than one callback is hooked', $np->mailer );
remove_all_filters( 'pre_wp_mail' );

add_filter( 'pre_wp_mail', '__return_false' );
$np = np_diag_run();
np_assert( ! $np->passed && 'plugin' === $np->mailer['type'] && '' === $np->mailer['name'], 'pre_wp_mail: a WordPress function is not named as a plugin', $np->mailer );
np_assert( '' === $np->error, 'pre_wp_mail: no sendmail cause for a plugin', $np->error );
np_assert( 'Sending is handled by another plugin, through the pre_wp_mail filter' === NanoPost_Email_Check_Mailer::describe( $np->mailer ), 'pre_wp_mail: status text without a name' );
remove_all_filters( 'pre_wp_mail' );
if ( null !== $np_saved_pre ) {
	$wp_filter['pre_wp_mail'] = $np_saved_pre; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the hooks saved above.
}

// A plugin that swaps in its own PHPMailer subclass and sends by API, while Mailer reads
// "mail" (as SMTP2GO does): recorded as handled by that plugin, with no sendmail cause.
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/class-nanopost-email-check-test-api-mailer.php';
global $phpmailer;
$np_saved_mailer = $phpmailer;
$np_swap         = static function ( $atts ) {
	$GLOBALS['phpmailer'] = new NanoPost_Email_Check_Test_Api_Mailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- What the plugin under test does.
	return $atts;
};
$np_is_mail      = static function ( $mailer ) {
	$mailer->isMail();
};
add_filter( 'wp_mail', $np_swap );
add_action( 'phpmailer_init', $np_is_mail, 1000 );
$np = np_diag_run();
remove_filter( 'wp_mail', $np_swap );
remove_action( 'phpmailer_init', $np_is_mail, 1000 );
$phpmailer = $np_saved_mailer; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the instance saved above.
np_assert( $np->passed && 'plugin' === $np->mailer['type'] && 'phpmailer' === $np->mailer['via'], 'PHPMailer subclass: recorded as handled by a plugin, not as mail()', $np->mailer );
np_assert( 'nanoPost Email Check' === $np->mailer['name'], 'PHPMailer subclass: named by the plugin of its class file', $np->mailer );
np_assert( 'Sending is handled by nanoPost Email Check, through its own PHPMailer class' === NanoPost_Email_Check_Mailer::describe( $np->mailer ), 'PHPMailer subclass: status text', NanoPost_Email_Check_Mailer::describe( $np->mailer ) );

// A wp_mail() that never runs pre_wp_mail is not core's. Core's own file names no plugin.
$np_info = ( new NanoPost_Email_Check_Mailer() )->info();
np_assert( 'plugin' === $np_info['type'] && 'wp_mail' === $np_info['via'] && '' === $np_info['name'], 'replaced wp_mail(): recorded, and WordPress itself is not named', $np_info );
np_assert( 'Sending is handled by another plugin, which replaces wp_mail()' === NanoPost_Email_Check_Mailer::describe( $np_info ), 'replaced wp_mail(): status text' );

// Other values.
np_assert(
	'Sends through SMTP smtp.example.com:465' === NanoPost_Email_Check_Mailer::describe(
		array(
			'type' => 'smtp',
			'host' => 'smtp.example.com:465',
			'port' => 25,
		)
	),
	'a port in the host is not repeated'
);
np_assert(
	'Sends through the "sendgrid" mailer' === NanoPost_Email_Check_Mailer::describe(
		array(
			'type' => 'other',
			'name' => 'sendgrid',
		)
	),
	'another PHPMailer mailer is named'
);
np_assert(
	array(
		'type' => '',
		'host' => '',
		'port' => 0,
		'name' => '',
		'via'  => '',
	) === NanoPost_Email_Check_Mailer::normalize( array( 'type' => 'evil' ) ),
	'an unknown type is not kept'
);
np_assert(
	0 === NanoPost_Email_Check_Mailer::normalize(
		array(
			'type' => 'smtp',
			'port' => 70000,
		)
	)['port'],
	'a port out of range is not kept'
);

// Results logged before this field existed load with no notice and show no mailer.
np_reset();
update_option(
	NanoPost_Email_Check_Log::OPTION,
	array(
		array(
			'check'     => 'wp_mail',
			'trigger'   => 'scheduled',
			'passed'    => false,
			'error'     => 'Could not instantiate mail function.',
			'recipient' => 'old@example.com',
			'time'      => time() - 60,
		),
		array(
			'check'  => 'wp_mail',
			'passed' => true,
			'mailer' => 'not an array',
		),
	),
	false
);
$np_old = NanoPost_Email_Check_Log::all();
np_assert( 2 === count( $np_old ) && '' === $np_old[0]->mailer['type'] && '' === $np_old[1]->mailer['type'], 'old log rows load with an unknown mailer', $np_old[0]->mailer );
np_assert( '' === NanoPost_Email_Check_Mailer::describe( $np_old[0]->mailer ) && '' === NanoPost_Email_Check_Mailer::short_label( $np_old[0]->mailer ), 'old log rows: no mailer text' );
np_assert( 'Could not instantiate mail function.' === $np_old[0]->error, 'old log rows: error text unchanged' );

$np_diag_admin = (int) get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0];
wp_set_current_user( $np_diag_admin );
$np_x = np_xpath( np_render_screen() );
np_assert( 0 === $np_x->query( '//div[@id="nanopost-email-check-status"]//th[.="Mailer"]' )->length, 'old log rows: no Mailer row on the screen' );
np_assert( 2 === $np_x->query( '//div[@id="nanopost-email-check-log"]//tbody/tr' )->length, 'old log rows: the log shows them' );

// The screen shows the mailer, escaped.
np_reset();
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'manual', true, '', 'a@example.com', time() - 120, array( 'type' => 'mail' ) ) );
NanoPost_Email_Check_Log::add(
	new NanoPost_Email_Check_Result(
		'wp_mail',
		'manual',
		true,
		'',
		'a@example.com',
		time() - 60,
		array(
			'type' => 'smtp',
			'host' => 'smtp.example.com',
			'port' => 587,
		)
	)
);
$np_x = np_xpath( np_render_screen() );
np_assert( 'Sends through SMTP smtp.example.com:587' === trim( (string) $np_x->evaluate( 'string(//div[@id="nanopost-email-check-status"]//tr[th="Mailer"]/td)' ) ), 'screen: Status shows the mailer of the last check' );
$np_cells = $np_x->query( '//div[@id="nanopost-email-check-log"]//tbody/tr/td[2]/span' );
np_assert( 2 === $np_cells->length && 'SMTP smtp.example.com:587' === $np_cells->item( 0 )->textContent && 'PHP mail()' === $np_cells->item( 1 )->textContent, 'screen: the log shows each mailer under the result' );

// A repeated mailer is shown once, on the oldest row of the run, and on every failure.
$np_smtp = array(
	'type' => 'smtp',
	'host' => 'smtp.example.com',
	'port' => 587,
);
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', false, 'SMTP Error: x', 'a@example.com', time() - 30, $np_smtp ) );
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', true, '', 'a@example.com', time() - 20, $np_smtp ) );
NanoPost_Email_Check_Log::add( new NanoPost_Email_Check_Result( 'wp_mail', 'scheduled', true, '', 'a@example.com', time() - 10, $np_smtp ) );
$np_x    = np_xpath( np_render_screen() );
$np_rows = $np_x->query( '//div[@id="nanopost-email-check-log"]//tbody/tr' );
$np_seen = array();
foreach ( $np_rows as $np_row ) {
	$np_seen[] = $np_x->query( './td[2]/span', $np_row )->length;
}
np_assert( array( 0, 0, 1, 1, 1 ) === $np_seen, 'screen: the log repeats a mailer only on failures and changes', $np_seen );

$np_evil = 'Evil "q" & <b>bold</b><img src=x onerror=alert(1)><script>alert(1)</script>';
NanoPost_Email_Check_Log::add(
	new NanoPost_Email_Check_Result(
		'wp_mail',
		'manual',
		true,
		'',
		'a@example.com',
		time(),
		array(
			'type' => 'plugin',
			'name' => $np_evil,
		)
	)
);
NanoPost_Email_Check_Log::add(
	new NanoPost_Email_Check_Result(
		'wp_mail',
		'manual',
		true,
		'',
		'a@example.com',
		time(),
		array(
			'type' => 'smtp',
			'host' => $np_evil,
			'port' => 25,
		)
	)
);
$np_html = np_render_screen();
$np_x    = np_xpath( $np_html );
np_assert( 0 === $np_x->query( '//div[@id="nanopost-email-check-status" or @id="nanopost-email-check-log"]//b | //img[@src="x"] | //script[contains(., "alert(1)")]' )->length, 'screen: no element from a hostile mailer value' );
np_assert( 0 === $np_x->query( '//@*[starts-with(translate(name(), "ON", "on"), "on")]' )->length, 'screen: no event-handler attribute' );
np_assert( false !== strpos( $np_html, 'Sends through SMTP Evil &quot;q&quot; &amp; bold' ) && false !== strpos( $np_html, 'Plugin: Evil &quot;q&quot; &amp; bold' ), 'screen: hostile mailer values are shown as text, escaped', $np_html );

np_reset();
