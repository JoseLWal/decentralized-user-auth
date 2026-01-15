<?php
namespace Dua;

use Dua\AuthToken;
use WP_Error;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Handles linking and unlinking of user accounts across subsites.
 * Also manages remote login via token-based authentication.
 */
class UserLinker {

    /**
     * Links a subsite account to a main user account.
     * Validates credentials and updates user metadata.
     *
     * @param int    $mainUserId
     * @param string $siteUrl
     * @param string $username
     * @param string $password
     * @return array|\WP_Error
     */
    public static function linkAccount($mainUserId, $siteUrl, $username, $password) {
        $parsedHost = parse_url($siteUrl, PHP_URL_HOST);
        $site       = get_site_by_path($parsedHost, '/');

        // Validate site existence.
        if (!$site) {
            return new WP_Error('invalid_site', 'Invalid subsite URL.');
        }

        $blogId = (int) $site->blog_id;

        // Attempt to retrieve user by login or email.
        $user = get_user_by('login', $username, $blogId) ?: get_user_by('email', $username, $blogId);

        // Validate credentials.
        if (!$user || !wp_check_password($password, $user->user_pass, $user->ID)) {
            return new WP_Error('auth_failed', 'Authentication failed.');
        }

        // Check for existing linkage.
        $existingMainId = (int) $user->main_id;
        if ($existingMainId && $existingMainId !== $mainUserId) {
            return new WP_Error('already_linked', 'This account is already linked to another user.');
        }

        global $wpdb;

        // Link account by updating main_id.
        $wpdb->update($wpdb->users, ['main_id' => $mainUserId], ['ID' => $user->ID]);

        return [
            'site_id'    => $blogId,
            'site_url'   => $siteUrl,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'ID'         => $user->ID
        ];
    }

    /**
     * Unlinks a subsite account from its main user.
     * Requires ownership or network-level capability.
     *
     * @param int $userId
     * @param int $currentUserId
     * @return bool|\WP_Error
     */
    public static function unlinkAccount($userId, $currentUserId) {
        global $wpdb;

        $linkedMainId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT main_id FROM {$wpdb->users} WHERE ID = %d", $userId
        ));

        $isMainUser      = ($linkedMainId === $currentUserId);
        $hasNetworkCaps  = is_main_site() && current_user_can('manage_network_users');

        // Validate permission to unlink.
        if (!$isMainUser && !$hasNetworkCaps) {
            return new WP_Error('unauthorized', 'Unauthorized unlink attempt.');
        }

        // Remove linkage.
        $wpdb->update($wpdb->users, ['main_id' => null], ['ID' => $userId]);

        return true;
    }

    /**
     * Generates a remote login URL for a subsite account.
     *
     * @param int $userId
     * @param int $siteId
     * @return string
     */
    public static function generateLoginUrl($userId, $siteId) {
        $token = AuthToken::generate($userId, $siteId);
        return get_site_url($siteId) . '/wp-login.php?action=remote_login&token=' . urlencode($token);
    }

    /**
     * Handles remote login via token authentication.
     * Validates token, IP, and rate limits before logging in.
     *
     * @return void
     */
    public static function remoteLogin() {
        $token = $_GET['token'] ?? '';
        $data  = AuthToken::decode($token);

        // Validate token structure.
        if (is_wp_error($data)) {
            wp_redirect(home_url('/?error=invalid_token'));
            exit;
        }

        $userId   = (int) $data['user_id'];
        $siteId   = (int) $data['site_id'];
        $timestamp= (int) $data['timestamp'];
        $ip       = $data['ip'];
        $expiry   = dua_get_remote_login_token_expiry();

        // Validate IP match.
        if ($_SERVER['REMOTE_ADDR'] !== $ip) {
            wp_redirect(home_url('/?error=ip_mismatch'));
            exit;
        }

        // Validate token expiry.
        if (time() - $timestamp > $expiry) {
            wp_redirect(home_url('/?error=token_expired'));
            exit;
        }

        // Rate limiting
        $limitKey = "dua_login_attempts_{$userId}";
        $attempts = (int) get_transient($limitKey);

        if ($attempts >= 5) {
            wp_redirect(home_url('/?error=rate_limited'));
            exit;
        }

        $cacheExpiry = dua_get_cache_expiry();
        set_transient($limitKey, $attempts + 1, $cacheExpiry);

        // Authenticate user.
        $user = get_user_by('ID', $userId);
        if ($user) {
            wp_set_auth_cookie($userId, true);
            wp_redirect(home_url());
            exit;
        }

        wp_redirect(home_url('/?error=user_not_found'));
        exit;
    }
}
