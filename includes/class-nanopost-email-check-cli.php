<?php
/**
 * WP-CLI command: wp email-check.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run email checks, show their status and send a test alert from the command line.
 *
 * ## EXIT CODES
 *
 * run: 0 when the check passed, 1 when it failed.
 * status: 0 ok, 1 failing, 2 stopped or not scheduled, 3 no check has run yet.
 * test-alert: 0 when the receiver accepted the message, 1 otherwise.
 * settings set: 1 on an invalid value, which changes nothing.
 *
 * ## EXAMPLES
 *
 *     # Send a test email now.
 *     $ wp email-check run
 *     Success: WordPress accepted the test email for owner@example.com. Check that inbox (and the spam folder) to confirm it arrived.
 *
 *     # Show the state for a monitoring script.
 *     $ wp email-check status --format=json
 *
 *     # Set the address for the test emails.
 *     $ wp email-check settings set recipient owner@example.com
 *     Success: Saved recipient: "owner@example.com".
 */
class NanoPost_Email_Check_CLI {

	const STATE_OK            = 'ok';
	const STATE_FAILING       = 'failing';
	const STATE_STOPPED       = 'stopped';
	const STATE_NOT_SCHEDULED = 'not-scheduled';
	const STATE_NOT_CHECKED   = 'not-checked';

	/**
	 * Register the commands. Called only when WP-CLI is running.
	 */
	public static function register() {
		WP_CLI::add_command( 'email-check', __CLASS__ );
		WP_CLI::add_command( 'email-check settings', 'NanoPost_Email_Check_CLI_Settings' );
	}

	/**
	 * Run the checks now, like "Send test now" on the plugin screen.
	 *
	 * Sends one test email to the saved recipient. The result is logged with "WP-CLI" as
	 * the trigger, and it sends a webhook alert like any other check. There is no
	 * once-a-minute limit.
	 *
	 * Exits with 0 when the check passed and 1 when it failed.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp email-check run
	 *     Success: WordPress accepted the test email for owner@example.com. Check that inbox (and the spam folder) to confirm it arrived.
	 */
	public function run() {
		/* translators: %s: recipient email address. */
		WP_CLI::log( sprintf( __( 'Sending a test email to %s ...', 'nanopost-email-check' ), NanoPost_Email_Check_Settings::recipient() ) );
		$results = NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_CLI );

		if ( empty( $results ) ) {
			WP_CLI::error( __( 'No checks ran.', 'nanopost-email-check' ) );
		}

		$failed = array();
		foreach ( $results as $result ) {
			if ( ! $result->passed ) {
				$failed[] = $result;
			}
		}

		if ( empty( $failed ) && '' !== $results[0]->test_id ) {
			$check_url = NanoPost_Email_Check_Connection::get()['check_url'];
			WP_CLI::success(
				sprintf(
					/* translators: 1: test ID, 2: URL of the check in nanoPost. */
					__( 'WordPress accepted the test email for nanoPost (test %1$s). See whether it arrived: %2$s', 'nanopost-email-check' ),
					$results[0]->test_id,
					'' !== $check_url ? $check_url : __( 'the check page in nanoPost', 'nanopost-email-check' )
				)
			);
			if ( NanoPost_Email_Check_Result::ANNOUNCE_OK !== $results[0]->announce ) {
				WP_CLI::warning( __( 'The plugin could not tell nanoPost about this test. nanoPost still records the email if it arrives.', 'nanopost-email-check' ) );
			}
			return;
		}

