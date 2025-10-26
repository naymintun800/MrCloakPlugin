<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Security Class
 *
 * Handles encryption/decryption of sensitive data and obfuscation
 * of bot detection patterns to prevent easy copying
 */
class MRC_Security {

    /**
     * Encrypt sensitive data using AES-256
     *
     * @param string $data Data to encrypt
     * @return string Base64 encoded encrypted data
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        $key = self::get_encryption_key();
        $iv = self::get_iv();

        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);

        return base64_encode($encrypted);
    }

    /**
     * Decrypt sensitive data
     *
     * @param string $encrypted_data Base64 encoded encrypted data
     * @return string Decrypted data
     */
    public static function decrypt($encrypted_data) {
        if (empty($encrypted_data)) {
            return '';
        }

        $key = self::get_encryption_key();
        $iv = self::get_iv();

        $decoded = base64_decode($encrypted_data);
        $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $key, 0, $iv);

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Store access token securely
     *
     * @param string $token JWT token
     */
    public static function store_access_token($token) {
        $encrypted_token = self::encrypt($token);
        update_option('mrc_access_token', $encrypted_token);
    }

    /**
     * Get stored access token
     *
     * @return string|null Decrypted access token or null
     */
    public static function get_access_token() {
        $encrypted_token = get_option('mrc_access_token');
        if ($encrypted_token) {
            return self::decrypt($encrypted_token);
        }
        return null;
    }

    /**
     * Get encryption key derived from WordPress salts
     *
     * @return string 32-byte encryption key
     */
    private static function get_encryption_key() {
        // Use WordPress salts to generate encryption key
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'default-insecure-key';
        $salt .= defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '';
        $salt .= defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '';

        return substr(hash('sha256', $salt, true), 0, 32);
    }

    /**
     * Get initialization vector for encryption
     *
     * @return string 16-byte IV
     */
    private static function get_iv() {
        $salt = defined('NONCE_KEY') ? NONCE_KEY : 'default-nonce';
        $salt .= defined('AUTH_SALT') ? AUTH_SALT : '';

        return substr(hash('sha256', $salt, true), 0, 16);
    }

    /**
     * Encode bot patterns to prevent easy extraction
     *
     * @param array $patterns Array of bot user agent patterns
     * @return string Encoded patterns
     */
    public static function encode_bot_patterns($patterns) {
        $json = json_encode($patterns);
        $encoded = base64_encode($json);

        // XOR obfuscation with key
        $key = self::get_obfuscation_key();
        $obfuscated = self::xor_string($encoded, $key);

        return base64_encode($obfuscated);
    }

    /**
     * Decode bot patterns
     *
     * @param string $encoded_patterns Encoded patterns
     * @return array Decoded patterns
     */
    public static function decode_bot_patterns($encoded_patterns) {
        if (empty($encoded_patterns)) {
            return array();
        }

        $key = self::get_obfuscation_key();
        $obfuscated = base64_decode($encoded_patterns);
        $deobfuscated = self::xor_string($obfuscated, $key);
        $json = base64_decode($deobfuscated);

        return json_decode($json, true) ?: array();
    }

    /**
     * Hash IP address for storage
     *
     * @param string $ip_address IP address
     * @return string Hashed IP
     */
    public static function hash_ip($ip_address) {
        $salt = self::get_ip_salt();
        return hash('sha256', $ip_address . $salt);
    }

    /**
     * Check if IP matches hashed IP
     *
     * @param string $ip_address IP to check
     * @param string $hashed_ip Hashed IP to compare
     * @return bool True if match
     */
    public static function verify_ip_hash($ip_address, $hashed_ip) {
        return self::hash_ip($ip_address) === $hashed_ip;
    }

    /**
     * Encode IP ranges for storage
     *
     * @param array $ip_ranges Array of CIDR ranges
     * @return string Encoded IP ranges
     */
    public static function encode_ip_ranges($ip_ranges) {
        // Store IP ranges in encoded format
        $data = array();
        foreach ($ip_ranges as $range) {
            if (strpos($range, '/') !== false) {
                list($ip, $mask) = explode('/', $range);
                $data[] = array(
                    'i' => base64_encode($ip),
                    'm' => intval($mask)
                );
            }
        }

        $json = json_encode($data);
        $key = self::get_obfuscation_key();
        $obfuscated = self::xor_string($json, $key);

        return base64_encode($obfuscated);
    }

