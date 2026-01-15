<?php
namespace Dua\Admin;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Handles admin behaviors on the Add User page.
 * Used to suppress UI sections and block existing user additions.
 */
class AddUserPageController {

    /**
     * Registers hooks for Add User page behaviors.
     * Hooked during plugin initialization.
     */
    public function __construct() {
        // Disable "Add Existing User" form on multisite user-new.php.
        add_filter('show_network_site_users_add_existing_form', '__return_false');

        // Remove "Add Existing User" form on subsites user-new.php.
        add_action('admin_head', [$this, 'suppressExistingUserSection']);

        // Prevent existing users from being added to a site.
        add_filter('can_add_user_to_blog', [$this, 'preventExistingUserAddition'], 10, 4);
    }

    /**
     * Suppresses the "Add Existing User" section via CSS and JavaScript.
     * Only runs on site-level user-new.php page.
     *
     * @return void
     */
    public function suppressExistingUserSection() {
        global $pagenow;

        if ($pagenow !== 'user-new.php' || is_network_admin()) {
            return;
        }

        // Hide the existing form via CSS — safe and non-invasive.
        echo '<style> #add-existing-user, #add-existing-user + p, #adduser { display: none; } </style>';

        // Remove the existing form via JavaScript — safe and non-invasive.
        echo '<script>
            document.addEventListener("DOMContentLoaded", function () {
                const heading = document.getElementById("add-existing-user");
                const paragraph = heading?.nextElementSibling;
                const form = document.getElementById("adduser");

                if (heading) heading.remove();
                if (paragraph && paragraph.tagName === "P") paragraph.remove();
                if (form) form.remove();
            });
        </script>';
    }

    /**
     * Prevents existing users from being added to a site.
     * Returns WP_Error to block the action before it occurs.
     *
     * @param true|WP_Error $retval  Default true.
     * @param int           $user_id User ID.
     * @param string        $role    Role being assigned.
     * @param int           $blog_id Site ID.
     * @return true|WP_Error
     */
    public function preventExistingUserAddition($retval, $user_id, $role, $blog_id) {
        // Allow if user is not yet a member of the site
        if (!is_user_member_of_blog($user_id, $blog_id)) {
            return $retval;
        }

        // Block if user is already scoped elsewhere.
        if (get_current_blog_id() === $blog_id) {
            return new \WP_Error(
                'dua_existing_user_blocked',
                __('Adding existing users to a subsite is not allowed.', 'decentralized-user-auth')
            );
        }

        return $retval;
    }
}
