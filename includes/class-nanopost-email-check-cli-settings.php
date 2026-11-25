<?php
/**
 * WP-CLI command: wp email-check settings.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Show and change the settings of the plugin screen.
 *
 * Settings:
 *
 * * recipient: the address for the test emails, or up to 3 addresses separated by
 *   commas. Empty means the site admin email.
 * * interval: twicedaily, daily or weekly.
 * * webhook_url: the URL for alerts. Empty means no webhook.
 *
 * ## EXAMPLES
 *
 *     $ wp email-check settings get
 *     $ wp email-check settings set interval weekly
 *     Success: Saved interval: "weekly".
 */
class NanoPost_Email_Check_CLI_Settings {

	/**
	 * Show the settings.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Show only this setting, as a plain value: recipient, interval or webhook_url.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp email-check settings get
	 *     $ wp email-check settings get recipient
	 *     $ wp email-check settings get --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function get( $args, $assoc_args ) {
		$settings = self::current();
		$format   = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( isset( $args[0] ) ) {
			if ( ! array_key_exists( $args[0], $settings ) ) {
				WP_CLI::error( NanoPost_Email_Check_Settings::validate( $args[0], '' ) );
			}
			WP_CLI::print_value( $settings[ $args[0] ], array( 'format' => $format ) );
			return;
		}

		if ( in_array( $format, array( 'json', 'yaml' ), true ) ) {
			WP_CLI::print_value( $settings, array( 'format' => $format ) );
			return;
		}

		$rows = array();
		foreach ( $settings as $key => $value ) {
			$rows[] = array(
				'key'    => $key,
				'value'  => $value,
				'source' => NanoPost_Email_Check_Settings::source( $key ),
			);
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'value', 'source' ) );
	}

	/**
	 * Change one setting.
	 *
	 * The value is checked with the same rules as the plugin screen. An invalid value
	 * changes nothing, and the command exits with 1. A new interval reschedules the
	 * checks.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting to change: recipient, interval or webhook_url.
	 *
	 * <value>
	 * : The new value. For interval: twicedaily, daily or weekly. Use "" to clear
	 * recipient or webhook_url.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp email-check settings set recipient owner@example.com
	 *     Success: Saved recipient: "owner@example.com".
	 *
	 *     $ wp email-check settings set recipient "owner@example.com, monitor@example.net"
	 *     Success: Saved recipient: "owner@example.com, monitor@example.net".
	 *
	 *     $ wp email-check settings set webhook_url https://hooks.slack.com/services/T000/B000/XXXX
	 *     Success: Saved webhook_url: "https://hooks.slack.com/services/T000/B000/XXXX".
	 *
	 *     $ wp email-check settings set recipient ""
	 *     Success: Saved recipient: "". Test emails go to the site admin email.
	 *
	 * @param array $args Positional arguments: key and value.
	 */
	public function set( $args ) {
		list( $key, $value ) = $args;

		if ( array_key_exists( $key, NanoPost_Email_Check_Settings::defaults() ) && NanoPost_Email_Check_Settings::locked( $key ) ) {
			$where = NanoPost_Email_Check_Settings::SOURCE_CONSTANT === NanoPost_Email_Check_Settings::source( $key )
				? NanoPost_Email_Check_Settings::constant_name( $key )
				: NanoPost_Email_Check_Settings::FILTER . ' filter';
			/* translators: 1: setting name, 2: constant name or filter name. */
			WP_CLI::error( sprintf( __( 'The setting %1$s is set in code (%2$s) and cannot be changed here.', 'nanopost-email-check' ), $key, $where ) );
		}

		$clean = NanoPost_Email_Check_Settings::validate( $key, $value );
		if ( is_wp_error( $clean ) ) {
			WP_CLI::error( $clean );
		}

		// The stored values only: values set in code must not be copied into the option.
		$settings         = NanoPost_Email_Check_Settings::stored();
		$settings[ $key ] = $clean;
		// Through the option, so the sanitize callback and the reschedule hooks run.
		update_option( NanoPost_Email_Check_Settings::OPTION, $settings );

		if ( self::current()[ $key ] !== $clean ) {
			/* translators: %s: setting name. */
			WP_CLI::error( sprintf( __( 'The setting %s could not be saved.', 'nanopost-email-check' ), $key ) );
		}

		/* translators: 1: setting name, 2: value. */
		$message = sprintf( __( 'Saved %1$s: "%2$s".', 'nanopost-email-check' ), $key, $clean );
		if ( 'recipient' === $key && '' === $clean ) {
			$message .= ' ' . __( 'Test emails go to the site admin email.', 'nanopost-email-check' );
		}
		WP_CLI::success( $message );
	}

	/**
	 * The settings as the plugin uses them: saved values or values set in code, with
	 * defaults filled in.
	 *
	 * @return array
	 */
	private static function current() {
		$settings             = NanoPost_Email_Check_Settings::get();
		$settings['interval'] = NanoPost_Email_Check_Settings::interval();
		return $settings;
	}
}
