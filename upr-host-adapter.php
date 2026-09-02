<?php
/**
 * Plugin Name: UPR Host Adapter
 * Description: Host-side adapters for Universal Product Reviews (delivery, support, pilot policy, DEV verification).
 * Version: 0.1.3
 * Author: UPR Host Adapter contributors
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce, universal-product-reviews
 * Text Domain: upr-host-adapter
 * License: GPL-2.0-or-later
 *
 * @package Upr_Host_Adapter
 */

defined( 'ABSPATH' ) || exit;

define( 'UPR_HOST_ADAPTER_VERSION', '0.1.3' );
define( 'UPR_HOST_ADAPTER_FILE', __FILE__ );
define( 'UPR_HOST_ADAPTER_PATH', plugin_dir_path( __FILE__ ) );

require_once UPR_HOST_ADAPTER_PATH . 'includes/class-upr-pin.php';
require_once UPR_HOST_ADAPTER_PATH . 'includes/class-options.php';
require_once UPR_HOST_ADAPTER_PATH . 'includes/class-delivery-adapter.php';
require_once UPR_HOST_ADAPTER_PATH . 'includes/class-support-adapter.php';
require_once UPR_HOST_ADAPTER_PATH . 'includes/class-invitation-send-policy.php';
require_once UPR_HOST_ADAPTER_PATH . 'includes/class-review-availability-ux.php';
require_once UPR_HOST_ADAPTER_PATH . 'includes/class-admin-settings.php';
require_once UPR_HOST_ADAPTER_PATH . 'includes/class-plugin.php';

/**
 * Automatic updates via the private update server. Define PRIVATE_UPDATE_SERVER
 * (scheme + host, no trailing slash) in wp-config.php to enable; when it is not
 * defined the plugin does not check for updates.
 */
if ( defined( 'PRIVATE_UPDATE_SERVER' ) && PRIVATE_UPDATE_SERVER ) {
	require_once UPR_HOST_ADAPTER_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		rtrim( (string) PRIVATE_UPDATE_SERVER, '/' ) . '/?action=get_metadata&slug=upr-host-adapter',
		UPR_HOST_ADAPTER_FILE,
		'upr-host-adapter'
	);
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Upr_Host_Adapter_Plugin::instance()->init();
	},
	20
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once UPR_HOST_ADAPTER_PATH . 'cli/class-verify-dev-mail-command.php';
	require_once UPR_HOST_ADAPTER_PATH . 'cli/class-verify-pilot-preflight-command.php';
	require_once UPR_HOST_ADAPTER_PATH . 'cli/class-verify-wp6-dev-command.php';
	require_once UPR_HOST_ADAPTER_PATH . 'cli/class-verify-support-dev-command.php';
	require_once UPR_HOST_ADAPTER_PATH . 'cli/class-verify-as-drain-dev-command.php';
	require_once UPR_HOST_ADAPTER_PATH . 'cli/class-verify-token-redaction-dev-command.php';
	require_once UPR_HOST_ADAPTER_PATH . 'cli/class-verify-native-submit-dev-command.php';
	WP_CLI::add_command( 'upr-host-adapter verify-dev-mail', 'Upr_Host_Adapter_Verify_Dev_Mail_Command' );
	WP_CLI::add_command( 'upr-host-adapter verify-pilot-preflight', 'Upr_Host_Adapter_Verify_Pilot_Preflight_Command' );
	WP_CLI::add_command( 'upr-host-adapter verify-wp6-dev', 'Upr_Host_Adapter_Verify_Wp6_Dev_Command' );
	WP_CLI::add_command( 'upr-host-adapter verify-support-dev', 'Upr_Host_Adapter_Verify_Support_Dev_Command' );
	WP_CLI::add_command( 'upr-host-adapter verify-as-drain-dev', 'Upr_Host_Adapter_Verify_As_Drain_Dev_Command' );
	WP_CLI::add_command( 'upr-host-adapter verify-token-redaction-dev', 'Upr_Host_Adapter_Verify_Token_Redaction_Dev_Command' );
	WP_CLI::add_command( 'upr-host-adapter verify-native-submit-dev', 'Upr_Host_Adapter_Verify_Native_Submit_Dev_Command' );
}
