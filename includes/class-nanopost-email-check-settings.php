<?php
/**
 * Settings.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, registers and sanitizes the plugin settings. All stored settings are in one option.
 * Constants and a filter can set them from code.
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
	 * Constant for each setting. Defined in wp-config.php or a must-use plugin, a constant
	 * wins over the stored value.
	 */
	const CONSTANTS = array(
		'recipient'   => 'NANOPOST_EMAIL_CHECK_RECIPIENT',
		'interval'    => 'NANOPOST_EMAIL_CHECK_INTERVAL',
		'webhook_url' => 'NANOPOST_EMAIL_CHECK_WEBHOOK_URL',
	);

	const SOURCE_OPTION   = 'option';
	const SOURCE_CONSTANT = 'constant';
	const SOURCE_FILTER   = 'filter';

	const FILTER = 'nanopost_email_check_settings';

	/**
	 * Webhook URLs already checked in this request: URL => valid. The check can look up
	 * the host name, so it runs once per URL.
	 *
	 * @var array
	 */
	private static $checked_urls = array();

	/**
	 * Effective settings: the stored option, then the constants, then the
	 * nanopost_email_check_settings filter. Values from code are checked like the form
	 * input; an invalid one is ignored (see errors()).
	 *
	 * @return array
	 */
	public static function get() {
		return self::resolve()['settings'];
	}

	/**
	 * Stored settings, with defaults filled in. Ignores constants and the filter.
	 *
	 * @return array
	 */
	public static function stored() {
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
	 * Where the effective value of a setting comes from.
	 *
	 * @param string $key Setting key.
	 * @return string SOURCE_OPTION, SOURCE_CONSTANT or SOURCE_FILTER.
	 */
	public static function source( $key ) {
		$sources = self::resolve()['sources'];
		return isset( $sources[ $key ] ) ? $sources[ $key ] : self::SOURCE_OPTION;
	}

	/**
	 * True when a valid constant or the filter sets the value. The plugin screen then shows
	 * it read-only, and saving the screen keeps the stored value.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function locked( $key ) {
		return self::SOURCE_OPTION !== self::source( $key );
	}

	/**
	 * Name of the constant for a setting, or an empty string for an unknown key.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function constant_name( $key ) {
		return isset( self::CONSTANTS[ $key ] ) ? self::CONSTANTS[ $key ] : '';
	}

	/**
	 * Values from code that were ignored because they are not valid.
	 *
	 * @return array[] List of arrays: key, source (SOURCE_CONSTANT or SOURCE_FILTER), name
	 *                 (constant or filter name), message (translated, plain text).
	 */
	public static function errors() {
		return self::resolve()['errors'];
	}

	/**
	 * Build the effective settings, their sources and the errors.
	 *
	 * @return array{settings: array, sources: array, errors: array}
	 */
	private static function resolve() {
		$settings = self::stored();
		$sources  = array_fill_keys( array_keys( $settings ), self::SOURCE_OPTION );
		$errors   = array();

		foreach ( self::CONSTANTS as $key => $name ) {
			$raw = self::constant_value( $name );
			if ( null === $raw ) {
				continue;
			}
			$value = self::validate( $key, $raw );
			if ( null === $value ) {
				$errors[] = self::invalid( $key, self::SOURCE_CONSTANT );
				continue;
			}
			$settings[ $key ] = $value;
			$sources[ $key ]  = self::SOURCE_CONSTANT;
		}

		/**
		 * Filters the effective settings. Runs after the constants. A changed value is
		 * checked like the form input and shown read-only on the plugin screen; an invalid
		 * one is ignored and reported there.
		 *
		 * @param array $settings recipient (empty = site admin email), interval
		 *                        (twicedaily, daily or weekly) and webhook_url (empty = none).
		 */
		$filtered = apply_filters( 'nanopost_email_check_settings', $settings );
		$filtered = is_array( $filtered ) ? $filtered : array();

		foreach ( $settings as $key => $current ) {
			if ( ! array_key_exists( $key, $filtered ) || ( is_scalar( $filtered[ $key ] ) && (string) $filtered[ $key ] === $current ) ) {
				continue;
			}
			$value = self::validate( $key, $filtered[ $key ] );
			if ( null === $value ) {
				$errors[] = self::invalid( $key, self::SOURCE_FILTER );
				continue;
			}
			if ( $value !== $current ) {
				$settings[ $key ] = $value;
				$sources[ $key ]  = self::SOURCE_FILTER;
			}
		}

		return array(
			'settings' => $settings,
			'sources'  => $sources,
			'errors'   => $errors,
		);
	}

	/**
	 * Value of a settings constant, or null when it is not defined.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	private static function constant_value( $name ) {
		$value = defined( $name ) ? constant( $name ) : null;

		/**
		 * Filters the value read from a settings constant. Null means "not defined". Exists
		 * so tests can stand in for constants, which cannot be undefined; to set values from
		 * code, use the constants or the nanopost_email_check_settings filter.
		 *
		 * @param mixed  $value Constant value, or null.
		 * @param string $name  Constant name.
		 */
		return apply_filters( 'nanopost_email_check_constant', $value, $name );
	}

	/**
	 * Check one value with the rules of the settings form.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $raw Value.
	 * @return string|null The value to use, or null when it is not valid.
	 */
	public static function validate( $key, $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$raw = trim( (string) $raw );

		if ( 'recipient' === $key ) {
			// Accept the address only as typed: sanitize_email() would silently turn
			// "first last@example.org" into a different address.
			return '' === $raw || ( is_email( $raw ) && sanitize_email( $raw ) === $raw ) ? $raw : null;
		}

		if ( 'interval' === $key ) {
			$interval = sanitize_key( $raw );
			return array_key_exists( $interval, self::intervals() ) ? $interval : null;
		}

		if ( 'webhook_url' === $key ) {
			if ( '' === $raw ) {
				return '';
			}
			if ( ! isset( self::$checked_urls[ $raw ] ) ) {
				// Accept the URL only as typed: esc_url_raw() silently drops characters it rejects.
				self::$checked_urls[ $raw ] = esc_url_raw( $raw, array( 'https', 'http' ) ) === $raw && false !== wp_http_validate_url( $raw );
			}
			return self::$checked_urls[ $raw ] ? $raw : null;
		}

		return null;
	}

	/**
	 * Error record for an ignored value from code.
	 *
	 * @param string $key    Setting key.
	 * @param string $source SOURCE_CONSTANT or SOURCE_FILTER.
	 * @return array
	 */
	private static function invalid( $key, $source ) {
		if ( self::SOURCE_CONSTANT === $source ) {
			$name  = self::CONSTANTS[ $key ];
			$label = $name;
		} else {
			$name = self::FILTER;
			/* translators: 1: setting key such as "recipient", 2: filter name. */
			$label = sprintf( __( 'The "%1$s" value from the %2$s filter', 'nanopost-email-check' ), $key, $name );
		}

		if ( 'recipient' === $key ) {
			$reason = __( 'It is not a valid email address.', 'nanopost-email-check' );
		} elseif ( 'interval' === $key ) {
			/* translators: %s: list of schedule names. */
			$reason = sprintf( __( 'It must be one of: %s.', 'nanopost-email-check' ), implode( ', ', array_keys( self::intervals() ) ) );
		} else {
			$reason = __( 'It must be a public http or https address on port 80, 443 or 8080.', 'nanopost-email-check' );
		}

		return array(
			'key'     => $key,
			'source'  => $source,
			'name'    => $name,
			/* translators: 1: constant name or filter description, 2: reason. */
			'message' => sprintf( __( '%1$s is ignored. %2$s', 'nanopost-email-check' ), $label, $reason ),
		);
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
	 * Sanitize submitted settings. An invalid value keeps the saved one and shows an error.
	 * A setting locked by a constant or the filter keeps its stored value and is not checked.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		// options.php has already unslashed the input.
		$input  = is_array( $input ) ? $input : array();
		$output = self::stored();

		foreach ( array_keys( $output ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) || self::locked( $key ) ) {
				continue;
			}
			$value = self::validate( $key, $input[ $key ] );

			if ( null !== $value ) {
				$output[ $key ] = $value;
			} elseif ( 'interval' === $key ) {
				$output[ $key ] = 'daily';
			} elseif ( 'recipient' === $key ) {
				self::error( 'invalid_recipient', __( 'The email address is not valid. The previous address was kept.', 'nanopost-email-check' ) );
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
