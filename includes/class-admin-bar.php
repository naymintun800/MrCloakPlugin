<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Admin Bar Widget
 *
 * Adds quick-access widget to WordPress admin bar
 */
class MRC_Admin_Bar {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Add menu to admin bar
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        // Only show to admins
        if (!current_user_can('manage_options')) {
            return;
        }

        $mask_manager = MRC_Mask_Manager::get_instance();
        $enabled_masks = $mask_manager->get_enabled_masks();
        $enabled_count = count($enabled_masks);

        $stats = $mask_manager->get_simple_stats();

        // Parent menu
        $wp_admin_bar->add_node(array(
            'id' => 'mr-cloak',
            'title' => sprintf(
                '<span class="ab-icon dashicons dashicons-shield"></span><span class="ab-label">Mr. Cloak [%d]</span>',
                $enabled_count
            ),
            'href' => admin_url('admin.php?page=mr-cloak'),
            'meta' => array(
                'class' => 'mrc-admin-bar-menu'
            )
        ));

        // Enabled masks
        if (!empty($enabled_masks)) {
            $wp_admin_bar->add_node(array(
                'id' => 'mr-cloak-masks-header',
                'parent' => 'mr-cloak',
                'title' => '<strong>Active Masks</strong>',
                'meta' => array(
                    'class' => 'mrc-admin-bar-header'
                )
            ));

            foreach ($enabled_masks as $mask) {
                $config = $mask['local_config'];
                $landing_page_name = $this->get_landing_page_name($config);

                $wp_admin_bar->add_node(array(
                    'id' => 'mr-cloak-mask-' . $mask['id'],
                    'parent' => 'mr-cloak',
                    'title' => sprintf(
                        '<span class="mrc-mask-indicator mrc-mask-active"></span> %s<br><small style="opacity: 0.7;">%s</small>',
                        esc_html($mask['name']),
                        esc_html($landing_page_name)
                    ),
                    'href' => admin_url('admin.php?page=mr-cloak'),
                    'meta' => array(
                        'class' => 'mrc-admin-bar-mask'
                    )
                ));
            }
        }

        // Stats
        $wp_admin_bar->add_node(array(
            'id' => 'mr-cloak-divider',
            'parent' => 'mr-cloak',
            'title' => '<hr style="margin: 5px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.1);">',
            'meta' => array(
                'class' => 'mrc-admin-bar-divider'
            )
        ));

        $wp_admin_bar->add_node(array(
            'id' => 'mr-cloak-stats',
            'parent' => 'mr-cloak',
            'title' => sprintf(
                'Whitelisted: %s | Filtered: %s',
                number_format($stats['whitelisted']),
                number_format($stats['filtered'])
            ),
            'meta' => array(
                'class' => 'mrc-admin-bar-stats'
            )
        ));

        // Links
        $wp_admin_bar->add_node(array(
            'id' => 'mr-cloak-dashboard',
            'parent' => 'mr-cloak',
            'title' => 'Dashboard',
            'href' => admin_url('admin.php?page=mr-cloak')
        ));

        $wp_admin_bar->add_node(array(
            'id' => 'mr-cloak-settings',
            'parent' => 'mr-cloak',
            'title' => 'Settings',
            'href' => admin_url('admin.php?page=mrc-settings')
        ));
    }

    /**
     * Get landing page name for display
     *
     * @param array $config Mask configuration
     * @return string Landing page name
     */
    private function get_landing_page_name($config) {
        if ($config['landing_page_type'] === 'home') {
            return 'Landing: Home Page';
        }

        if (!empty($config['landing_page_id'])) {
            $page = get_post($config['landing_page_id']);
            if ($page) {
                return 'Landing: ' . $page->post_title;
            }
        }

        return 'Landing: Not Set';
    }

    /**
     * Enqueue admin bar styles
     */
    public function enqueue_styles() {
        if (!is_admin_bar_showing()) {
            return;
        }

        ?>
        <style>
            #wpadminbar .mrc-admin-bar-menu .ab-icon:before {
                content: "\f332";
                top: 2px;
            }

            #wpadminbar .mrc-mask-indicator {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 5px;
            }

            #wpadminbar .mrc-mask-active {
                background: #46b450;
                box-shadow: 0 0 4px #46b450;
            }

            #wpadminbar .mrc-admin-bar-header {
                pointer-events: none;
            }

            #wpadminbar .mrc-admin-bar-header .ab-item {
                color: rgba(240, 245, 250, 0.7) !important;
                font-size: 11px !important;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            #wpadminbar .mrc-admin-bar-divider .ab-item {
                pointer-events: none;
                padding: 0 !important;
                margin: 5px 0 !important;
            }

            #wpadminbar .mrc-admin-bar-stats .ab-item {
                font-size: 12px;
                color: rgba(240, 245, 250, 0.8) !important;
            }

            #wpadminbar .mrc-admin-bar-mask .ab-item {
                white-space: normal !important;
                line-height: 1.4;
            }
        </style>
        <?php
    }
}
