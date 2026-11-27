<?php
/**
 * The wp_mail() hand-off check.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends a test email with wp_mail() and records whether WordPress handed it to the mail
 * server. It does not confirm delivery: the owner sees the email arrive, or not.
 */
class NanoPost_Email_Check_Mail_Check implements NanoPost_Email_Check_Check {

	/**
	 * Check id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wp_mail';
	}

	/**
	 * Check name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Test email sent with wp_mail()', 'nanopost-email-check' );
	}

	/**
	 * Send the test email.
	 *
	 * @param string $trigger Trigger.
	 * @return NanoPost_Email_Check_Result
	 */
	public function run( $trigger ) {
		$recipient  = NanoPost_Email_Check_Settings::recipient();
		$recipients = NanoPost_Email_Check_Settings::recipients();
		$time       = time();
		$test_id    = '';

		// While connected to nanoPost, the test goes only to the check's address, with a
		// new test ID in it. The recipient setting is used again after a disconnect.
		if ( NanoPost_Email_Check_Connection::is_connected() ) {
			$test_id    = NanoPost_Email_Check_Connection::new_test_id();
			$recipient  = NanoPost_Email_Check_Connection::test_address( $test_id );
			$recipients = array( $recipient );
		}

		if ( array() === $recipients || count( $recipients ) !== count( array_filter( $recipients, 'is_email' ) ) ) {
			return new NanoPost_Email_Check_Result(
				$this->id(),
				$trigger,
				false,
				__( 'No valid recipient address. Set one on the plugin screen.', 'nanopost-email-check' ),
				$recipient,
				$time
			);
		}

		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};

		$watch = new NanoPost_Email_Check_Mailer();
		$watch->start();
		add_action( 'wp_mail_failed', $catch );
		$sent = (bool) wp_mail( $recipients, $this->subject( $trigger ), $this->message( $trigger, $time ) );
		remove_action( 'wp_mail_failed', $catch );
		$watch->stop();

		$mailer = $watch->info();
		$cause  = $sent ? '' : $watch->failure_cause( $mailer );

		$result = new NanoPost_Email_Check_Result( $this->id(), $trigger, $sent, $sent ? '' : self::add_cause( $error, $cause ), $recipient, $time, $mailer );

		if ( '' !== $test_id ) {
			$result->test_id  = $test_id;
			$call             = NanoPost_Email_Check_App::announce( $test_id, $time, $trigger, $sent, $sent ? '' : NanoPost_Email_Check_App::announce_error( $error, $cause ) );
			$result->announce = self::announce_outcome( $call );
			if ( $call['ok'] && $sent ) {
				NanoPost_Email_Check_Canary::schedule_fetch( $test_id, $call );
			}
		}

		return $result;
	}

	/**
	 * What the announce answer means for the plugin's own webhook. A 2xx counts as "ok"
	 * only when Email Canary watches (the block says "on"; an answer with no block, from
	 * an app before the block, counts as watching). The block is stored.
	 *
	 * @param array $call Result of the announce call.
	 * @return string A NanoPost_Email_Check_Result::ANNOUNCE_ constant.
	 */
	private static function announce_outcome( $call ) {
		if ( 401 === $call['code'] ) {
			return NanoPost_Email_Check_Result::ANNOUNCE_ENDED;
		}
		if ( ! $call['ok'] ) {
			return NanoPost_Email_Check_Result::ANNOUNCE_FAILED;
		}
		if ( NanoPost_Email_Check_Canary::take( $call['body'] ) && 'on' !== NanoPost_Email_Check_Canary::block()['watching'] ) {
			return NanoPost_Email_Check_Result::ANNOUNCE_UNWATCHED;
		}
		return NanoPost_Email_Check_Result::ANNOUNCE_OK;
	}

	/**
	 * Add a cause in brackets after the error text. The error text is cut, not the cause,
	 * so the whole stays within $max characters.
	 *
	 * @param string $error Error text.
	 * @param string $cause Cause. Nothing is added when empty.
	 * @param int    $max   Most characters. Default NanoPost_Email_Check_Result::MAX_ERROR_LENGTH.
	 * @return string
	 */
	public static function add_cause( $error, $cause, $max = NanoPost_Email_Check_Result::MAX_ERROR_LENGTH ) {
		if ( '' === $cause ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, $max ) : substr( $error, 0, $max );
		}
		$suffix = '(' . $cause . ')';
		if ( '' === $error ) {
			return $suffix;
		}
		$suffix = ' ' . $suffix;
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $suffix ) : strlen( $suffix );
		$room   = max( 0, $max - $length );
		$error  = function_exists( 'mb_substr' ) ? mb_substr( $error, 0, $room ) : substr( $error, 0, $room );
		return $error . $suffix;
	}

	/**
	 * Email subject.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	private function subject( $trigger ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( NanoPost_Email_Check_Result::TRIGGER_MANUAL === $trigger ) {
			/* translators: %s: site name. */
			return sprintf( __( '[%s] Email check: manual test', 'nanopost-email-check' ), $site );
		}
		if ( NanoPost_Email_Check_Result::TRIGGER_CLI === $trigger ) {
			/* translators: %s: site name. */
			return sprintf( __( '[%s] Email check: WP-CLI test', 'nanopost-email-check' ), $site );
		}
		/* translators: %s: site name. */
		return sprintf( __( '[%s] Email check: scheduled test', 'nanopost-email-check' ), $site );
	}

	/**
	 * The "Started by" line of the email body.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	private function started_by( $trigger ) {
		if ( NanoPost_Email_Check_Result::TRIGGER_MANUAL === $trigger ) {
			return __( 'Started by: a user on the plugin screen', 'nanopost-email-check' );
		}
		if ( NanoPost_Email_Check_Result::TRIGGER_CLI === $trigger ) {
			return __( 'Started by: WP-CLI', 'nanopost-email-check' );
		}
		return __( 'Started by: the schedule', 'nanopost-email-check' );
	}

	/**
	 * Email body, plain text.
	 *
	 * @param string $trigger Trigger.
	 * @param int    $time    Unix time of the run.
	 * @return string
	 */
	private function message( $trigger, $time ) {
		$lines = array(
			/* translators: %s: site address. */
			sprintf( __( 'This is a test email from nanoPost Email Check on %s.', 'nanopost-email-check' ), home_url( '/' ) ),
			'',
			__( 'If you can read this, your site was able to send email.', 'nanopost-email-check' ),
			'',
			/* translators: %s: date and time. */
			sprintf( __( 'Sent: %s', 'nanopost-email-check' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			$this->started_by( $trigger ),
			'',
			/* translators: %s: URL of the plugin screen. */
			sprintf( __( 'Settings and results: %s', 'nanopost-email-check' ), NanoPost_Email_Check_Admin::page_url() ),
		);

		return implode( "\n", $lines );
	}
}
