<?php
/**
 * Plugin Name: Decentralized User Authentication
 * Description: Site-scoped user authentication for WordPress multisite.
 * Version: 1.0.0
 * Author: J. Lawrence Walkollie
 * Text Domain: dua
 * Domain Path: /languages
 * Network: true
 */

// Security: Prevent direct access to this file.
defined('ABSPATH') || exit;

// Plugin Constants
define('DUA_VERSION', '1.0.0');
define('DUA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DUA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Multisite Enforcement
if (!is_multisite()) {
    add_action('admin_notices', 'dua_show_multisite_required_notice');
    return;
}

/**
 * Displays an admin notice if WordPress Multisite is not enabled.
 * Hooked into 'admin_notices' during plugin bootstrap.
 *
 * @return void
 */
function dua_show_multisite_required_notice() {
    echo '<div class="notice notice-error"><p><strong>Decentralized User Authentication</strong> requires WordPress Multisite to function.</p></div>';
}

// Network Activation Check
add_action('admin_init', 'dua_check_network_activation');

/**
 * Displays a warning if the plugin is not network-activated.
 * Hooked into 'admin_init' to ensure plugin-wide availability.
 *
 * @return void
 */
function dua_check_network_activation() {
    if (!is_plugin_active_for_network(plugin_basename(__FILE__))) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p><strong>Decentralized User Authentication</strong> must be network-activated to function properly across subsites.</p></div>';
        });
    }
}

// Activation and Deactivation Hooks
register_activation_hook(__FILE__, 'dua_activate');
register_deactivation_hook(__FILE__, 'dua_deactivate');

// Plugin Bootstrap
require_once DUA_PLUGIN_DIR . '/vendor/autoload.php';

use Dua\Plugin;

// Instantiate the core plugin class.
new Plugin();
