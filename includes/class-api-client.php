<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak API Client
 *
 * Handles all communication with the Mr. Cloak SaaS backend API
 * Includes license activation, heartbeat, mask fetching, and analytics submission
 */
class MRC_API_Client {

    private static $instance = null;
    private $api_base_url = 'https://mrcloak.com';
    private $timeout = 30;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Activate license key on this domain
     *
     * @param string $license_key License key (format: MRC-XXXXXXXX-XXXX-XXXX)
     * @param string $domain Domain where plugin is installed (will be normalized)
     * @return array Response with accessToken and policy, or detailed error
     */
    public function activate_license($license_key, $domain) {
        // Normalize domain
        $domain = self::normalize_domain($domain);

        // Validate domain format
        if (empty($domain) || $domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
            return array(
                'error' => 'Invalid domain',
                'error_type' => 'invalid_domain',
                'message' => 'Invalid domain detected. Please use a proper domain name (not localhost or IP address).'
            );
        }

        $url = $this->api_base_url . '/api/licenses/activate';

        $body = array(
            'licenseKey' => $license_key,
            'domain' => $domain,
            'pluginVersion' => MRC_VERSION
        );

        $response = $this->make_request('POST', $url, $body);

        // Check for domain security errors
        if (isset($response['error']) && isset($response['status_code']) && $response['status_code'] === 403) {
            $error_message = $response['error'];
            $masked_key = self::mask_license_key($license_key);

            // Domain not authorized (Strict Mode)
            if (strpos($error_message, 'Domain not authorized') !== false) {
                return array(
                    'error' => $error_message,
                    'error_type' => 'domain_not_authorized',
                    'title' => 'Domain Authorization Required',
                    'message' => 'This license is in Strict Mode and requires domain authorization.',
                    'instructions' => array(
                        '1. Log in to your Mr. Cloak dashboard',
                        '2. Go to Settings > Domain Security',
                        '3. Add this domain to your whitelist:',
                        "   <strong>{$domain}</strong>",
                        '4. Come back here and click "Retry Activation"'
                    ),
                    'domain' => $domain,
                    'license' => $masked_key,
                    'dashboard_url' => 'https://mrcloak.com/dashboard/settings',
                    'show_retry' => true
                );
            }

            // Domain access revoked (Flexible Mode)
            if (strpos($error_message, 'Domain access revoked') !== false) {
                return array(
                    'error' => $error_message,
                    'error_type' => 'domain_revoked',
                    'title' => 'Domain Access Revoked',
                    'message' => 'The license owner has revoked access for this domain.',
                    'instructions' => array(
                        'If this is your domain and you revoked access by mistake:',
                        '1. Log in to your Mr. Cloak dashboard',
                        '2. Go to Settings > Domain Security',
                        '3. Find this domain in "Revoked Domains":',
                        "   <strong>{$domain}</strong>",
                        '4. Click "Restore" to reactivate it',
                        '5. Come back here and click "Retry Activation"'
                    ),
                    'domain' => $domain,
                    'license' => $masked_key,
                    'dashboard_url' => 'https://mrcloak.com/dashboard/settings',
                    'show_retry' => true
                );
            }
        }

        if (isset($response['accessToken'])) {
            // Store access token and policy data
            MRC_Security::store_access_token($response['accessToken']);
            update_option('mrc_license_key', MRC_Security::encrypt($license_key));
            update_option('mrc_domain', $domain);
            update_option('mrc_subscription_status', $response['policy']['status']);
            update_option('mrc_next_heartbeat', $response['nextCheckAt']);

            // Schedule heartbeat cron
            $this->schedule_heartbeat();

            // Fetch masks immediately after activation
            $this->get_masks($license_key);
        }

        return $response;
    }

