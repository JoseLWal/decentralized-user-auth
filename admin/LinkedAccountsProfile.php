<?php
namespace Dua\Admin;

use Dua\AuthToken;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Renders the linked accounts UI on user profile pages.
 * Displays connected subsite accounts and provides linking interface.
 */
class LinkedAccountsProfile {

    /**
     * Registers hooks to render UI on user profile pages.
     * Hooked into 'show_user_profile' and 'edit_user_profile'.
     */
    public function __construct() {
        add_action('show_user_profile', [$this, 'renderUi']);
        add_action('edit_user_profile', [$this, 'renderUi']);
    }

    /**
     * Renders the linked accounts interface in the user profile.
     * Only visible on the main site and for authorized users.
     *
     * @param \WP_User $user The user object being edited.
     * @return void
     */
    public function renderUi($user) {
        // Only render on the main site.
        if (get_current_blog_id() !== 1) {
            return;
        }

        // Only allow self-editing or super admin.
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        if (get_current_user_id() !== $user->ID && !is_super_admin()) {
            return;
        }

        $linkedAccounts = AuthToken::getLinkedAccounts($user->ID);
        ?>

        <h2><?php esc_html_e('Connected Accounts', 'decentralized-user-auth'); ?></h2>
        <?php wp_nonce_field('dua_link_account', 'dua_nonce'); ?>
        <input type="hidden" id="dua_main_user_id" value="<?php echo esc_attr($user->ID); ?>">

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Site URL', 'decentralized-user-auth'); ?></th>
                    <th><?php esc_html_e('Username', 'decentralized-user-auth'); ?></th>
                    <th><?php esc_html_e('Email', 'decentralized-user-auth'); ?></th>
                    <th><?php esc_html_e('Actions', 'decentralized-user-auth'); ?></th>
                </tr>
            </thead>
            <tbody id="dua-linked-list">
                <?php if ($linkedAccounts): ?>
                    <?php foreach ($linkedAccounts as $account): 
                        $token     = AuthToken::generate($account->ID, $account->site_id);
                        $loginUrl  = get_site_url($account->site_id) . '/wp-login.php?action=remote_login&token=' . urlencode($token);
                    ?>
                        <tr>
                            <td><?php echo esc_url(get_site_url($account->site_id)); ?></td>
                            <td><?php echo esc_html($account->user_login); ?></td>
                            <td><?php echo esc_html($account->user_email); ?></td>
                            <td>
                                <a href="<?php echo esc_url($loginUrl); ?>" class="button button-secondary" target="_blank"><?php esc_html_e('Sign In', 'decentralized-user-auth'); ?></a>
                                <button class="button button-link-delete dua-unlink-user-account" data-user-id="<?php echo esc_attr($account->ID); ?>"><?php esc_html_e('Unlink', 'decentralized-user-auth'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr id="no-linked-account">
                        <td colspan="4"><em><?php esc_html_e('No accounts linked yet.', 'decentralized-user-auth'); ?></em></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table><br>

        <h2><?php esc_html_e('Connect an Account', 'decentralized-user-auth'); ?></h2>
        <table class="form-table link-account-fields-table" id="link-account-fields-table">
            <tr>
                <th><label for="dua_site_url"><?php esc_html_e('Subsite URL', 'decentralized-user-auth'); ?></label></th>
                <td><input type="url" id="dua_site_url" class="regular-text" placeholder="https://example.site" required></td>
            </tr>
            <tr>
                <th><label for="dua_username"><?php esc_html_e('Username or Email', 'decentralized-user-auth'); ?></label></th>
                <td><input type="text" id="dua_username" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="dua_password"><?php esc_html_e('Password', 'decentralized-user-auth'); ?></label></th>
                <td><input type="password" id="dua_password" class="regular-text" required></td>
            </tr>
            <tr>
                <th></th>
                <td>
                    <button id="dua-connect-button" type="button" class="button button-secondary"><?php esc_html_e('Link Account', 'decentralized-user-auth'); ?></button>
                    <p id="dua-link-status" style="margin-top:8px;"></p>
                </td>
            </tr>
        </table>

        <?php
    }
}
