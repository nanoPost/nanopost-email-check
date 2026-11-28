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
	 * Places a link can come from, sent as utm_content: the page title, the Status
	 * section, the settings form, the result log, the two admin notices and WP-CLI.
	 */
	const PLACE_HEADER        = 'header';
	const PLACE_STATUS        = 'status';
	const PLACE_SETTINGS      = 'settings';
	const PLACE_LOG           = 'log';
	const PLACE_NOTICE_FAILED = 'notice-failed';
	const PLACE_NOTICE_STALE  = 'notice-stale';
	const PLACE_CLI           = 'cli';
	const PLACE_CANARY        = 'canary';
	const PLACE_NOTICE_CANARY = 'notice-canary';
	const PLACE_PROOF         = 'proof';

	/**
	 * The app's terms and privacy sections. Plain links, shown next to "Connect".
	 */
	const TERMS_URL   = 'https://nanopo.st/terms/#the-app-appnanopost';
	const PRIVACY_URL = 'https://nanopo.st/privacy/#the-app-appnanopost';

	/**
	 * Plugin home page.
	 *
	 * @param string $place Where the link is shown (a PLACE_ constant).
	 * @return string
	 */
	public static function home( $place = '' ) {
		return self::url( '', $place );
	}

	/**
	 * What a passing check means, and what it does not.
	 *
	 * @param string $place Where the link is shown (a PLACE_ constant).
	 * @return string
	 */
	public static function pass( $place = '' ) {
		return self::url( '', $place, 'what-a-pass-means' );
	}

	/**
	 * The general failure page, for when wp_mail() returned false with no error text.
	 *
	 * @param string $place Where the link is shown (a PLACE_ constant).
	 * @return string
	 */
	public static function send_failed( $place = '' ) {
		return self::url( 'send-failed/', $place );
	}

	/**
	 * Error decoder with the error text. The text goes after "#", so the browser does not
	 * send it to the server. The page reads it in the browser. Only the tags go in the
	 * query string.
	 *
	 * @param string $error Error text.
	 * @param string $place Where the link is shown (a PLACE_ constant).
	 * @return string
	 */
	public static function error( $error, $place = '' ) {
		$error = function_exists( 'mb_substr' ) ? mb_substr( $error, 0, self::MAX_ERROR_LENGTH ) : substr( $error, 0, self::MAX_ERROR_LENGTH );
		return self::url( 'error/', $place, 'error=' . rawurlencode( $error ) );
	}

	/**
	 * WP-Cron not running.
	 *
	 * @param string $place Where the link is shown (a PLACE_ constant).
	 * @return string
	 */
	public static function cron( $place = '' ) {
		return self::url( 'cron/', $place );
	}

	/**
	 * Webhook setup.
	 *
	 * @param string $place Where the link is shown (a PLACE_ constant).
	 * @return string
	 */
	public static function webhook( $place = '' ) {
		return self::url( 'webhook/', $place );
	}

	/**
	 * Anchors on the Email Canary help page. Agreed with the help pages; do not rename.
	 */
	const CANARY_TURN_ON = 'turn-on-email-canary';
	const CANARY_CONFIRM = 'before-you-confirm-alerts-are-off';
	const CANARY_CHANGE  = 'change-the-alert-address';
	const CANARY_STOP    = 'stop-watching';
	const CANARY_SENT    = 'what-is-sent-to-nanopost';
	const CANARY_KEY     = 'turn-on-with-an-account-key';

	/**
	 * A part of the Email Canary help page.
	 *
	 * @param string $anchor A CANARY_ constant.
	 * @param string $place  Where the link is shown.
	 * @return string
	 */
	public static function canary( $anchor, $place = '' ) {
		return self::url( 'canary/', $place, $anchor );
	}

	/**
	 * The proof of arrival after "Send test now".
	 *
	 * @param string $place Where the link is shown.
	 * @return string
	 */
	public static function proof( $place = '' ) {
		return self::canary( self::CANARY_TURN_ON, $place );
	}

	/**
	 * The right help page for a failed result.
	 *
	 * @param NanoPost_Email_Check_Result $result Failed result.
	 * @param string                      $place  Where the link is shown (a PLACE_ constant).
	 * @return string
	 */
	public static function for_failure( NanoPost_Email_Check_Result $result, $place = '' ) {
		return '' === $result->error ? self::send_failed( $place ) : self::error( $result->error, $place );
	}

	/**
	 * Help page URL with the tags that name the plugin and the place of the link. The
	 * query string holds only these fixed tags; anything else goes in the fragment.
	 *
	 * @param string $path     Path below BASE.
	 * @param string $place    Where the link is shown.
	 * @param string $fragment Fragment, without "#".
	 * @return string
	 */
	private static function url( $path, $place, $fragment = '' ) {
		$tags = array(
			'utm_source' => self::PLACE_CLI === $place ? 'wp-cli' : 'wp-admin',
			'utm_medium' => 'email-check',
		);
		if ( '' !== $place ) {
			$tags['utm_content'] = $place;
		}

		return self::BASE . $path . '?' . http_build_query( $tags, '', '&' ) . ( '' === $fragment ? '' : '#' . $fragment );
	}
}
