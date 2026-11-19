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
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = self::defaults();

		// Keep only known keys, as strings. The option can be changed outside the settings form.
		foreach ( $settings as $key => $default ) {
			if ( isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ) {
				$settings[ $key ] = (string) $stored[ $key ];
			}
		}

		return $settings;
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
		// options.php has already unslashed the input.
		$input  = is_array( $input ) ? $input : array();
		$output = self::get();

		if ( isset( $input['recipient'] ) && is_scalar( $input['recipient'] ) ) {
			$raw = trim( (string) $input['recipient'] );
			// Accept the address only as typed: sanitize_email() would silently turn
			// "first last@example.org" into a different address.
			$recipient = is_email( $raw ) && sanitize_email( $raw ) === $raw ? $raw : '';

			if ( '' === $raw ) {
				$output['recipient'] = '';
			} elseif ( '' !== $recipient && is_email( $recipient ) ) {
				$output['recipient'] = $recipient;
			} else {
				self::error( 'invalid_recipient', __( 'The email address is not valid. The previous address was kept.', 'nanopost-email-check' ) );
			}
		}

		if ( isset( $input['interval'] ) && is_scalar( $input['interval'] ) ) {
			$interval           = sanitize_key( (string) $input['interval'] );
			$output['interval'] = array_key_exists( $interval, self::intervals() ) ? $interval : 'daily';
		}

		if ( isset( $input['webhook_url'] ) && is_scalar( $input['webhook_url'] ) ) {
			$raw = trim( (string) $input['webhook_url'] );
			// Accept the URL only as typed: esc_url_raw() silently drops characters it rejects.
			$url = esc_url_raw( $raw, array( 'https', 'http' ) ) === $raw ? $raw : '';

			if ( '' === $raw ) {
				$output['webhook_url'] = '';
			} elseif ( '' !== $url && wp_http_validate_url( $url ) ) {
				$output['webhook_url'] = $url;
			} else {
				self::error( 'invalid_webhook_url', __( 'The webhook URL is not valid. It must be a public http or https address on port 80, 443 or 8080. The previous URL was kept.', 'nanopost-email-check' ) );
			}
		}

		return $output;
	}

	/**
	 * Show a settings error on the plugin screen. The function exists only in wp-admin; the
	 * option can also be saved from elsewhere, for example WP-CLI.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	private static function error( $code, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message );
		}
	}
}
