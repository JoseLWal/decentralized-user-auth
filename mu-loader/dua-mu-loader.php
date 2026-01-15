<?php
/**
 * MU plugin: Early cookie scoping for multisite isolation.
 *
 * Sets site-specific cookie constants before WordPress loads fully.
 * Enables roaming support via externally validated cookies and filters.
 * Requires a shared network salt for consistent token signing.
 */

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Step 1: Define default cookie constants early.
 *
 * These constants are scoped per site and per browser instance.
 * They may be overridden later if roaming logic applies.
 */
$host       = $_SERVER['HTTP_HOST'] ?? 'default.local';
$cleanHost  = preg_replace('/[^a-z0-9.-]/', '', strtolower($host));
$blogId     = get_current_blog_id();
$userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? '';
$uaHash     = substr(md5($userAgent), 0, 8); // Short hash per browser

// Construct cookie suffix using host, site ID, and UA hash.
$cookieSuffix = md5($cleanHost) . "_site_{$blogId}_{$uaHash}";
$cookieDomain = $cleanHost;

// Define WordPress cookie constants for this site context.
define('COOKIEHASH', $cookieSuffix);
define('LOGGED_IN_COOKIE', 'wordpress_logged_in_' . COOKIEHASH);
define('AUTH_COOKIE', 'wordpress_' . COOKIEHASH);
define('SECURE_AUTH_COOKIE', 'wordpress_sec_' . COOKIEHASH);
define('USER_COOKIE', 'wordpress_user_' . COOKIEHASH);
define('PASS_COOKIE', 'wordpress_pass_' . COOKIEHASH);
define('TEST_COOKIE', 'wordpress_test_cookie_' . COOKIEHASH);
define('COOKIE_DOMAIN', $cookieDomain);
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');

/**
 * Step 2: Include the roaming cookie handler, and necessary files.
 * This class manages cross-site authentication via signed cookies.
 */
require_once WP_PLUGIN_DIR . '/decentralized-user-auth/inc/utils.php';
require_once WP_PLUGIN_DIR . '/decentralized-user-auth/inc/auth/DuaRoamingCookie.php';

/**
 * Step 3: Hook into WordPress login and session lifecycle.
 * These hooks enable roaming user detection and cookie propagation.
 */
// Fires after successful login and cookie creation.
// Used to set roaming cookie for cross-site authentication.
add_action('wp_login', ['DuaRoamingCookie', 'onLogin'], 10, 2);

// Fires when WordPress sets the current user.
// Used to validate roaming cookie and switch user context if needed.
add_action('set_current_user', ['DuaRoamingCookie', 'onValidateSession']);

// Fires during login form initialization.
// Used to bypass reauthentication if roaming session is already valid.
add_action('login_init', ['DuaRoamingCookie', 'maybeBypassReauth']);

// Fires before logout.
// Used to delete roaming cookie and clean up session state.
add_action('wp_logout', ['DuaRoamingCookie', 'onLogout']);
