<?php
/**
 * Admin screen at Tools > Email Check.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings form, "Send test alert", "Send test now" and the result log.
 */
class NanoPost_Email_Check_Admin {

	const PAGE        = 'nanopost-email-check';
	const SEND_ACTION = 'nanopost_email_check_send';
	const CAPABILITY  = 'manage_options';
	const SEND_LOCK   = 'nanopost_email_check_send_lock';

	const TEST_ALERT_ACTION = 'nanopost_email_check_test_alert';
	const TEST_ALERT_LOCK   = 'nanopost_email_check_test_alert_lock';
	const TEST_ALERT_FORM   = 'nanopost-email-check-test-alert';

	const REVIEW_URL = 'https://wordpress.org/support/plugin/nanopost-email-check/reviews/#new-post';

	const CONNECT_ACTION    = 'nanopost_email_check_connect';
	const RETURN_ACTION     = 'nanopost_email_check_connect_return';
	const DISCONNECT_ACTION = 'nanopost_email_check_disconnect';
	const RESULT_ACTION     = 'nanopost_email_check_test_result';
	const DISMISS_ENDED     = 'nanopost_email_check_dismiss_ended';

	/**
	 * Per-user transients: the connect state (60 minutes) and the test to show proof for.
	 */
	const STATE_PREFIX = 'nanopost_email_check_connect_state_';
	const PROOF_PREFIX = 'nanopost_email_check_proof_';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_' . self::TEST_ALERT_ACTION, array( __CLASS__, 'handle_test_alert' ) );
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_' . self::RETURN_ACTION, array( __CLASS__, 'handle_connect_return' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::DISMISS_ENDED, array( __CLASS__, 'handle_dismiss_ended' ) );
		add_action( 'wp_ajax_' . self::RESULT_ACTION, array( __CLASS__, 'ajax_test_result' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NANOPOST_EMAIL_CHECK_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * URL of the plugin screen.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'tools.php?page=' . self::PAGE );
	}

