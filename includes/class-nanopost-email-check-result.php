<?php
/**
 * Result of one check.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one check run. Stored in the log as an array.
 */
class NanoPost_Email_Check_Result {

	const TRIGGER_SCHEDULED = 'scheduled';
	const TRIGGER_MANUAL    = 'manual';
	const TRIGGER_CLI       = 'cli';

	/**
	 * Error text is cut at this many characters. It is stored in the log, shown on admin
	 * screens and sent to the webhook.
	 */
	const MAX_ERROR_LENGTH = 4000;

	const ANNOUNCE_OK     = 'ok';
	const ANNOUNCE_FAILED = 'failed';
	const ANNOUNCE_ENDED  = 'ended';

	/**
	 * Check id, from NanoPost_Email_Check_Check::id().
	 *
	 * @var string
	 */
	public $check;

	/**
	 * What started the run: scheduled, manual (the plugin screen) or cli (WP-CLI).
	 *
	 * @var string
	 */
	public $trigger;

	/**
	 * True when the check passed.
	 *
	 * @var bool
	 */
	public $passed;

	/**
	 * Error text when the check failed. Empty when it passed, or when it failed and no
	 * error text was given.
	 *
	 * @var string
	 */
	public $error;

	/**
	 * Address the test email went to.
	 *
	 * @var string
	 */
	public $recipient;

	/**
	 * Unix time of the run.
	 *
	 * @var int
	 */
	public $time;

	/**
	 * How the test email was sent, from NanoPost_Email_Check_Mailer::normalize(). Type ''
	 * when not known, for example in results logged before version 1.3.0.
	 *
	 * @var array
	 */
	public $mailer;

	/**
	 * ID of the test in nanoPost, while the site is connected. Empty otherwise.
	 *
	 * @var string
	 */
	public $test_id = '';

	/**
	 * Whether nanoPost took the announce call for this test: ANNOUNCE_OK, ANNOUNCE_FAILED,
	 * ANNOUNCE_ENDED (nanoPost answered 401: the connection had ended, for example the
	 * check moved to another site), or '' when no call was due (not connected).
	 *
	 * @var string
	 */
	public $announce = '';

	/**
	 * Constructor.
	 *
	 * @param string   $check     Check id.
	 * @param string   $trigger   Trigger.
	 * @param bool     $passed    Passed or failed.
	 * @param string   $error     Error text.
	 * @param string   $recipient Recipient address.
	 * @param int|null $time      Unix time. Default: now.
	 * @param array    $mailer    Mailer info. Default: not known.
	 */
	public function __construct( $check, $trigger, $passed, $error = '', $recipient = '', $time = null, $mailer = array() ) {
		$this->check     = (string) $check;
		$this->trigger   = (string) $trigger;
		$this->passed    = (bool) $passed;
		$this->error     = self::cut( (string) $error );
		$this->recipient = (string) $recipient;
		$this->time      = null === $time ? time() : (int) $time;
		$this->mailer    = NanoPost_Email_Check_Mailer::normalize( $mailer );
	}

	/**
	 * Cut text to MAX_ERROR_LENGTH characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function cut( $text ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_ERROR_LENGTH ) : substr( $text, 0, self::MAX_ERROR_LENGTH );
	}

	/**
	 * Error text to show: the stored text, or a fallback when a failure gave none.
	 *
	 * @return string
	 */
	public function error_message() {
		if ( $this->passed || '' !== $this->error ) {
			return $this->error;
		}
		return __( 'wp_mail() returned false and gave no error message.', 'nanopost-email-check' );
	}

	/**
	 * Name of what started the run, for the log.
	 *
	 * @return string
	 */
	public function trigger_label() {
		if ( self::TRIGGER_MANUAL === $this->trigger ) {
			return __( 'Manual', 'nanopost-email-check' );
		}
		if ( self::TRIGGER_CLI === $this->trigger ) {
			return __( 'WP-CLI', 'nanopost-email-check' );
		}
		return __( 'Schedule', 'nanopost-email-check' );
	}

	/**
	 * Array form for storage.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'check'     => $this->check,
			'trigger'   => $this->trigger,
			'passed'    => $this->passed,
			'error'     => $this->error,
			'recipient' => $this->recipient,
			'time'      => $this->time,
			'mailer'    => $this->mailer,
			'test_id'   => $this->test_id,
			'announce'  => $this->announce,
		);
	}

	/**
	 * Build a result from its stored array form.
	 *
	 * @param array $data Stored data.
	 * @return NanoPost_Email_Check_Result
	 */
	public static function from_array( $data ) {
		$data = wp_parse_args(
			(array) $data,
			array(
				'check'     => '',
				'trigger'   => '',
				'passed'    => false,
				'error'     => '',
				'recipient' => '',
				'time'      => 0,
				'mailer'    => array(),
				'test_id'   => '',
				'announce'  => '',
			)
		);

		$result           = new self( $data['check'], $data['trigger'], $data['passed'], $data['error'], $data['recipient'], $data['time'], $data['mailer'] );
		$result->test_id  = is_string( $data['test_id'] ) && preg_match( '/^[a-z0-9]{12}$/', $data['test_id'] ) ? $data['test_id'] : '';
		$result->announce = in_array( $data['announce'], array( self::ANNOUNCE_OK, self::ANNOUNCE_FAILED, self::ANNOUNCE_ENDED ), true ) ? $data['announce'] : '';
		return $result;
	}
}
