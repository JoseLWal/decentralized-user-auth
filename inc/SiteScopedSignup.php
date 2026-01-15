<?php
namespace Dua;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Handles site-scoped validation and metadata storage during user signup.
 * Injects custom validation logic via dua_site_scoped_signup_validation filter.
 */
class SiteScopedSignup {
    
    /**
     * Registers WordPress hooks for multisite signup behavior.
     * Hooked during plugin initialization.
     *
     * @return void
     */
    public static function registerHooks() {
        // Injects full site-scoped validation logic.
        add_filter('dua_site_scoped_signup_validation', [self::class, 'validateSignup'], 10, 3);

        // Stores site_id in wp_signups table after a user signs up.
        add_action('after_signup_user', [self::class, 'storeSiteId'], 10, 4);
    }

    /**
     * Performs full site-scoped validation for username and email.
     * Replaces default signup reservation logic.
     *
     * @param mixed  $override Null or array to override validation.
     * @param string $user_name
     * @param string $user_email
     * @return array|null
     */
    public static function validateSignup($override, $user_name, $user_email) {
        global $wpdb;

        $errors         = new \WP_Error();
        $orig_username  = $user_name;
        $site_id        = get_current_blog_id();

        // Check username reservation scoped to site
        $signup = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->signups} WHERE user_login = %s AND site_id = %d",
                $user_name,
                $site_id
            )
        );

        if ($signup instanceof \stdClass) {
            $registered_at = mysql2date('U', $signup->registered);
            $now           = time();
            $diff          = $now - $registered_at;

            if ($diff > 2 * DAY_IN_SECONDS) {
                $wpdb->delete($wpdb->signups, ['user_login' => $user_name, 'site_id' => $site_id]);
            } else {
                $errors->add('user_name', __('That username is currently reserved but may be available in a couple of days.'));
            }
        }

        // Check email reservation scoped to site
        $signup = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->signups} WHERE user_email = %s AND site_id = %d",
                $user_email,
                $site_id
            )
        );

        if ($signup instanceof \stdClass) {
            $diff = time() - mysql2date('U', $signup->registered);

            if ($diff > 2 * DAY_IN_SECONDS) {
                $wpdb->delete($wpdb->signups, ['user_email' => $user_email, 'site_id' => $site_id]);
            } else {
                $errors->add('user_email', __('That email address has already been used. Please check your inbox for an activation email. It will become available in a couple of days if you do nothing.'));
            }
        }

        return [
            'user_name'     => $user_name,
            'orig_username' => $orig_username,
            'user_email'    => $user_email,
            'errors'        => $errors,
        ];
    }

    /**
     * Stores site_id in wp_signups after signup creation.
     *
     * @param string $userLogin
     * @param string $userEmail
     * @param string $activationKey
     * @param array  $meta Optional metadata (unused).
     * @return void
     */
    public static function storeSiteId($userLogin, $userEmail, $activationKey, $meta = []) {
        global $wpdb;

        $siteId = get_current_blog_id();

        $wpdb->update(
            $wpdb->signups,
            ['site_id' => $siteId],
            [
                'user_login'     => $userLogin,
                'activation_key' => $activationKey,
            ]
        );
    }
}
