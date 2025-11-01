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
     * Get IP reputation data from Mr. Cloak threat intelligence service
     *
     * @param string $ip_address IP address
     * @return array IP reputation data
     */
    public function get_ip_reputation($ip_address) {
        // Check cached result first
        $cache_key = 'mrc_ip_rep_' . md5($ip_address);
        $cached_result = get_transient($cache_key);

        if ($cached_result !== false) {
            return $cached_result;
        }

        $defaults = array(
            'success' => false,
            'error' => null,
            'is_proxy' => false,
            'is_vpn' => false,
            'is_tor' => false,
            'is_crawler' => false,
            'is_bot' => false,
            'fraud_score' => 0,
            'isp' => null,
            'asn' => null,
            'organization' => null,
            'is_hosting' => false,
            'country_code' => null,
            'timezone' => null,
            'mobile' => false,
            'abuse_velocity' => 'none',
            'recent_abuse' => false
        );

        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            $result = $defaults;
            $result['error'] = 'Invalid IP address';
            set_transient($cache_key, $result, HOUR_IN_SECONDS);
            return $result;
        }

        // Query Mr. Cloak reputation service (proxied through SaaS backend)
        $api_client = MRC_API_Client::get_instance();
        $response = $api_client->lookup_ip_reputation($ip_address);

        $result = array_merge($defaults, $response);

        // Cache result for 6 hours to reduce API calls
        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Check if visitor is using VPN/Proxy
     *
     * @param string $ip_address IP address
     * @return bool True if VPN/Proxy detected
     */
    public function is_vpn_or_proxy($ip_address) {
        $ip_reputation = $this->get_ip_reputation($ip_address);
        return $ip_reputation['is_vpn'] || $ip_reputation['is_proxy'] || $ip_reputation['is_tor'];
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
     * Verify legitimate bot via reverse DNS lookup
     *
     * @param string $ip_address IP address
     * @param string $bot_name Bot name detected from user agent
     * @return bool True if bot is verified legitimate
     */
    public function verify_legitimate_bot($ip_address, $bot_name) {
        // Check cache first
        $cache_key = 'mrc_bot_verify_' . md5($ip_address . $bot_name);
        $cached_result = get_transient($cache_key);

        if ($cached_result !== false) {
            return $cached_result === 'yes';
        }

        $is_verified = false;

        // Get hostname from IP
        $hostname = gethostbyaddr($ip_address);

        if ($hostname && $hostname !== $ip_address) {
            // Define verification patterns for known bots
            $verification_patterns = array(
                'googlebot' => array('.googlebot.com', '.google.com'),
                'google-ads-review' => array('.googlebot.com', '.google.com'),
                'bingbot' => array('.search.msn.com'),
                'facebookexternalhit' => array('.facebook.com', '.fbsv.net'),
                'facebot' => array('.facebook.com', '.fbsv.net')
            );

            if (isset($verification_patterns[$bot_name])) {
                foreach ($verification_patterns[$bot_name] as $pattern) {
                    if (substr($hostname, -strlen($pattern)) === $pattern) {
                        // Forward lookup to verify
                        $forward_ip = gethostbyname($hostname);
                        if ($forward_ip === $ip_address) {
                            $is_verified = true;
                            break;
                        }
                    }
                }
            }
        }

        // Cache for 24 hours
        set_transient($cache_key, $is_verified ? 'yes' : 'no', DAY_IN_SECONDS);

        return $is_verified;
    }

    /**
     * Check for indicators of headless browser or automation
     *
     * @param array $fingerprint JavaScript fingerprint data
     * @return array Detection result with suspicion score
     */
    public function detect_headless_browser($fingerprint) {
        if (empty($fingerprint) || !is_array($fingerprint)) {
            return array(
                'is_headless' => false,
                'suspicion_score' => 0,
                'indicators' => array()
            );
        }

        $indicators = array();
        $suspicion_score = 0;

        // Check automation detection from fingerprint
        if (isset($fingerprint['automation'])) {
            $auto = $fingerprint['automation'];

            if (isset($auto['webdriver']) && $auto['webdriver']) {
                $indicators[] = 'webdriver_detected';
                $suspicion_score += 50;
            }

            if (isset($auto['phantom']) && $auto['phantom']) {
                $indicators[] = 'phantom_js_detected';
                $suspicion_score += 50;
            }

            if (isset($auto['selenium']) && $auto['selenium']) {
                $indicators[] = 'selenium_detected';
                $suspicion_score += 50;
            }

            if (isset($auto['headlessChrome']) && $auto['headlessChrome']) {
                $indicators[] = 'headless_chrome_detected';
                $suspicion_score += 50;
            }

            // Check for missing features typical of headless browsers
            if (isset($auto['plugins']) && !$auto['plugins']) {
                $indicators[] = 'no_plugins';
                $suspicion_score += 20;
            }

            if (isset($auto['languages']) && !$auto['languages']) {
                $indicators[] = 'no_languages';
                $suspicion_score += 20;
            }
        }

        // Check canvas fingerprint
        if (isset($fingerprint['canvas'])) {
            // Headless browsers often have very similar or null canvas fingerprints
            if (empty($fingerprint['canvas']) || $fingerprint['canvas'] === 'data:,') {
                $indicators[] = 'invalid_canvas';
                $suspicion_score += 25;
            }
        }

        // Check WebGL
        if (isset($fingerprint['webgl'])) {
            $webgl = $fingerprint['webgl'];
            if (empty($webgl) || (isset($webgl['vendor']) && $webgl['vendor'] === 'Google Inc.')) {
                // SwiftShader is often used in headless Chrome
                if (isset($webgl['renderer']) && stripos($webgl['renderer'], 'SwiftShader') !== false) {
                    $indicators[] = 'swiftshader_webgl';
                    $suspicion_score += 30;
                }
            }
        }

        return array(
            'is_headless' => $suspicion_score >= 50,
            'suspicion_score' => min($suspicion_score, 100),
            'indicators' => $indicators
        );
    }

    /**
     * Check behavioral patterns for bot-like activity
     *
     * @param array $behavior Behavioral data from JavaScript
     * @return array Analysis result
     */
    public function analyze_behavior($behavior) {
        if (empty($behavior) || !is_array($behavior)) {
            return array(
                'is_suspicious' => false,
                'suspicion_score' => 0,
                'reasons' => array()
            );
        }

        $reasons = array();
        $suspicion_score = 0;

        // Check time on page
        $time_on_page = $behavior['timeOnPage'] ?? 0;
        if ($time_on_page < 0.5) {
            $reasons[] = 'very_short_visit';
            $suspicion_score += 30;
        }

        // Check mouse movements
        $mouse_movements = $behavior['mouseMovements'] ?? 0;
        if ($mouse_movements === 0 && $time_on_page > 2) {
            $reasons[] = 'no_mouse_movement';
            $suspicion_score += 40;
        }

        // Check for any human interaction
        $clicks = $behavior['clicks'] ?? 0;
        $scrolls = $behavior['scrolls'] ?? 0;
        $keypresses = $behavior['keypresses'] ?? 0;

        if ($clicks === 0 && $scrolls === 0 && $keypresses === 0 && $time_on_page > 3) {
            $reasons[] = 'no_interactions';
            $suspicion_score += 35;
        }

        // Check for unnatural patterns
        if (isset($behavior['interactions']) && is_array($behavior['interactions'])) {
            // Check if all interactions happen at exactly the same intervals
            $intervals = array();
            for ($i = 1; $i < count($behavior['interactions']); $i++) {
                $interval = $behavior['interactions'][$i]['time'] - $behavior['interactions'][$i-1]['time'];
                $intervals[] = $interval;
            }

            // If all intervals are within 10ms of each other, it's suspicious
            if (count($intervals) > 2) {
                $avg_interval = array_sum($intervals) / count($intervals);
                $variance = 0;
                foreach ($intervals as $interval) {
                    $variance += pow($interval - $avg_interval, 2);
                }
                $variance = $variance / count($intervals);

                if ($variance < 100) { // Very consistent timing
                    $reasons[] = 'robotic_timing';
                    $suspicion_score += 25;
                }
            }
        }

        return array(
            'is_suspicious' => $suspicion_score >= 50,
            'suspicion_score' => min($suspicion_score, 100),
            'reasons' => $reasons
        );
    }

    /**
     * Check for IP and geolocation consistency
     *
     * @param array $ip_reputation IP reputation data
     * @param array $fingerprint JavaScript fingerprint
     * @return array Consistency check result
     */
    public function check_ip_consistency($ip_reputation, $fingerprint) {
        $inconsistencies = array();
        $suspicion_score = 0;

        if (empty($ip_reputation) || empty($fingerprint)) {
            return array(
                'is_consistent' => true,
                'inconsistencies' => array(),
                'suspicion_score' => 0
            );
        }

        // Check timezone consistency
        if (isset($ip_reputation['timezone']) && isset($fingerprint['timezone'])) {
            $ip_timezone = $ip_reputation['timezone'];
            $browser_timezone = $fingerprint['timezone'];

            if ($ip_timezone !== $browser_timezone) {
                $inconsistencies[] = 'timezone_mismatch';
                $suspicion_score += 30;
            }
        }

        // Check if hosting/data center IP claims to be residential
        if (isset($ip_reputation['is_hosting']) && $ip_reputation['is_hosting']) {
            $inconsistencies[] = 'hosting_provider_ip';
            $suspicion_score += 40;
        }

        // Check if mobile claim matches IP type
        if (isset($ip_reputation['mobile']) && isset($fingerprint['platform'])) {
            $is_mobile_ip = $ip_reputation['mobile'];
            $platform = strtolower($fingerprint['platform']);
            $is_mobile_platform = (stripos($platform, 'mobile') !== false ||
                                    stripos($platform, 'android') !== false ||
                                    stripos($platform, 'iphone') !== false);

            if ($is_mobile_ip !== $is_mobile_platform) {
                $inconsistencies[] = 'mobile_type_mismatch';
                $suspicion_score += 25;
            }
        }

        // Check fraud score from IP reputation
        if (isset($ip_reputation['fraud_score']) && $ip_reputation['fraud_score'] > 75) {
            $inconsistencies[] = 'high_fraud_score';
            $suspicion_score += $ip_reputation['fraud_score'] / 2;
        }

        return array(
            'is_consistent' => $suspicion_score < 50,
            'inconsistencies' => $inconsistencies,
            'suspicion_score' => min($suspicion_score, 100)
        );
    }

    /**
     * Get all visitor information for filtering
     *
     * @param string $ip_address IP address
     * @param string $user_agent User agent
     * @return array Visitor information
     */
    public function get_visitor_info($ip_address, $user_agent) {
        // Get IP reputation data
        $ip_reputation = $this->get_ip_reputation($ip_address);

        // Detect bot from user agent
        $bot_info = $this->detect_bot($user_agent);

        // If bot detected, verify if it's legitimate
        if ($bot_info['is_bot']) {
            $bot_info['is_verified'] = $this->verify_legitimate_bot($ip_address, $bot_info['bot_name']);

            // If unverified and IP reputation says it's a bot, increase confidence
            if (!$bot_info['is_verified'] && $ip_reputation['is_bot']) {
                $bot_info['confidence'] = 'high';
            } elseif (!$bot_info['is_verified']) {
                $bot_info['confidence'] = 'medium';
            } else {
                $bot_info['confidence'] = 'verified';
            }
        }

        return array(
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'bot_info' => $bot_info,
            'ip_reputation' => $ip_reputation,
            'country_code' => $ip_reputation['country_code'] ?? $this->get_country_from_ip($ip_address),
            'browser' => $this->detect_browser($user_agent),
            'os' => $this->detect_os($user_agent),
            'languages' => $this->parse_languages(self::get_accept_language()),
            'is_vpn' => $ip_reputation['is_vpn'] || $ip_reputation['is_proxy'] || $ip_reputation['is_tor'],
            'is_hosting' => $ip_reputation['is_hosting'] ?? false,
            'fraud_score' => $ip_reputation['fraud_score'] ?? 0
        );
    }
}
