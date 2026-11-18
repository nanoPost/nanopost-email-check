<?php
/**
 * Settings.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, registers and sanitizes the plugin settings. All settings are in one option.
 */
class NanoPost_Email_Check_Settings {

	const OPTION = 'nanopost_email_check_settings';
	const GROUP  = 'nanopost_email_check';

	/**
	 * Schedules the owner can choose. All are built into WordPress.
	 *
	 * @return array Schedule name => label.
	 */
	public static function intervals() {
		return array(
			'twicedaily' => __( 'Twice daily', 'nanopost-email-check' ),
			'daily'      => __( 'Daily', 'nanopost-email-check' ),
			'weekly'     => __( 'Weekly', 'nanopost-email-check' ),
		);
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'recipient'   => '',
			'interval'    => 'daily',
			'webhook_url' => '',
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function get() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * The address test emails go to: the saved address, or the site admin email.
	 *
	 * @return string
	 */
	public static function recipient() {
		$settings = self::get();
		return '' !== $settings['recipient'] ? $settings['recipient'] : (string) get_option( 'admin_email' );
	}

	/**
	 * The chosen schedule name.
	 *
	 * @return string
	 */
	public static function interval() {
		$settings = self::get();
		return array_key_exists( $settings['interval'], self::intervals() ) ? $settings['interval'] : 'daily';
	}

	/**
	 * The webhook URL, or an empty string when none is set.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		$settings = self::get();
		return (string) $settings['webhook_url'];
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize submitted settings. An invalid address keeps the saved one and shows an error.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$old    = self::get();
		$output = $old;

		if ( array_key_exists( 'recipient', $input ) ) {
			$recipient = sanitize_email( wp_unslash( $input['recipient'] ) );
			$raw       = trim( (string) wp_unslash( $input['recipient'] ) );

			if ( '' === $raw ) {
				$output['recipient'] = '';
			} elseif ( '' !== $recipient && is_email( $recipient ) ) {
				$output['recipient'] = $recipient;
			} else {
				add_settings_error(
					self::OPTION,
					'invalid_recipient',
					__( 'The email address is not valid. The previous address was kept.', 'nanopost-email-check' )
				);
			}
		}

		if ( array_key_exists( 'interval', $input ) ) {
			$interval           = sanitize_key( wp_unslash( $input['interval'] ) );
			$output['interval'] = array_key_exists( $interval, self::intervals() ) ? $interval : 'daily';
		}

		if ( array_key_exists( 'webhook_url', $input ) ) {
			$raw = trim( (string) wp_unslash( $input['webhook_url'] ) );
			$url = esc_url_raw( $raw, array( 'https', 'http' ) );

			if ( '' === $raw ) {
				$output['webhook_url'] = '';
			} elseif ( '' !== $url && wp_http_validate_url( $url ) ) {
				$output['webhook_url'] = $url;
			} else {
				add_settings_error(
					self::OPTION,
					'invalid_webhook_url',
					__( 'The webhook URL is not valid. It must be a public http or https address. The previous URL was kept.', 'nanopost-email-check' )
				);
			}
		}

		return $output;
	}
}
