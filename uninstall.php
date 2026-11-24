<?php
/**
 * Remove everything the plugin stored. Runs when the plugin is deleted.
 *
 * @package NanoPost_Email_Check
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 */
function nanopost_email_check_uninstall_site() {
	global $wpdb;

	delete_option( 'nanopost_email_check_settings' );
	delete_option( 'nanopost_email_check_log' );
	delete_option( 'nanopost_email_check_webhook_state' );
	delete_option( 'nanopost_email_check_schedule_state' );
	delete_transient( 'nanopost_email_check_send_lock' );
	delete_transient( 'nanopost_email_check_test_alert_lock' );
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
