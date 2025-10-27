<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Notifications System
 *
 * Manages dismissible notifications for the dashboard
 */
class MRC_Notifications {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook for AJAX dismiss
        add_action('wp_ajax_mrc_dismiss_notification', array($this, 'ajax_dismiss_notification'));
    }

    /**
     * Get all active notifications
     *
     * @return array Array of notifications
     */
    public function get_notifications() {
        $notifications = get_option('mrc_notifications', array());

        // Auto-generate system notifications
        $this->check_subscription_expiry();
        $this->check_api_failures();
        $this->check_mask_configuration();

        return get_option('mrc_notifications', array());
    }

    /**
     * Add a notification
     *
     * @param string $id Unique notification ID
     * @param string $type Notice type (error, warning, info, success)
     * @param string $message Notification message
     * @param array $actions Optional array of actions
     */
    public function add_notification($id, $type, $message, $actions = array()) {
        $notifications = get_option('mrc_notifications', array());

        $notifications[$id] = array(
            'type' => $type,
            'message' => $message,
            'actions' => $actions,
            'timestamp' => time()
        );

        update_option('mrc_notifications', $notifications);
    }

    /**
     * Dismiss a notification
     *
     * @param string $id Notification ID
     */
    public function dismiss_notification($id) {
        $notifications = get_option('mrc_notifications', array());

        if (isset($notifications[$id])) {
            unset($notifications[$id]);
            update_option('mrc_notifications', $notifications);
        }
    }

    /**
     * Check for subscription expiry
     */
    private function check_subscription_expiry() {
        $subscription_status = get_option('mrc_subscription_status');

        if ($subscription_status === 'past_due') {
            $this->add_notification(
                'subscription_past_due',
                'error',
                'Your subscription payment is past due. Service may be interrupted.',
                array(
                    array(
                        'label' => 'Update Payment',
                        'url' => 'https://mrcloak.com/dashboard/billing',
                        'type' => 'primary'
                    )
                )
            );
        } elseif ($subscription_status === 'grace') {
            $this->add_notification(
                'subscription_grace',
                'warning',
                'Your subscription is in grace period. Please update your payment method.',
                array(
                    array(
                        'label' => 'Update Payment',
                        'url' => 'https://mrcloak.com/dashboard/billing',
                        'type' => 'primary'
                    )
                )
            );
        } else {
            // Clear these notifications if subscription is fine
            $this->dismiss_notification('subscription_past_due');
            $this->dismiss_notification('subscription_grace');
        }
    }

    /**
     * Check for API failures
     */
    private function check_api_failures() {
        $failures = get_option('mrc_api_failures', array());
        $has_recent_failures = false;

        foreach ($failures as $endpoint => $failure_data) {
            // Check if failure happened in last 30 minutes
            if (isset($failure_data['last_time']) && (time() - $failure_data['last_time']) < 1800) {
                if ($failure_data['count'] >= 3) {
                    $has_recent_failures = true;
                    break;
                }
            }
        }

        if ($has_recent_failures) {
            $this->add_notification(
                'api_failures',
                'warning',
                'API connection issues detected. Using cached data. This may affect real-time updates.',
                array(
                    array(
                        'label' => 'Retry',
                        'url' => admin_url('admin.php?page=mr-cloak&action=refresh_masks'),
                        'type' => 'secondary'
                    )
                )
            );
        } else {
            $this->dismiss_notification('api_failures');
        }
    }

    /**
     * Check for mask configuration issues
     */
    private function check_mask_configuration() {
        $mask_manager = MRC_Mask_Manager::get_instance();
        $enabled_masks = $mask_manager->get_enabled_masks();

        $masks_without_pages = array();

        foreach ($enabled_masks as $mask) {
            $config = $mask['local_config'];
            if (empty($config['landing_page_id']) && $config['landing_page_type'] !== 'home') {
                $masks_without_pages[] = $mask['name'];
            }
        }

        if (!empty($masks_without_pages)) {
            $this->add_notification(
                'masks_no_landing_page',
                'warning',
                'Some enabled masks don\'t have landing pages set: ' . implode(', ', $masks_without_pages),
                array(
                    array(
                        'label' => 'Configure',
                        'url' => admin_url('admin.php?page=mr-cloak'),
                        'type' => 'primary'
                    )
                )
            );
        } else {
            $this->dismiss_notification('masks_no_landing_page');
        }
    }

    /**
     * Render notifications HTML
     */
    public function render_notifications() {
        $notifications = $this->get_notifications();

        if (empty($notifications)) {
            return;
        }

        foreach ($notifications as $id => $notification):
            $type = $notification['type'];
            $message = $notification['message'];
            $actions = isset($notification['actions']) ? $notification['actions'] : array();
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible mrc-notification" data-notification-id="<?php echo esc_attr($id); ?>" style="padding: 15px; position: relative;">
            <p><strong>Mr. Cloak:</strong> <?php echo esc_html($message); ?></p>
            <?php if (!empty($actions)): ?>
                <p>
                    <?php foreach ($actions as $action): ?>
                        <a href="<?php echo esc_url($action['url']); ?>"
                           class="button button-<?php echo esc_attr($action['type']); ?>"
                           <?php if (strpos($action['url'], 'http') === 0): ?>target="_blank" rel="noopener"<?php endif; ?>>
                            <?php echo esc_html($action['label']); ?>
                        </a>
                    <?php endforeach; ?>
                    <button type="button" class="button button-secondary mrc-dismiss-notification">Dismiss</button>
                </p>
            <?php endif; ?>
        </div>
        <?php
        endforeach;
    }

    /**
     * AJAX handler for dismissing notifications
     */
    public function ajax_dismiss_notification() {
        check_ajax_referer('mrc_dismiss_notification', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $notification_id = isset($_POST['notification_id']) ? sanitize_text_field($_POST['notification_id']) : '';

        if ($notification_id) {
            $this->dismiss_notification($notification_id);
            wp_send_json_success();
        }

        wp_send_json_error('Invalid notification ID');
    }

    /**
     * Get notification count
     *
     * @return int Number of active notifications
     */
    public function get_notification_count() {
        $notifications = $this->get_notifications();
        return count($notifications);
    }
}
