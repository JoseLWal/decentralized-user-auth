<?php
namespace Dua;

use Dua\UserLinker;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Handles AJAX endpoints for linking and unlinking user accounts.
 * Provides secure account linking across subsites.
 */
class AjaxController {

    /**
     * Registers AJAX hooks for account linking operations.
     * Hooked during plugin initialization.
     *
     * @return void
     */
    public static function registerHooks() {
        $instance = new self();

        // Handles AJAX request to link a user account to a subsite.
        add_action('wp_ajax_dua_link_user_account', [$instance, 'linkUser']);

        // Handles AJAX request to unlink a user account from its main account.
        add_action('wp_ajax_dua_unlink_user_account', [$instance, 'unlinkUser']);

        // Handles AJAX request to generate a remote login token URL.
        add_action('wp_ajax_dua_get_linked_account_token', [$instance, 'getToken']);

        // Intercepts login form for remote login flow (non-AJAX).
        add_action('login_form_remote_login', [UserLinker::class, 'remoteLogin']);
    }

    /**
     * Links a user account to a subsite account.
     *
     * @return void
     */
    public function linkUser() {
        check_ajax_referer('dua_link_account', 'nonce');

        $mainUserId = absint($_POST['main_user_id'] ?? 0);
        $siteUrl    = esc_url_raw($_POST['site_url'] ?? '');
        $username   = sanitize_user($_POST['username'] ?? '');
        $password   = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        // Validate required input.
        if (!$mainUserId || !$username || !$siteUrl || !$password) {
            wp_send_json_error('Missing required data.');
        }

        // Ensure current user has permission to link.
        if ($mainUserId !== get_current_user_id() && !current_user_can('edit_user', $mainUserId)) {
            wp_send_json_error('Permission denied.');
        }

        $result = UserLinker::linkAccount($mainUserId, $siteUrl, $username, $password);

        is_wp_error($result)
            ? wp_send_json_error($result->get_error_message())
            : wp_send_json_success(['message' => 'Account linked successfully.', 'account' => $result]);
    }

    /**
     * Unlinks a user account from its main account.
     *
     * @return void
     */
    public function unlinkUser() {
        $userId = absint($_POST['user_id'] ?? 0);

        // Validate user ID.
        if (!$userId) {
            wp_send_json_error('Invalid user ID.');
        }

        $result = UserLinker::unlinkAccount($userId, get_current_user_id());

        is_wp_error($result)
            ? wp_send_json_error($result->get_error_message())
            : wp_send_json_success('Account unlinked.');
    }

    /**
     * Generates a login token URL for remote authentication.
     *
     * @return void
     */
    public function getToken() {
        $userId = absint($_POST['user_id'] ?? 0);
        $siteId = absint($_POST['site_id'] ?? 0);

        // Validate input parameters.
        if (!$userId || !$siteId) {
            wp_send_json_error('Missing parameters.');
        }

        $url = UserLinker::generateLoginUrl($userId, $siteId);

        wp_send_json_success(['login_url' => $url]);
    }
}
