<?php
namespace Dua;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Registers core user-related hooks for site-scoped behavior.
 * Handles user registration, deletion, and blog removal logic.
 */
class UserHooks {

    /**
     * Registers WordPress hooks for user lifecycle events.
     * Hooked during plugin initialization.
     *
     * @return void
     */
    public static function registerHooks() {
        // Assigns site_id to newly registered users immediately after registration.
        add_action('user_register', [self::class, 'assignSiteId']);

        // Blocks deletion of users not scoped to the current site.
        // Ensures site isolation during user lifecycle events.
        add_action('delete_user', [self::class, 'preventCrossSiteDeletion']);

        // Prevents unauthorized removal of users from blogs unless scoped.
        // Runs before WordPress removes a user from a site.
        add_filter('pre_remove_user_from_blog', [self::class, 'preventUnauthorizedRemoval'], 10, 2);

        // Registers cleanup flag and shutdown logic when a user is removed from a blog.
        add_action('remove_user_from_blog', [self::class, 'markUserForCleanup'], 10, 3);

        // Runs on every shutdown to ensure orphaned users flagged are cleaned up.
        add_action('shutdown', [self::class, 'cleanupOrphanedUsersForCurrentSite']);
    }

    /**
     * Assigns the current site ID to newly registered users.
     *
     * @param int $userId ID of the newly registered user.
     * @return void
     */
    public static function assignSiteId($userId) {
        global $wpdb;
        $siteId = get_current_blog_id();

        // Update the site_id column for the new user.
        $wpdb->update(
            $wpdb->users,
            ['site_id' => $siteId],
            ['ID' => $userId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Prevents deletion of users who are not scoped to the current site.
     *
     * @param int $userId ID of the user being deleted.
     * @return void
     */
    public static function preventCrossSiteDeletion($userId) {
        global $wpdb;
        $siteId = get_current_blog_id();

        // Retrieve site_id and main_id for the user.
        $userData = $wpdb->get_row($wpdb->prepare("
            SELECT site_id, main_id FROM {$wpdb->users}
            WHERE ID = %d
        ", $userId));

        if (!$userData) {
            return;
        }

        // Block deletion if user is not scoped to current site.
        if ((int) $userData->site_id !== $siteId) {
            wp_die(__('Cannot delete user outside your site scope.', 'decentralized-user-auth'));
        }
    }

    /**
     * Prevents unauthorized removal of users from a blog.
     *
     * @param int $userId ID of the user being removed.
     * @param int $blogId ID of the target blog.
     * @return WP_Error|null
     */
    public static function preventUnauthorizedRemoval($userId, $blogId) {
        // Allow removal from network admin.
        if (is_network_admin()) {
            return null;
        }

        global $wpdb;

        // Retrieve site_id for the user.
        $userData = $wpdb->get_row($wpdb->prepare("
            SELECT site_id FROM {$wpdb->users}
            WHERE ID = %d
        ", $userId));

        if (!isset($userData->site_id)) {
            return null;
        }

        // Block removal if user is not scoped to target blog.
        if ((int) $userData->site_id !== (int) $blogId) {
            return new \WP_Error(
                'unauthorized_removal',
                __('Unauthorized removal: User is not scoped to this site.', 'decentralized-user-auth')
            );
        }

        return null;
    }

    /**
     * Marks a user for deferred cleanup after being removed from a blog.
     * Writes a temporary flag to the dua-data folder and registers shutdown logic.
     *
     * @param int $userId ID of the user being removed.
     * @param int $blogId ID of the blog the user is being removed from.
     * @param int $reassign ID of the user to reassign content to (if any).
     * @return void
     */
    public static function markUserForCleanup($userId, $blogId, $reassign) {
        // Don't mark for cleanup if user wasn't removed.
        if (!is_user_member_of_blog($userId, $blogId)) {
            return;
        }

        $path = WP_CONTENT_DIR . '/dua-data/pending-user-cleanup.php';

        // Ensure folder exists.
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // Ensure file exists.
        if (!file_exists($path)) {
            file_put_contents($path, "<?php\nreturn [];\n");
        }

        // Load current flags.
        $flags = include $path;

        // Add user to site’s cleanup list.
        $flags[$blogId] = isset($flags[$blogId])
            ? array_unique(array_merge($flags[$blogId], [$userId]))
            : [$userId];

        // Write updated flags back to file.
        file_put_contents($path, "<?php\nreturn " . var_export($flags, true) . ";\n");
    }

    /**
     * Checks for orphaned users flagged for the current site and deletes them.
     * Runs early on every shutdown and perform the cleanup.
     *
     * @return void
     */
    public static function cleanupOrphanedUsersForCurrentSite() {
        $siteId = get_current_blog_id();
        $path = WP_CONTENT_DIR . '/dua-data/pending-user-cleanup.php';

        if (!file_exists($path)) {
            return;
        }

        $flags = include $path;

        if (!isset($flags[$siteId]) || empty($flags[$siteId])) {
            return;
        }

        $changed = false;

        // Check if flagged users for current site are orphaned and delete them.
        foreach ($flags[$siteId] as $index => $userId) {
            $blogs = get_blogs_of_user($userId);

            if (empty($blogs)) {
                wpmu_delete_user($userId);
                unset($flags[$siteId][$index]);
                $changed = true;
            }
        }

        // Clean up empty site entry
        if (empty($flags[$siteId])) {
            unset($flags[$siteId]);
            $changed = true;
        }

        // Write updated flags back to file
        if ($changed) {
            file_put_contents($path, "<?php\nreturn " . var_export($flags, true) . ";\n");
        }
    }
}