    /**
     * Decode IP ranges
     *
     * @param string $encoded_ranges Encoded IP ranges
     * @return array Decoded IP ranges
     */
    public static function decode_ip_ranges($encoded_ranges) {
        if (empty($encoded_ranges)) {
            return array();
        }

        $key = self::get_obfuscation_key();
        $obfuscated = base64_decode($encoded_ranges);
        $deobfuscated = self::xor_string($obfuscated, $key);
        $data = json_decode($deobfuscated, true);

        if (!is_array($data)) {
            return array();
        }

        $ranges = array();
        foreach ($data as $item) {
            $ip = base64_decode($item['i']);
            $mask = $item['m'];
            $ranges[] = "{$ip}/{$mask}";
        }

        return $ranges;
    }

    /**
     * XOR string with key for obfuscation
     *
     * @param string $string String to obfuscate
     * @param string $key Obfuscation key
     * @return string Obfuscated string
     */
    private static function xor_string($string, $key) {
        $result = '';
        $key_length = strlen($key);
        $string_length = strlen($string);

        for ($i = 0; $i < $string_length; $i++) {
            $result .= $string[$i] ^ $key[$i % $key_length];
        }

        return $result;
    }

    /**
     * Get obfuscation key for XOR
     *
     * @return string Obfuscation key
     */
    private static function get_obfuscation_key() {
        $salt = defined('NONCE_KEY') ? NONCE_KEY : 'obfuscation-key';
        $salt .= defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '';
        $salt .= defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '';

        return hash('sha256', $salt);
    }

    /**
     * Get salt for IP hashing
     *
     * @return string IP salt
     */
    private static function get_ip_salt() {
        $salt = get_option('mrc_ip_salt');
        if (!$salt) {
            $salt = wp_generate_password(32, true, true);
            update_option('mrc_ip_salt', $salt);
        }
        return $salt;
    }

    /**
     * Validate license key format
     *
     * @param string $license_key License key
     * @return bool True if valid format
     */
    public static function validate_license_key_format($license_key) {
        // Format: MRC-XXXXXXXX-XXXX-XXXX
        return preg_match('/^MRC-[A-Z0-9]{8}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $license_key);
    }

    /**
     * Sanitize domain name
     *
     * @param string $domain Domain name
     * @return string Sanitized domain
     */
    public static function sanitize_domain($domain) {
        // Remove protocol, www, and trailing slashes
        $domain = strtolower($domain);
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = rtrim($domain, '/');

        return sanitize_text_field($domain);
    }

    /**
     * Check if domain matches token
     *
     * @param string $token JWT token
     * @param string $domain Domain to verify
     * @return bool True if domain matches token
     */
    public static function verify_token_domain($token, $domain) {
        if (empty($token)) {
            return false;
        }

        // Decode JWT (simple validation - just check domain claim)
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $payload = base64_decode($parts[1]);
        $data = json_decode($payload, true);

        if (!$data || !isset($data['dom'])) {
            return false;
        }

        $token_domain = self::sanitize_domain($data['dom']);
        $current_domain = self::sanitize_domain($domain);

        return $token_domain === $current_domain;
    }

    /**
     * Rate limit license activation attempts
     *
     * @param string $ip_address IP address
     * @return bool True if within rate limit, false if exceeded
     */
    public static function check_activation_rate_limit($ip_address) {
        $transient_key = 'mrc_activation_attempts_' . md5($ip_address);
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
            return true;
        }

        if ($attempts >= 5) {
            return false;
        }

        set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Generate random bot pattern cache key
     *
     * @return string Cache key
     */
    public static function get_bot_patterns_cache_key() {
        // Use rotating cache key to prevent pattern extraction
        $hour = date('YmdH');
        return 'mrc_bp_' . substr(md5($hour . AUTH_KEY), 0, 8);
    }

    /**
     * Obfuscate function/variable names in runtime
     * (Used to make code harder to read when decompiled)
     *
     * @param string $name Original name
     * @return string Obfuscated name
     */
    public static function obfuscate_name($name) {
        return '_' . substr(md5($name . NONCE_KEY), 0, 12);
    }
}
