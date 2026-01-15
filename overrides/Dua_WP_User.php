<?php
namespace Dua\Overrides;

// Exit if accessed directly
defined('ABSPATH') || exit;

class Dua_WP_User {
    public static function get_data_by( $field, $value, $site_id = null ) {
        global $wpdb;

        if ( 'ID' === $field ) {
            $field = 'id';
        }

        if ( 'id' === $field ) {
            if ( ! is_numeric( $value ) || (int) $value < 1 ) {
                return false;
            }
            $value = (int) $value;
        } else {
            $value = trim( $value );
        }

        if ( ! $value || is_null( $site_id ) ) {
            return false;
        }

        switch ( $field ) {
            case 'id':
                $db_field = 'ID';
                break;
            case 'slug':
                $value    = sanitize_title( $value );
                $db_field = 'user_nicename';
                break;
            case 'email':
                $value    = sanitize_email( $value );
                $db_field = 'user_email';
                break;
            case 'login':
                $value    = sanitize_user( $value );
                $db_field = 'user_login';
                break;
            default:
                return false;
        }

        $cache_key = "dua_user_{$field}_{$value}_site_{$site_id}";
        $user = wp_cache_get($cache_key, 'dua');

        if (!$user) {
            $user = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->users} WHERE $db_field = %s AND site_id = %d LIMIT 1",
                    $value,
                    $site_id
                )
            );

            // Fallback to site_id = 1 if user not found
            if ( ! $user && $site_id !== 1 ) {
                $main_site_id = 1;
                $fallback_user = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->users} WHERE $db_field = %s AND site_id = %d LIMIT 1",
                        $value,
                        $main_site_id
                    )
                );

                /**
                 * Only allow fallback if user is roaming user.
                 */
                if ( $fallback_user && dua_is_roaming_user( $fallback_user ) ) {
                    $user = $fallback_user;
                }
            }

            if ($user) {
                $cache_expiry = dua_get_cache_expiry();
                wp_cache_set($cache_key, $user, 'dua', $cache_expiry);
            }
        }

        if ( ! $user ) {
            return false;
        }

        update_user_caches( $user );

        return $user;
    }
}
