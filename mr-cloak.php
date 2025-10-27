<?php
/**
 * Plugin Name: Mr. Cloak
 * Plugin URI: https://mrcloak.com
 * Description: SaaS-powered traffic filtering and bot detection for affiliate marketers. Cloaks your campaigns from ad review bots.
 * Version: 3.0.0
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
define('MRC_VERSION', '3.0.0');

// Require core classes
require_once MRC_PLUGIN_DIR . 'includes/class-security.php';
require_once MRC_PLUGIN_DIR . 'includes/class-api-client.php';
require_once MRC_PLUGIN_DIR . 'includes/class-bot-detector.php';
require_once MRC_PLUGIN_DIR . 'includes/class-mask-manager.php';
require_once MRC_PLUGIN_DIR . 'includes/class-analytics-queue.php';
require_once MRC_PLUGIN_DIR . 'includes/class-redirector.php';

// Require admin classes
if (is_admin()) {
    require_once MRC_PLUGIN_DIR . 'includes/class-admin.php';
    require_once MRC_PLUGIN_DIR . 'includes/class-notifications.php';
    require_once MRC_PLUGIN_DIR . 'includes/class-admin-bar.php';
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

        // Register cron hooks
        add_action('mrc_heartbeat', array($this, 'run_heartbeat'));
        add_action('mrc_flush_analytics', array($this, 'flush_analytics_queue'));

        // Register cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
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
        // Schedule heartbeat cron (every 30 minutes)
        if (!wp_next_scheduled('mrc_heartbeat')) {
            wp_schedule_event(time() + 1800, 'mrc_every_30_minutes', 'mrc_heartbeat');
        }

        // Schedule analytics flush (hourly)
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
        wp_clear_scheduled_hook('mrc_heartbeat');
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
        $schedules['mrc_every_30_minutes'] = array(
            'interval' => 1800,
            'display' => __('Every 30 minutes', 'mr-cloak')
        );
        return $schedules;
    }

    /**
     * Run heartbeat cron job
     */
    public function run_heartbeat() {
        $api_client = MRC_API_Client::get_instance();
        $api_client->heartbeat();
    }

    /**
     * Flush analytics queue cron job
     */
    public function flush_analytics_queue() {
        $analytics_queue = MRC_Analytics_Queue::get_instance();
        $analytics_queue->flush_queue();
    }
}

// Initialize plugin
Mr_Cloak::get_instance();
