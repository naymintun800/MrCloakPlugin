<?php
/**
 * Plugin Name: Mr. Cloak
 * Plugin URI: https://mrcloak.com
 * Description: SaaS-powered traffic filtering and bot detection for affiliate marketers. Cloaks your campaigns from ad review bots.
 * Version: 3.0.1
 * Author: Mr. Cloak
 * License: GPL v2 or later
 * Text Domain: mr-cloak
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MRC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MRC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MRC_VERSION', '3.0.1');

// Require core classes
require_once MRC_PLUGIN_DIR . 'includes/class-security.php';
require_once MRC_PLUGIN_DIR . 'includes/class-api-client.php';
require_once MRC_PLUGIN_DIR . 'includes/class-bot-detector.php';
require_once MRC_PLUGIN_DIR . 'includes/class-mask-manager.php';
require_once MRC_PLUGIN_DIR . 'includes/class-analytics-queue.php';
require_once MRC_PLUGIN_DIR . 'includes/class-redirector.php';
require_once MRC_PLUGIN_DIR . 'includes/class-honeypot.php';

// Require admin classes
if (is_admin()) {
    require_once MRC_PLUGIN_DIR . 'includes/class-admin.php';
    require_once MRC_PLUGIN_DIR . 'includes/class-notifications.php';
    require_once MRC_PLUGIN_DIR . 'includes/class-admin-bar.php';
    require_once MRC_PLUGIN_DIR . 'includes/class-plugin-updater.php';
}

/**
 * Main Mr. Cloak Plugin Class
 */
class Mr_Cloak {

