<?php
/**
 * Test double for the integration tests: a PHPMailer subclass that sends "by API", as
 * some mailer plugins do, while its Mailer property still reads "mail".
 *
 * @package NanoPost_Email_Check
 */

/**
 * Overrides postSend() and sends nothing.
 */
class NanoPost_Email_Check_Test_Api_Mailer extends PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Pretend to send by an API.
	 *
	 * @return bool
	 */
	public function postSend() {
		return true;
	}
}
