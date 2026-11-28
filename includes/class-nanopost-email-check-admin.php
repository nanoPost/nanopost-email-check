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

	const TURN_ON_ACTION     = 'nanopost_email_check_turn_on';
	const TURN_OFF_ACTION    = 'nanopost_email_check_turn_off';
	const ALERT_EMAIL_ACTION = 'nanopost_email_check_alert_email';
	const RESULT_ACTION      = 'nanopost_email_check_test_result';
	const DISMISS_ENDED      = 'nanopost_email_check_dismiss_ended';
	const STATE_ACTION       = 'nanopost_email_check_canary_state';

	/**
	 * Per-user transient: the test to show proof for.
	 */
	const PROOF_PREFIX = 'nanopost_email_check_proof_';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_' . self::TEST_ALERT_ACTION, array( __CLASS__, 'handle_test_alert' ) );
		add_action( 'admin_post_' . self::TURN_ON_ACTION, array( __CLASS__, 'handle_turn_on' ) );
		add_action( 'admin_post_' . self::TURN_OFF_ACTION, array( __CLASS__, 'handle_turn_off' ) );
		add_action( 'admin_post_' . self::ALERT_EMAIL_ACTION, array( __CLASS__, 'handle_alert_email' ) );
		add_action( 'admin_post_' . self::DISMISS_ENDED, array( __CLASS__, 'handle_dismiss_ended' ) );
		add_action( 'wp_ajax_' . self::RESULT_ACTION, array( __CLASS__, 'ajax_test_result' ) );
		add_action( 'wp_ajax_' . self::STATE_ACTION, array( __CLASS__, 'ajax_canary_state' ) );
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
			__( 'Test how often', 'nanopost-email-check' ),
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
		if ( NanoPost_Email_Check_Connection::canary_active() ) {
			echo '<p class="description nanopost-email-check-connected-note"><strong>';
			esc_html_e( 'Not used while Email Canary is on: test emails then go to Email Canary.', 'nanopost-email-check' );
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
	 * Handle "Turn on Email Canary", also "Turn on" again after a stop. With the same
	 * address and a canary that only stopped, this is "start" (no new confirm). Otherwise
	 * the plugin makes the canary with a token it made itself: a retry after a lost answer
	 * sends the same token. Then a test goes out at once, for the proof of arrival.
	 */
	public static function handle_turn_on() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change Email Canary.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::TURN_ON_ACTION );

		$email = isset( $_POST['alert_email'] ) ? sanitize_email( wp_unslash( $_POST['alert_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			self::done( array( 'outcome' => 'canary-email' ) );
		}

		$block = NanoPost_Email_Check_Canary::block();
		if ( NanoPost_Email_Check_Connection::is_connected() ) {
			$same = self::is_alert_address( $email, $block );
			if ( $same && ( null === $block || 'not_confirmed' !== $block['stopped_reason'] ) ) {
				// The same address: "start". It also answers when the canary already
				// watches (for example, an earlier click timed out here but reached the app).
				$call = NanoPost_Email_Check_App::start( 'turn_on' );
				if ( ! $call['ok'] && 409 !== $call['code'] ) {
					self::done( self::failure( 'canary-failed', $call ) );
				}
				if ( $call['ok'] && NanoPost_Email_Check_Connection::canary_active() ) {
					self::send_first_test();
					$block = NanoPost_Email_Check_Canary::block();
					self::done( array( 'outcome' => null !== $block && 'over_limit' === $block['watching'] ? 'canary-over-limit' : 'canary-on-again' ) );
				}
				self::done( array( 'outcome' => 'canary-still-off' ) );
			}
			if ( ! $same ) {
				// Another address: a new canary (spec "Wrong owner"). The old one stops and
				// is deleted after 30 days.
				NanoPost_Email_Check_App::disconnect();
			}
			// Not confirmed in 24 hours: POST /connect again with the same token, which
			// starts it again and sends a new confirm email.
		}

		$call = NanoPost_Email_Check_App::connect( $email );
		if ( ! $call['ok'] ) {
			self::done( self::failure( 'canary-failed', $call ) );
		}
		self::send_first_test();
		self::done( array( 'outcome' => 'canary-on' ) );
	}

	/**
	 * The test that goes out at "Turn on", for the proof of arrival.
	 */
	private static function send_first_test() {
		set_transient( self::SEND_LOCK, 1, MINUTE_IN_SECONDS );
		foreach ( NanoPost_Email_Check_Plugin::run_checks( NanoPost_Email_Check_Result::TRIGGER_MANUAL ) as $result ) {
			if ( '' !== $result->test_id ) {
				set_transient( self::PROOF_PREFIX . get_current_user_id(), $result->test_id, 10 * MINUTE_IN_SECONDS );
			}
		}
	}

	/**
	 * Handle "Turn Email Canary off". Before the confirm nothing is kept, so the plugin
	 * ends the connection. Otherwise it is a stop that keeps the token. When nanoPost
	 * cannot be reached, nothing changes here: Email Canary still watches.
	 */
	public static function handle_turn_off() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change Email Canary.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::TURN_OFF_ACTION );

		$block = NanoPost_Email_Check_Canary::block();
		if ( null !== $block && 'unconfirmed' === $block['watching'] ) {
			NanoPost_Email_Check_App::disconnect();
			self::done( array( 'outcome' => 'canary-off' ) );
		}
		$call = NanoPost_Email_Check_App::stop( 'turn_off' );
		if ( ! $call['ok'] && 409 !== $call['code'] ) {
			self::done( self::failure( 'canary-off-failed', $call ) );
		}
		self::done( array( 'outcome' => NanoPost_Email_Check_Connection::is_connected() ? 'canary-off' : 'canary-off-ended' ) );
	}

	/**
	 * Handle "Resend" (the same address) and "Change" (another address).
	 */
	public static function handle_alert_email() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change Email Canary.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::ALERT_EMAIL_ACTION );

		$email = isset( $_POST['alert_email'] ) ? sanitize_email( wp_unslash( $_POST['alert_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			self::done( array( 'outcome' => 'canary-email' ) );
		}
		$resend = strtolower( $email ) === strtolower( self::alert_address( NanoPost_Email_Check_Canary::block() ) );
		$call   = NanoPost_Email_Check_App::set_alert_email( $email );
		if ( ! $call['ok'] ) {
			self::done( self::failure( 'email-failed', $call ) );
		}
		$block   = NanoPost_Email_Check_Canary::block();
		$limited = null !== $block && null !== $block['confirm_mail'] && 'limited' === $block['confirm_mail']['state'];
		self::done(
			array(
				'outcome' => $limited ? 'email-limited' : ( $resend ? 'email-resent' : 'email-changed' ),
				'detail'  => $email,
			)
		);
	}

	/**
	 * True when an address is one of the canary's own: confirmed, pending, or the one
	 * typed last. Then "Turn on" starts the same canary.
	 *
	 * @param string     $email Address.
	 * @param array|null $block Block, or null.
	 * @return bool
	 */
	private static function is_alert_address( $email, $block ) {
		$own = array( NanoPost_Email_Check_Connection::alert_email() );
		if ( null !== $block ) {
			$own = array_merge( $own, wp_list_pluck( $block['alerts_to'], 'email' ) );
		}
		return in_array( strtolower( $email ), array_map( 'strtolower', array_filter( $own ) ), true );
	}

	/**
	 * The confirmed address that gets the alerts, else alert_address().
	 *
	 * @param array|null $block Block, or null.
	 * @return string
	 */
	private static function confirmed_address( $block ) {
		if ( null !== $block ) {
			foreach ( $block['alerts_to'] as $entry ) {
				if ( 'confirmed' === $entry['state'] ) {
					return $entry['email'];
				}
			}
		}
		return self::alert_address( $block );
	}

	/**
	 * The address the owner set for alerts: the newest pending one, else the confirmed
	 * one, else the one typed last.
	 *
	 * @param array|null $block Block, or null.
	 * @return string
	 */
	public static function alert_address( $block ) {
		$confirmed = '';
		$pending   = '';
		if ( null !== $block ) {
			foreach ( $block['alerts_to'] as $entry ) {
				if ( 'pending' === $entry['state'] ) {
					$pending = $entry['email'];
				} elseif ( '' === $confirmed ) {
					$confirmed = $entry['email'];
				}
			}
		}
		if ( '' !== $pending ) {
			return $pending;
		}
		return '' !== $confirmed ? $confirmed : NanoPost_Email_Check_Connection::alert_email();
	}

	/**
	 * The notice for a failed call.
	 *
	 * @param string $outcome Outcome key.
	 * @param array  $call    Call result.
	 * @return array
	 */
	private static function failure( $outcome, $call ) {
		return array(
			'outcome' => $outcome,
			'detail'  => '' !== $call['message'] ? $call['message'] : self::call_detail( $call ),
		);
	}

	/**
	 * Store the outcome for one notice and go back to the screen.
	 *
	 * @param array $outcome Outcome (and detail).
	 */
	private static function done( $outcome ) {
		set_transient( self::notice_key(), $outcome, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() . '#nanopost-email-check-connection' );
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

		$test   = NanoPost_Email_Check_Canary::take_test_answer( $call['body'] );
		$body   = $call['body'];
		$fields = array();
		foreach ( array( 'sent_at', 'arrived_at' ) as $key ) {
			$fields[ $key ] = isset( $body[ $key ] ) && is_string( $body[ $key ] ) ? $body[ $key ] : null;
		}
		foreach ( array( 'spf', 'dkim', 'dmarc' ) as $key ) {
			$fields[ $key ] = '' === $test[ $key ] ? null : $test[ $key ];
		}
		$fields['status']    = in_array( $test['arrival'], NanoPost_Email_Check_Canary::ARRIVALS, true ) ? $test['arrival'] : 'waiting';
		$fields['arrived']   = 0 === $test['arrived_at'] ? null : self::format_date( $test['arrived_at'] );
		$fields['reason']    = NanoPost_Email_Check_Canary::reason_text( $test['reason'] );
		$fields['help_url']  = $test['help_url'];
		$fields['check_url'] = isset( $body['check_url'] ) && is_string( $body['check_url'] ) ? esc_url_raw( $body['check_url'], array( 'https', 'http' ) ) : NanoPost_Email_Check_Connection::get()['check_url'];
		wp_send_json_success( $fields );
	}

	/**
	 * The state of the canary, asked by the browser when the plugin screen opens. The
	 * plugin calls "GET /check" at most once a minute. When the state changed, the answer
	 * has the new HTML of the Email Canary part and of the log (escaped on the server).
	 */
	public static function ajax_canary_state() {
		check_ajax_referer( self::STATE_ACTION );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		if ( ! NanoPost_Email_Check_Connection::is_connected() || ! NanoPost_Email_Check_Canary::refresh_for_screen() ) {
			wp_send_json_success( array( 'changed' => false ) );
		}

		ob_start();
		self::render_canary();
		$canary = (string) ob_get_clean();
		ob_start();
		self::render_log();
		$log = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'changed' => true,
				'canary'  => $canary,
				'log'     => $log,
			)
		);
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
		echo '<div id="nanopost-email-check-send">';
		self::render_send_form();
		echo '</div>';
		echo '</div>';

		echo '<div id="nanopost-email-check-connection">';
		self::render_connection();
		echo '</div>';
		self::enqueue_canary_assets();

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
		if ( in_array( $outcome, array( 'passed', 'failed', 'canary-on', 'canary-on-again', 'canary-over-limit' ), true ) ) {
			$proof = get_transient( self::PROOF_PREFIX . get_current_user_id() );
			delete_transient( self::PROOF_PREFIX . get_current_user_id() );
			$proof = is_string( $proof ) && NanoPost_Email_Check_Connection::canary_active() ? $proof : '';
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
		} elseif ( 'canary-on' === $outcome ) {
			$class   = 'notice-success';
			$message = __( 'Email Canary: turned on. A test email went out. Its arrival shows below.', 'nanopost-email-check' );
		} elseif ( 'canary-on-again' === $outcome ) {
			$class   = 'notice-success';
			$message = __( 'Email Canary is on again.', 'nanopost-email-check' );
		} elseif ( 'canary-over-limit' === $outcome ) {
			$class   = 'notice-warning';
			$message = __( 'Email Canary: alerts are OFF. Your nanoPost account has reached its limit of sites. Remove one in nanoPost, or ask us for more.', 'nanopost-email-check' );
		} elseif ( 'canary-still-off' === $outcome ) {
			$class   = 'notice-warning';
			$message = __( 'Email Canary could not turn on again. See why below.', 'nanopost-email-check' );
		} elseif ( 'canary-off' === $outcome ) {
			$class   = 'notice-success';
			$message = __( 'Email Canary is off. Email Check sends test emails to the address in the settings again. Email Canary keeps the history for 30 days.', 'nanopost-email-check' );
		} elseif ( 'canary-off-ended' === $outcome ) {
			$class   = 'notice-success';
			$message = __( 'Email Canary is off. Email Check sends test emails to the address in the settings again.', 'nanopost-email-check' );
		} elseif ( 'canary-off-failed' === $outcome ) {
			$class = 'notice-error';
			/* translators: %s: error text or HTTP status. */
			$message = sprintf( __( 'Could not reach nanoPost (%s). Email Canary is still on. Try again later.', 'nanopost-email-check' ), $extra );
		} elseif ( 'canary-failed' === $outcome ) {
			$class = 'notice-error';
			/* translators: %s: error text or HTTP status. */
			$message = sprintf( __( 'Email Canary did not turn on: %s. Nothing changed. Try again later.', 'nanopost-email-check' ), $extra );
		} elseif ( 'canary-email' === $outcome ) {
			$class   = 'notice-error';
			$message = __( 'Enter a valid email address for the alerts.', 'nanopost-email-check' );
		} elseif ( 'email-resent' === $outcome ) {
			$class = 'notice-success';
			/* translators: %s: email address. */
			$message = sprintf( __( 'Email Canary: we sent the confirm email again to %s.', 'nanopost-email-check' ), $extra );
		} elseif ( 'email-changed' === $outcome ) {
			$class = 'notice-success';
			/* translators: %s: email address. */
			$message = sprintf( __( 'Email Canary: we sent a confirm email to %s. Alerts go there once it is confirmed.', 'nanopost-email-check' ), $extra );
		} elseif ( 'email-limited' === $outcome ) {
			$class   = 'notice-warning';
			$message = __( 'Email Canary: we cannot send another confirm email now. Try again tomorrow. Proof of arrival still works.', 'nanopost-email-check' );
		} elseif ( 'email-failed' === $outcome ) {
			$class = 'notice-error';
			/* translators: %s: error text or HTTP status. */
			$message = sprintf( __( 'The alert address did not change: %s. Try again later.', 'nanopost-email-check' ), $extra );
		} elseif ( 'passed' === $outcome && '' !== $proof ) {
			$class   = 'notice-success';
			$message = __( 'WordPress accepted the test email for Email Canary. The proof of arrival shows below.', 'nanopost-email-check' );
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
	 * Notice after the connection ended without the owner's click.
	 */
	private static function render_ended_notice() {
		$reason = NanoPost_Email_Check_Connection::ended_reason();
		if ( '' === $reason || NanoPost_Email_Check_Connection::is_connected() ) {
			return;
		}
		$message = NanoPost_Email_Check_Connection::ENDED_SITE_CHANGED === $reason
			? __( 'Email Canary is off: the site address changed (a copied or moved site). Turn it on again to use Email Canary from this address.', 'nanopost-email-check' )
			: __( 'Email Canary is off: nanoPost ended it for this site (it was deleted in nanoPost, or stopped for more than 30 days). Email Check sends test emails to the address in the settings again.', 'nanopost-email-check' );

		echo '<div class="notice notice-warning nanopost-email-check-reconnect"><p>' . esc_html( $message ) . '</p><p>';
		echo '<a href="#nanopost-email-check-connection">' . esc_html__( 'Turn on again', 'nanopost-email-check' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', self::DISMISS_ENDED, admin_url( 'admin-post.php' ) ), self::DISMISS_ENDED ) ) . '">' . esc_html__( 'Dismiss', 'nanopost-email-check' ) . '</a>';
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
			echo '<p>' . self::help_link( $check_url, __( 'Open in nanoPost', 'nanopost-email-check' ) ) . ' &middot; '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
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
					'missing'     => __( 'The test email did not arrive at nanoPost in time.', 'nanopost-email-check' ),
					'send_failed' => __( 'The site could not send the test email. See the error in the log below.', 'nanopost-email-check' ),
					'unknown'     => __( 'Email Canary cannot tell now. Email Check sends your test emails as usual.', 'nanopost-email-check' ),
					'unreachable' => __( 'Could not reach nanoPost. Open nanoPost to see the result.', 'nanopost-email-check' ),
					'timeout'     => __( 'No result yet. The email can still arrive: the log below shows it later.', 'nanopost-email-check' ),
					'none'        => __( 'none', 'nanopost-email-check' ),
				),
			)
		);
	}

	/**
	 * The Email Canary area: the offer with the consent text, or the state of the canary
	 * with what the owner can do. The inner part is replaced by the browser when a newer
	 * state comes (assets/canary.js).
	 */
	private static function render_connection() {
		echo '<h2>' . esc_html__( 'Email Canary', 'nanopost-email-check' ) . ' <span class="nanopost-email-check-tagline">' . esc_html__( 'Did it arrive? Does it keep arriving?', 'nanopost-email-check' ) . '</span></h2>';
		echo '<div id="nanopost-email-check-canary">';
		self::render_canary();
		echo '</div>';

		if ( ! NanoPost_Email_Check_Connection::is_connected() ) {
			return;
		}
		$connection = NanoPost_Email_Check_Connection::get();
		$too_old    = NanoPost_Email_Check_Connection::too_old_message();
		if ( '' !== $too_old ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $too_old ) . ' ' . esc_html__( 'Until then, the plugin does not call nanoPost. Test emails still go to Email Canary.', 'nanopost-email-check' ) . '</p></div>';
		} elseif ( $connection['retry_until'] > time() ) {
			/* translators: %s: date and time. */
			echo '<p class="description">' . esc_html( sprintf( __( 'nanoPost asked the plugin to wait. Calls start again at %s.', 'nanopost-email-check' ), self::format_date( $connection['retry_until'] ) ) ) . '</p>';
		}
	}

	/**
	 * The state part of the Email Canary area.
	 */
	private static function render_canary() {
		if ( ! NanoPost_Email_Check_Connection::is_connected() ) {
			self::render_offer();
			return;
		}

		$block = NanoPost_Email_Check_Canary::block();
		if ( null === $block ) {
			echo '<p>' . esc_html__( 'Email Canary: no state from nanoPost yet. It shows here after the next test email.', 'nanopost-email-check' ) . '</p>';
			self::render_canary_actions( true );
			return;
		}

		// "Cannot tell" is about arrivals: it takes the place of "on", and does not hide a
		// stop or a missing confirm. The rest of the area stays, so the owner can still
		// change where alerts go.
		$address = self::alert_address( $block );
		switch ( $block['watching'] ) {
			case 'on':
				if ( NanoPost_Email_Check_Canary::cannot_tell( $block ) ) {
					echo '<p class="nanopost-email-check-state"><span aria-hidden="true">?</span> <strong>' . esc_html__( 'Email Canary cannot tell now.', 'nanopost-email-check' ) . '</strong> ' . esc_html__( 'Email Check sends your test emails as usual.', 'nanopost-email-check' ) . '</p>';
					break;
				}
				echo '<p class="nanopost-email-check-state nanopost-email-check-state-on"><span aria-hidden="true">&#9679;</span> <strong>' . esc_html__( 'Email Canary is on', 'nanopost-email-check' ) . '</strong></p>';
				break;
			case 'unconfirmed':
				echo '<div class="notice notice-warning inline"><p><span aria-hidden="true">&#9650;</span> <strong>' . esc_html__( 'Alerts are OFF until you confirm.', 'nanopost-email-check' ) . '</strong> ';
				/* translators: %s: email address. */
				echo esc_html( sprintf( __( 'We sent an email to %s.', 'nanopost-email-check' ), $address ) );
				if ( $block['confirm_by'] > 0 ) {
					/* translators: %s: date and time. */
					echo ' ' . esc_html( sprintf( __( 'Until %s, Email Canary shows here whether test emails arrive, but sends no alerts. Then it turns off.', 'nanopost-email-check' ), self::format_date( $block['confirm_by'] ) ) );
				}
				echo ' ' . self::help_link( NanoPost_Email_Check_Help::canary( NanoPost_Email_Check_Help::CANARY_CONFIRM, NanoPost_Email_Check_Help::PLACE_CANARY ), __( 'Why confirm', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
				echo '</p>';
				self::render_confirm_mail( $block, $address );
				echo '</div>';
				break;
			case 'over_limit':
				echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Email Canary: alerts are OFF.', 'nanopost-email-check' ) . '</strong> ' . esc_html__( 'Your nanoPost account has reached its limit of sites. Remove one in nanoPost, or ask us for more.', 'nanopost-email-check' ) . '</p></div>';
				break;
			case 'stopped':
				echo '<p class="nanopost-email-check-state"><strong>' . esc_html( self::stopped_text( $block['stopped_reason'], $address ) ) . '</strong></p>';
				break;
			default:
				echo '<p>' . esc_html( NanoPost_Email_Check_Canary::open_text() ) . '</p>';
		}

		foreach ( NanoPost_Email_Check_Canary::problems( $block ) as $problem ) {
			echo '<div class="notice notice-error inline"><p><span aria-hidden="true">&#10005;</span> <strong>' . esc_html( NanoPost_Email_Check_Canary::problem_text( $problem ) ) . '</strong>';
			$reason = 'missing' === $problem['type'] ? NanoPost_Email_Check_Canary::reason_text( $problem['reason'] ) : __( 'Real emails from this site can be refused.', 'nanopost-email-check' );
			if ( 'missing' === $problem['type'] && '' === $reason && 'ok' === $block['sending'] && $problem['sent_at'] > 0 ) {
				$reason = __( 'Email Check sent it, so it was probably lost or refused after that.', 'nanopost-email-check' );
			}
			if ( '' !== $reason ) {
				echo ' ' . esc_html( $reason );
			}
			if ( null !== $block['alerts_to'] && '' !== $address ) {
				/* translators: %s: email address. */
				echo ' ' . esc_html( sprintf( __( 'We emailed %s.', 'nanopost-email-check' ), $address ) );
			}
			if ( '' !== $problem['help_url'] ) {
				echo ' ' . self::help_link( $problem['help_url'], __( 'What to do', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			}
			echo '</p></div>';
		}

		if ( 'stopped' === $block['watching'] ) {
			self::render_turn_on_form( self::confirmed_address( $block ), __( 'Turn on Email Canary', 'nanopost-email-check' ) );
			self::render_open_link( $block );
			return;
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		$arrival = $block['last_arrival'];
		$label   = 'missing' === $block['arrival'] ? __( 'Last arrival', 'nanopost-email-check' ) : __( 'Last test', 'nanopost-email-check' );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		if ( null === $arrival || 0 === $arrival['received_at'] ) {
			esc_html_e( 'No test email has arrived yet.', 'nanopost-email-check' );
		} else {
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Arrived %s', 'nanopost-email-check' ), self::format_time( $arrival['received_at'] ) ) ) . '<br />' . NanoPost_Email_Check_Canary::auth_labels( $arrival ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- auth_labels() escapes.
		}
		echo '</td></tr>';
		if ( $block['next_expected_by'] > 0 && 'on' === $block['watching'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Next test', 'nanopost-email-check' ) . '</th><td>';
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Expected by %s', 'nanopost-email-check' ), self::format_time( $block['next_expected_by'] ) ) );
			echo '</td></tr>';
		}
		if ( 'on' === $block['watching'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Alerts go to', 'nanopost-email-check' ) . '</th><td>';
			$confirmed = array();
			$pending   = array();
			$failing   = array();
			foreach ( $block['alerts_to'] as $entry ) {
				if ( 'pending' === $entry['state'] ) {
					$pending[] = $entry['email'];
				} elseif ( 'confirmed' === $entry['state'] ) {
					$confirmed[] = $entry['email'];
				} elseif ( 'failing' === $entry['state'] ) {
					$confirmed[] = $entry['email'];
					$failing[]   = $entry['email'];
				} else {
					/* translators: 1: email address, 2: "Open nanoPost to see." */
					$confirmed[] = sprintf( __( '%1$s (%2$s)', 'nanopost-email-check' ), $entry['email'], NanoPost_Email_Check_Canary::open_text() );
				}
			}
			echo esc_html( implode( ', ', $confirmed ) );
			foreach ( $failing as $email ) {
				/* translators: %s: email address. */
				echo '<br /><strong class="nanopost-email-check-failing">' . esc_html( sprintf( __( 'Email Canary: alerts do not reach %s.', 'nanopost-email-check' ), $email ) ) . '</strong>';
			}
			if ( array() !== $pending ) {
				/* translators: %s: email address. */
				echo '<br /><span class="description">' . esc_html( sprintf( __( 'Waiting for the confirm of %s. Alerts go there once it is confirmed.', 'nanopost-email-check' ), implode( ', ', $pending ) ) ) . '</span>';
				self::render_confirm_mail( $block, end( $pending ) );
			}
			self::render_change_form( $address );
			echo '</td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Webhook', 'nanopost-email-check' ) . '</th><td>' . esc_html( self::webhook_host() ) . '</td></tr>';
		echo '</tbody></table>';

		if ( NanoPost_Email_Check_Canary::is_old( $block ) && $block['observed_at'] > 0 ) {
			/* translators: %s: date and time. */
			echo '<p class="description">' . esc_html( sprintf( __( 'This is the state as of %s. nanoPost has sent no newer one.', 'nanopost-email-check' ), self::format_date( $block['observed_at'] ) ) ) . '</p>';
		}
		self::render_canary_actions( true, $block );
	}

	/**
	 * The text for a canary that stopped watching, by its reason.
	 *
	 * @param string $reason  stopped_reason.
	 * @param string $address Alert address.
	 * @return string
	 */
	private static function stopped_text( $reason, $address ) {
		switch ( $reason ) {
			case 'not_confirmed':
				/* translators: %s: email address. */
				return sprintf( __( 'Email Canary is off: %s was not confirmed.', 'nanopost-email-check' ), $address );
			case 'over_limit':
				return __( 'Email Canary is off: your nanoPost account has reached its limit of sites. Remove one in nanoPost, or ask us for more.', 'nanopost-email-check' );
			case 'mail_link':
				return __( 'Email Canary is off (asked for by email).', 'nanopost-email-check' );
			case 'deactivated':
				return __( 'Email Canary is off (the plugin was deactivated).', 'nanopost-email-check' );
			default:
				return __( 'Email Canary is off. Email Canary keeps the history for 30 days.', 'nanopost-email-check' );
		}
	}

	/**
	 * The offer, when Email Canary is not on: the alert address, the button, and the
	 * consent text (nothing is sent before the click).
	 */
	private static function render_offer() {
		$email = NanoPost_Email_Check_Connection::alert_email();
		if ( '' === $email ) {
			$user  = wp_get_current_user();
			$email = $user instanceof WP_User ? (string) $user->user_email : '';
		}

		echo '<p>' . esc_html__( 'Email Check can tell you that WordPress sent the email. Email Canary checks that it arrived, and emails you when it does not.', 'nanopost-email-check' ) . '</p>';
		self::render_key_line();
		if ( ! self::site_is_public() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This site address is not public. Test emails from it may never leave the server.', 'nanopost-email-check' ) . '</p></div>';
		}
		self::render_turn_on_form( $email, __( 'Turn on Email Canary', 'nanopost-email-check' ) );
		echo '<p class="description nanopost-email-check-consent">' . esc_html__( 'When you turn on Email Canary, this site sends to nanoPost: the site address, the alert address above, the site\'s time zone, the test interval, the webhook URL, and the versions of this plugin, WordPress and PHP. After each test it sends the test ID, the time, how the test started, and whether WordPress accepted the email, with the error text if it did not. nanoPost keeps the IP address of each call for 30 days. If an account key is set, the site sends it once, to add the site to that nanoPost account.', 'nanopost-email-check' ) . '</p>';
		echo '<p>' . self::help_link( NanoPost_Email_Check_Help::canary( NanoPost_Email_Check_Help::CANARY_SENT, NanoPost_Email_Check_Help::PLACE_CANARY ), __( 'What is sent to nanoPost', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::TERMS_URL, __( 'Terms', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::PRIVACY_URL, __( 'Privacy', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::canary( NanoPost_Email_Check_Help::CANARY_TURN_ON, NanoPost_Email_Check_Help::PLACE_CANARY ), __( 'How Email Canary works', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo '</p>';
	}

	/**
	 * One line while an account key is set in wp-config.php: what it does on this site, or
	 * why it does not.
	 */
	private static function render_key_line() {
		if ( '' === NanoPost_Email_Check_Key::constant_key() ) {
			return;
		}
		$link  = ' ' . self::help_link( NanoPost_Email_Check_Help::canary( NanoPost_Email_Check_Help::CANARY_KEY, NanoPost_Email_Check_Help::PLACE_CANARY ), __( 'How the account key works', 'nanopost-email-check' ) );
		$error = NanoPost_Email_Check_Key::error();
		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline nanopost-email-check-key"><p>' . esc_html( NanoPost_Email_Check_Key::error_text( $error ) ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is escaped in help_link().
			return;
		}
		switch ( NanoPost_Email_Check_Key::blocked_reason() ) {
			case 'not_public':
				$text = __( 'An account key is set in wp-config.php, but this site address is not public, so the key does not turn Email Canary on by itself.', 'nanopost-email-check' );
				break;
			case 'not_main_site':
				$text = __( 'An account key is set in wp-config.php. On a network it turns Email Canary on only for the main site. For this site, use WP-CLI: wp email-check connect --key=<key> --url=<site address>', 'nanopost-email-check' );
				break;
			case 'tried':
			case 'ended':
				$text = __( 'An account key is set in wp-config.php. It does not turn Email Canary on by itself again for this site address. Use the button below.', 'nanopost-email-check' );
				break;
			default:
				$text = __( 'An account key is set in wp-config.php. Email Canary turns on by itself in a moment, with alerts to the address of the nanoPost account.', 'nanopost-email-check' );
		}
		echo '<p class="description nanopost-email-check-key">' . esc_html( $text ) . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is escaped in help_link().
	}

	/**
	 * "Send alerts to [address] [Turn on]".
	 *
	 * @param string $email Pre-filled address.
	 * @param string $label Button text.
	 */
	private static function render_turn_on_form( $email, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nanopost-email-check-turn-on">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::TURN_ON_ACTION ) . '" />';
		wp_nonce_field( self::TURN_ON_ACTION );
		echo '<p><label for="nanopost_email_check_alert_email">' . esc_html__( 'Send alerts to', 'nanopost-email-check' ) . '</label> ';
		echo '<input type="email" required class="regular-text" id="nanopost_email_check_alert_email" name="alert_email" value="' . esc_attr( $email ) . '" autocomplete="email" /> ';
		submit_button( $label, 'primary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * Resend of the confirm email, or why it cannot go now.
	 *
	 * @param array  $block   Block.
	 * @param string $address The address waiting for its confirm.
	 */
	private static function render_confirm_mail( $block, $address ) {
		$mail = $block['confirm_mail'];
		if ( null !== $mail && 'limited' === $mail['state'] ) {
			echo '<p>' . esc_html__( 'Email Canary: we cannot send another confirm email now. Try again tomorrow. Proof of arrival still works.', 'nanopost-email-check' ) . '</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nanopost-email-check-resend">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ALERT_EMAIL_ACTION ) . '" />';
		echo '<input type="hidden" name="alert_email" value="' . esc_attr( $address ) . '" />';
		wp_nonce_field( self::ALERT_EMAIL_ACTION );
		if ( null !== $mail && $mail['next_allowed_at'] > time() ) {
			echo '<button type="button" class="button" disabled="disabled">' . esc_html__( 'Resend', 'nanopost-email-check' ) . '</button> ';
			/* translators: %s: date and time. */
			echo '<span class="description">' . esc_html( sprintf( __( 'You can resend it after %s.', 'nanopost-email-check' ), self::format_date( $mail['next_allowed_at'] ) ) ) . '</span>';
		} else {
			submit_button( __( 'Resend', 'nanopost-email-check' ), 'secondary', 'submit', false );
		}
		echo '</form>';
		if ( 'unconfirmed' === $block['watching'] ) {
			self::render_change_form( $address );
		}
	}

	/**
	 * "Change" the alert address: a new confirm email goes to the new address.
	 *
	 * @param string $address Current address.
	 */
	private static function render_change_form( $address ) {
		echo '<details class="nanopost-email-check-change" style="display:inline-block;"><summary>' . esc_html__( 'Change', 'nanopost-email-check' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ALERT_EMAIL_ACTION ) . '" />';
		wp_nonce_field( self::ALERT_EMAIL_ACTION );
		echo '<p><label for="nanopost_email_check_new_alert_email" class="screen-reader-text">' . esc_html__( 'New alert address', 'nanopost-email-check' ) . '</label>';
		echo '<input type="email" required class="regular-text" id="nanopost_email_check_new_alert_email" name="alert_email" value="' . esc_attr( $address ) . '" autocomplete="email" /> ';
		submit_button( __( 'Send confirm email', 'nanopost-email-check' ), 'secondary', 'submit', false );
		echo '</p><p class="description">' . esc_html__( 'Only where alerts go changes. The new address must confirm by email.', 'nanopost-email-check' ) . ' ';
		echo self::help_link( NanoPost_Email_Check_Help::canary( NanoPost_Email_Check_Help::CANARY_CHANGE, NanoPost_Email_Check_Help::PLACE_CANARY ), __( 'More', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo '</p></form></details>';
	}

	/**
	 * "Turn Email Canary off" and "Open in nanoPost".
	 *
	 * @param bool       $off   Show the off button.
	 * @param array|null $block Block, or null.
	 */
	private static function render_canary_actions( $off, $block = null ) {
		echo '<div class="nanopost-email-check-canary-actions">';
		if ( $off ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::TURN_OFF_ACTION ) . '" />';
			wp_nonce_field( self::TURN_OFF_ACTION );
			submit_button( __( 'Turn Email Canary off', 'nanopost-email-check' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		self::render_open_link( $block );
		echo '</div>';
	}

	/**
	 * The small "Open in nanoPost" link.
	 *
	 * @param array|null $block Block, or null.
	 */
	private static function render_open_link( $block ) {
		$url = null !== $block && '' !== $block['check_url'] ? $block['check_url'] : NanoPost_Email_Check_Connection::get()['check_url'];
		if ( '' !== $url ) {
			echo self::help_link( $url, __( 'Open in nanoPost', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		}
	}

	/**
	 * The webhook as the owner set it: its host, or "none".
	 *
	 * @return string
	 */
	public static function webhook_host() {
		$url = NanoPost_Email_Check_Settings::webhook_url();
		if ( '' === $url ) {
			return __( 'none', 'nanopost-email-check' );
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' === $host ? $url : $host;
	}

	/**
	 * False for a site address that the internet cannot reach: localhost, a reserved
	 * name (.local, .test, .example, .invalid, .localhost), or a private or loopback IP.
	 *
	 * @return bool
	 */
	public static function site_is_public() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host ) {
			return false;
		}
		if ( preg_match( '/\.(local|test|example|invalid|localhost)$/', $host ) ) {
			return false;
		}
		$ip = trim( $host, '[]' );
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}

	/**
	 * Styles for the SPF, DKIM and DMARC labels, and the script that asks for a newer
	 * state when the screen opens (while connected).
	 */
	private static function enqueue_canary_assets() {
		wp_register_style( 'nanopost-email-check-canary', false, array(), NANOPOST_EMAIL_CHECK_VERSION );
		wp_enqueue_style( 'nanopost-email-check-canary' );
		wp_add_inline_style( 'nanopost-email-check-canary', NanoPost_Email_Check_Canary::label_css() );

		if ( ! NanoPost_Email_Check_Connection::is_connected() ) {
			return;
		}
		wp_enqueue_script( 'nanopost-email-check-canary', plugins_url( 'assets/canary.js', NANOPOST_EMAIL_CHECK_FILE ), array(), NANOPOST_EMAIL_CHECK_VERSION, true );
		wp_localize_script(
			'nanopost-email-check-canary',
			'nanopostEmailCheckCanary',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::STATE_ACTION ),
				'action'  => self::STATE_ACTION,
			)
		);
	}

	/**
	 * What Email Canary said about one test, for its log row.
	 *
	 * @param NanoPost_Email_Check_Result $result Result.
	 * @return string HTML.
	 */
	private static function canary_cell( $result ) {
		if ( '' === $result->test_id ) {
			return '&mdash;';
		}
		if ( ! $result->passed ) {
			return esc_html__( '(no email to wait for)', 'nanopost-email-check' );
		}
		$canary  = $result->canary;
		$arrival = isset( $canary['arrival'] ) ? (string) $canary['arrival'] : '';
		if ( 'arrived' === $arrival ) {
			$time = isset( $canary['arrived_at'] ) ? (int) $canary['arrived_at'] : 0;
			$html = '<strong style="color:#00a32a;">' . esc_html__( 'Arrived', 'nanopost-email-check' ) . '</strong>';
			if ( $time > 0 ) {
				$html .= ' ' . esc_html( self::format_date( $time ) );
			}
			$results = array();
			foreach ( array( 'spf', 'dkim', 'dmarc' ) as $key ) {
				$results[ $key ] = isset( $canary[ $key ] ) ? (string) $canary[ $key ] : '';
			}
			return $html . '<br />' . NanoPost_Email_Check_Canary::auth_labels( $results );
		}
		if ( 'missing' === $arrival ) {
			$html   = '<strong style="color:#d63638;">' . esc_html__( 'Did not arrive', 'nanopost-email-check' ) . '</strong>';
			$reason = NanoPost_Email_Check_Canary::reason_text( isset( $canary['reason'] ) ? (string) $canary['reason'] : '' );
			if ( '' !== $reason ) {
				$html .= '<br />' . esc_html( $reason );
			}
			if ( ! empty( $canary['help_url'] ) ) {
				$html .= ' ' . self::help_link( (string) $canary['help_url'], __( 'What to do', 'nanopost-email-check' ) );
			}
			return $html;
		}
		if ( 'unknown' === $arrival ) {
			return esc_html__( 'Email Canary cannot tell now', 'nanopost-email-check' );
		}
		if ( '' === $arrival || 'waiting' === $arrival ) {
			return esc_html__( 'No result yet', 'nanopost-email-check' );
		}
		return esc_html( NanoPost_Email_Check_Canary::open_text() );
	}

	/**
	 * Last result, schedule state and next scheduled run, with help links.
	 */
	private static function render_status() {
		$latest = NanoPost_Email_Check_Log::latest();
		$next   = NanoPost_Email_Check_Scheduler::next_run();
		$stale  = NanoPost_Email_Check_Alerts::stale_since();

		echo '<h2>' . esc_html__( 'Email Check', 'nanopost-email-check' ) . ' <span class="nanopost-email-check-tagline">' . esc_html__( 'Can this site send email?', 'nanopost-email-check' ) . '</span></h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Last test', 'nanopost-email-check' ) . '</th><td>';
		if ( null === $latest ) {
			esc_html_e( 'No test has run yet. Use "Send test now" below.', 'nanopost-email-check' );
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
			echo '<tr><th scope="row">' . esc_html__( 'Sends by', 'nanopost-email-check' ) . '</th><td>' . esc_html( $mailer ) . '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'Schedule', 'nanopost-email-check' ) . '</th><td>';
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
					__( '%1$s. Next test: %2$s', 'nanopost-email-check' ),
					NanoPost_Email_Check_Settings::intervals()[ NanoPost_Email_Check_Settings::interval() ],
					self::format_time( $next )
				)
			);
		} else {
			esc_html_e( 'Not scheduled.', 'nanopost-email-check' );
		}
		echo '</td></tr>';

		// Each area repeats only what the owner set: where test emails go, and the webhook.
		echo '<tr><th scope="row">' . esc_html__( 'Test emails go to', 'nanopost-email-check' ) . '</th><td>';
		echo esc_html( NanoPost_Email_Check_Connection::canary_active() ? __( 'Email Canary', 'nanopost-email-check' ) : implode( ', ', NanoPost_Email_Check_Settings::recipients() ) );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Webhook', 'nanopost-email-check' ) . '</th><td>' . esc_html( self::webhook_host() ) . '</td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * "Send test now" form. Posts to admin-post.php.
	 */
	private static function render_send_form() {
		echo '<p class="description">' . esc_html(
			NanoPost_Email_Check_Connection::canary_active()
				? __( 'Send one test email to Email Canary now, and see whether it arrives. Save any changed settings first.', 'nanopost-email-check' )
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

		echo '<h2>' . esc_html__( 'Recent tests', 'nanopost-email-check' ) . '</h2>';

		if ( empty( $results ) ) {
			echo '<p>' . esc_html__( 'No results yet.', 'nanopost-email-check' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'nanopost-email-check' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Email Check', 'nanopost-email-check' ) . '</th>';
		$canary = array() !== array_filter( wp_list_pluck( $results, 'test_id' ) ) || NanoPost_Email_Check_Connection::is_connected();
		if ( $canary ) {
			echo '<th scope="col">' . esc_html__( 'Email Canary', 'nanopost-email-check' ) . '</th>';
		}
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
			if ( $canary ) {
				echo '<td class="nanopost-email-check-canary-cell">' . self::canary_cell( $result ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- canary_cell() escapes.
			}
			echo '<td>' . esc_html( $result->trigger_label() ) . '</td>';
			echo '<td>' . esc_html( self::recipient_label( $result ) ) . '</td>';
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
	 * Where a test went, as the owner sees it: "Email Canary" for a test to the canary
	 * address (never the address itself), else the recipient.
	 *
	 * @param NanoPost_Email_Check_Result $result Result.
	 * @return string
	 */
	public static function recipient_label( $result ) {
		return '' !== $result->test_id ? __( 'Email Canary', 'nanopost-email-check' ) : $result->recipient;
	}

	/**
	 * Pass or fail label.
	 *
	 * @param bool $passed Passed.
	 * @return string HTML.
	 */
	private static function status_badge( $passed ) {
		return $passed
			? '<strong style="color:#00a32a;">' . esc_html__( 'Sent', 'nanopost-email-check' ) . '</strong>'
			: '<strong style="color:#d63638;">' . esc_html__( 'Could not send', 'nanopost-email-check' ) . '</strong>';
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
