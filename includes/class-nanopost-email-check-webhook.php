<?php
/**
 * Optional webhook alert.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Posts a JSON message to the URL the owner entered: on every failed check, and once on the
 * first passing check after a failure. With no URL set, it makes no outside requests.
 */
class NanoPost_Email_Check_Webhook {

	const STATE_OPTION = 'nanopost_email_check_webhook_state';
	const TIMEOUT      = 5;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'nanopost_email_check_result', array( __CLASS__, 'on_result' ) );
	}

	/**
	 * Decide whether a result needs a webhook message, and send it.
	 *
	 * @param NanoPost_Email_Check_Result $result Result.
	 */
	public static function on_result( $result ) {
		if ( ! $result instanceof NanoPost_Email_Check_Result ) {
			return;
		}

		$url = NanoPost_Email_Check_Settings::webhook_url();
		if ( '' === $url ) {
			return;
		}

		$state    = self::state();
		$previous = isset( $state['last_status'][ $result->check ] ) ? $state['last_status'][ $result->check ] : '';

		if ( $result->passed && 'fail' !== $previous ) {
			// Passing, and nothing to recover from: stay quiet.
			return;
		}

		$status   = $result->passed ? 'pass' : 'fail';
		$delivery = self::send( $url, self::payload( $result, $url ) );

		$state['last_status'][ $result->check ] = $status;
		$state['last_delivery']                 = $delivery;
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Message body. Google Chat rejects unknown fields, so it gets only "text".
	 *
	 * @param NanoPost_Email_Check_Result $result Result.
	 * @param string                      $url    Webhook URL.
	 * @return array
	 */
	public static function payload( NanoPost_Email_Check_Result $result, $url ) {
		$site = home_url();

		if ( $result->passed ) {
			/* translators: %s: site address. */
			$text = sprintf( __( 'Email check passed again on %s.', 'nanopost-email-check' ), $site );
		} else {
			/* translators: 1: site address, 2: error text. */
			$text = sprintf( __( 'Email check failed on %1$s: %2$s', 'nanopost-email-check' ), $site, $result->error_message() );
		}

		if ( self::is_google_chat( $url ) ) {
			return array( 'text' => $text );
		}

		return array(
			'text'   => $text,
			'site'   => $site,
			'status' => $result->passed ? 'pass' : 'fail',
			'error'  => $result->passed ? '' : $result->error_message(),
			'time'   => gmdate( 'c', $result->time ),
		);
	}

	/**
	 * True for a Google Chat webhook URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_google_chat( $url ) {
		return 'chat.googleapis.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * POST the JSON body. Uses wp_safe_remote_post(), so local and private addresses are refused.
	 *
	 * @param string $url     URL.
	 * @param array  $payload Body.
	 * @return array Delivery record: time, ok, detail.
	 */
	private static function send( $url, $payload ) {
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'time'   => time(),
				'ok'     => false,
				'detail' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'time'   => time(),
			'ok'     => $code >= 200 && $code < 300,
			'detail' => trim( $code . ' ' . wp_remote_retrieve_response_message( $response ) ),
		);
	}

	/**
	 * Stored state: last status sent per check, and the last delivery.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return wp_parse_args(
			$state,
			array(
				'last_status'   => array(),
				'last_delivery' => array(),
			)
		);
	}
}
