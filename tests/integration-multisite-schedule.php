<?php
/**
 * Integration tests: scheduling after a network activation, on new sites and outside
 * wp-admin. Included from integration.php.
 *
 * @package NanoPost_Email_Check
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Files.FileName -- Test file, not shipped.

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

/**
 * Call the plugin's wp_loaded callback, as a request to the current site would.
 */
function np_sched_wp_loaded() {
	$np_callback = array( 'NanoPost_Email_Check_Scheduler', 'ensure_scheduled' );
	if ( false !== has_action( 'wp_loaded', $np_callback ) ) {
		call_user_func( $np_callback );
	}
}

// ---------------------------------------------------------------------------------------
np_section( 'Schedule outside wp-admin' );
np_reset();
np_assert( ! is_admin(), 'the run is outside wp-admin (WP-CLI)' );
np_assert( false !== has_action( 'wp_loaded', array( 'NanoPost_Email_Check_Scheduler', 'ensure_scheduled' ) ), 'the event is checked on every request (wp_loaded), not only in wp-admin' );
np_assert( false === has_action( 'admin_init', array( 'NanoPost_Email_Check_Scheduler', 'ensure_scheduled' ) ), 'no second check on admin_init' );
np_sched_wp_loaded();
np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'a request outside wp-admin schedules a missing event' );
np_assert( NanoPost_Email_Check_Scheduler::scheduled_since() > 0, 'the grace period starts' );
$np_before = wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK );
np_sched_wp_loaded();
np_assert( wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ) === $np_before, 'a later request keeps the next run' );

// ---------------------------------------------------------------------------------------
np_section( 'Activation hook' );
np_reset();
$np_activate = 'activate_' . plugin_basename( NANOPOST_EMAIL_CHECK_FILE );
np_assert( false !== has_action( $np_activate, array( 'NanoPost_Email_Check_Scheduler', 'activate' ) ), 'the activation callback is registered' );
// Fire the hook as activate_plugin() does: $network_wide is its one argument.
do_action( $np_activate, false );
np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'activation schedules this site' );

if ( ! is_multisite() ) {
	np_reset();
	do_action( $np_activate, true );
	np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'single site: a network-wide flag still schedules this site' );
	WP_CLI::log( '  skip  network activation and new sites (not multisite)' );
} else {
	$np_site2 = (int) get_sites(
		array(
			'fields'       => 'ids',
			'site__not_in' => array( get_current_blog_id() ),
			'number'       => 1,
		)
	)[0];

	// Network activation schedules every site, not only the current one.
	np_reset();
	switch_to_blog( $np_site2 );
	np_reset();
	restore_current_blog();
	do_action( $np_activate, true );
	np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'network activation schedules the current site' );
	switch_to_blog( $np_site2 );
	np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'network activation schedules the second site' );
	np_assert( NanoPost_Email_Check_Scheduler::scheduled_since() > 0, 'the second site grace period starts' );
	restore_current_blog();
	$np_sites   = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	$np_missing = array();
	foreach ( $np_sites as $np_site ) {
		switch_to_blog( $np_site );
		if ( ! wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ) ) {
			$np_missing[] = $np_site;
		}
		restore_current_blog();
	}
	np_assert( array() === $np_missing, 'network activation schedules every site (' . count( $np_sites ) . ')', $np_missing );

	// A site activation (not network-wide) schedules only the current site.
	np_reset();
	switch_to_blog( $np_site2 );
	np_reset();
	restore_current_blog();
	do_action( $np_activate, false );
	np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'site activation schedules the current site' );
	switch_to_blog( $np_site2 );
	np_assert( false === wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'site activation leaves the other site alone' );
	restore_current_blog();

	// A new site. Creating one is DDL and would end the test transaction, so call the
	// wp_initialize_site callback for the existing second site, after clearing its event.
	$np_priority = has_action( 'wp_initialize_site', array( 'NanoPost_Email_Check_Scheduler', 'initialize_site' ) );
	np_assert( false !== $np_priority && $np_priority > 10, 'new sites are handled after core sets them up (priority > 10)', $np_priority );

	$np_basename = plugin_basename( NANOPOST_EMAIL_CHECK_FILE );
	$np_network  = static function ( $value ) use ( $np_basename ) {
		$value = is_array( $value ) ? $value : array();
		unset( $value[ $np_basename ] );
		return $value;
	};
	switch_to_blog( $np_site2 );
	np_reset();
	restore_current_blog();
	add_filter( 'site_option_active_sitewide_plugins', $np_network );
	NanoPost_Email_Check_Scheduler::initialize_site( get_site( $np_site2 ) );
	remove_filter( 'site_option_active_sitewide_plugins', $np_network );
	switch_to_blog( $np_site2 );
	np_assert( false === wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'new site: not scheduled when the plugin is not network-active' );
	restore_current_blog();

	$np_network = static function ( $value ) use ( $np_basename ) {
		$value                 = is_array( $value ) ? $value : array();
		$value[ $np_basename ] = time();
		return $value;
	};
	add_filter( 'site_option_active_sitewide_plugins', $np_network );
	NanoPost_Email_Check_Scheduler::initialize_site( get_site( $np_site2 ) );
	remove_filter( 'site_option_active_sitewide_plugins', $np_network );
	np_assert( get_current_blog_id() !== $np_site2, 'new site: the current site is restored' );
	switch_to_blog( $np_site2 );
	$np_event = wp_get_scheduled_event( NanoPost_Email_Check_Scheduler::HOOK );
	np_assert( $np_event && 'daily' === $np_event->schedule, 'new site: scheduled daily when the plugin is network-active', $np_event );
	np_assert( NanoPost_Email_Check_Scheduler::scheduled_since() > 0, 'new site: the grace period starts' );
	restore_current_blog();

	// A request to a site that was never scheduled (for example a site that existed before
	// an update to this version) schedules it, with no admin visit.
	switch_to_blog( $np_site2 );
	np_reset();
	np_sched_wp_loaded();
	np_assert( false !== wp_next_scheduled( NanoPost_Email_Check_Scheduler::HOOK ), 'a request to the second site schedules it' );
	np_reset();
	restore_current_blog();
	np_reset();
}
