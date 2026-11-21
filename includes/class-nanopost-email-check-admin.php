<?php
/**
 * Admin screen at Tools > Email Check.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings form, "Send test now" and the result log.
 */
class NanoPost_Email_Check_Admin {

	const PAGE        = 'nanopost-email-check';
	const SEND_ACTION = 'nanopost_email_check_send';
	const CAPABILITY  = 'manage_options';
	const SEND_LOCK   = 'nanopost_email_check_send_lock';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( __CLASS__, 'handle_send' ) );
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
	 * Webhook URL field, with the result of the last delivery.
	 */
	public static function field_webhook_url() {
		$settings = NanoPost_Email_Check_Settings::get();
		printf(
			'<input type="url" class="large-text code" id="nanopost_email_check_webhook_url" name="%1$s[webhook_url]" value="%2$s" placeholder="https://hooks.slack.com/services/..." autocomplete="off" />',
			esc_attr( NanoPost_Email_Check_Settings::OPTION ),
			esc_attr( $settings['webhook_url'] )
		);
		echo '<p class="description">';
		esc_html_e( 'Optional. When a check fails, the plugin posts a short JSON message to this URL, and once more when checks pass again. Works with Slack, Google Chat, Discord (Slack-compatible URL) and automation services. Leave empty for no outside requests.', 'nanopost-email-check' );
		echo ' ' . self::help_link( NanoPost_Email_Check_Help::webhook(), __( 'How to set up a webhook', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo '</p>';

		$delivery = NanoPost_Email_Check_Webhook::state()['last_delivery'];
		if ( '' !== $settings['webhook_url'] && ! empty( $delivery['time'] ) ) {
			echo '<p class="description">';
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: "delivered" or "failed", 3: HTTP status or error text. */
					__( 'Last webhook message: %1$s, %2$s (%3$s).', 'nanopost-email-check' ),
					self::format_time( (int) $delivery['time'] ),
					! empty( $delivery['ok'] ) ? __( 'delivered', 'nanopost-email-check' ) : __( 'failed', 'nanopost-email-check' ),
					isset( $delivery['detail'] ) ? (string) $delivery['detail'] : ''
				)
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
			'<input type="email" class="regular-text" id="nanopost_email_check_recipient" name="%1$s[recipient]" value="%2$s" placeholder="%3$s" />',
			esc_attr( NanoPost_Email_Check_Settings::OPTION ),
			esc_attr( $settings['recipient'] ),
			esc_attr( $admin )
		);
		echo '<p class="description">';
		printf(
			/* translators: %s: site admin email address. */
			esc_html__( 'Use an address you read often, ideally at a different provider from this site. Leave empty to use the site admin email (%s).', 'nanopost-email-check' ),
			esc_html( $admin )
		);
		echo '</p>';
	}

	/**
	 * Interval field.
	 */
	public static function field_interval() {
		$current = NanoPost_Email_Check_Settings::interval();
		printf( '<select id="nanopost_email_check_interval" name="%s[interval]">', esc_attr( NanoPost_Email_Check_Settings::OPTION ) );
		foreach ( NanoPost_Email_Check_Settings::intervals() as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
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
		}

		set_transient( self::notice_key(), $passed ? 'passed' : 'failed', MINUTE_IN_SECONDS );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Per-user key for the one-time "Send test now" notice.
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
		echo self::help_link( NanoPost_Email_Check_Help::home(), __( 'Help', 'nanopost-email-check' ), 'page-title-action' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
		echo '<hr class="wp-header-end">';

		// Screens outside the Settings menu must print these themselves. No argument, so the
		// "Settings saved." message (stored under "general") shows too.
		settings_errors();
		self::render_send_notice();
		// Section ids are stable hooks for styling, tests and documentation screenshots.
		echo '<div id="nanopost-email-check-status">';
		self::render_status();
		echo '</div>';

		echo '<form id="nanopost-email-check-settings" method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( NanoPost_Email_Check_Settings::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';

		echo '<div id="nanopost-email-check-send">';
		self::render_send_form();
		echo '</div>';

		echo '<div id="nanopost-email-check-log">';
		self::render_log();
		echo '</div>';

		echo '</div>';
	}

	/**
	 * One-time notice after "Send test now".
	 */
	private static function render_send_notice() {
		$outcome = get_transient( self::notice_key() );
		if ( false === $outcome ) {
			return;
		}
		delete_transient( self::notice_key() );

		if ( 'wait' === $outcome ) {
			$class   = 'notice-warning';
			$message = __( 'A test email was sent less than one minute ago. Wait one minute, then try again.', 'nanopost-email-check' );
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
				echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::pass(), __( 'What a pass means', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			} else {
				echo '<br /><code>' . esc_html( $latest->error_message() ) . '</code> &middot; ';
				echo self::help_link( NanoPost_Email_Check_Help::for_failure( $latest ), __( 'What this error means', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
			}
		}
		echo '</td></tr>';

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
			echo ' &middot; ' . self::help_link( NanoPost_Email_Check_Help::cron(), __( 'How to fix WP-Cron', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
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
			sprintf(
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

		foreach ( $results as $result ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::format_time( $result->time ) ) . '</td>';
			echo '<td>' . wp_kses_post( self::status_badge( $result->passed ) ) . '</td>';
			echo '<td>' . esc_html( NanoPost_Email_Check_Result::TRIGGER_MANUAL === $result->trigger ? __( 'Manual', 'nanopost-email-check' ) : __( 'Schedule', 'nanopost-email-check' ) ) . '</td>';
			echo '<td>' . esc_html( $result->recipient ) . '</td>';
			echo '<td>';
			if ( $result->passed ) {
				echo '&mdash;';
			} else {
				echo '<code>' . esc_html( $result->error_message() ) . '</code> ';
				echo self::help_link( NanoPost_Email_Check_Help::for_failure( $result ), __( 'What this means', 'nanopost-email-check' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in help_link().
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
	private static function format_time( $time ) {
		$date = self::format_date( $time );
		$diff = human_time_diff( $time, time() );

		return $time <= time()
			/* translators: 1: date and time, 2: time span such as "5 mins". */
			? sprintf( __( '%1$s (%2$s ago)', 'nanopost-email-check' ), $date, $diff )
			/* translators: 1: date and time, 2: time span such as "5 mins". */
			: sprintf( __( '%1$s (in %2$s)', 'nanopost-email-check' ), $date, $diff );
	}
}
