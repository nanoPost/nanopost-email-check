<?php
/**
 * Plugin Name:       nanoPost Email Check
 * Plugin URI:        https://nanopo.st/email-check-plugin/
 * Description:       Check that your site can still send email. Sends a scheduled test email, logs each result and warns you when a check fails.
 * Version:           1.6.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            nanoPost
 * Author URI:        https://nanopo.st/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nanopost-email-check
 *
 * @package NanoPost_Email_Check
 */

defined( 'ABSPATH' ) || exit;

define( 'NANOPOST_EMAIL_CHECK_VERSION', '1.6.0' );
define( 'NANOPOST_EMAIL_CHECK_FILE', __FILE__ );
define( 'NANOPOST_EMAIL_CHECK_DIR', plugin_dir_path( __FILE__ ) );

require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/interface-nanopost-email-check-check.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-result.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-mailer.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-settings.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-log.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-mail-check.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-scheduler.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-plugin.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-help.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-alerts.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-webhook.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-connection.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-app.php';
require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-cli.php';
	require_once NANOPOST_EMAIL_CHECK_DIR . 'includes/class-nanopost-email-check-cli-settings.php';
}

register_activation_hook( __FILE__, array( 'NanoPost_Email_Check_Scheduler', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NanoPost_Email_Check_Scheduler', 'deactivate' ) );

NanoPost_Email_Check_Plugin::boot();
