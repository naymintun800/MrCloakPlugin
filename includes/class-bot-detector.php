<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Bot Detector
 *
 * Detects various types of bots including:
 * - Ad review bots (Facebook Ads, Google Ads, TikTok Ads, etc.)
 * - Social media bots (Facebook, Twitter, LinkedIn, etc.)
 * - Messaging bots (Telegram, WhatsApp, Slack, etc.)
 * - SEO bots (Googlebot, Bingbot)
 */
class MRC_Bot_Detector {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Detect if visitor is a bot and return bot information
     *
     * @param string $user_agent User agent string
     * @return array Bot detection result with 'is_bot', 'bot_name', 'bot_category'
     */
    public function detect_bot($user_agent) {
        if (empty($user_agent)) {
            return array(
                'is_bot' => false,
                'bot_name' => null,
                'bot_category' => null
            );
        }

        $user_agent_lower = strtolower($user_agent);

        // Get bot patterns (obfuscated for security)
        $patterns = $this->get_bot_patterns();

        // Check each category
        foreach ($patterns as $category => $bots) {
            foreach ($bots as $bot_name => $bot_patterns) {
                foreach ($bot_patterns as $pattern) {
                    if (stripos($user_agent_lower, strtolower($pattern)) !== false) {
                        return array(
                            'is_bot' => true,
                            'bot_name' => $bot_name,
                            'bot_category' => $category
                        );
                    }
                }
            }
        }

        return array(
            'is_bot' => false,
            'bot_name' => null,
            'bot_category' => null
        );
    }

    /**
     * Check if bot should be blocked based on mask rules
     *
     * @param array $bot_info Bot detection result
     * @param array $mask Mask configuration
     * @return array Result with 'should_block' and 'reason'
     */
    public function should_block_bot($bot_info, $mask) {
        if (!$bot_info['is_bot']) {
            return array('should_block' => false, 'reason' => null);
        }

        $bot_name = $bot_info['bot_name'];
        $bot_category = $bot_info['bot_category'];

        // Priority 1: Check blacklist (always block)
        if (isset($mask['bot_blacklist']) && in_array($bot_name, $mask['bot_blacklist'])) {
            return array('should_block' => true, 'reason' => 'bot_blacklisted');
        }

        // Priority 2: Check whitelist (always allow)
        if (isset($mask['bot_whitelist']) && in_array($bot_name, $mask['bot_whitelist'])) {
            return array('should_block' => false, 'reason' => null);
        }

        // Priority 3: Check if ad review bot and blocking enabled
        if ($bot_category === 'ad_review_bots' && !empty($mask['block_ad_review_bots'])) {
            return array('should_block' => true, 'reason' => 'ad_review_bot_blocked');
        }

        // Priority 4: Check if other bot (social/messaging) and blocking enabled
        if ($bot_category === 'other_bots' && !empty($mask['block_other_bots'])) {
            return array('should_block' => true, 'reason' => 'other_bot_blocked');
        }

        // Priority 5: SEO bots always allowed unless blacklisted
        if ($bot_category === 'seo_bots') {
            return array('should_block' => false, 'reason' => null);
        }

        return array('should_block' => false, 'reason' => null);
    }

    /**
     * Get bot patterns organized by category
     * Patterns are obfuscated to prevent easy copying
     *
     * @return array Bot patterns by category
     */
    private function get_bot_patterns() {
        // Try to get from cache first
        $cache_key = MRC_Security::get_bot_patterns_cache_key();
        $cached_patterns = get_transient($cache_key);

        if ($cached_patterns !== false) {
            return MRC_Security::decode_bot_patterns($cached_patterns);
        }

        // Define bot patterns
        $patterns = array(
            'ad_review_bots' => array(
                'google-ads-review' => array(
                    'Google-InspectionTool',
                    'Google-Ads-Creatives-Assistant',
                    'AdsBot-Google',
                    'Mediapartners-Google',
                    'Google-Ads-Overview',
                    'Google-Ads-Crawlers'
                ),
                'facebook-ads-review' => array(
                    'facebookexternalhit',
                    'facebot',
                    'FacebookBot',
                    'Facebook-Ads-Bot'
                ),
                'tiktok-ads-review' => array(
                    'TikTok',
                    'ByteSpider',
                    'Bytedance',
                    'TikTokBot'
                ),
                'snapchat-ads-review' => array(
                    'Snapchat',
                    'SnapchatAdsBot',
                    'Snapbot'
                ),
                'twitter-ads-review' => array(
                    'TwitterBot',
                    'Twitter-Ads-Crawler'
                ),
                'linkedin-ads-review' => array(
                    'LinkedInBot',
                    'LinkedIn-Ads-Bot'
                ),
                'pinterest-ads-review' => array(
                    'Pinterest',
                    'Pinterestbot',
                    'Pinterest-Ads-Bot'
                )
            ),
            'other_bots' => array(
                'facebookexternalhit' => array(
                    'facebookexternalhit'
                ),
                'facebot' => array(
                    'facebot',
                    'FacebookBot'
                ),
                'twitterbot' => array(
                    'Twitterbot',
                    'Twitter'
                ),
                'linkedinbot' => array(
                    'LinkedInBot',
                    'LinkedIn'
                ),
                'pinterestbot' => array(
                    'Pinterest',
                    'Pinterestbot'
                ),
                'slackbot' => array(
                    'Slackbot',
                    'Slack-ImgProxy'
                ),
                'telegrambot' => array(
                    'TelegramBot',
                    'Telegram'
                ),
                'whatsapp' => array(
                    'WhatsApp',
                    'WhatsAppBot'
                ),
                'snapchat' => array(
                    'Snapchat',
                    'SnapBot'
                ),
                'tiktok' => array(
                    'TikTok',
                    'Musical.ly'
                )
            ),
            'seo_bots' => array(
                'googlebot' => array(
                    'Googlebot',
                    'Google-InspectionTool'
                ),
                'bingbot' => array(
                    'bingbot',
                    'BingPreview',
                    'msnbot'
                )
            )
        );

        // Encode and cache patterns
        $encoded = MRC_Security::encode_bot_patterns($patterns);
        set_transient($cache_key, $encoded, HOUR_IN_SECONDS);

        return $patterns;
    }