    private static $instance = null;
    private $mask_manager;
    private $redirector;
    private $admin;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'init_components'));
        add_action('template_redirect', array($this, 'handle_request'), 1);
        add_action('admin_init', array($this, 'check_version_upgrade'));
        add_action('init', array('MRC_Analytics_Queue', 'schedule_cron'));

        // Register cron hooks
        add_action('mrc_heartbeat', array($this, 'run_heartbeat'));
        add_action('mrc_flush_analytics', array($this, 'flush_analytics_queue'));

        // Register cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));

        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // Register AJAX handlers
        add_action('wp_ajax_mrc_save_fingerprint', array($this, 'ajax_save_fingerprint'));
        add_action('wp_ajax_nopriv_mrc_save_fingerprint', array($this, 'ajax_save_fingerprint'));
        add_action('wp_ajax_mrc_save_behavior', array($this, 'ajax_save_behavior'));
        add_action('wp_ajax_nopriv_mrc_save_behavior', array($this, 'ajax_save_behavior'));

        // Add honeypot to footer
        add_action('wp_footer', array($this, 'add_honeypot'));

        // Initialize auto-updater for admin users
        if (is_admin()) {
            new MRC_Plugin_Updater(__FILE__);
        }
    }

    public function init_components() {
        $this->mask_manager = MRC_Mask_Manager::get_instance();
        $this->redirector = MRC_Redirector::get_instance();

        if (is_admin()) {
            $this->admin = MRC_Admin::get_instance();
        }
    }

    /**
     * Handle incoming request - main traffic filtering logic
     */
    public function handle_request() {
        // Skip for WordPress admins and admin area
        if (is_admin() || current_user_can('manage_options')) {
            return;
        }

        // Validate license using cached check (5-minute cache)
        // This ensures license status is verified without requiring access tokens
        $api_client = MRC_API_Client::get_instance();
        if (!$api_client->is_license_valid()) {
            // License is invalid or revoked - disable filtering
            return;
        }

        // Check if filtering is enabled globally
        if (!$this->mask_manager->is_filtering_enabled()) {
            return;
        }

        // Get visitor IP
        $ip_address = MRC_API_Client::get_client_ip();

        // Check IP whitelist
        $whitelisted_ips = get_option('mrc_whitelisted_ips', array());
        if (in_array($ip_address, $whitelisted_ips)) {
            return; // IP is whitelisted, skip filtering
        }

        // Determine current page
        $current_page_id = get_queried_object_id();
        $is_home = is_front_page() || is_home();

        // Find mask that applies to this page
        $active_mask = $this->mask_manager->get_mask_for_page($current_page_id, $is_home);

        // If no mask applies to this page, let WordPress continue normally
        if (!$active_mask) {
            return;
        }

        // Get visitor info
        $user_agent = MRC_Bot_Detector::get_user_agent();

        // Process visitor with the mask for this page
        $result = $this->mask_manager->process_visitor($ip_address, $user_agent, $active_mask);

        // Handle visitor based on result
        // Only whitelisted visitors get redirected to offer
        // Filtered visitors stay on current page (no action)
        $this->redirector->handle_visitor($result);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Heartbeat cron is now disabled - validation happens on-demand
        // This reduces API load on backend

        // Ensure analytics cron is set to run every 15 minutes
        MRC_Analytics_Queue::schedule_cron();

        // Set default options
        $default_options = array(
            'mrc_filtering_enabled' => false,
            'mrc_redirect_method' => 'php_301'
        );

        foreach ($default_options as $key => $value) {
            if (!get_option($key)) {
                add_option($key, $value);
            }
        }

        // Run migration if upgrading from old version
        $this->run_migration();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear cron jobs
        MRC_Analytics_Queue::unschedule_cron();
    }

    /**
     * Check for version upgrade
     */
    public function check_version_upgrade() {
        $current_version = get_option('mrc_plugin_version', '0.0.0');

        if (version_compare($current_version, MRC_VERSION, '<')) {
            $this->upgrade_plugin($current_version);
            update_option('mrc_plugin_version', MRC_VERSION);
        }
    }

    /**
     * Upgrade plugin from older version
     */
    private function upgrade_plugin($from_version) {
        // If upgrading from version 2.x (Facebook Bot Detector)
        if (version_compare($from_version, '3.0.0', '<')) {
            $this->run_migration();
        }
    }

    /**
     * Run migration from old plugin version
     */
    private function run_migration() {
        global $wpdb;

        // Drop old database tables
        $tables_to_drop = array(
            $wpdb->prefix . 'facebook_bot_logs',
            $wpdb->prefix . 'facebook_bot_redirects',
            $wpdb->prefix . 'facebook_bot_whitelist'
        );

        foreach ($tables_to_drop as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        // Remove old plugin options
        $old_options = array(
            'fbd_detection_sensitivity',
            'fbd_log_retention_days',
            'fbd_enable_ip_verification',
            'fbd_enable_frequency_detection',
            'fbd_frequency_threshold',
            'fbd_frequency_window',
            'fbd_confidence_threshold',
            'fbd_ip_whitelist',
            'fbd_ip_blacklist',
            'fbd_enable_redirection',
            'fbd_redirect_url',
            'fbd_redirect_method',
            'fbd_auto_whitelist_threshold',
            'fbd_redirect_log_retention',
            'fbd_default_whitelist_initialized',
            'fbd_plugin_version'
        );

        foreach ($old_options as $option) {
            delete_option($option);
        }

        // Migrate redirect method if exists
        $old_redirect_method = get_option('fbd_redirect_method');
        if ($old_redirect_method) {
            $new_method = $old_redirect_method;
            switch ($old_redirect_method) {
                case '301':
                    $new_method = 'php_301';
                    break;
                case '302':
                    $new_method = 'php_302';
                    break;
                case '307':
                    $new_method = 'php_307';
                    break;
            }
            update_option('mrc_redirect_method', $new_method);
        }

        // Mark migration as complete
        update_option('mrc_migration_completed', true);

        // Show admin notice
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Mr. Cloak 3.0 Installed!</strong> The plugin has been upgraded from Facebook Bot Detector.</p>';
            echo '<p>Please <a href="' . admin_url('admin.php?page=mrc-settings') . '">activate your license</a> to start using the new features.</p>';
            echo '</div>';
        });
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['mrc_every_15_minutes'] = array(
            'interval' => 900,
            'display' => __('Every 15 minutes', 'mr-cloak')
        );
        return $schedules;
    }

    /**
     * Run heartbeat cron job
     * DISABLED: Heartbeat is now deprecated - validation happens on-demand
     */
    public function run_heartbeat() {
        // No longer needed - validation happens on every visitor request
        // This reduces unnecessary API calls to the backend
        return;
    }

    /**
     * Flush analytics queue cron job
     */
    public function flush_analytics_queue() {
        $analytics_queue = MRC_Analytics_Queue::get_instance();
        $analytics_queue->flush_queue();
    }

    /**
     * Enqueue frontend scripts for fingerprinting
     */
    public function enqueue_frontend_scripts() {
        // Only enqueue on non-admin pages
        if (is_admin()) {
            return;
        }

        // Only enqueue if filtering is enabled
        if (!$this->mask_manager || !$this->mask_manager->is_filtering_enabled()) {
            return;
        }

        // Enqueue fingerprint script
        wp_enqueue_script(
            'mrc-fingerprint',
            MRC_PLUGIN_URL . 'assets/js/fingerprint.js',
            array(),
            MRC_VERSION,
            true
        );

        // Localize script with AJAX URL and nonce
        wp_localize_script('mrc-fingerprint', 'mrcFingerprint', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mrc_fingerprint_nonce')
        ));
    }

    /**
     * AJAX handler to save fingerprint data
     */
    public function ajax_save_fingerprint() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mrc_fingerprint_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Get fingerprint data
        $fingerprint = isset($_POST['fingerprint']) ? json_decode(stripslashes($_POST['fingerprint']), true) : array();

        if (empty($fingerprint)) {
            wp_send_json_error('No fingerprint data');
            return;
        }

        // Get or create visitor session
        $session_id = $this->get_visitor_session_id();

        // Store fingerprint data in transient (expires after 1 hour)
        set_transient('mrc_fp_' . $session_id, $fingerprint, HOUR_IN_SECONDS);

        // Run advanced detection checks
        $bot_detector = MRC_Bot_Detector::get_instance();
        $headless_check = $bot_detector->detect_headless_browser($fingerprint);

        // Store detection results
        set_transient('mrc_headless_' . $session_id, $headless_check, HOUR_IN_SECONDS);

        wp_send_json_success(array(
            'session_id' => $session_id,
            'headless_detected' => $headless_check['is_headless']
        ));
    }

    /**
     * AJAX handler to save behavioral data
     */
    public function ajax_save_behavior() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mrc_fingerprint_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Get behavior data
        $behavior = isset($_POST['behavior']) ? json_decode(stripslashes($_POST['behavior']), true) : array();

        if (empty($behavior)) {
            wp_send_json_error('No behavior data');
            return;
        }

        // Get visitor session
        $session_id = $this->get_visitor_session_id();

        // Store behavior data
        set_transient('mrc_behavior_' . $session_id, $behavior, HOUR_IN_SECONDS);

        // Run behavioral analysis
        $bot_detector = MRC_Bot_Detector::get_instance();
        $behavior_analysis = $bot_detector->analyze_behavior($behavior);

        // Store analysis results
        set_transient('mrc_behavior_analysis_' . $session_id, $behavior_analysis, HOUR_IN_SECONDS);

        wp_send_json_success(array(
            'session_id' => $session_id,
            'suspicious' => $behavior_analysis['is_suspicious']
        ));
    }

    /**
     * Get or create visitor session ID
     *
     * @return string Session ID
     */
    private function get_visitor_session_id() {
        // Check for existing session cookie
        if (isset($_COOKIE['mrc_session'])) {
            return sanitize_text_field($_COOKIE['mrc_session']);
        }

        // Create new session ID
        $session_id = wp_generate_password(32, false);

        // Set cookie (expires in 1 hour) with secure flags
        $cookie_options = array(
            'expires'  => time() + HOUR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'
        );
        setcookie('mrc_session', $session_id, $cookie_options);

        return $session_id;
    }

    /**
     * Add honeypot to footer
     */
    public function add_honeypot() {
        $honeypot = MRC_Honeypot::get_instance();
        $honeypot->add_to_footer();
    }
}

// Initialize plugin
Mr_Cloak::get_instance();
