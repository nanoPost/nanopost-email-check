<?php
/**
 * Admin notices for a failed or stale check.
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows a notice to users who can manage options when the last check failed, or when
 * scheduled checks have stopped. Each notice goes away by itself once the problem is
 * gone. A user can also dismiss one notice; a new failure shows a new notice.
 */
class NanoPost_Email_Check_Alerts {

	const DISMISS_ACTION = 'nanopost_email_check_dismiss';
	const DISMISSED_META = 'nanopost_email_check_dismissed';

	/**
	 * The WP-Cron lock ("doing_cron" transient) before WordPress may start WP-Cron for this
	 * request. Null when not recorded.
	 *
	 * @var string|null
	 */
	private static $cron_lock_before = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
		// WordPress starts WP-Cron on wp_loaded (priority 10). Record the lock before that.
		add_action( 'wp_loaded', array( __CLASS__, 'remember_cron_lock' ), 0 );
	}

	/**
	 * Record the WP-Cron lock before WordPress may start WP-Cron for this request.
	 */
	public static function remember_cron_lock() {
		self::$cron_lock_before = (string) get_transient( 'doing_cron' );
	}

	/**
	 * True when this request has just started WP-Cron: the lock is set and changed since the
	 * start of the request. WP-Cron then runs in a separate request that may not have finished.
	 *
	 * @return bool
	 */
	private static function cron_started_now() {
		if ( null === self::$cron_lock_before ) {
			return false;
		}
		$lock = (string) get_transient( 'doing_cron' );
		return '' !== $lock && $lock !== self::$cron_lock_before;
	}

	/**
	 * The newest failed result among the latest result of each check, or null when every
	 * check passed last time.
	 *
	 * @return NanoPost_Email_Check_Result|null
	 */
	public static function failed_result() {
		foreach ( NanoPost_Email_Check_Log::latest_per_check() as $result ) {
			if ( ! $result->passed ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * When scheduled checks have stopped: the time since which no scheduled check has
	 * finished. Null when checks run on time.
	 *
	 * Stopped means no scheduled check finished for more than twice the interval, counted
	 * from the last finished check or, before the first one, from the start of the schedule.
	 * This covers WP-Cron not running, and checks that start but crash (WP-Cron books the
	 * next run before it runs the check, so a crash leaves a future event).
	 *
	 * @return int|null Unix time.
	 */
	public static function stale_since() {
		$baseline = max( NanoPost_Email_Check_Scheduler::last_completed(), NanoPost_Email_Check_Scheduler::scheduled_since() );
		if ( 0 === $baseline ) {
			return null;
		}

		// This page load has just started WP-Cron, which may be running the event right now.
		// If it does not, the next page load shows the notice.
		if ( self::cron_started_now() ) {
			return null;
		}

		$interval = NanoPost_Email_Check_Scheduler::current_interval_seconds();
		return ( time() - $baseline > 2 * $interval ) ? $baseline : null;
	}

	/**
	 * True when DISABLE_WP_CRON is on, so only a server cron job runs scheduled events.
	 *
	 * @return bool
	 */
	public static function wp_cron_disabled() {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Active alerts, keyed by a string that changes with each new problem.
	 *
	 * @return array Key => type ("failed" or "stale").
	 */
	public static function active() {
		$alerts = array();

		$failed = self::failed_result();
		if ( null !== $failed ) {
			$alerts[ 'failed:' . $failed->time ] = 'failed';
		}

		$stale = self::stale_since();
		if ( null !== $stale ) {
			$alerts[ 'stale:' . $stale ] = 'stale';
		}

		return $alerts;
	}

	/**
	 * Print the notices.
	 */
	public static function render() {
		if ( ! current_user_can( NanoPost_Email_Check_Admin::CAPABILITY ) ) {
			return;
		}

		// The plugin screen shows the same facts in its status table.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'tools_page_' . NanoPost_Email_Check_Admin::PAGE === $screen->id ) {
			return;
		}

		$dismissed = self::dismissed();

		foreach ( self::active() as $key => $type ) {
			if ( in_array( $key, $dismissed, true ) ) {
				continue;
			}

			if ( 'failed' === $type ) {
				self::render_failed( $key );
			} else {
				self::render_stale( $key );
			}
		}
	}

	/**
	 * Notice for a failed check.
	 *
	 * @param string $key Alert key.
	 */
	private static function render_failed( $key ) {
		$result = self::failed_result();

		echo '<div id="nanopost-email-check-notice-failed" class="notice notice-error"><p>';
		echo '<strong>' . esc_html__( 'Email check failed.', 'nanopost-email-check' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: date and time, 2: email address. */
				__( 'On %1$s, WordPress could not send the test email to %2$s. Your site may not be able to send email.', 'nanopost-email-check' ),
				NanoPost_Email_Check_Admin::format_date( $result->time ),
				$result->recipient
			)
		);
		echo '</p><p>' . esc_html__( 'Error:', 'nanopost-email-check' ) . ' <code>' . esc_html( $result->error_message() ) . '</code></p>';

		self::render_links(
			$key,
			array(
				NanoPost_Email_Check_Help::for_failure( $result, NanoPost_Email_Check_Help::PLACE_NOTICE_FAILED ) => __( 'What this error means', 'nanopost-email-check' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Notice for stopped scheduled checks.
	 *
	 * @param string $key Alert key.
	 */
	private static function render_stale( $key ) {
		echo '<div id="nanopost-email-check-notice-stale" class="notice notice-warning"><p>';
		echo '<strong>' . esc_html__( 'Email checks have stopped.', 'nanopost-email-check' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %s: date and time. */
				__( 'No scheduled email check has finished since %s. WP-Cron may not be running on this site, or the check may be stopping with a PHP error.', 'nanopost-email-check' ),
				NanoPost_Email_Check_Admin::format_date( (int) self::stale_since() )
			)
		);
		echo '</p>';

		if ( self::wp_cron_disabled() ) {
			echo '<p>' . esc_html__( 'DISABLE_WP_CRON is set in wp-config.php, so a cron job on the server must run wp-cron.php.', 'nanopost-email-check' ) . '</p>';
		}

		self::render_links(
			$key,
			array(
				NanoPost_Email_Check_Help::cron( NanoPost_Email_Check_Help::PLACE_NOTICE_STALE ) => __( 'How to fix WP-Cron', 'nanopost-email-check' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Help link(s), a link to the plugin screen, and a dismiss link.
	 *
	 * @param string $key   Alert key.
	 * @param array  $links Help URL => label.
	 */
	private static function render_links( $key, $links ) {
		$parts = array();

		foreach ( $links as $url => $label ) {
			$parts[] = sprintf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $url ), esc_html( $label ) );
		}

		$parts[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( NanoPost_Email_Check_Admin::page_url() ), esc_html__( 'View results', 'nanopost-email-check' ) );

		$dismiss = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISMISS_ACTION,
					'alert'  => rawurlencode( $key ),
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION
		);
		$parts[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $dismiss ), esc_html__( 'Dismiss', 'nanopost-email-check' ) );

		echo '<p>' . wp_kses_post( implode( ' &middot; ', $parts ) ) . '</p>';
	}

	/**
	 * Alert keys the current user dismissed on this site. Stored per site (user option), so
	 * on multisite a dismissal on one site does not affect another.
	 *
	 * @return string[]
	 */
	private static function dismissed() {
		$dismissed = get_user_option( self::DISMISSED_META, get_current_user_id() );
		return is_array( $dismissed ) ? array_map( 'strval', $dismissed ) : array();
	}

	/**
	 * Dismiss one alert for the current user, then go back.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( NanoPost_Email_Check_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nanopost-email-check' ), 403 );
		}
		check_admin_referer( self::DISMISS_ACTION );

		$key    = isset( $_GET['alert'] ) ? sanitize_text_field( wp_unslash( $_GET['alert'] ) ) : '';
		$active = self::active();

		if ( isset( $active[ $key ] ) ) {
			// Keep only keys of alerts that are still active, plus this one.
			$dismissed   = array_intersect( self::dismissed(), array_keys( $active ) );
			$dismissed[] = $key;
			update_user_option( get_current_user_id(), self::DISMISSED_META, array_values( array_unique( $dismissed ) ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
