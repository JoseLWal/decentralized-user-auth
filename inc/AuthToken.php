<?php
namespace Dua;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Handles generation and validation of authentication tokens.
 * Also provides lookup for linked subsite accounts.
 */
class AuthToken {

    /**
     * Generates a signed authentication token for a user.
     * Includes user ID, site ID, IP address, and timestamp.
     *
     * @param int $userId
     * @param int $siteId
     * @return string Base64-encoded token
     */
    public static function generate($userId, $siteId) {
        $payload = [
            'user_id'   => $userId,
            'site_id'   => $siteId,
            'timestamp' => time(),
            'ip'        => $_SERVER['REMOTE_ADDR'],
        ];

        $secret = defined('DUA_AUTH_SECRET') ? DUA_AUTH_SECRET : (wp_salt() . AUTH_KEY);
        $json   = json_encode($payload);
        $sig    = hash_hmac('sha256', $json, $secret);

        return base64_encode(json_encode([
            'data'      => $payload,
            'signature' => $sig,
        ]));
    }

    /**
     * Decodes and validates an authentication token.
     * Verifies signature and returns payload if valid.
     *
     * @param string $token
     * @return array|\WP_Error
     */
    public static function decode($token) {
        $raw = base64_decode($token, true);

        // Reject malformed base64.
        if (!$raw) {
            return new \WP_Error('invalid_token', 'Malformed token.');
        }

        $parsed = json_decode($raw, true);

        // Validate token structure.
        if (!is_array($parsed) || !isset($parsed['data'], $parsed['signature'])) {
            return new \WP_Error('invalid_token', 'Invalid token structure.');
        }

        $data     = $parsed['data'];
        $sig      = $parsed['signature'];
        $secret   = defined('DUA_AUTH_SECRET') ? DUA_AUTH_SECRET : (wp_salt() . AUTH_KEY);
        $expected = hash_hmac('sha256', json_encode($data), $secret);

        // Verify signature.
        if (!hash_equals($expected, $sig)) {
            return new \WP_Error('invalid_token', 'Signature mismatch.');
        }

        return $data;
    }

    /**
     * Retrieves linked subsite accounts for a main user.
     * Cached for performance using wp_cache.
     *
     * @param int $mainUserId
     * @return array List of linked account objects
     */
    public static function getLinkedAccounts($mainUserId) {
        $cacheKey = 'dua_linked_' . $mainUserId;
        $accounts = wp_cache_get($cacheKey, 'dua');

        if (!$accounts) {
            global $wpdb;

            $accounts = $wpdb->get_results($wpdb->prepare("
                SELECT ID, user_login, user_email, site_id 
                FROM {$wpdb->users}
                WHERE main_id = %d AND site_id != 1
            ", $mainUserId));

            $cacheExpiry = dua_get_cache_expiry();
            wp_cache_set($cacheKey, $accounts, 'dua', $cacheExpiry);
        }

        return $accounts;
    }
}