	/**
	 * Add the screen under Tools.
	 */
	public static function add_page() {
		add_management_page(
			__( 'nanoPost Email Check', 'nanopost-email-check' ),
			__( 'Email Check', 'nanopost-email-check' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'nanopost-email-check' ) )
		);
		return $links;
	}

	/**
	 * Register the section and fields. The setting itself is registered on init.
	 */
	public static function register_settings() {
		add_settings_section( 'nanopost_email_check_main', __( 'Settings', 'nanopost-email-check' ), '__return_false', self::PAGE );

		add_settings_field(
			'nanopost_email_check_recipient',
			__( 'Send test emails to', 'nanopost-email-check' ),
			array( __CLASS__, 'field_recipient' ),
			self::PAGE,
			'nanopost_email_check_main',
			array( 'label_for' => 'nanopost_email_check_recipient' )
		);

		add_settings_field(
			'nanopost_email_check_interval',
			__( 'Check how often', 'nanopost-email-check' ),
			array( __CLASS__, 'field_interval' ),
			self::PAGE,
			'nanopost_email_check_main',
			array( 'label_for' => 'nanopost_email_check_interval' )
		);

		add_settings_field(
			'nanopost_email_check_webhook_url',
			__( 'Webhook URL', 'nanopost-email-check' ),
			array( __CLASS__, 'field_webhook_url' ),
			self::PAGE,
			'nanopost_email_check_main',
			array( 'label_for' => 'nanopost_email_check_webhook_url' )
		);
	}

	/**
	 * Webhook URL field, with "Send test alert" and the result of the last delivery.
	 */
	public static function field_webhook_url() {
		$settings = NanoPost_Email_Check_Settings::get();
		printf(
			'<input type="url" class="large-text code" id="nanopost_email_check_webhook_url" name="%1$s[webhook_url]" value="%2$s" placeholder="https://hooks.slack.com/services/..." autocomplete="off"%3$s />',
			esc_attr( NanoPost_Email_Check_Settings::OPTION ),
			esc_attr( $settings['webhook_url'] ),
			self::disabled( 'webhook_url' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute text.
		);
		self::locked_note( 'webhook_url' );
		echo '<p class="description">';
		esc_html_e( 'Optional. When a check fails, the plugin posts a short JSON message to this URL, and once more when checks pass again. Works with Slack, Google Chat, Discord (Slack-compatible URL) and automation services. Leave empty for no outside requests.', 'nanopost-email-check' );
		echo ' ' . self::help_link( NanoPost_Email_Check_Help::webhook( NanoPost_Email_Check_Help::PLACE_SETTINGS ), __( 'How to set up a webhook', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo '</p>';

		// The button belongs to a separate form after the settings form (forms cannot be
		// nested). It sends to the webhook URL in use (saved, or set in code).
		echo '<p>';
		if ( '' !== $settings['webhook_url'] ) {
			printf( '<button type="submit" class="button" form="%1$s">%2$s</button> ', esc_attr( self::TEST_ALERT_FORM ), esc_html__( 'Send test alert', 'nanopost-email-check' ) );
			echo '<span class="description">' . esc_html__( 'Sends a test message to this URL.', 'nanopost-email-check' ) . '</span>';
		} else {
			echo '<button type="button" class="button" disabled="disabled">' . esc_html__( 'Send test alert', 'nanopost-email-check' ) . '</button> ';
			echo '<span class="description">' . esc_html__( 'Enter and save a webhook URL to send a test alert.', 'nanopost-email-check' ) . '</span>';
		}
		echo '</p>';

		$delivery = NanoPost_Email_Check_Webhook::state()['last_delivery'];
		if ( '' !== $settings['webhook_url'] && ! empty( $delivery['time'] ) ) {
			$args = array(
				self::format_time( (int) $delivery['time'] ),
				! empty( $delivery['ok'] ) ? __( 'delivered', 'nanopost-email-check' ) : __( 'failed', 'nanopost-email-check' ),
				isset( $delivery['detail'] ) ? (string) $delivery['detail'] : '',
			);
			echo '<p class="description">';
			echo esc_html(
				empty( $delivery['test'] )
					/* translators: 1: date and time, 2: "delivered" or "failed", 3: HTTP status or error text. */
					? vsprintf( __( 'Last webhook message: %1$s, %2$s (%3$s).', 'nanopost-email-check' ), $args )
					/* translators: 1: date and time, 2: "delivered" or "failed", 3: HTTP status or error text. */
					: vsprintf( __( 'Last webhook message (test alert): %1$s, %2$s (%3$s).', 'nanopost-email-check' ), $args )
			);
			echo '</p>';
		}
	}

	/**
	 * Recipient field.
	 */
	public static function field_recipient() {
		$settings = NanoPost_Email_Check_Settings::get();
		$admin    = (string) get_option( 'admin_email' );
		printf(
			'<input type="email" multiple class="regular-text" id="nanopost_email_check_recipient" name="%1$s[recipient]" value="%2$s" placeholder="%3$s"%4$s />',
			esc_attr( NanoPost_Email_Check_Settings::OPTION ),
			esc_attr( $settings['recipient'] ),
			esc_attr( $admin ),
			self::disabled( 'recipient' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute text.
		);
		self::locked_note( 'recipient' );
		if ( NanoPost_Email_Check_Connection::is_connected() ) {
			echo '<p class="description nanopost-email-check-connected-note"><strong>';
			esc_html_e( 'While this site is connected to nanoPost, test emails go only to nanoPost. This address is used again after you disconnect.', 'nanopost-email-check' );
			echo '</strong></p>';
		}
		echo '<p class="description">';
		printf(
			/* translators: 1: maximum number of addresses, 2: site admin email address. */
			esc_html__( 'Use an address you read often, ideally at a different provider from this site. To send each test to more than one address, for example your own and a monitoring address, enter up to %1$d addresses separated by commas. Leave empty to use the site admin email (%2$s).', 'nanopost-email-check' ),
			(int) NanoPost_Email_Check_Settings::MAX_RECIPIENTS,
			esc_html( $admin )
		);
		echo '</p>';
	}

	/**
	 * Interval field.
	 */
	public static function field_interval() {
		$current = NanoPost_Email_Check_Settings::interval();
		printf(
			'<select id="nanopost_email_check_interval" name="%1$s[interval]"%2$s>',
			esc_attr( NanoPost_Email_Check_Settings::OPTION ),
			self::disabled( 'interval' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute text.
		);
		foreach ( NanoPost_Email_Check_Settings::intervals() as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		self::locked_note( 'interval' );
	}

	/**
	 * Disabled attribute for a field set in code. A disabled field is not sent with the
	 * form, so saving keeps the stored value.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function disabled( $key ) {
		return NanoPost_Email_Check_Settings::locked( $key ) ? ' disabled="disabled"' : '';
	}

	/**
	 * "Set in code" note under a field set by a constant or the filter.
	 *
	 * @param string $key Setting key.
	 */
	private static function locked_note( $key ) {
		$source = NanoPost_Email_Check_Settings::source( $key );
		if ( NanoPost_Email_Check_Settings::SOURCE_CONSTANT === $source ) {
			$where = NanoPost_Email_Check_Settings::constant_name( $key );
		} elseif ( NanoPost_Email_Check_Settings::SOURCE_FILTER === $source ) {
			/* translators: %s: filter name. */
			$where = sprintf( __( '%s filter', 'nanopost-email-check' ), NanoPost_Email_Check_Settings::FILTER );
		} else {
			return;
		}
		echo '<p class="description nanopost-email-check-locked">';
		/* translators: %s: constant name, or filter name followed by "filter". */
		echo esc_html( sprintf( __( 'Set in code (%s).', 'nanopost-email-check' ), $where ) );
		echo '</p>';
	}

	/**
	 * Errors for settings in code that were ignored because they are not valid.
	 */
	private static function render_code_errors() {
		foreach ( NanoPost_Email_Check_Settings::errors() as $error ) {
			printf( '<div class="notice notice-error nanopost-email-check-code-error"><p>%s</p></div>', esc_html( $error['message'] ) );
		}
	}

	/**
	 * Handle "Send test now". Runs the checks, stores the outcome for one notice, returns
	 * to the screen. At most one manual test per site each minute, so the button cannot
	 * send a stream of mail.
	 */
	public static function handle_send() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to run email checks.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::SEND_ACTION );

		if ( false !== get_transient( self::SEND_LOCK ) ) {
			set_transient( self::notice_key(), 'wait', MINUTE_IN_SECONDS );
			wp_safe_redirect( self::page_url() );
			exit;
		}
		set_transient( self::SEND_LOCK, 1, MINUTE_IN_SECONDS );

		$results = NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_MANUAL );
		$passed  = ! empty( $results );
		foreach ( $results as $result ) {
			$passed = $passed && $result->passed;
			if ( '' !== $result->test_id ) {
				set_transient( self::PROOF_PREFIX . get_current_user_id(), $result->test_id, 10 * MINUTE_IN_SECONDS );
			}
		}

		set_transient( self::notice_key(), $passed ? 'passed' : 'failed', MINUTE_IN_SECONDS );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Handle "Send test alert". Posts a test message to the saved webhook URL, stores the
	 * outcome for one notice, returns to the screen. At most one test alert per site each
	 * minute.
	 */
	public static function handle_test_alert() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to send test alerts.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::TEST_ALERT_ACTION );

		if ( '' === NanoPost_Email_Check_Settings::webhook_url() ) {
			$outcome = 'alert-none';
		} elseif ( false !== get_transient( self::TEST_ALERT_LOCK ) ) {
			$outcome = 'alert-wait';
		} else {
			set_transient( self::TEST_ALERT_LOCK, 1, MINUTE_IN_SECONDS );
			$delivery = NanoPost_Email_Check_Webhook::send_test();
			$outcome  = ! empty( $delivery['ok'] ) ? 'alert-sent' : 'alert-failed';
		}

		set_transient( self::notice_key(), $outcome, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Handle "Connect to nanoPost": make the state, then send the browser to the app,
	 * where the owner logs in or signs up and approves the site.
	 */
	public static function handle_connect() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::CONNECT_ACTION );

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_PREFIX . get_current_user_id(), $state, HOUR_IN_SECONDS );

		$return = add_query_arg( 'action', self::RETURN_ACTION, admin_url( 'admin-post.php' ) );
		wp_redirect( NanoPost_Email_Check_App::connect_url( $return, $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The app is on another host by design; the URL is built from the API base.
		exit;
	}

	/**
	 * The app sends the browser back here with a one-time code and the state. Checks the
	 * state (it stands in for a nonce), then exchanges the code for a token.
	 */
	public static function handle_connect_return() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this site.', 'nanopost-email-check' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The state is the one-time check; it is compared below.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$key    = self::STATE_PREFIX . get_current_user_id();
		$stored = get_transient( $key );
		delete_transient( $key );

		if ( ! is_string( $stored ) || '' === $state || ! hash_equals( $stored, $state ) ) {
			$outcome = array( 'outcome' => 'connect-state' );
		} elseif ( '' === $code && 'cancelled' === $error ) {
			// The owner pressed Cancel in nanoPost: back to the screen, as if nothing happened.
			$outcome = null;
		} elseif ( '' === $code ) {
			$outcome = array( 'outcome' => 'connect-cancelled' );
		} else {
			$call    = NanoPost_Email_Check_App::exchange( $code );
			$outcome = $call['ok']
				? array( 'outcome' => 'connected' )
				: array(
					'outcome' => 'invalid_code' === $call['error'] ? 'connect-invalid' : 'connect-failed',
					'detail'  => '' !== $call['message'] ? $call['message'] : self::call_detail( $call ),
				);
		}

		if ( null !== $outcome ) {
			set_transient( self::notice_key(), $outcome, MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Handle "Disconnect": tell the app (best effort) and delete the connection here.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to disconnect this site.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::DISCONNECT_ACTION );

		NanoPost_Email_Check_App::disconnect();
		set_transient( self::notice_key(), 'disconnected', MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Hide the "Reconnect" notice.
	 */
	public static function handle_dismiss_ended() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change this.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::DISMISS_ENDED );
		NanoPost_Email_Check_Connection::clear_ended_reason();
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Proof of arrival, asked by the browser. The plugin calls the app here, on the
	 * server, so the token never reaches the browser.
	 */
	public static function ajax_test_result() {
		check_ajax_referer( self::RESULT_ACTION );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}

		$test_id = isset( $_POST['test_id'] ) ? sanitize_key( wp_unslash( $_POST['test_id'] ) ) : '';
		if ( ! preg_match( '/^[a-z0-9]{12}$/', $test_id ) ) {
			wp_send_json_error( array( 'error' => 'invalid_test' ), 400 );
		}

		$call = NanoPost_Email_Check_App::test_result( $test_id );
		if ( ! $call['ok'] ) {
			wp_send_json_error(
				array(
					'error'     => $call['error'],
					'check_url' => NanoPost_Email_Check_Connection::get()['check_url'],
				)
			);
		}

		$body   = $call['body'];
		$fields = array();
		foreach ( array( 'status', 'sent_at', 'arrived_at', 'spf', 'dkim', 'dmarc' ) as $key ) {
			$fields[ $key ] = isset( $body[ $key ] ) && is_string( $body[ $key ] ) ? $body[ $key ] : null;
		}
		$fields['status']    = in_array( $fields['status'], array( 'waiting', 'arrived', 'missing', 'send_failed' ), true ) ? $fields['status'] : 'waiting';
		$arrived             = null === $fields['arrived_at'] ? false : strtotime( $fields['arrived_at'] );
		$fields['arrived']   = false === $arrived ? null : self::format_date( $arrived );
		$fields['check_url'] = isset( $body['check_url'] ) && is_string( $body['check_url'] ) ? esc_url_raw( $body['check_url'], array( 'https', 'http' ) ) : NanoPost_Email_Check_Connection::get()['check_url'];
		wp_send_json_success( $fields );
	}

	/**
	 * Short text for a failed call: the HTTP status, or the network error.
	 *
	 * @param array $call Call result.
	 * @return string
	 */
	private static function call_detail( $call ) {
		if ( 0 === $call['code'] ) {
			return '' !== $call['message'] ? $call['message'] : $call['error'];
		}
		return 'HTTP ' . $call['code'];
	}

	/**
	 * Per-user key for the one-time "Send test now" and "Send test alert" notice.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'nanopost_email_check_sent_' . get_current_user_id();
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'nanoPost Email Check', 'nanopost-email-check' ) . '</h1> ';
		echo self::help_link( NanoPost_Email_Check_Help::home( NanoPost_Email_Check_Help::PLACE_HEADER ), __( 'Help', 'nanopost-email-check' ), 'page-title-action' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo '<hr class="wp-header-end">';

		// Screens outside the Settings menu must print these themselves. No argument, so the
		// "Settings saved." message (stored under "general") shows too.
		settings_errors();
		self::render_code_errors();
		self::render_send_notice();
		// Section ids are stable hooks for styling, tests and documentation screenshots.
		echo '<div id="nanopost-email-check-status">';
		self::render_status();
		echo '</div>';

		echo '<div id="nanopost-email-check-connection">';
		self::render_connection();
		echo '</div>';

		echo '<form id="nanopost-email-check-settings" method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( NanoPost_Email_Check_Settings::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';

		if ( '' !== NanoPost_Email_Check_Settings::webhook_url() ) {
			echo '<form id="' . esc_attr( self::TEST_ALERT_FORM ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::TEST_ALERT_ACTION ) . '" />';
			wp_nonce_field( self::TEST_ALERT_ACTION, '_wpnonce', false );
			echo '</form>';
		}

		echo '<div id="nanopost-email-check-send">';
		self::render_send_form();
		echo '</div>';

		echo '<div id="nanopost-email-check-log">';
		self::render_log();
		echo '</div>';

		echo '<p id="nanopost-email-check-rate" class="description" style="margin-top:2em;">';
		echo self::help_link( self::REVIEW_URL, __( 'Rate this plugin on WordPress.org', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo '</p>';

		echo '</div>';
	}

	/**
	 * One-time notice after "Send test now" or "Send test alert".
	 */
	private static function render_send_notice() {
		self::render_ended_notice();

		$outcome = get_transient( self::notice_key() );
		if ( false === $outcome ) {
			return;
		}
		delete_transient( self::notice_key() );

		$extra = '';
		if ( is_array( $outcome ) ) {
			$extra   = isset( $outcome['detail'] ) ? (string) $outcome['detail'] : '';
			$outcome = isset( $outcome['outcome'] ) ? (string) $outcome['outcome'] : '';
		}
		$proof = '';
		if ( in_array( $outcome, array( 'passed', 'failed' ), true ) ) {
			$proof = get_transient( self::PROOF_PREFIX . get_current_user_id() );
			delete_transient( self::PROOF_PREFIX . get_current_user_id() );
			$proof = is_string( $proof ) && NanoPost_Email_Check_Connection::is_connected() ? $proof : '';
		}

		$delivery = NanoPost_Email_Check_Webhook::state()['last_delivery'];
		$detail   = isset( $delivery['detail'] ) ? (string) $delivery['detail'] : '';

		if ( 'alert-sent' === $outcome ) {
			$class = 'notice-success';
			/* translators: %s: HTTP status, such as "200 OK". */
			$message = sprintf( __( 'The test alert was delivered (%s). Check that it arrived.', 'nanopost-email-check' ), $detail );
		} elseif ( 'alert-failed' === $outcome ) {
			$class = 'notice-error';
			/* translators: %s: HTTP status or error text. */
			$message = sprintf( __( 'The test alert was not delivered (%s). Check the webhook URL.', 'nanopost-email-check' ), $detail );
		} elseif ( 'alert-wait' === $outcome ) {
			$class   = 'notice-warning';
			$message = __( 'A test alert was sent less than one minute ago. Wait one minute, then try again.', 'nanopost-email-check' );
		} elseif ( 'alert-none' === $outcome ) {
			$class   = 'notice-error';
			$message = __( 'No webhook URL is saved. Save a webhook URL first, then send a test alert.', 'nanopost-email-check' );
		} elseif ( 'wait' === $outcome ) {
			$class   = 'notice-warning';
			$message = __( 'A test email was sent less than one minute ago. Wait one minute, then try again.', 'nanopost-email-check' );
		} elseif ( 'connected' === $outcome ) {
			$class   = 'notice-success';
			$message = __( 'This site is connected to nanoPost. Test emails now go to nanoPost, and nanoPost alerts you when a test does not arrive.', 'nanopost-email-check' );
		} elseif ( 'disconnected' === $outcome ) {
			$class   = 'notice-success';
			$message = __( 'This site is disconnected from nanoPost. Test emails go to the address in the settings again.', 'nanopost-email-check' );
		} elseif ( 'connect-state' === $outcome ) {
			$class   = 'notice-error';
			$message = __( 'The connection was not made: the request expired or did not come from this screen. Click "Connect to nanoPost" again.', 'nanopost-email-check' );
		} elseif ( 'connect-cancelled' === $outcome ) {
			$class   = 'notice-warning';
			$message = __( 'The connection was not made. Click "Connect to nanoPost" to try again.', 'nanopost-email-check' );
		} elseif ( 'connect-invalid' === $outcome ) {
			$class   = 'notice-error';
			$message = __( 'The connection was not made: the approval code was wrong, used or expired. Click "Connect to nanoPost" again.', 'nanopost-email-check' );
		} elseif ( 'connect-failed' === $outcome ) {
			$class = 'notice-error';
			/* translators: %s: error text or HTTP status. */
			$message = sprintf( __( 'The connection was not made: could not reach nanoPost (%s). Try again later.', 'nanopost-email-check' ), $extra );
		} elseif ( 'passed' === $outcome && '' !== $proof ) {
			$class   = 'notice-success';
			$message = __( 'WordPress accepted the test email for nanoPost. The proof of arrival shows below.', 'nanopost-email-check' );
		} elseif ( 'passed' === $outcome ) {
			$class   = 'notice-success';
			$message = sprintf(
				/* translators: %s: recipient email address. */
				__( 'WordPress accepted the test email for %s. Check that inbox (and the spam folder) to confirm it arrived.', 'nanopost-email-check' ),
				NanoPost_Email_Check_Settings::recipient()
			);
		} else {
			$class   = 'notice-error';
			$message = __( 'The test email failed. See the error in the log below.', 'nanopost-email-check' );
		}

		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );

		if ( '' !== $proof ) {
			self::render_proof( $proof );
		}
	}

	/**
	 * "Reconnect" notice after the connection ended without the owner's click.
	 */
	private static function render_ended_notice() {
		$reason = NanoPost_Email_Check_Connection::ended_reason();
		if ( '' === $reason || NanoPost_Email_Check_Connection::is_connected() ) {
			return;
		}
		$message = NanoPost_Email_Check_Connection::ENDED_SITE_CHANGED === $reason
			? __( 'The connection to nanoPost ended because the site address changed (for example a copied or moved site). Test emails go to the address in the settings again. Connect again to use nanoPost from this address.', 'nanopost-email-check' )
			: __( 'nanoPost ended the connection to this site (it was removed in nanoPost, or moved to another site). Test emails go to the address in the settings again. Reconnect to use nanoPost.', 'nanopost-email-check' );

		echo '<div class="notice notice-warning nanopost-email-check-reconnect"><p>' . esc_html( $message ) . '</p><p>';
		self::connect_button( __( 'Reconnect', 'nanopost-email-check' ) );
		echo ' <a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', self::DISMISS_ENDED, admin_url( 'admin-post.php' ) ), self::DISMISS_ENDED ) ) . '">' . esc_html__( 'Dismiss', 'nanopost-email-check' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Proof of arrival box. The browser asks the plugin every 5 seconds for up to 2
	 * minutes (assets/proof.js); without JavaScript, the link to the check stays.
	 *
	 * @param string $test_id Test ID.
	 */
	private static function render_proof( $test_id ) {
		$check_url = NanoPost_Email_Check_Connection::get()['check_url'];
		echo '<div id="nanopost-email-check-proof" class="notice notice-info" data-test-id="' . esc_attr( $test_id ) . '">';
		echo '<p><strong>' . esc_html__( 'Proof of arrival', 'nanopost-email-check' ) . '</strong></p>';
		echo '<p class="nanopost-email-check-proof-status" aria-live="polite">' . esc_html__( 'Waiting for the test email to arrive at nanoPost ...', 'nanopost-email-check' ) . '</p>';
		if ( '' !== $check_url ) {
			echo '<p>' . self::help_link( $check_url, __( 'Open the check in nanoPost', 'nanopost-email-check' ) ) . ' &middot; '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			echo self::help_link( NanoPost_Email_Check_Help::proof( NanoPost_Email_Check_Help::PLACE_PROOF ), __( 'About proof of arrival', 'nanopost-email-check' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		}
		echo '</div>';

		wp_enqueue_script( 'nanopost-email-check-proof', plugins_url( 'assets/proof.js', NANOPOST_EMAIL_CHECK_FILE ), array(), NANOPOST_EMAIL_CHECK_VERSION, true );
		wp_localize_script(
			'nanopost-email-check-proof',
			'nanopostEmailCheckProof',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::RESULT_ACTION ),
				'action'  => self::RESULT_ACTION,
				'text'    => array(
					'waiting'     => __( 'Waiting for the test email to arrive at nanoPost ...', 'nanopost-email-check' ),
					/* translators: %s: date and time the email arrived. */
					'arrived'     => __( 'Arrived at nanoPost: %s.', 'nanopost-email-check' ),
					/* translators: 1: SPF result, 2: DKIM result, 3: DMARC result, such as "pass". */
					'auth'        => __( 'SPF: %1$s. DKIM: %2$s. DMARC: %3$s.', 'nanopost-email-check' ),
					'missing'     => __( 'The test email did not arrive at nanoPost in time. nanoPost alerts you about it.', 'nanopost-email-check' ),
					'send_failed' => __( 'The site could not send the test email. See the error in the log below.', 'nanopost-email-check' ),
					'unreachable' => __( 'Could not reach nanoPost. Open the check in nanoPost to see the result.', 'nanopost-email-check' ),
					'timeout'     => __( 'No result yet. The email can still arrive: open the check in nanoPost to see the result.', 'nanopost-email-check' ),
					'none'        => __( 'none', 'nanopost-email-check' ),
				),
			)
		);
	}

	/**
	 * The nanoPost section: consent and "Connect", or the connection and "Disconnect".
	 */
	private static function render_connection() {
		echo '<h2>' . esc_html__( 'nanoPost', 'nanopost-email-check' ) . '</h2>';

		if ( ! NanoPost_Email_Check_Connection::is_connected() ) {
			echo '<p>' . esc_html__( 'Optional. Connect this site to nanoPost (app.nanopo.st), a mail monitoring service. Each test email then goes to nanoPost, which shows here whether it arrived, with its SPF, DKIM and DMARC results, and alerts you when a test does not arrive.', 'nanopost-email-check' ) . '</p>';
			echo '<p>' . esc_html__( 'When you connect, this site sends to nanoPost: the site address; the versions of this plugin, WordPress and PHP; the check interval; the webhook URL, if you set one; and for each test its ID, the time, what started it, and whether wp_mail() succeeded, with its error text, which can contain host names or email addresses. nanoPost also receives the test emails, and keeps the IP address of each call for 30 days. Nothing is sent before you connect.', 'nanopost-email-check' ) . '</p>';
			echo '<p>';
			self::connect_button( __( 'Connect to nanoPost', 'nanopost-email-check' ) );
			echo ' ' . self::help_link( NanoPost_Email_Check_Help::TERMS_URL, __( 'Terms', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::PRIVACY_URL, __( 'Privacy', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::connect( NanoPost_Email_Check_Help::PLACE_CONNECTION ), __( 'How connecting works', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			echo '</p>';
			return;
		}

		$connection = NanoPost_Email_Check_Connection::get();
		echo '<p>';
		echo esc_html(
			sprintf(
				/* translators: %s: the check's email address in nanoPost. */
				__( 'Connected. Test emails go to nanoPost (%s).', 'nanopost-email-check' ),
				$connection['address']
			)
		);
		if ( '' !== $connection['check_url'] ) {
			echo ' ' . self::help_link( $connection['check_url'], __( 'Open the check in nanoPost', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		}
		echo '</p>';

		$too_old = NanoPost_Email_Check_Connection::too_old_message();
		if ( '' !== $too_old ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $too_old ) . ' ' . esc_html__( 'Until then, the plugin does not call nanoPost. Test emails still go to nanoPost.', 'nanopost-email-check' ) . '</p></div>';
		} elseif ( $connection['retry_until'] > time() ) {
			/* translators: %s: date and time. */
			echo '<p class="description">' . esc_html( sprintf( __( 'nanoPost asked the plugin to wait. Calls start again at %s.', 'nanopost-email-check' ), self::format_date( $connection['retry_until'] ) ) ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::DISCONNECT_ACTION ) . '" />';
		wp_nonce_field( self::DISCONNECT_ACTION );
		submit_button( __( 'Disconnect', 'nanopost-email-check' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Test emails then go to the address in the settings again. nanoPost stops the check and deletes it after 30 days, unless this site connects again.', 'nanopost-email-check' ) . '</span>';
		echo '</form>';
	}

	/**
	 * A "Connect" button in its own small form.
	 *
	 * @param string $label Button text.
	 */
	private static function connect_button( $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CONNECT_ACTION ) . '" />';
		wp_nonce_field( self::CONNECT_ACTION );
		submit_button( $label, 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Last result, schedule state and next scheduled run, with help links.
	 */
	private static function render_status() {
		$latest = NanoPost_Email_Check_Log::latest();
		$next   = NanoPost_Email_Check_Scheduler::next_run();
		$stale  = NanoPost_Email_Check_Alerts::stale_since();

		echo '<h2>' . esc_html__( 'Status', 'nanopost-email-check' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Last check', 'nanopost-email-check' ) . '</th><td>';
		if ( null === $latest ) {
			esc_html_e( 'No check has run yet. Use "Send test now" below.', 'nanopost-email-check' );
		} else {
			printf(
				'%1$s &middot; %2$s',
				wp_kses_post( self::status_badge( $latest->passed ) ),
				esc_html( self::format_time( $latest->time ) )
			);
			if ( $latest->passed ) {
				echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::pass( NanoPost_Email_Check_Help::PLACE_STATUS ), __( 'What a pass means', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			} else {
				echo '<br /><code>' . esc_html( $latest->error_message() ) . '</code> &middot; ';
				echo self::help_link( NanoPost_Email_Check_Help::for_failure( $latest, NanoPost_Email_Check_Help::PLACE_STATUS ), __( 'What this error means', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			}
		}
		echo '</td></tr>';

		$mailer = null === $latest ? '' : NanoPost_Email_Check_Mailer::describe( $latest->mailer );
		if ( '' !== $mailer ) {
			echo '<tr><th scope="row">' . esc_html__( 'Mailer', 'nanopost-email-check' ) . '</th><td>' . esc_html( $mailer ) . '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Scheduled checks', 'nanopost-email-check' ) . '</th><td>';
		if ( null !== $stale ) {
			echo '<strong style="color:#d63638;">' . esc_html__( 'Stopped', 'nanopost-email-check' ) . '</strong> &middot; ';
			echo esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'No scheduled check has finished since %s. WP-Cron may not be running, or the check may be stopping with a PHP error.', 'nanopost-email-check' ),
					self::format_date( $stale )
				)
			);
			if ( NanoPost_Email_Check_Alerts::wp_cron_disabled() ) {
				echo '<br />' . esc_html__( 'DISABLE_WP_CRON is set in wp-config.php, so a cron job on the server must run wp-cron.php.', 'nanopost-email-check' );
			}
			echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::cron( NanoPost_Email_Check_Help::PLACE_STATUS ), __( 'How to fix WP-Cron', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		} elseif ( $next ) {
			echo esc_html(
				sprintf(
					/* translators: 1: schedule name such as "Daily", 2: date and time. */
					__( '%1$s. Next check: %2$s', 'nanopost-email-check' ),
					NanoPost_Email_Check_Settings::intervals()[ NanoPost_Email_Check_Settings::interval() ],
					self::format_time( $next )
				)
			);
		} else {
			esc_html_e( 'Not scheduled.', 'nanopost-email-check' );
		}
		echo '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * "Send test now" form. Posts to admin-post.php.
	 */
	private static function render_send_form() {
		echo '<h2>' . esc_html__( 'Send test now', 'nanopost-email-check' ) . '</h2>';
		echo '<p>' . esc_html(
			NanoPost_Email_Check_Connection::is_connected()
				? __( 'Send one test email to nanoPost now, and see whether it arrives. Save any changed settings first.', 'nanopost-email-check' )
				: sprintf(
					/* translators: %s: recipient email address. */
					__( 'Send one test email to %s now. Save any changed settings first.', 'nanopost-email-check' ),
					NanoPost_Email_Check_Settings::recipient()
				)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SEND_ACTION ) . '" />';
		wp_nonce_field( self::SEND_ACTION );
		submit_button( __( 'Send test now', 'nanopost-email-check' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Result log table.
	 */
	private static function render_log() {
		$results = NanoPost_Email_Check_Log::all();

		echo '<h2>' . esc_html__( 'Recent results', 'nanopost-email-check' ) . '</h2>';

		if ( empty( $results ) ) {
			echo '<p>' . esc_html__( 'No results yet.', 'nanopost-email-check' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'nanopost-email-check' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Result', 'nanopost-email-check' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Started by', 'nanopost-email-check' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Sent to', 'nanopost-email-check' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Error', 'nanopost-email-check' ) . '</th>';
		echo '</tr></thead><tbody>';

		$results = array_values( $results );
		foreach ( $results as $index => $result ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::format_time( $result->time ) ) . '</td>';
			echo '<td>' . wp_kses_post( self::status_badge( $result->passed ) );
			// The mailer only where it helps: on a failure, and where it changed from the
			// older row below. The Status section always shows the current one.
			$mailer = NanoPost_Email_Check_Mailer::short_label( $result->mailer );
			$older  = isset( $results[ $index + 1 ] ) ? NanoPost_Email_Check_Mailer::short_label( $results[ $index + 1 ]->mailer ) : null;
			if ( '' !== $mailer && ( ! $result->passed || $mailer !== $older ) ) {
				echo '<br /><span class="description" style="overflow-wrap:anywhere;">' . esc_html( $mailer ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $result->trigger_label() ) . '</td>';
			echo '<td>' . esc_html( $result->recipient ) . '</td>';
			echo '<td>';
			if ( $result->passed ) {
				echo '&mdash;';
			} else {
				echo '<code>' . esc_html( $result->error_message() ) . '</code> ';
				echo self::help_link( NanoPost_Email_Check_Help::for_failure( $result, NanoPost_Email_Check_Help::PLACE_LOG ), __( 'What this means', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Pass or fail label.
	 *
	 * @param bool $passed Passed.
	 * @return string HTML.
	 */
	private static function status_badge( $passed ) {
		return $passed
			? '<strong style="color:#00a32a;">' . esc_html__( 'Passed', 'nanopost-email-check' ) . '</strong>'
			: '<strong style="color:#d63638;">' . esc_html__( 'Failed', 'nanopost-email-check' ) . '</strong>';
	}

	/**
	 * Link to a help page on nanopo.st, opened in a new tab.
	 *
	 * @param string $url   URL.
	 * @param string $label Link text.
	 * @param string $css_class Optional CSS class.
	 * @return string Escaped HTML.
	 */
	private static function help_link( $url, $label, $css_class = '' ) {
		return sprintf(
			'<a href="%1$s"%2$s target="_blank" rel="noopener">%3$s<span class="screen-reader-text"> %4$s</span></a>',
			esc_url( $url ),
			'' === $css_class ? '' : ' class="' . esc_attr( $css_class ) . '"',
			esc_html( $label ),
			esc_html__( '(opens in a new tab)', 'nanopost-email-check' )
		);
	}

	/**
	 * Site-local date and time.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function format_date( $time ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Site-local date and time, plus "x ago" or "in x".
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function format_time( $time ) {
		$date = self::format_date( $time );
		$diff = human_time_diff( $time, time() );

		return $time <= time()
			/* translators: 1: date and time, 2: time span such as "5 mins". */
			? sprintf( __( '%1$s (%2$s ago)', 'nanopost-email-check' ), $date, $diff )
			/* translators: 1: date and time, 2: time span such as "5 mins". */
			: sprintf( __( '%1$s (in %2$s)', 'nanopost-email-check' ), $date, $diff );
	}
}
