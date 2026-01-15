<?php
/**
 * Utility functions for Decentralized User Authentication.
 * Includes caching helpers and roaming user detection.
 */

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Determines whether a user is considered roaming.
 *
 * A roaming user is typically a super admin across the network.
 * Developers may override this logic via the 'dua_is_roaming_user' filter.
 *
 * @param object $user WP_User or stdClass object.
 * @return bool
 */
function dua_is_roaming_user($user) {
    $is_roaming = false;

    // Check if user is a super admin.
    if (is_object($user) && !empty($user->user_login)) {
        $super_admins = get_super_admins();
        $is_roaming   = in_array($user->user_login, $super_admins, true);
    }

    return apply_filters('dua_is_roaming_user', $is_roaming, $user);
}

/**
 * Retrieves the cached expiry time for plugin data.
 *
 * Falls back to site option if transient is missing.
 *
 * @return int Expiry time in seconds.
 */
function dua_get_cache_expiry() {
    $cached = get_transient('dua_cache_expiry_cached');

    // Return cached value if available.
    if ($cached !== false) {
        return $cached;
    }

    // Fallback to site option and cache it.
    $expiry = get_site_option('dua_cache_expiry', 3600);
    set_transient('dua_cache_expiry_cached', $expiry, HOUR_IN_SECONDS);

    return $expiry;
}

/**
 * Retrieves a site option with transient caching.
 *
 * @param string $key           Option key.
 * @param mixed  $default       Default value if option is missing.
 * @param string $transient_key Transient cache key.
 * @return mixed
 */
function dua_get_cached_option($key, $default, $transient_key) {
    $cached = get_transient($transient_key);

    // Return cached value if available.
    if ($cached !== false) {
        return $cached;
    }

    // Fallback to site option and cache it.
    $value = get_site_option($key, $default);
    set_transient($transient_key, $value, dua_get_cache_expiry());

    return $value;
}

/**
 * Retrieves the roaming secret key from network settings.
 * Falls back to default if not set. Cached via transient.
 *
 * @return string
 */
function dua_get_roaming_secret_key() {
    $default = 'dua-super-consistent-network-secret';
    return dua_get_cached_option('dua_roaming_secret_key', $default, 'dua_roaming_secret_key_cached');
}

/**
 * Retrieves the roaming cookie duration.
 * Cached via 'dua_roaming_cookie_expiry_cached'.
 *
 * @return int
 */
function dua_get_roaming_cookie_expiry() {
    return dua_get_cached_option('dua_roaming_cookie_expiry', 60, 'dua_roaming_cookie_expiry_cached');
}

/**
 * Retrieves the remote login token expiry duration.
 * Cached via 'dua_remote_login_token_expiry_cached'.
 *
 * @return int
 */
function dua_get_remote_login_token_expiry() {
    return dua_get_cached_option('dua_remote_login_token_expiry', 60, 'dua_remote_login_token_expiry_cached');
}

/**
 * Retrieves the maximum allowed login attempts.
 * Cached via 'dua_rate_limit_max_cached'.
 *
 * @return int
 */
function dua_get_rate_limit_max() {
    return dua_get_cached_option('dua_rate_limit_max', 5, 'dua_rate_limit_max_cached');
}

/**
 * Retrieves the wait time after rate limit is triggered.
 * Cached via 'dua_rate_limit_wait_cached'.
 *
 * @return int
 */
function dua_get_rate_limit_wait() {
    return dua_get_cached_option('dua_rate_limit_wait', 300, 'dua_rate_limit_wait_cached');
}

/**
 * Handles plugin activation logic.
 *
 * Loads the DuaActivator class manually and triggers schema setup
 * and MU plugin installation. This class is not autoloaded to avoid
 * unnecessary runtime overhead.
 *
 * @return void
 */
function dua_activate() {
    require_once DUA_PLUGIN_DIR . '/inc/DuaActivator.php';
    DuaActivator::activate();
}

/**
 * Handles plugin deactivation logic.
 *
 * Loads the DuaActivator class manually and removes the MU plugin loader.
 * This ensures clean teardown without autoloading unused classes.
 *
 * @return void
 */
function dua_deactivate() {
    require_once DUA_PLUGIN_DIR . '/inc/DuaActivator.php';
    DuaActivator::deactivate();
}

/**
 * Re-injects site-scoped signup validation hook after WordPress core upgrade.
 *
 * WordPress overwrites core files during upgrades, including ms-functions.php.
 * This function ensures the Decentralized User Authentication patch is reapplied
 * immediately after a core update completes.
 *
 * @param WP_Upgrader $upgrader WP_Upgrader instance.
 * @param array       $options  Upgrade context including 'action' and 'type'.
 * @return void
 */
function dua_after_wordpress_upgrade($upgrader, $options) {
    if (
        isset($options['action'], $options['type']) &&
        $options['action'] === 'update' &&
        $options['type'] === 'core'
    ) {
        require_once DUA_PLUGIN_DIR . '/inc/DuaActivator.php';
        DuaActivator::injectSignupHook();
    }
}
