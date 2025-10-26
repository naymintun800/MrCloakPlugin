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
                $cached_masks = $api_client->get_masks($license_key);
            }
        }

        if (empty($cached_masks)) {
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
                $cached_masks = $api_client->get_masks($license_key);
            }
        }

        return $cached_masks;
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
                    'visitor_type' => 'bot',
                    'blocked_reason' => $bot_decision['reason'],
                    'action' => 'block'
                );
            } else {
                // Bot is whitelisted or SEO bot
                return array(
                    'visitor_type' => 'bot',
                    'blocked_reason' => null,
                    'action' => 'allow_safe_page'
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
                'visitor_type' => 'blocked',
                'blocked_reason' => $blocked_reason,
                'action' => 'block'
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
     * @return array Processing result with 'action', 'redirect_url', 'visitor_type', etc.
     */
    public function process_visitor($ip_address, $user_agent) {
        // Get active mask
        $mask = $this->get_active_mask();

        if (!$mask) {
            // No active mask - show safe page
            return array(
                'action' => 'safe_page',
                'visitor_type' => 'unknown',
                'blocked_reason' => 'no_active_mask',
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

        if (in_array($subscription_status, array('past_due', 'revoked', 'suspended'))) {
            return false;
        }

        return $filtering_enabled;
    }

    /**
     * Get safe/maintenance page HTML
     *
     * @return string HTML content
     */
    public function get_safe_page_html() {
        $custom_html = get_option('mrc_safe_page_html', '');

        if (!empty($custom_html)) {
            return $custom_html;
        }

        // Default safe page
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Please Wait</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            text-align: center;
            max-width: 500px;
        }
        h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-weight: 300;
        }
        p {
            font-size: 1.2rem;
            opacity: 0.9;
            line-height: 1.6;
        }
        .loader {
            margin: 2rem auto;
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Please Wait</h1>
        <div class="loader"></div>
        <p>We\'re preparing your experience...</p>
    </div>
</body>
</html>';
    }
}
