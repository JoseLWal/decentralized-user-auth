<?php
namespace Dua;

// Prevent direct access.
defined('ABSPATH') || exit;

// Load required classes for plugin initialization.
use Dua\UserHooks;
use Dua\AjaxController;
use Dua\SiteScopedSignup;
use Dua\Admin\NetworkSettingsPage;
use Dua\Admin\AddUserPageController;
use Dua\Admin\LinkedAccountsProfile;

/**
 * Core plugin bootstrap class.
 *
 * Handles dependency loading, localization, asset registration,
 * and component initialization.
 */
class Plugin {

    /**
     * Initializes the plugin lifecycle.
     * Called during plugin instantiation from the main file.
     */
    public function __construct() {
        $this->loadDependencies();
        $this->registerTextDomain();
        $this->enqueueAdminAssets();
        $this->registerHooks();
        $this->initializeComponents();
    }

    /**
     * Loads internal utility files and overrides.
     * Includes helper functions and pluggable overrides.
     */
    private function loadDependencies() {
        require_once DUA_PLUGIN_DIR . 'inc/utils.php';
        require_once DUA_PLUGIN_DIR . 'overrides/pluggable.php';
    }

    /**
     * Registers plugin text domain for localization.
     * Hooked into 'plugins_loaded'.
     */
    private function registerTextDomain() {
        add_action('plugins_loaded', [$this, 'loadTextDomain']);
    }

    /**
     * Loads plugin translation files.
     *
     * @return void
     */
    public function loadTextDomain() {
        load_plugin_textdomain('dua', false, dirname(plugin_basename(__FILE__)) . '/../languages');
    }

    /**
     * Registers admin asset loading hook.
     * Hooked into 'admin_enqueue_scripts'.
     */
    private function enqueueAdminAssets() {
        add_action('admin_enqueue_scripts', [$this, 'loadAdminAssets']);
    }

    /**
     * Loads admin-specific JavaScript and CSS assets.
     * Only enqueued specific site and network admin page.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function loadAdminAssets($hook) {
        // Pages in single-site and network admin that need the assets.
        $singleSiteHooks = ['profile.php', 'user-edit.php'];
        $networkHooks = ['settings.php', 'site-info.php', 'site-users.php'];

        // Determine if we're on a supported single-site or network admin page.
        $loadAssetsForSite = in_array($hook, $singleSiteHooks, true);
        $loadAssetsForNetwork = is_network_admin() && in_array($hook, $networkHooks, true);

        // Load assets only if the current page matches one of the allowed contexts.
        if ($loadAssetsForSite || $loadAssetsForNetwork) {
            wp_enqueue_script(
                'dua-admin-js',
                DUA_PLUGIN_URL . '/assets/js/admin.js',
                ['jquery'],
                null,
                true
            );

            wp_enqueue_style(
                'dua-admin-css',
                DUA_PLUGIN_URL . '/assets/css/admin.css',
                [],
                null
            );
        }
    }

    /**
     * Registers global plugin hooks.
     *
     * Includes core patching logic and UI suppression for multisite environments.
     * Called during plugin bootstrap to ensure early execution.
     *
     * @return void
     */
    public static function registerHooks() {
        // Re-inject signup hook after WordPress core upgrade.
        add_action('upgrader_process_complete', 'dua_after_wordpress_upgrade', 10, 2);
    }

    /**
     * Initializes plugin components and registers hooks.
     * Instantiates admin pages and registers core hooks.
     */
    private function initializeComponents() {
        UserHooks::registerHooks();
        AjaxController::registerHooks();
        SiteScopedSignup::registerHooks();

        new NetworkSettingsPage();
        new LinkedAccountsProfile();
        new AddUserPageController();
    }
}
