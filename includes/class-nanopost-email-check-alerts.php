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
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * The latest result, if it failed.
	 *
	 * @return NanoPost_Email_Check_Result|null
	 */
	public static function failed_result() {
		$latest = NanoPost_Email_Check_Log::latest();
		return ( null !== $latest && ! $latest->passed ) ? $latest : null;
	}

	/**
	 * When scheduled checks have stopped: the time the missed check was due. Null when
	 * checks run on time.
	 *
	 * The event is overdue by more than one interval, so the last scheduled check is older
	 * than twice the interval.
	 *
	 * @return int|null Unix time.
	 */
	public static function stale_since() {
		$next = NanoPost_Email_Check_Scheduler::next_run();
		if ( ! $next ) {
			return null;
		}

		$interval = NanoPost_Email_Check_Scheduler::interval_seconds( NanoPost_Email_Check_Settings::interval() );
		return ( time() - $next > $interval ) ? (int) $next : null;
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

		$dismissed = (array) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );

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

		echo '<div class="notice notice-error"><p>';
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
				NanoPost_Email_Check_Help::for_failure( $result ) => __( 'What this error means', 'nanopost-email-check' ),
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
		echo '<div class="notice notice-warning"><p>';
		echo '<strong>' . esc_html__( 'Email checks have stopped.', 'nanopost-email-check' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %s: date and time. */
				__( 'A scheduled email check was due on %s and has not run. WP-Cron may not be running on this site.', 'nanopost-email-check' ),
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
				NanoPost_Email_Check_Help::cron() => __( 'How to fix WP-Cron', 'nanopost-email-check' ),
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
			$dismissed   = array_intersect( (array) get_user_meta( get_current_user_id(), self::DISMISSED_META, true ), array_keys( $active ) );
			$dismissed[] = $key;
			update_user_meta( get_current_user_id(), self::DISMISSED_META, array_values( array_unique( $dismissed ) ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
