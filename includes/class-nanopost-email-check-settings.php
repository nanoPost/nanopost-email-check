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
	 * Sanitize submitted settings. An invalid address or URL keeps the saved one and shows
	 * an error. An unknown interval (only possible with a changed form) becomes daily.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		// options.php has already unslashed the input.
		$input  = is_array( $input ) ? $input : array();
		$output = self::get();

		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}

			$value = self::validate( $key, $input[ $key ] );

			if ( ! is_wp_error( $value ) ) {
				$output[ $key ] = $value;
			} elseif ( 'interval' === $key ) {
				$output[ $key ] = 'daily';
			} else {
				self::error( $value->get_error_code(), $value->get_error_message() );
			}
		}

		return $output;
	}

	/**
	 * Check one setting value. Used by the settings screen and by WP-CLI, so both accept
	 * the same values.
	 *
	 * @param string $key   Setting key: recipient, interval or webhook_url.
	 * @param mixed  $value Value as entered.
	 * @return string|WP_Error The value to save, or an error that says why it is refused.
	 */
	public static function validate( $key, $value ) {
		if ( ! array_key_exists( $key, self::defaults() ) ) {
			return new WP_Error(
				'unknown_setting',
				sprintf(
					/* translators: 1: setting name, 2: list of setting names. */
					__( 'Unknown setting "%1$s". Use one of: %2$s.', 'nanopost-email-check' ),
					$key,
					implode( ', ', array_keys( self::defaults() ) )
				)
			);
		}

		$raw = is_scalar( $value ) ? trim( (string) $value ) : null;

		if ( 'recipient' === $key ) {
			// Accept the address only as typed: sanitize_email() would silently turn
			// "first last@example.org" into a different address.
			if ( '' === $raw || ( null !== $raw && is_email( $raw ) && sanitize_email( $raw ) === $raw ) ) {
				return $raw;
			}
			return new WP_Error( 'invalid_recipient', __( 'The email address is not valid. The previous address was kept.', 'nanopost-email-check' ) );
		}

		if ( 'interval' === $key ) {
			$interval = null === $raw ? '' : sanitize_key( $raw );
			if ( array_key_exists( $interval, self::intervals() ) ) {
				return $interval;
			}
			return new WP_Error(
				'invalid_interval',
				sprintf(
					/* translators: %s: list of schedule names. */
					__( 'The interval is not valid. Use one of: %s.', 'nanopost-email-check' ),
					implode( ', ', array_keys( self::intervals() ) )
				)
			);
		}

		// Webhook URL. Accept it only as typed: esc_url_raw() silently drops characters it rejects.
		if ( '' === $raw || ( null !== $raw && esc_url_raw( $raw, array( 'https', 'http' ) ) === $raw && wp_http_validate_url( $raw ) ) ) {
			return $raw;
		}
		return new WP_Error( 'invalid_webhook_url', __( 'The webhook URL is not valid. It must be a public http or https address on port 80, 443 or 8080. The previous URL was kept.', 'nanopost-email-check' ) );
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