		if ( empty( $failed ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: %s: recipient email address. */
					__( 'WordPress accepted the test email for %s. Check that inbox (and the spam folder) to confirm it arrived.', 'nanopost-email-check' ),
					$results[0]->recipient
				)
			);
			return;
		}

		foreach ( $failed as $result ) {
			/* translators: %s: error text. */
			WP_CLI::log( sprintf( __( 'Error text: %s', 'nanopost-email-check' ), $result->error_message() ) );
			/* translators: %s: URL of a help page. */
			WP_CLI::log( sprintf( __( 'What this error means: %s', 'nanopost-email-check' ), NanoPost_Email_Check_Help::for_failure( $result, NanoPost_Email_Check_Help::PLACE_CLI ) ) );
			if ( self::sendmail_failure( $result ) ) {
				WP_CLI::log( __( 'Note: a line such as "sh: 1: ...: not found" above comes from the shell that PHP started for the sendmail program. It is the same error.', 'nanopost-email-check' ) );
			}
		}

		WP_CLI::error(
			sprintf(
				/* translators: %s: recipient email address. */
				__( 'The test email to %s failed.', 'nanopost-email-check' ),
				$failed[0]->recipient
			)
		);
	}

	/**
	 * Show whether the checks run and pass.
	 *
	 * States:
	 *
	 * * ok: the last check passed and scheduled checks run on time.
	 * * failing: the last check failed.
	 * * stopped: no scheduled check has finished for more than twice the interval.
	 * * not-scheduled: there is no scheduled event. The next request to the site schedules it again.
	 * * not-checked: the checks are scheduled, but no check has run yet.
	 *
	 * When checks have stopped or are not scheduled, that state is shown even if the last
	 * check failed, because the last result may be old.
	 *
	 * Exits with 0 for ok, 1 for failing, 2 for stopped or not-scheduled, and 3 for not-checked (no check has run yet).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. JSON times are ISO 8601 in UTC.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp email-check status
	 *
	 *     # In a monitoring script: 0 ok, 1 failing, 2 stopped or not scheduled, 3 no check yet.
	 *     $ wp email-check status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) {
		$data   = self::status_data();
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
		} else {
			WP_CLI\Utils\format_items( 'table', self::status_rows( $data ), array( 'field', 'value' ) );
		}

		$code = self::status_exit_code( $data['state'] );
		if ( 0 !== $code ) {
			WP_CLI::halt( $code );
		}
	}

	/**
	 * Post a test alert to the saved webhook URL.
	 *
	 * Works like "Send test alert" on the plugin screen, without the once-a-minute limit.
	 *
	 * The message says that it is a test, and its JSON has "test": true. It does not change
	 * the alert state, so it neither starts nor ends an alert.
	 *
	 * Exits with 0 when the receiver accepted the message (HTTP 2xx), and 1 when it did not
	 * or no webhook URL is saved.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp email-check test-alert
	 *     Success: The test alert was delivered (200 OK). Check that it arrived.
	 *
	 * @subcommand test-alert
	 */
	public function test_alert() {
		$delivery = NanoPost_Email_Check_Webhook::send_test();

		if ( null === $delivery ) {
			WP_CLI::error( __( 'No webhook URL is saved. Save one first: wp email-check settings set webhook_url <url>', 'nanopost-email-check' ) );
		}

		$detail = isset( $delivery['detail'] ) ? (string) $delivery['detail'] : '';

		if ( empty( $delivery['ok'] ) ) {
			/* translators: %s: HTTP status or error text. */
			WP_CLI::error( sprintf( __( 'The test alert was not delivered (%s). Check the webhook URL.', 'nanopost-email-check' ), $detail ) );
		}

		/* translators: %s: HTTP status, such as "200 OK". */
		WP_CLI::success( sprintf( __( 'The test alert was delivered (%s). Check that it arrived.', 'nanopost-email-check' ), $detail ) );
	}

	/**
	 * The status as data. Uses the same rules as the admin notices and the Status section.
	 *
	 * The "stopped" rule ignores the WP-Cron start guard of a page load: that guard needs a
	 * lock recorded on wp_loaded, which is only hooked in wp-admin.
	 *
	 * @return array
	 */
	public static function status_data() {
		$latest   = NanoPost_Email_Check_Log::latest();
		$failed   = NanoPost_Email_Check_Alerts::failed_result();
		$stale    = NanoPost_Email_Check_Alerts::stale_since();
		$next     = NanoPost_Email_Check_Scheduler::next_run();
		$url_set  = '' !== NanoPost_Email_Check_Settings::webhook_url();
		$delivery = NanoPost_Email_Check_Webhook::state()['last_delivery'];

		if ( ! $next ) {
			$state = self::STATE_NOT_SCHEDULED;
		} elseif ( null !== $stale ) {
			$state = self::STATE_STOPPED;
		} elseif ( null !== $failed ) {
			$state = self::STATE_FAILING;
		} elseif ( null === $latest ) {
			$state = self::STATE_NOT_CHECKED;
		} else {
			$state = self::STATE_OK;
		}

		return array(
			'state'         => $state,
			'last_check'    => null === $latest ? null : array(
				'time'      => self::iso_time( $latest->time ),
				'trigger'   => $latest->trigger,
				'passed'    => $latest->passed,
				'error'     => $latest->error_message(),
				'recipient' => $latest->recipient,
				'test_id'   => '' === $latest->test_id ? null : $latest->test_id,
			),
			'failed_check'  => null === $failed ? null : array(
				'check' => $failed->check,
				'time'  => self::iso_time( $failed->time ),
				'error' => $failed->error_message(),
				'help'  => NanoPost_Email_Check_Help::for_failure( $failed, NanoPost_Email_Check_Help::PLACE_CLI ),
			),
			'mailer'        => null === $latest ? null : $latest->mailer,
			'interval'      => NanoPost_Email_Check_Settings::interval(),
			'settings'      => self::settings_sources(),
			'code_errors'   => wp_list_pluck( NanoPost_Email_Check_Settings::errors(), 'message' ),
			'next_run'      => $next ? self::iso_time( (int) $next ) : null,
			'cron'          => self::cron_data(),
			'stopped_since' => null === $stale ? null : self::iso_time( $stale ),
			'webhook'       => array(
				'url_set'       => $url_set,
				'last_delivery' => ! $url_set || empty( $delivery['time'] ) ? null : array(
					'time'   => self::iso_time( (int) $delivery['time'] ),
					'ok'     => ! empty( $delivery['ok'] ),
					'detail' => isset( $delivery['detail'] ) ? (string) $delivery['detail'] : '',
					'test'   => ! empty( $delivery['test'] ),
				),
			),
			'nanopost'      => self::nanopost_data(),
		);
	}

	/**
	 * The connection to the nanoPost app, as data. Never holds the token.
	 *
	 * @return array
	 */
	private static function nanopost_data() {
		$connected = NanoPost_Email_Check_Connection::is_connected();
		$conn      = NanoPost_Email_Check_Connection::get();
		$too_old   = $connected ? NanoPost_Email_Check_Connection::too_old_message() : '';
		$failed    = NanoPost_Email_Check_App::failed_calls();
		$last      = $failed ? end( $failed ) : null;

		if ( ! $connected ) {
			$calls = 'not_connected';
		} elseif ( '' !== $too_old ) {
			$calls = 'stopped_too_old';
		} elseif ( $conn['retry_until'] > time() ) {
			$calls = 'waiting';
		} else {
			$calls = 'on';
		}

		return array(
			'connected'        => $connected,
			'address'          => $connected ? $conn['address'] : null,
			'check_url'        => $connected && '' !== $conn['check_url'] ? $conn['check_url'] : null,
			'calls'            => $calls,
			'message'          => '' !== $too_old ? $too_old : null,
			'retry_until'      => 'waiting' === $calls ? self::iso_time( $conn['retry_until'] ) : null,
			'ended'            => $connected || '' === NanoPost_Email_Check_Connection::ended_reason() ? null : NanoPost_Email_Check_Connection::ended_reason(),
			'last_failed_call' => null === $last ? null : array(
				'time'  => self::iso_time( (int) $last['time'] ),
				'call'  => (string) $last['call'],
				'code'  => (int) $last['code'],
				'error' => (string) $last['error'],
			),
		);
	}

	/**
	 * True when a check failed because the sendmail program for PHP mail(), sendmail or
	 * qmail is missing or not executable. Then the shell PHP starts has printed its own
	 * error line on stderr, which this command cannot hold back.
	 *
	 * @param NanoPost_Email_Check_Result $result Failed result.
	 * @return bool
	 */
	private static function sendmail_failure( $result ) {
		$types = array( NanoPost_Email_Check_Mailer::TYPE_MAIL, NanoPost_Email_Check_Mailer::TYPE_SENDMAIL, NanoPost_Email_Check_Mailer::TYPE_QMAIL );
		return in_array( $result->mailer['type'], $types, true ) && 1 === preg_match( '/\((sendmail_path|Sendmail) ".*": .+ (not found|not executable)\)$/', $result->error );
	}

	/**
	 * Whether WP-Cron runs on this site, as far as the site can tell. It cannot see the
	 * server's cron jobs, but an event that has been due for a while shows that nothing has
	 * run WP-Cron since then.
	 *
	 * @return array disabled (DISABLE_WP_CRON), alternate (ALTERNATE_WP_CRON), and
	 *               oldest_overdue: ISO 8601 time of the oldest event due for more than
	 *               10 minutes, or null.
	 */
	private static function cron_data() {
		$crons  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$oldest = null;
		if ( is_array( $crons ) && $crons ) {
			$first = (int) min( array_keys( $crons ) );
			if ( $first > 0 && $first < time() - 10 * MINUTE_IN_SECONDS ) {
				$oldest = self::iso_time( $first );
			}
		}

		return array(
			'disabled'       => NanoPost_Email_Check_Alerts::wp_cron_disabled(),
			'alternate'      => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'oldest_overdue' => $oldest,
		);
	}

	/**
	 * Where each setting comes from: option, constant or filter. Values are left out: the
	 * webhook URL can hold a secret.
	 *
	 * @return array Setting key => source.
	 */
	private static function settings_sources() {
		$sources = array();
		foreach ( array_keys( NanoPost_Email_Check_Settings::defaults() ) as $key ) {
			$sources[ $key ] = NanoPost_Email_Check_Settings::source( $key );
		}
		return $sources;
	}

	/**
	 * Exit code for a state: 0 ok, 1 failing, 2 stopped or not scheduled, 3 not checked yet.
	 *
	 * @param string $state A STATE_ constant.
	 * @return int
	 */
	public static function status_exit_code( $state ) {
		if ( self::STATE_OK === $state ) {
			return 0;
		}
		if ( self::STATE_NOT_CHECKED === $state ) {
			return 3;
		}
		return self::STATE_FAILING === $state ? 1 : 2;
	}

	/**
	 * The status as table rows for people: site-local times, labels in words. Built from
	 * status_data() only, so the table and the JSON always agree.
	 *
	 * @param array $data From status_data().
	 * @return array[] Rows with "field" and "value".
	 */
	public static function status_rows( $data ) {
		$rows = array();
		$add  = static function ( $field, $value ) use ( &$rows ) {
			$rows[] = array(
				'field' => $field,
				'value' => $value,
			);
		};

		$add( __( 'State', 'nanopost-email-check' ), $data['state'] );

		$last = $data['last_check'];
		if ( null === $last ) {
			$add( __( 'Last check', 'nanopost-email-check' ), __( 'No check has run yet. Run: wp email-check run', 'nanopost-email-check' ) );
		} else {
			$add(
				__( 'Last check', 'nanopost-email-check' ),
				sprintf(
					/* translators: 1: "Passed" or "Failed", 2: date and time, 3: what started the check, such as "WP-CLI". */
					__( '%1$s, %2$s, started by %3$s', 'nanopost-email-check' ),
					$last['passed'] ? __( 'Passed', 'nanopost-email-check' ) : __( 'Failed', 'nanopost-email-check' ),
					NanoPost_Email_Check_Admin::format_time( self::unix_time( $last['time'] ) ),
					NanoPost_Email_Check_Result::from_array( array( 'trigger' => $last['trigger'] ) )->trigger_label()
				)
			);
			$add( __( 'Sent to', 'nanopost-email-check' ), $last['recipient'] );
			$mailer = null === $data['mailer'] ? '' : NanoPost_Email_Check_Mailer::describe( $data['mailer'] );
			if ( '' !== $mailer ) {
				$add( __( 'Mailer', 'nanopost-email-check' ), $mailer );
			}
		}

		if ( null !== $data['failed_check'] ) {
			$add( __( 'Error', 'nanopost-email-check' ), $data['failed_check']['error'] );
			$add( __( 'What this error means', 'nanopost-email-check' ), $data['failed_check']['help'] );
		}

		if ( self::STATE_NOT_SCHEDULED === $data['state'] ) {
			$add( __( 'Scheduled checks', 'nanopost-email-check' ), __( 'Not scheduled. The next request to the site schedules the checks again.', 'nanopost-email-check' ) );
		} elseif ( null !== $data['stopped_since'] ) {
			$add(
				__( 'Scheduled checks', 'nanopost-email-check' ),
				sprintf(
					/* translators: %s: date and time. */
					__( 'Stopped. No scheduled check has finished since %s. WP-Cron may not be running, or the check may be stopping with a PHP error.', 'nanopost-email-check' ),
					NanoPost_Email_Check_Admin::format_date( self::unix_time( $data['stopped_since'] ) )
				)
			);
			if ( NanoPost_Email_Check_Alerts::wp_cron_disabled() ) {
				$add( '', __( 'DISABLE_WP_CRON is set in wp-config.php, so a cron job on the server must run wp-cron.php.', 'nanopost-email-check' ) );
			}
			$add( __( 'How to fix WP-Cron', 'nanopost-email-check' ), NanoPost_Email_Check_Help::cron( NanoPost_Email_Check_Help::PLACE_CLI ) );
		} else {
			$add(
				__( 'Scheduled checks', 'nanopost-email-check' ),
				sprintf(
					/* translators: 1: schedule name such as "Daily", 2: date and time. */
					__( '%1$s. Next check: %2$s', 'nanopost-email-check' ),
					NanoPost_Email_Check_Settings::intervals()[ $data['interval'] ],
					NanoPost_Email_Check_Admin::format_time( self::unix_time( $data['next_run'] ) )
				)
			);
		}

		if ( $data['cron']['disabled'] ) {
			$add( __( 'WP-Cron', 'nanopost-email-check' ), __( 'Disabled in wp-config.php (DISABLE_WP_CRON). Scheduled checks run only when a server cron job runs "wp cron event run --due-now" or requests wp-cron.php. This command cannot see the server\'s cron jobs.', 'nanopost-email-check' ) );
		} else {
			$add( __( 'WP-Cron', 'nanopost-email-check' ), __( 'Enabled. Scheduled checks run when the site gets a visit after they are due.', 'nanopost-email-check' ) );
		}
		if ( null !== $data['cron']['oldest_overdue'] ) {
			$add(
				'',
				sprintf(
					/* translators: %s: date and time. */
					__( 'An event has been due since %s: nothing has run WP-Cron since then.', 'nanopost-email-check' ),
					NanoPost_Email_Check_Admin::format_time( self::unix_time( $data['cron']['oldest_overdue'] ) )
				)
			);
		}

		$in_code = array_keys( array_diff( $data['settings'], array( NanoPost_Email_Check_Settings::SOURCE_OPTION ) ) );
		if ( $in_code ) {
			$add( __( 'Set in code', 'nanopost-email-check' ), implode( ', ', $in_code ) );
		}
		foreach ( $data['code_errors'] as $message ) {
			$add( __( 'Ignored', 'nanopost-email-check' ), $message );
		}

		$delivery = $data['webhook']['last_delivery'];
		if ( ! $data['webhook']['url_set'] ) {
			$webhook = __( 'No webhook URL is saved.', 'nanopost-email-check' );
		} elseif ( null === $delivery ) {
			$webhook = __( 'A URL is saved. No message has been sent yet.', 'nanopost-email-check' );
		} else {
			$args    = array(
				NanoPost_Email_Check_Admin::format_time( self::unix_time( $delivery['time'] ) ),
				$delivery['ok'] ? __( 'delivered', 'nanopost-email-check' ) : __( 'failed', 'nanopost-email-check' ),
				$delivery['detail'],
			);
			$webhook = $delivery['test']
				/* translators: 1: date and time, 2: "delivered" or "failed", 3: HTTP status or error text. */
				? vsprintf( __( 'Last webhook message (test alert): %1$s, %2$s (%3$s).', 'nanopost-email-check' ), $args )
				/* translators: 1: date and time, 2: "delivered" or "failed", 3: HTTP status or error text. */
				: vsprintf( __( 'Last webhook message: %1$s, %2$s (%3$s).', 'nanopost-email-check' ), $args );
		}
		$add( __( 'Webhook', 'nanopost-email-check' ), $webhook );

		$np = $data['nanopost'];
		if ( ! $np['connected'] ) {
			$text = __( 'Not connected. Connect on the plugin screen (Tools > Email Check).', 'nanopost-email-check' );
			if ( null !== $np['ended'] ) {
				$text .= ' ' . ( NanoPost_Email_Check_Connection::ENDED_SITE_CHANGED === $np['ended']
					? __( 'The last connection ended because the site address changed.', 'nanopost-email-check' )
					: __( 'The last connection was ended in nanoPost.', 'nanopost-email-check' ) );
			}
		} else {
			/* translators: %s: the check's email address in nanoPost. */
			$text = sprintf( __( 'Connected. Test emails go to %s.', 'nanopost-email-check' ), $np['address'] );
			if ( 'stopped_too_old' === $np['calls'] ) {
				$text .= ' ' . $np['message'];
			} elseif ( 'waiting' === $np['calls'] ) {
				/* translators: %s: date and time. */
				$text .= ' ' . sprintf( __( 'Calls wait until %s.', 'nanopost-email-check' ), NanoPost_Email_Check_Admin::format_time( self::unix_time( $np['retry_until'] ) ) );
			}
		}
		$add( __( 'nanoPost', 'nanopost-email-check' ), $text );
		if ( null !== $np['check_url'] ) {
			$add( __( 'Check in nanoPost', 'nanopost-email-check' ), $np['check_url'] );
		}

		return $rows;
	}

	/**
	 * ISO 8601 time from status_data() as Unix time.
	 *
	 * @param string $time ISO 8601 time.
	 * @return int
	 */
	private static function unix_time( $time ) {
		return (int) strtotime( $time );
	}

	/**
	 * Unix time as ISO 8601 in UTC.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function iso_time( $time ) {
		return gmdate( 'c', $time );
	}
}
