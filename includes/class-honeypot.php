<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Honeypot System
 *
 * Implements challenge-response mechanisms to detect bots:
 * - Honeypot fields (invisible form fields)
 * - Time-based challenges
 * - Cookie manipulation tests
 * - JavaScript computation tests
 */
class MRC_Honeypot {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate honeypot HTML to inject into page
     *
     * @return string HTML for honeypot fields
     */
    public function generate_honeypot_html() {
        $field_name = $this->get_honeypot_field_name();
        $timestamp = time();
        $token = $this->generate_token($timestamp);

        $html = '<!-- Mr. Cloak Bot Detection -->' . "\n";
        $html .= '<div style="position: absolute; left: -9999px; top: -9999px; opacity: 0; pointer-events: none;" aria-hidden="true">' . "\n";

        // Honeypot field - bots often auto-fill all fields
        $html .= '<label for="' . esc_attr($field_name) . '">Leave this field empty</label>' . "\n";
        $html .= '<input type="text" id="' . esc_attr($field_name) . '" name="' . esc_attr($field_name) . '" value="" tabindex="-1" autocomplete="off" />' . "\n";

        // Time-based challenge token
        $html .= '<input type="hidden" name="mrc_visit_time" value="' . esc_attr($timestamp) . '" />' . "\n";
        $html .= '<input type="hidden" name="mrc_visit_token" value="' . esc_attr($token) . '" />' . "\n";

        $html .= '</div>' . "\n";

        // Add cookie test via JavaScript
        $html .= '<script>';
        $html .= 'try { document.cookie = "mrc_cookie_test=' . esc_js($token) . '; path=/; SameSite=Lax"; } catch(e) {}';
        $html .= '</script>' . "\n";

        return $html;
    }

    /**
     * Check if visitor passed honeypot challenges
     *
     * @return array Result with 'passed' boolean and 'failures' array
     */
    public function check_honeypot() {
        $failures = array();
        $score = 0;

        // Check if honeypot field was filled (bots often auto-fill all fields)
        $field_name = $this->get_honeypot_field_name();
        if (!empty($_POST[$field_name]) || !empty($_GET[$field_name])) {
            $failures[] = 'honeypot_field_filled';
            $score += 50;
        }

        // Check time-based challenge (did visitor spend reasonable time on page?)
        if (isset($_POST['mrc_visit_time']) || isset($_GET['mrc_visit_time'])) {
            $visit_time = intval($_POST['mrc_visit_time'] ?? $_GET['mrc_visit_time']);
            $time_on_page = time() - $visit_time;

            // If page was submitted in less than 1 second, very suspicious
            if ($time_on_page < 1) {
                $failures[] = 'submitted_too_fast';
                $score += 40;
            }

            // Verify token
            $token = $_POST['mrc_visit_token'] ?? $_GET['mrc_visit_token'] ?? '';
            $expected_token = $this->generate_token($visit_time);

            if ($token !== $expected_token) {
                $failures[] = 'invalid_token';
                $score += 30;
            }
        }

        // Check cookie test
        if (isset($_COOKIE['mrc_cookie_test'])) {
            // Cookie exists, which is good
            // Could add additional validation here
        } else {
            // Missing cookie might indicate bot, but could also be privacy settings
            // Don't fail on this alone, just note it
            $failures[] = 'no_cookie_support';
            $score += 10;
        }

        // Check if user agent is present
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            $failures[] = 'missing_user_agent';
            $score += 30;
        }

        // Check for Accept header (legitimate browsers always send this)
        if (empty($_SERVER['HTTP_ACCEPT'])) {
            $failures[] = 'missing_accept_header';
            $score += 25;
        }

        // Check referer for direct visits (optional, many privacy tools strip this)
        // Not counting as failure, just informational
        if (empty($_SERVER['HTTP_REFERER'])) {
            // Direct visit or referer stripped
        }

        return array(
            'passed' => $score < 50,
            'failures' => $failures,
            'suspicion_score' => min($score, 100)
        );
    }

    /**
     * Get honeypot field name (rotates daily for security)
     *
     * @return string Field name
     */
    private function get_honeypot_field_name() {
        // Rotate field name daily so bots can't hardcode it
        $date = date('Ymd');
        $salt = wp_salt('auth');
        return 'website_' . substr(md5($date . $salt), 0, 8);
    }

    /**
     * Generate time-based token
     *
     * @param int $timestamp Timestamp
     * @return string Token
     */
    private function generate_token($timestamp) {
        $salt = wp_salt('nonce');
        return substr(md5($timestamp . $salt . $_SERVER['REMOTE_ADDR']), 0, 16);
    }

    /**
     * Inject honeypot into page content
     *
     * @param string $content Page content
     * @return string Modified content
     */
    public function inject_into_content($content) {
        // Only inject on non-admin pages
        if (is_admin()) {
            return $content;
        }

        // Don't inject if filtering is disabled
        $mask_manager = MRC_Mask_Manager::get_instance();
        if (!$mask_manager->is_filtering_enabled()) {
            return $content;
        }

        $honeypot_html = $this->generate_honeypot_html();

        // Inject at the beginning of body content
        return $honeypot_html . $content;
    }

    /**
     * Add honeypot to footer
     */
    public function add_to_footer() {
        // Only add on non-admin pages
        if (is_admin()) {
            return;
        }

        // Don't add if filtering is disabled
        $mask_manager = MRC_Mask_Manager::get_instance();
        if (!$mask_manager->is_filtering_enabled()) {
            return;
        }

        echo $this->generate_honeypot_html();
    }
}