    /**
     * Get user agent from request
     *
     * @return string User agent
     */
    public static function get_user_agent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Check if visitor is using VPN/Proxy
     * (Placeholder - would need integration with VPN detection service)
     *
     * @param string $ip_address IP address
     * @return bool True if VPN/Proxy detected
     */
    public function is_vpn_or_proxy($ip_address) {
        // Check cached result first
        $cache_key = 'mrc_vpn_check_' . md5($ip_address);
        $cached_result = get_transient($cache_key);

        if ($cached_result !== false) {
            return $cached_result === 'yes';
        }

        // TODO: Integrate with VPN detection service (ProxyCheck.io, IPQualityScore, etc.)
        // For now, return false (no VPN detection)
        $is_vpn = false;

        // Cache result for 1 hour
        set_transient($cache_key, $is_vpn ? 'yes' : 'no', HOUR_IN_SECONDS);

        return $is_vpn;
    }

    /**
     * Detect visitor's country from IP
     * (Placeholder - would need GeoIP database or service)
     *
     * @param string $ip_address IP address
     * @return string|null Country code (ISO 3166-1 alpha-2) or null
     */
    public function get_country_from_ip($ip_address) {
        // Check cached result first
        $cache_key = 'mrc_geo_' . md5($ip_address);
        $cached_result = get_transient($cache_key);

        if ($cached_result !== false) {
            return $cached_result;
        }

        // TODO: Integrate with GeoIP service (MaxMind, IP2Location, etc.)
        // For now, try to use free ip-api.com service
        $country_code = null;

        $api_url = "http://ip-api.com/json/{$ip_address}?fields=status,countryCode";
        $response = wp_remote_get($api_url, array('timeout' => 5));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($data && $data['status'] === 'success') {
                $country_code = $data['countryCode'];
            }
        }

        // Cache result for 24 hours
        set_transient($cache_key, $country_code, DAY_IN_SECONDS);

        return $country_code;
    }

    /**
     * Detect browser from user agent
     *
     * @param string $user_agent User agent string
     * @return string|null Browser name or null
     */
    public function detect_browser($user_agent) {
        if (empty($user_agent)) {
            return null;
        }

        $user_agent_lower = strtolower($user_agent);

        // Order matters - check specific browsers first
        $browsers = array(
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Brave' => 'Brave',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
            'Firefox' => 'Firefox',
            'MSIE' => 'IE',
            'Trident' => 'IE'
        );

        foreach ($browsers as $pattern => $browser) {
            if (stripos($user_agent_lower, strtolower($pattern)) !== false) {
                return $browser;
            }
        }

        return null;
    }

    /**
     * Detect operating system from user agent
     *
     * @param string $user_agent User agent string
     * @return string|null OS name or null
     */
    public function detect_os($user_agent) {
        if (empty($user_agent)) {
            return null;
        }

        $user_agent_lower = strtolower($user_agent);

        $os_patterns = array(
            'windows' => 'Windows',
            'mac os x' => 'macOS',
            'macintosh' => 'macOS',
            'linux' => 'Linux',
            'android' => 'Android',
            'iphone' => 'iOS',
            'ipad' => 'iOS',
            'cros' => 'ChromeOS'
        );

        foreach ($os_patterns as $pattern => $os) {
            if (strpos($user_agent_lower, $pattern) !== false) {
                return $os;
            }
        }

        return null;
    }

    /**
     * Parse language codes from Accept-Language header
     *
     * @param string $accept_language Accept-Language header value
     * @return array Array of language codes (ISO 639-1)
     */
    public function parse_languages($accept_language) {
        if (empty($accept_language)) {
            return array();
        }

        $languages = array();
        $parts = explode(',', $accept_language);

        foreach ($parts as $part) {
            // Extract language code (ignore quality values)
            $lang = trim(explode(';', $part)[0]);

            // Get primary language code (e.g., 'en' from 'en-US')
            if (strpos($lang, '-') !== false) {
                $lang = explode('-', $lang)[0];
            }

            $languages[] = strtolower($lang);
        }

        return array_unique($languages);
    }

    /**
     * Get Accept-Language header from request
     *
     * @return string Accept-Language header
     */
    public static function get_accept_language() {
        return $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    }

    /**
     * Get all visitor information for filtering
     *
     * @param string $ip_address IP address
     * @param string $user_agent User agent
     * @return array Visitor information
     */
    public function get_visitor_info($ip_address, $user_agent) {
        return array(
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'bot_info' => $this->detect_bot($user_agent),
            'country_code' => $this->get_country_from_ip($ip_address),
            'browser' => $this->detect_browser($user_agent),
            'os' => $this->detect_os($user_agent),
            'languages' => $this->parse_languages(self::get_accept_language()),
            'is_vpn' => $this->is_vpn_or_proxy($ip_address)
        );
    }
}
