<?php
/**
 * Remove everything the plugin stored. Runs when the plugin is deleted.
 *
 * @package NanoPost_Email_Check
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Tell nanoPost that this site's connection ends (best effort, 5 seconds). Runs only on a
 * site the owner connected. The plugin's classes are not loaded here. On a network, calls
 * stop 20 seconds after the first one, so a large network does not time out. (WordPress
 * includes this file inside a function, so the start time is kept in a static variable.)
 */
function nanopost_email_check_uninstall_disconnect() {
	static $deadline = null;
	if ( null === $deadline ) {
		$deadline = time() + 20;
	}
	$connection = get_option( 'nanopost_email_check_connection', array() );
	if ( ! is_array( $connection ) || empty( $connection['token'] ) || ! is_string( $connection['token'] ) || time() >= $deadline ) {
		return;
	}
	$base    = defined( 'NANOPOST_EMAIL_CHECK_APP_URL' ) && is_string( NANOPOST_EMAIL_CHECK_APP_URL ) && '' !== NANOPOST_EMAIL_CHECK_APP_URL ? untrailingslashit( NANOPOST_EMAIL_CHECK_APP_URL ) : 'https://app.nanopo.st/api/v1';
	$version = '';
	if ( function_exists( 'get_file_data' ) ) {
		$version = (string) get_file_data( __DIR__ . '/nanopost-email-check.php', array( 'version' => 'Version' ) )['version'];
	}
	wp_remote_request(
		$base . '/connection',
		array(
			'method'      => 'DELETE',
			'timeout'     => 5,
			'redirection' => 0,
			'user-agent'  => 'nanoPost-Email-Check/' . $version,
			'headers'     => array(
				'Accept'           => 'application/json',
				'Authorization'    => 'Bearer ' . $connection['token'],
				'X-Plugin-Version' => $version,
			),
		)
	);
}

/**
 * Remove the plugin's data from the current site.
 */
function nanopost_email_check_uninstall_site() {
	global $wpdb;

	nanopost_email_check_uninstall_disconnect();

	delete_option( 'nanopost_email_check_connection' );
	delete_option( 'nanopost_email_check_connection_notice' );
	delete_option( 'nanopost_email_check_app_log' );
	wp_clear_scheduled_hook( 'nanopost_email_check_site_sync' );
	delete_option( 'nanopost_email_check_settings' );
	delete_option( 'nanopost_email_check_log' );
	delete_option( 'nanopost_email_check_webhook_state' );
	delete_option( 'nanopost_email_check_schedule_state' );
	delete_transient( 'nanopost_email_check_send_lock' );
	delete_transient( 'nanopost_email_check_test_alert_lock' );
	// Per-user connect state and proof transients.
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\\_transient\\_nanopost\\_email\\_check\\_connect\\_state\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nanopost\\_email\\_check\\_connect\\_state\\_%' OR option_name LIKE '\\_transient\\_nanopost\\_email\\_check\\_proof\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nanopost\\_email\\_check\\_proof\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time cleanup of transients with a user ID in the name.
	wp_clear_scheduled_hook( 'nanopost_email_check_run' );

	// Dismissed notices are stored per site as a user option (blog prefix + key).
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'nanopost_email_check_dismissed', '', true );
}

if ( is_multisite() ) {
	$nanopost_email_check_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $nanopost_email_check_sites as $nanopost_email_check_site_id ) {
		switch_to_blog( $nanopost_email_check_site_id );
		nanopost_email_check_uninstall_site();
		restore_current_blog();
	}
} else {
	nanopost_email_check_uninstall_site();
}
