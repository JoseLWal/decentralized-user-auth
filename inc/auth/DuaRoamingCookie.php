<?php
// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Manages roaming cookie authentication across subsites.
 * Handles session validation, login propagation, and secure cookie signing.
 */
class DuaRoamingCookie {
    const COOKIE_NAME      = 'dua_roaming_user';
    const COOKIE_PATH      = '/';

    /**
     * Validates roaming session after WordPress cookie check.
     * Hooked into 'validate_auth_cookie'.
     *
     * @param int $userId
     * @return void
     */
    public static function onValidateSession($userId) {
        if (!self::isDomainCookieCompatible()) {
            return;
        }

        $cookie       = self::getValidCookie();
        $currentUser  = wp_get_current_user();
        $wpUserId     = $currentUser->ID;
        $isRoaming    = dua_is_roaming_user($currentUser);

        if ($cookie) {
            if (self::isLoggingOut()) {
                return;
            }

            if (!is_user_logged_in()) {
                self::setWpUser($cookie['user_id']);
            } elseif ($wpUserId !== $cookie['user_id']) {
                wp_logout();
                self::setWpUser($cookie['user_id']);
            }
        } elseif ($isRoaming && is_user_logged_in()) {
            wp_logout();
        }
    }

    /**
     * Sets roaming cookie after successful login.
     * Hooked into 'wp_login'.
     *
     * @param string   $userLogin
     * @param \WP_User $user
     * @return void
     */
    public static function onLogin($userLogin, $user) {
        if (dua_is_roaming_user($user)) {
            self::setCookie($user->ID);
        }
    }

    /**
     * Deletes roaming cookie before logout.
     * Hooked into 'wp_logout'.
     *
     * @return void
     */
    public static function onLogout() {
        self::deleteCookie();
    }

    /**
     * Checks if logout is in progress.
     * Prevents reauthentication during logout.
     *
     * @return bool
     */
    protected static function isLoggingOut() {
        return isset($_GET['action']) && $_GET['action'] === 'logout';
    }

    /**
     * Sets a signed roaming cookie for the user.
     *
     * @param int $userId
     * @return void
     */
    protected static function setCookie($user_id) {
        $iat     = time();
        $exp     = $iat + self::getLifetime();
        $nonce   = wp_generate_password(12, false);
        $payload = compact('user_id', 'iat', 'exp', 'nonce');

        $payload = apply_filters('dua_roaming_cookie_payload', $payload, $user_id);
        $payload['sig'] = self::signPayload($payload);

        $cookieValue = rawurlencode(wp_json_encode($payload));

        setcookie(
            self::COOKIE_NAME,
            $cookieValue,
            [
                'expires'  => $exp,
                'path'     => self::COOKIE_PATH,
                'domain'   => self::getDomain(),
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Validates roaming cookie and returns payload.
     *
     * @return array|false
     */
    protected static function getValidCookie() {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        $raw  = rawurldecode($_COOKIE[self::COOKIE_NAME]);
        $data = json_decode($raw, true);

        if (!is_array($data) || empty($data['sig'])) {
            return false;
        }

        $sig = $data['sig'];
        unset($data['sig']);

        $expectedSig = self::signPayload($data);

        if (!hash_equals($expectedSig, $sig)) {
            return false;
        }

        if (time() > $data['exp']) {
            return false;
        }

        return $data;
    }

    /**
     * Deletes the roaming cookie.
     *
     * @return void
     */
    public static function deleteCookie() {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires'  => time() - 3600,
                'path'     => self::COOKIE_PATH,
                'domain'   => self::getDomain(),
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Signs the cookie payload using HMAC.
     *
     * @param array $data
     * @return string
     */
    protected static function signPayload($data) {
        $json = wp_json_encode($data);
        return hash_hmac('sha256', $json, dua_get_roaming_secret_key());
    }

    /**
     * Returns cookie lifetime.
     *
     * @return int
     */
    protected static function getLifetime() {
        return dua_get_roaming_cookie_expiry();
    }

    /**
     * Returns wildcard domain for cookie scoping.
     *
     * @return string
     */
    protected static function getDomain() {
        $network = get_network();
        $domain  = $network->domain ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        return '.' . ltrim(strtolower($domain), '.');
    }

    /**
     * Checks if domain matches host for cookie compatibility.
     *
     * @return bool
     */
    protected static function isDomainCookieCompatible() {
        $domain = self::getDomain();
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        return stripos($host, ltrim($domain, '.')) !== false;
    }

    /**
     * Sets WordPress user context from cookie.
     *
     * @param int $userId
     * @return void
     */
    protected static function setWpUser($userId) {
        wp_set_auth_cookie($userId, true);
        wp_set_current_user($userId);
    }

    /**
     * Bypasses reauthentication if user is already validated.
     * Hooked into login flow.
     *
     * @return void
     */
    public static function maybeBypassReauth() {
        if (!is_user_logged_in()) {
            return;
        }

        if (isset($_GET['reauth']) && $_GET['reauth'] === '1' && isset($_GET['redirect_to'])) {
            wp_redirect(esc_url_raw($_GET['redirect_to']));
            exit;
        }
    }
}
