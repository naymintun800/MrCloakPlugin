<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Mask Manager
 *
 * Manages mask-based traffic filtering
 * Applies country, language, OS, browser, VPN, and bot filters
 */
class MRC_Mask_Manager {

    private static $instance = null;
    private $bot_detector;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->bot_detector = MRC_Bot_Detector::get_instance();
    }

    /**
     * Get active mask for current domain
     *
     * @return array|null Active mask or null
     */
    public function get_active_mask() {
        $current_domain = parse_url(home_url(), PHP_URL_HOST);
        $cached_masks = get_option('mrc_cached_masks', array());

        if (empty($cached_masks)) {
            // Try to fetch masks if cache is empty
            $api_client = MRC_API_Client::get_instance();
            $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

            if ($license_key) {
                $response = $api_client->get_masks($license_key);

                // Only use response if it's not an error
                if (is_array($response) && !isset($response['error'])) {
                    $cached_masks = $response;
                }
            }
        }

        // Ensure cached_masks is an array
        if (!is_array($cached_masks) || empty($cached_masks)) {
            return null;
        }

        // Find mask that's active for this domain
        foreach ($cached_masks as $mask) {
            if (isset($mask['active_domain']) &&
                MRC_Security::sanitize_domain($mask['active_domain']) === MRC_Security::sanitize_domain($current_domain)) {
                return $mask;
            }
        }

        return null;
    }

    /**
     * Get all masks for current license
     *
     * @return array Array of masks
     */
    public function get_all_masks() {
        $cached_masks = get_option('mrc_cached_masks', array());

        // Refresh if cache is old (older than 6 hours)
        $last_updated = get_option('mrc_masks_updated', 0);
        if (time() - $last_updated > 21600) {
            $api_client = MRC_API_Client::get_instance();
            $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

            if ($license_key) {
                $response = $api_client->get_masks($license_key);

                // Only update cache if we got valid masks (not an error)
                if (is_array($response) && !isset($response['error'])) {
                    $cached_masks = $response;
                } else {
                    // Log error but keep using cached masks
                    if (isset($response['error'])) {
                        error_log('Mr. Cloak: Failed to fetch masks - ' . $response['error']);
                    }
                }
            }
        }

        // Ensure we always return an array, even if empty
        return is_array($cached_masks) ? $cached_masks : array();
    }

    /**
     * Check if visitor should be allowed or blocked based on mask rules
     *
     * @param array $visitor_info Visitor information from bot detector
     * @param array $mask Mask configuration
     * @return array Result with 'visitor_type', 'blocked_reason', 'action'
     */
    public function evaluate_visitor($visitor_info, $mask) {
        // Check if visitor is a bot first
        if ($visitor_info['bot_info']['is_bot']) {
            $bot_decision = $this->bot_detector->should_block_bot($visitor_info['bot_info'], $mask);

            if ($bot_decision['should_block']) {
                return array(
                    'visitor_type' => 'filtered',
                    'blocked_reason' => $bot_decision['reason'],
                    'action' => 'stay_on_page'
                );
            } else {
                // Bot is whitelisted or SEO bot - stays on page
                return array(
                    'visitor_type' => 'bot_whitelisted',
                    'blocked_reason' => null,
                    'action' => 'stay_on_page'
                );
            }
        }

        // Visitor is not a bot - check other filters
        $blocked_reason = null;

        // Check VPN/Proxy filter
        if (!empty($mask['filter_vpn_proxy']) && $visitor_info['is_vpn']) {
            $blocked_reason = 'vpn_or_proxy_detected';
        }

        // Check country whitelist
        if (!$blocked_reason && !empty($mask['whitelisted_countries'])) {
            if (!in_array($visitor_info['country_code'], $mask['whitelisted_countries'])) {
                $blocked_reason = 'country_not_whitelisted';
            }
        }

        // Check language whitelist
        if (!$blocked_reason && !empty($mask['whitelisted_languages'])) {
            $language_match = false;
            foreach ($visitor_info['languages'] as $lang) {
                if (in_array($lang, $mask['whitelisted_languages'])) {
                    $language_match = true;
                    break;
                }
            }
            if (!$language_match) {
                $blocked_reason = 'language_not_whitelisted';
            }
        }

        // Check OS whitelist
        if (!$blocked_reason && !empty($mask['whitelisted_os'])) {
            if (!in_array($visitor_info['os'], $mask['whitelisted_os'])) {
                $blocked_reason = 'os_not_whitelisted';
            }
        }

        // Check browser whitelist
        if (!$blocked_reason && !empty($mask['whitelisted_browsers'])) {
            if (!in_array($visitor_info['browser'], $mask['whitelisted_browsers'])) {
                $blocked_reason = 'browser_not_whitelisted';
            }
        }

        // Determine visitor type and action
        if ($blocked_reason) {
            return array(
                'visitor_type' => 'filtered',
                'blocked_reason' => $blocked_reason,
                'action' => 'stay_on_page'
            );
        } else {
            return array(
                'visitor_type' => 'whitelisted',
                'blocked_reason' => null,
                'action' => 'redirect_to_offer'
            );
        }
    }

    /**
     * Process visitor and return action to take
     *
     * @param string $ip_address IP address
     * @param string $user_agent User agent
     * @param array $mask Mask configuration to use for filtering
     * @return array Processing result with 'action', 'redirect_url', 'visitor_type', etc.
     */
    public function process_visitor($ip_address, $user_agent, $mask) {
        if (!$mask) {
            // No mask provided - do nothing (visitor stays on page)
            return array(
                'action' => 'none',
                'visitor_type' => 'unknown',
                'blocked_reason' => 'no_mask',
                'mask_id' => null
            );
        }

        // Get visitor information
        $visitor_info = $this->bot_detector->get_visitor_info($ip_address, $user_agent);

        // Evaluate visitor against mask rules
        $evaluation = $this->evaluate_visitor($visitor_info, $mask);

        // Prepare result
        $result = array(
            'action' => $evaluation['action'],
            'visitor_type' => $evaluation['visitor_type'],
            'blocked_reason' => $evaluation['blocked_reason'],
            'mask_id' => $mask['id'],
            'mask_name' => $mask['name'],
            'visitor_info' => $visitor_info
        );

        // Add redirect URL if visitor should be redirected to offer
        if ($evaluation['action'] === 'redirect_to_offer') {
            $result['redirect_url'] = $mask['offer_page_url'];
        }

        // Queue analytics event
        $this->queue_analytics_event($result);

        return $result;
    }

    /**
     * Queue analytics event for submission to API
     *
     * @param array $result Processing result
     */
    private function queue_analytics_event($result) {
        $queue = get_option('mrc_analytics_queue', array());

        $event = array(
            'mask_id' => $result['mask_id'],
            'visitor_type' => $result['visitor_type'],
            'country_code' => $result['visitor_info']['country_code'] ?? null,
            'user_agent' => $result['visitor_info']['user_agent'] ?? null,
            'blocked_reason' => $result['blocked_reason'],
            'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z')
        );

        $queue[] = $event;
        update_option('mrc_analytics_queue', $queue);

        // Increment persistent counters for dashboard display
        if ($result['visitor_type'] === 'whitelisted') {
            $whitelisted_count = get_option('mrc_total_whitelisted_count', 0);
            update_option('mrc_total_whitelisted_count', $whitelisted_count + 1);
        } else {
            $filtered_count = get_option('mrc_total_filtered_count', 0);
            update_option('mrc_total_filtered_count', $filtered_count + 1);
        }

        // Check if we should flush queue (50 events reached)
        if (count($queue) >= 50) {
            $analytics_queue = MRC_Analytics_Queue::get_instance();
            $analytics_queue->flush_queue();
        }
    }

    /**
     * Refresh masks from API
     *
     * @return bool True if successful
     */
    public function refresh_masks() {
        $api_client = MRC_API_Client::get_instance();
        $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

        if (!$license_key) {
            return false;
        }

        $masks = $api_client->get_masks($license_key);

        return !isset($masks['error']);
    }

    /**
     * Check if filtering is enabled
     *
     * @return bool True if enabled
     */
    public function is_filtering_enabled() {
        // Check if plugin is enabled
        $filtering_enabled = get_option('mrc_filtering_enabled', false);

        // Check if subscription is active
        $subscription_status = get_option('mrc_subscription_status');

        // If subscription is not active, disable filtering immediately
        if (in_array($subscription_status, array('past_due', 'revoked', 'suspended', 'expired', 'canceled'))) {
            // Clear cached data to prevent using stale masks
            delete_option('mrc_cached_masks');
            delete_option('mrc_mask_configs');
            return false;
        }

        // Check if license key exists
        $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));
        if (empty($license_key)) {
            return false;
        }

        return $filtering_enabled;
    }

    /**
     * Get all mask configurations (API masks + local settings)
     *
     * @return array Array of mask configurations
     */
    public function get_mask_configs() {
        $api_masks = $this->get_all_masks();
        $local_configs = get_option('mrc_mask_configs', array());

        // Merge API masks with local configurations
        $masks_with_config = array();

        foreach ($api_masks as $mask) {
            $mask_id = $mask['id'];
            $mask['local_config'] = isset($local_configs[$mask_id]) ? $local_configs[$mask_id] : array(
                'landing_page_id' => null,
                'landing_page_type' => 'home',
                'enabled' => false
            );
            $masks_with_config[] = $mask;
        }

        return $masks_with_config;
    }

    /**
     * Save mask configuration (landing page and enabled status)
     *
     * @param string $mask_id Mask UUID
     * @param array $config Configuration array
     * @return bool Success
     */
    public function save_mask_config($mask_id, $config) {
        $local_configs = get_option('mrc_mask_configs', array());

        $local_configs[$mask_id] = array(
            'landing_page_id' => isset($config['landing_page_id']) ? intval($config['landing_page_id']) : null,
            'landing_page_type' => isset($config['landing_page_type']) ? $config['landing_page_type'] : 'home',
            'enabled' => isset($config['enabled']) ? (bool)$config['enabled'] : false
        );

        return update_option('mrc_mask_configs', $local_configs);
    }

    /**
     * Get mask configuration for a specific page/post
     *
     * @param int $page_id WordPress page/post ID
     * @param bool $is_home Whether this is the home page
     * @return array|null Mask + config if found, null otherwise
     */
    public function get_mask_for_page($page_id, $is_home = false) {
        $mask_configs = $this->get_mask_configs();

        foreach ($mask_configs as $mask) {
            $config = $mask['local_config'];

            // Skip disabled masks
            if (!$config['enabled']) {
                continue;
            }

            // Check if this mask applies to this page
            if ($config['landing_page_type'] === 'home' && $is_home) {
                return $mask;
            }

            if ($config['landing_page_id'] == $page_id) {
                return $mask;
            }
        }

        return null;
    }

    /**
     * Get all enabled masks
     *
     * @return array Array of enabled masks with configs
     */
    public function get_enabled_masks() {
        $mask_configs = $this->get_mask_configs();

        return array_filter($mask_configs, function($mask) {
            return isset($mask['local_config']['enabled']) && $mask['local_config']['enabled'];
        });
    }

    /**
     * Toggle mask enabled status
     *
     * @param string $mask_id Mask UUID
     * @param bool $enabled Whether to enable or disable
     * @return bool Success
     */
    public function toggle_mask($mask_id, $enabled) {
        $local_configs = get_option('mrc_mask_configs', array());

        if (!isset($local_configs[$mask_id])) {
            $local_configs[$mask_id] = array(
                'landing_page_id' => null,
                'landing_page_type' => 'home',
                'enabled' => $enabled
            );
        } else {
            $local_configs[$mask_id]['enabled'] = $enabled;
        }

        return update_option('mrc_mask_configs', $local_configs);
    }

    /**
     * Get simple stats (whitelisted and filtered counts)
     * Returns cumulative totals from persistent counters
     *
     * @return array Stats array
     */
    public function get_simple_stats() {
        // Get cumulative totals from persistent counters
        $whitelisted = get_option('mrc_total_whitelisted_count', 0);
        $filtered = get_option('mrc_total_filtered_count', 0);

        return array(
            'whitelisted' => $whitelisted,
            'filtered' => $filtered,
            'total' => $whitelisted + $filtered
        );
    }
}
