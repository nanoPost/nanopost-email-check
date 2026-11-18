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
	delete_option( 'nanopost_email_check_settings' );
	delete_option( 'nanopost_email_check_log' );
	delete_option( 'nanopost_email_check_webhook_state' );
	wp_clear_scheduled_hook( 'nanopost_email_check_run' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $nanopost_email_check_site_id ) {
		switch_to_blog( $nanopost_email_check_site_id );
		nanopost_email_check_uninstall_site();
		restore_current_blog();
	}
} else {
	nanopost_email_check_uninstall_site();
}

// User meta is shared by all sites in a network.
delete_metadata( 'user', 0, 'nanopost_email_check_dismissed', '', true );
