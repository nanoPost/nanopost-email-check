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
		$recipient = NanoPost_Email_Check_Settings::recipient();
		$time      = time();

		if ( ! is_email( $recipient ) ) {
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
		$sent = (bool) wp_mail( $recipient, $this->subject( $trigger ), $this->message( $trigger, $time ) );
		remove_action( 'wp_mail_failed', $catch );
		$watch->stop();

		$mailer = $watch->info();
		if ( ! $sent ) {
			$error = self::add_cause( $error, $watch->failure_cause( $mailer ) );
		}

		return new NanoPost_Email_Check_Result( $this->id(), $trigger, $sent, $sent ? '' : $error, $recipient, $time, $mailer );
	}

	/**
	 * Add a cause in brackets after the error text. The error text is cut, not the cause,
	 * so the whole stays within NanoPost_Email_Check_Result::MAX_ERROR_LENGTH.
	 *
	 * @param string $error Error text.
	 * @param string $cause Cause. Nothing is added when empty.
	 * @return string
	 */
	public static function add_cause( $error, $cause ) {
		if ( '' === $cause ) {
			return $error;
		}
		$suffix = '(' . $cause . ')';
		if ( '' === $error ) {
			return $suffix;
		}
		$suffix = ' ' . $suffix;
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $suffix ) : strlen( $suffix );
		$room   = max( 0, NanoPost_Email_Check_Result::MAX_ERROR_LENGTH - $length );
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

		return NanoPost_Email_Check_Result::TRIGGER_MANUAL === $trigger
			/* translators: %s: site name. */
			? sprintf( __( '[%s] Email check: manual test', 'nanopost-email-check' ), $site )
			/* translators: %s: site name. */
			: sprintf( __( '[%s] Email check: scheduled test', 'nanopost-email-check' ), $site );
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
			NanoPost_Email_Check_Result::TRIGGER_MANUAL === $trigger
				? __( 'Started by: a user on the plugin screen', 'nanopost-email-check' )
				: __( 'Started by: the schedule', 'nanopost-email-check' ),
			'',
			/* translators: %s: URL of the plugin screen. */
			sprintf( __( 'Settings and results: %s', 'nanopost-email-check' ), NanoPost_Email_Check_Admin::page_url() ),
		);

		return implode( "\n", $lines );
	}
}
