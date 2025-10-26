<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Admin Class
 */
class MRC_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_notices', array($this, 'show_domain_revoked_notice'));
        add_action('wp_ajax_mrc_dismiss_revoked_notice', array($this, 'dismiss_revoked_notice'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Mr. Cloak',
            'Mr. Cloak',
            'manage_options',
            'mr-cloak',
            array($this, 'render_dashboard_page'),
            'dashicons-shield',
            80
        );

        add_submenu_page(
            'mr-cloak',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'mr-cloak',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'mr-cloak',
            'Settings',
            'Settings',
            'manage_options',
            'mrc-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        require_once MRC_PLUGIN_DIR . 'admin/admin-dashboard.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        require_once MRC_PLUGIN_DIR . 'admin/settings-page.php';
    }

    /**
     * Show domain revoked notice in admin
     */
    public function show_domain_revoked_notice() {
        $notice = get_transient('mrc_domain_revoked_notice');

        if (!$notice) {
            return;
        }

        $domain = isset($notice['domain']) ? esc_html($notice['domain']) : 'this domain';
        $message = isset($notice['message']) ? esc_html($notice['message']) : 'Domain access has been revoked';
        ?>
        <div class="notice notice-error" style="position: relative; padding: 15px;">
            <h3 style="margin-top: 0;">⚠️ Mr. Cloak: Domain Access Revoked</h3>
            <p><?php echo $message; ?></p>
            <p>
                <strong>Domain:</strong> <code><?php echo $domain; ?></code>
            </p>
            <p>Traffic filtering has been disabled for security.</p>
            <p>
                If this was a mistake, you can restore access from your dashboard:
            </p>
            <p>
                <a href="https://mrcloak.com/dashboard/settings"
                   class="button button-primary"
                   target="_blank"
                   rel="noopener">
                    Restore Domain Access
                </a>
                <button type="button"
                        class="button button-secondary mrc-dismiss-revoked-notice">
                    Dismiss Notice
                </button>
            </p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.mrc-dismiss-revoked-notice').on('click', function() {
                if (confirm('Dismiss this notice? You can retry activation from plugin settings.')) {
                    $.post(ajaxurl, {
                        action: 'mrc_dismiss_revoked_notice',
                        _wpnonce: '<?php echo wp_create_nonce('mrc_dismiss_revoked_notice'); ?>'
                    }, function() {
                        location.reload();
                    });
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler to dismiss domain revoked notice
     */
    public function dismiss_revoked_notice() {
        check_ajax_referer('mrc_dismiss_revoked_notice');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        delete_transient('mrc_domain_revoked_notice');
        wp_send_json_success();
    }
}
