<?php
/**
 * These are default wordpress functions being replaced by this plugin.
 *
 * @package WordPress
 */

// Load require classes.
use Dua\Overrides\Dua_WP_User;

if ( ! function_exists( 'get_user_by' ) ) :
    /**
     * Retrieves user info by a given field, scoped by site ID.
     *
     * @param string     $field   Field to search by: id | ID | slug | email | login.
     * @param int|string $value   Field value.
     * @param int|null   $site_id Optional site ID. Defaults to current site.
     * @return WP_User|false
     */
    function get_user_by( $field, $value, $site_id = null ) {
        if ( $site_id === null ) {
            $site_id = get_current_blog_id();
        }

        $userdata = Dua_WP_User::get_data_by( $field, $value, $site_id );

        if ( ! $userdata ) {
            return false;
        }

        //$user = new WP_User();
        $user = new WP_User($userdata->ID);
        $user->init( $userdata );

        return $user;
    }
endif;
