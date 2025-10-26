<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get subscription info
$subscription_status = get_option('mrc_subscription_status');
$license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

// Get mask info
$mask_manager = MRC_Mask_Manager::get_instance();
$active_mask = $mask_manager->get_active_mask();

// Get analytics queue stats
$analytics_queue = MRC_Analytics_Queue::get_instance();
$queue_stats = $analytics_queue->get_queue_stats();
$pending_events = $analytics_queue->get_pending_events(10);

?>

<div class="wrap">
    <h1>Mr. Cloak Dashboard</h1>

    <?php if (empty($license_key)): ?>
        <div class="notice notice-warning">
            <p><strong>Welcome to Mr. Cloak!</strong> Please <a href="<?php echo admin_url('admin.php?page=mrc-settings'); ?>">activate your license</a> to start filtering traffic.</p>
        </div>
    <?php else: ?>

    <div class="mrc-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">

        <!-- Subscription Status Card -->
        <div class="mrc-card" style="background: #fff; padding: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Subscription Status</h2>
            <p>
                <span class="mrc-status-badge mrc-status-<?php echo esc_attr($subscription_status); ?>">
                    <?php echo esc_html(ucfirst($subscription_status ?? 'Unknown')); ?>
                </span>
            </p>
            <p><strong>License:</strong> <code><?php echo esc_html($license_key); ?></code></p>
            <p>
                <a href="https://mrcloak.com/dashboard" target="_blank" class="button">View Dashboard</a>
            </p>
        </div>

        <!-- Active Mask Card -->
        <div class="mrc-card" style="background: #fff; padding: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Active Mask</h2>
            <?php if ($active_mask): ?>
                <p><strong><?php echo esc_html($active_mask['name']); ?></strong></p>
                <p><small>Offer: <?php echo esc_html($active_mask['offer_page_url']); ?></small></p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=mrc-settings'); ?>" class="button">Manage Masks</a>
                </p>
            <?php else: ?>
                <p>No mask active</p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=mrc-settings'); ?>" class="button button-primary">Enable a Mask</a>
                </p>
            <?php endif; ?>
        </div>

        <!-- Analytics Queue Card -->
        <div class="mrc-card" style="background: #fff; padding: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Analytics Queue</h2>
            <p><strong>Pending Events:</strong> <?php echo esc_html($queue_stats['pending_events']); ?></p>
            <p><strong>Failed Events:</strong> <?php echo esc_html($queue_stats['failed_events']); ?></p>
            <p><strong>Last Flush:</strong> <?php echo esc_html($queue_stats['last_flush_formatted']); ?></p>
            <p><small>Events are automatically submitted to Mr. Cloak when 50 events are queued or hourly.</small></p>
        </div>

    </div>

    <!-- Recent Events -->
    <?php if (!empty($pending_events)): ?>
    <div class="mrc-recent-events" style="background: #fff; padding: 20px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h2>Recent Events (Pending Submission)</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Visitor Type</th>
                    <th>Country</th>
                    <th>Blocked Reason</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($pending_events) as $event): ?>
                <tr>
                    <td><?php echo esc_html($event['visitor_type']); ?></td>
                    <td><?php echo esc_html($event['country_code'] ?? 'N/A'); ?></td>
                    <td><?php echo esc_html($event['blocked_reason'] ?? 'N/A'); ?></td>
                    <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($event['timestamp']))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div style="background: #fff; padding: 20px; margin-top: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h2>Quick Links</h2>
        <p>
            <a href="https://mrcloak.com/dashboard/analytics" target="_blank" class="button">View Detailed Analytics</a>
            <a href="https://mrcloak.com/dashboard/masks" target="_blank" class="button">Manage Masks</a>
            <a href="https://mrcloak.com/docs" target="_blank" class="button">Documentation</a>
        </p>
    </div>

    <?php endif; ?>
</div>

<style>
.mrc-status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 3px;
    font-weight: 600;
    font-size: 14px;
}

.mrc-status-active,
.mrc-status-trialing {
    background: #d4edda;
    color: #155724;
}

.mrc-status-grace {
    background: #fff3cd;
    color: #856404;
}

.mrc-status-past_due,
.mrc-status-revoked,
.mrc-status-suspended {
    background: #f8d7da;
    color: #721c24;
}

.mrc-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
}
</style>