    /**
     * Heartbeat check-in to refresh token and verify subscription
     *
     * @param string $access_token Current JWT token
     * @param string $domain Domain making the request
     * @param array $metrics Optional usage metrics
     * @return array Response with new accessToken and status
     */
    public function heartbeat($access_token = null, $domain = null, $metrics = array()) {
        if (!$access_token) {
            $access_token = MRC_Security::get_access_token();
        }

        if (!$access_token) {
            return array('error' => 'No access token available');
        }

        if (!$domain) {
            $domain = self::normalize_domain(parse_url(home_url(), PHP_URL_HOST));
        }

        $url = $this->api_base_url . '/api/licenses/heartbeat';

        // Gather metrics from analytics queue
        if (empty($metrics)) {
            $queue = get_option('mrc_analytics_queue', array());
            $metrics = array(
                'totalRequests' => count($queue),
                'uniqueVisitors' => 0, // Could track this separately
                'botsBlocked' => count(array_filter($queue, function($e) {
                    return $e['visitor_type'] === 'bot' || $e['visitor_type'] === 'blocked';
                }))
            );
        }

        $body = array(
            'accessToken' => $access_token,
            'domain' => $domain,
            'metrics' => $metrics
        );

        $response = $this->make_request('POST', $url, $body);

        if (isset($response['status'])) {
            // Check for domain revocation
            if ($response['status'] === 'revoked') {
                // Domain was revoked during active session!
                update_option('mrc_filtering_enabled', false);
                delete_option('mrc_access_token');

                // Set persistent admin notice
                set_transient('mrc_domain_revoked_notice', array(
                    'domain' => $domain,
                    'message' => $response['message'] ?? 'Domain access has been revoked'
                ), 0); // No expiration

                error_log('Mr. Cloak: Domain access revoked for ' . $domain);

                return $response;
            }
        }

        if (isset($response['accessToken'])) {
            // Store new rolling token
            MRC_Security::store_access_token($response['accessToken']);
            update_option('mrc_subscription_status', $response['status']);
            update_option('mrc_next_heartbeat', $response['nextCheckAt']);
            update_option('mrc_last_heartbeat', time());

            // Handle subscription status changes
            $this->handle_subscription_status($response['status'], $response['message']);
        }

        return $response;
    }

    /**
     * Get masks configuration from API
     *
     * @param string $license_key License key
     * @return array Array of mask objects
     */
    public function get_masks($license_key = null) {
        if (!$license_key) {
            $encrypted_key = get_option('mrc_license_key');
            $license_key = MRC_Security::decrypt($encrypted_key);
        }

        if (!$license_key) {
            return array('error' => 'No license key available');
        }

        $url = $this->api_base_url . '/api/plugin/masks?license_key=' . urlencode($license_key);

        $response = $this->make_request('GET', $url);

        if (isset($response['masks'])) {
            // Cache masks locally
            update_option('mrc_cached_masks', $response['masks']);
            update_option('mrc_masks_updated', time());
            update_option('mrc_license_status', $response['license_status']);

            if (isset($response['plan_limits'])) {
                update_option('mrc_plan_limits', $response['plan_limits']);
            }

            return $response['masks'];
        }

        return $response;
    }

    /**
     * Submit analytics events to API (batch)
     *
     * @param string $license_key License key
     * @param array $events Array of analytics events
     * @return array Response with success status
     */
    public function submit_analytics($license_key, $events) {
        if (empty($events)) {
            return array('success' => true, 'inserted' => 0, 'failed' => 0);
        }

        $url = $this->api_base_url . '/api/plugin/analytics';

        $body = array(
            'license_key' => $license_key,
            'events' => $events
        );

        $response = $this->make_request('POST', $url, $body);

        if (isset($response['success']) && $response['success']) {
            error_log("Mr. Cloak: Analytics submitted - {$response['inserted']} inserted, {$response['failed']} failed");
        }

        return $response;
    }

    /**
     * Get subscription status and plan information
     *
     * @param string $license_key License key
     * @return array Subscription details
     */
    public function get_subscription_status($license_key = null) {
        if (!$license_key) {
            $encrypted_key = get_option('mrc_license_key');
            $license_key = MRC_Security::decrypt($encrypted_key);
        }

        if (!$license_key) {
            return array('error' => 'No license key available');
        }

        $url = $this->api_base_url . '/api/plugin/subscription?license_key=' . urlencode($license_key);

        $response = $this->make_request('GET', $url);

        if (isset($response['subscription'])) {
            update_option('mrc_subscription_info', $response);
            update_option('mrc_subscription_updated', time());
        }

        return $response;
    }

