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

	/**
	 * Error text is cut at this many characters. It is stored in the log, shown on admin
	 * screens and sent to the webhook.
	 */
	const MAX_ERROR_LENGTH = 4000;

	/**
	 * Check id, from NanoPost_Email_Check_Check::id().
	 *
	 * @var string
	 */
	public $check;

	/**
	 * What started the run: scheduled or manual.
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
	 * Constructor.
	 *
	 * @param string   $check     Check id.
	 * @param string   $trigger   Trigger.
	 * @param bool     $passed    Passed or failed.
	 * @param string   $error     Error text.
	 * @param string   $recipient Recipient address.
	 * @param int|null $time      Unix time. Default: now.
	 */
	public function __construct( $check, $trigger, $passed, $error = '', $recipient = '', $time = null ) {
		$this->check     = (string) $check;
		$this->trigger   = (string) $trigger;
		$this->passed    = (bool) $passed;
		$this->error     = self::cut( (string) $error );
		$this->recipient = (string) $recipient;
		$this->time      = null === $time ? time() : (int) $time;
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
			)
		);

		return new self( $data['check'], $data['trigger'], $data['passed'], $data['error'], $data['recipient'], $data['time'] );
	}
}
