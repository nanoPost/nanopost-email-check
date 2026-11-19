<?php
/**
 * Help links to nanopo.st.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * URLs of the help pages. Plain links: the plugin never requests these pages itself.
 *
 * These URLs are fixed. The pages on nanopo.st keep them stable for released versions.
 */
class NanoPost_Email_Check_Help {

	const BASE = 'https://nanopo.st/email-check-plugin/';

	/**
	 * The error decoder reads at most this many characters.
	 */
	const MAX_ERROR_LENGTH = 4000;

	/**
	 * Plugin home page.
	 *
	 * @return string
	 */
	public static function home() {
		return self::BASE;
	}

	/**
	 * What a passing check means, and what it does not.
	 *
	 * @return string
	 */
	public static function pass() {
		return self::BASE . '#what-a-pass-means';
	}

	/**
	 * The general failure page, for when wp_mail() returned false with no error text.
	 *
	 * @return string
	 */
	public static function send_failed() {
		return self::BASE . 'send-failed/';
	}

	/**
	 * Error decoder with the error text. The text goes after "#", so the browser does not
	 * send it to the server. The page reads it in the browser.
	 *
	 * @param string $error Error text.
	 * @return string
	 */
	public static function error( $error ) {
		$error = function_exists( 'mb_substr' ) ? mb_substr( $error, 0, self::MAX_ERROR_LENGTH ) : substr( $error, 0, self::MAX_ERROR_LENGTH );
		return self::BASE . 'error/#error=' . rawurlencode( $error );
	}

	/**
	 * WP-Cron not running.
	 *
	 * @return string
	 */
	public static function cron() {
		return self::BASE . 'cron/';
	}

	/**
	 * Webhook setup.
	 *
	 * @return string
	 */
	public static function webhook() {
		return self::BASE . 'webhook/';
	}

	/**
	 * The right help page for a failed result.
	 *
	 * @param NanoPost_Email_Check_Result $result Failed result.
	 * @return string
	 */
	public static function for_failure( NanoPost_Email_Check_Result $result ) {
		return '' === $result->error ? self::send_failed() : self::error( $result->error );
	}
}