    /**
     * Enable a mask for current domain
     *
     * @param string $mask_id UUID of mask to enable
     * @param string $access_token JWT token
     * @param string $domain Domain
     * @return array Response
     */
    public function enable_mask($mask_id, $access_token = null, $domain = null) {
        if (!$access_token) {
            $access_token = MRC_Security::get_access_token();
        }

        if (!$domain) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
        }

        $url = $this->api_base_url . '/api/plugin/masks/enable';

        $body = array(
            'accessToken' => $access_token,
            'maskId' => $mask_id,
            'domain' => $domain
        );

        $response = $this->make_request('POST', $url, $body);

        if (isset($response['success']) && $response['success']) {
            update_option('mrc_active_mask_id', $mask_id);

            // Refresh masks to get updated active_domain
            $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));
            $this->get_masks($license_key);
        }

        return $response;
    }

    /**
     * Disable a mask for current domain
     *
     * @param string $mask_id UUID of mask to disable
     * @param string $access_token JWT token
     * @param string $domain Domain
     * @return array Response
     */
    public function disable_mask($mask_id, $access_token = null, $domain = null) {
        if (!$access_token) {
            $access_token = MRC_Security::get_access_token();
        }

        if (!$domain) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
        }

        $url = $this->api_base_url . '/api/plugin/masks/disable';

        $body = array(
            'accessToken' => $access_token,
            'maskId' => $mask_id,
            'domain' => $domain
        );

        $response = $this->make_request('POST', $url, $body);

        if (isset($response['success']) && $response['success']) {
            delete_option('mrc_active_mask_id');

            // Refresh masks to get updated active_domain
            $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));
            $this->get_masks($license_key);
        }

        return $response;
    }

    /**
     * Make HTTP request to API with enhanced security
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $url Full URL
     * @param array $body Request body (for POST)
     * @return array Decoded response or error
     */
    private function make_request($method, $url, $body = null) {
        // Prepare request body
        $request_body = $method === 'POST' && $body ? json_encode($body) : '';

        // Generate request signature for authentication
        $signature = $this->generate_request_signature($request_body, $url);

        // Generate device fingerprint to prevent token theft
        $fingerprint = $this->generate_device_fingerprint();

        // Get plugin integrity hash
        $integrity_hash = $this->get_plugin_integrity_hash();

        $args = array(
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-MRC-Signature' => $signature,
                'X-MRC-Fingerprint' => $fingerprint,
                'X-MRC-Integrity' => $integrity_hash,
                'X-MRC-Version' => MRC_VERSION,
                'X-MRC-PHP' => PHP_VERSION,
                'X-MRC-WP' => get_bloginfo('version')
            )
        );

        if ($method === 'POST' && $body) {
            $args['body'] = $request_body;
        }

        // Log request for audit trail (anonymized)
        $this->log_api_request($method, $url);

        if ($method === 'GET') {
            $response = wp_remote_get($url, $args);
        } else {
            $response = wp_remote_post($url, $args);
        }

        // Handle network errors
        if (is_wp_error($response)) {
            error_log('Mr. Cloak API error: ' . $response->get_error_message());
            $this->track_api_failure($url, $response->get_error_message());
            return array('error' => $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        // Handle HTTP errors
        if ($status_code !== 200) {
            error_log("Mr. Cloak API error (HTTP {$status_code}): " . ($data['error'] ?? 'Unknown error'));

            // Handle specific error codes
            if ($status_code === 401) {
                // Token expired or invalid - clear token
                delete_option('mrc_access_token');
            } elseif ($status_code === 429) {
                // Rate limited
                $reset_time = wp_remote_retrieve_header($response, 'X-RateLimit-Reset');
                error_log("Mr. Cloak: Rate limited until {$reset_time}");
                $this->handle_rate_limit($reset_time);
            } elseif ($status_code === 403 && isset($data['error']) && strpos($data['error'], 'Domain not authorized') !== false) {
                // Domain not whitelisted
                $this->show_domain_not_whitelisted_notice($data);
            }

            $this->track_api_failure($url, $data['error'] ?? 'HTTP ' . $status_code);
            return array('error' => $data['error'] ?? 'Unknown error', 'status_code' => $status_code);
        }

        return $data;
    }

    /**
     * Generate HMAC signature for request authentication
     *
     * @param string $request_body Request body JSON
     * @param string $url Request URL
     * @return string HMAC signature
     */
    private function generate_request_signature($request_body, $url) {
        // Get license key for signing (if available)
        $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

        // If no license key, use a temporary signing key
        if (!$license_key) {
            $signing_key = defined('AUTH_KEY') ? AUTH_KEY : 'temp-key';
        } else {
            $signing_key = $license_key;
        }

        // Create signature: HMAC(method + url + body + timestamp, license_key)
        $timestamp = time();
        $message = $url . $request_body . $timestamp;
        $signature = hash_hmac('sha256', $message, $signing_key);

        // Store timestamp for replay attack prevention
        update_option('mrc_last_request_timestamp', $timestamp);

        return $signature . ':' . $timestamp;
    }

    /**
     * Generate device fingerprint to prevent token theft
     *
     * @return string Device fingerprint hash
     */
    private function generate_device_fingerprint() {
        // Get cached fingerprint if exists
        $cached_fingerprint = get_option('mrc_device_fingerprint');
        if ($cached_fingerprint) {
            return $cached_fingerprint;
        }

        // Generate new fingerprint based on server characteristics
        $components = array(
            $_SERVER['SERVER_ADDR'] ?? '',
            $_SERVER['SERVER_SOFTWARE'] ?? '',
            php_uname('n'), // Hostname
            get_option('siteurl'),
            defined('DB_HOST') ? DB_HOST : '',
            ABSPATH
        );

        $fingerprint = hash('sha256', implode('|', $components));
        update_option('mrc_device_fingerprint', $fingerprint);

        return $fingerprint;
    }

    /**
     * Get plugin integrity hash to detect tampering
     *
     * @return string SHA256 hash of critical files
     */
    private function get_plugin_integrity_hash() {
        $cached_hash = get_transient('mrc_plugin_integrity_hash');
        if ($cached_hash) {
            return $cached_hash;
        }

        // Hash critical plugin files
        $files_to_hash = array(
            MRC_PLUGIN_DIR . 'mr-cloak.php',
            MRC_PLUGIN_DIR . 'includes/class-api-client.php',
            MRC_PLUGIN_DIR . 'includes/class-security.php',
            MRC_PLUGIN_DIR . 'includes/class-mask-manager.php'
        );

        $combined_hash = '';
        foreach ($files_to_hash as $file) {
            if (file_exists($file)) {
                $combined_hash .= hash_file('sha256', $file);
            }
        }

        $integrity_hash = hash('sha256', $combined_hash);

        // Cache for 1 hour
        set_transient('mrc_plugin_integrity_hash', $integrity_hash, HOUR_IN_SECONDS);

        return $integrity_hash;
    }

    /**
     * Log API request for audit trail
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     */
    private function log_api_request($method, $url) {
        $endpoint = str_replace($this->api_base_url, '', $url);
        $endpoint = strtok($endpoint, '?'); // Remove query params for privacy

        $log = get_option('mrc_api_audit_log', array());

        // Keep last 100 requests
        if (count($log) >= 100) {
            array_shift($log);
        }

        $log[] = array(
            'timestamp' => time(),
            'method' => $method,
            'endpoint' => $endpoint,
            'ip' => $this->get_anonymized_ip()
        );

        update_option('mrc_api_audit_log', $log);
    }

    /**
     * Track API failures for anomaly detection
     *
     * @param string $url Request URL
     * @param string $error Error message
     */
    private function track_api_failure($url, $error) {
        $failures = get_option('mrc_api_failures', array());

        $endpoint = str_replace($this->api_base_url, '', $url);
        $endpoint = strtok($endpoint, '?');

        if (!isset($failures[$endpoint])) {
            $failures[$endpoint] = array('count' => 0, 'last_error' => '', 'last_time' => 0);
        }

        $failures[$endpoint]['count']++;
        $failures[$endpoint]['last_error'] = $error;
        $failures[$endpoint]['last_time'] = time();

        update_option('mrc_api_failures', $failures);

        // Alert if too many failures
        if ($failures[$endpoint]['count'] >= 10) {
            error_log("Mr. Cloak: {$endpoint} has failed {$failures[$endpoint]['count']} times. Last error: {$error}");
        }
    }

    /**
     * Handle rate limiting with exponential backoff
     *
     * @param string $reset_time Reset timestamp
     */
    private function handle_rate_limit($reset_time) {
        // Store rate limit info
        update_option('mrc_rate_limited', true);
        update_option('mrc_rate_limit_reset', $reset_time);

        // Show admin notice
        add_action('admin_notices', function() use ($reset_time) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Mr. Cloak:</strong> API rate limit reached. Please wait until ' . esc_html($reset_time) . ' before retrying.';
            echo '</p></div>';
        });
    }

    /**
     * Show domain not whitelisted notice
     *
     * @param array $response API response
     */
    private function show_domain_not_whitelisted_notice($response) {
        $domain = parse_url(home_url(), PHP_URL_HOST);

        add_action('admin_notices', function() use ($domain, $response) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Mr. Cloak:</strong> Domain <code>' . esc_html($domain) . '</code> is not authorized for this license.<br>';
            echo esc_html($response['message'] ?? 'Please whitelist this domain in your Mr. Cloak dashboard.');
            echo '<br><a href="https://mrcloak.com/dashboard/domains" target="_blank" class="button">Whitelist Domain</a>';
            echo '</p></div>';
        });
    }

    /**
     * Get anonymized IP for logging
     *
     * @return string Anonymized IP
     */
    private function get_anonymized_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Anonymize IP (remove last octet for IPv4)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        return hash('sha256', $ip);
    }

    /**
     * Handle subscription status changes
     *
     * @param string $status Subscription status
     * @param string $message Status message
     */
    private function handle_subscription_status($status, $message) {
        update_option('mrc_subscription_status', $status);
        update_option('mrc_subscription_message', $message);

        // Disable filtering if subscription expired
        if (in_array($status, array('past_due', 'revoked', 'suspended'))) {
            update_option('mrc_filtering_enabled', false);

            // Show admin notice
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Mr. Cloak:</strong> ' . esc_html($message);
                echo '</p></div>';
            });
        } elseif ($status === 'grace') {
            // Show warning for grace period
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>Mr. Cloak:</strong> ' . esc_html($message);
                echo '</p></div>';
            });
        } else {
            // Enable filtering for active/trialing status
            update_option('mrc_filtering_enabled', true);
        }
    }

    /**
     * Schedule heartbeat cron job
     */
    private function schedule_heartbeat() {
        if (!wp_next_scheduled('mrc_heartbeat')) {
            wp_schedule_event(time() + 1800, 'mrc_every_30_minutes', 'mrc_heartbeat');
        }
    }

    /**
     * Get client IP address (supports proxies and CDNs)
     *
     * @return string IP address
     */
    public static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',      // Cloudflare
            'HTTP_X_FORWARDED_FOR',       // Standard proxy header
            'HTTP_X_REAL_IP',             // Nginx proxy
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Normalize domain for consistent API communication
     *
     * Removes protocol, www prefix, trailing slashes, and ports
     * Ensures consistent domain format across all API requests
     *
     * @param string $domain Domain to normalize
     * @return string Normalized domain
     */
    public static function normalize_domain($domain) {
        if (empty($domain)) {
            return '';
        }

        // Remove protocol if present
        $domain = preg_replace('#^https?://#i', '', $domain);

        // Remove trailing slash
        $domain = rtrim($domain, '/');

        // Remove www. prefix for consistency
        $domain = preg_replace('/^www\./i', '', $domain);

        // Remove port if present
        $domain = preg_replace('/:\d+$/', '', $domain);

        // Convert to lowercase
        $domain = strtolower($domain);

        return $domain;
    }

    /**
     * Mask license key for safe display in error messages
     *
     * Converts: MRC-12345678-ABCDEFGH-12345678
     * To: MRC-****-****-5678
     *
     * @param string $license_key Full license key
     * @return string Masked license key
     */
    public static function mask_license_key($license_key) {
        if (empty($license_key)) {
            return 'MRC-****-****-****';
        }

        $parts = explode('-', $license_key);

        if (count($parts) === 4) {
            // Show first part (MRC) and last 4 characters of last part
            return $parts[0] . '-****-****-' . substr($parts[3], -4);
        }

        return 'MRC-****-****-****';
    }
}
