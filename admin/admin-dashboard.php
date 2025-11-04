<?php
if (!defined('ABSPATH')) {
    exit;
}

// Handle license deactivation
if (isset($_POST['mrc_deactivate_license']) && check_admin_referer('mrc_dashboard')) {
    // Clear all license-related data
    delete_option('mrc_license_key');
    delete_option('mrc_subscription_status');
    delete_option('mrc_cached_masks');
    delete_option('mrc_masks_updated');
    delete_option('mrc_mask_configs');
    delete_option('mrc_filtering_enabled');

    // Clear analytics counters
    delete_option('mrc_total_whitelisted_count');
    delete_option('mrc_total_filtered_count');
    delete_option('mrc_analytics_queue');

    echo '<div class="notice notice-success"><p><strong>License Deactivated!</strong> You can now enter a new license key.</p></div>';
    echo '<script>window.location.reload();</script>';
}

// Handle license activation
if (isset($_POST['mrc_activate_license']) && check_admin_referer('mrc_dashboard')) {
    $license_key = sanitize_text_field($_POST['mrc_license_key']);

    if (empty($license_key)) {
        echo '<div class="notice notice-error"><p>Please enter a license key.</p></div>';
    } elseif (!MRC_Security::validate_license_key_format($license_key)) {
        echo '<div class="notice notice-error"><p>Invalid license key format. Expected format: MRC-XXXXXXXX-XXXXXXXX-XXXXXXXX</p></div>';
    } else {
        $api_client = MRC_API_Client::get_instance();
        $domain = MRC_API_Client::normalize_domain(parse_url(home_url(), PHP_URL_HOST));

        $response = $api_client->activate_license($license_key, $domain);

        if (isset($response['error'])) {
            // Check for domain security errors with detailed information
            if (isset($response['error_type']) && in_array($response['error_type'], array('domain_not_authorized', 'domain_revoked'))) {
                ?>
                <div class="notice notice-error" style="padding: 15px;">
                    <h3 style="margin-top: 0;"><?php echo esc_html($response['title'] ?? 'License Activation Failed'); ?></h3>

                    <?php if (!empty($response['message'])): ?>
                        <p><?php echo esc_html($response['message']); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($response['instructions'])): ?>
                        <ol style="margin-left: 20px;">
                            <?php foreach ($response['instructions'] as $instruction): ?>
                                <li><?php echo wp_kses_post($instruction); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>

                    <?php if (!empty($response['dashboard_url'])): ?>
                        <p>
                            <a href="<?php echo esc_url($response['dashboard_url']); ?>"
                               class="button button-primary"
                               target="_blank"
                               rel="noopener">
                                Open Dashboard Settings
                            </a>
                            <?php if (!empty($response['show_retry'])): ?>
                                <button type="button"
                                        class="button button-secondary"
                                        onclick="location.reload();">
                                    Retry Activation
                                </button>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php
            } else {
                echo '<div class="notice notice-error"><p><strong>License Activation Failed:</strong> ' . esc_html($response['error']) . '</p></div>';
            }
        } else {
            update_option('mrc_filtering_enabled', true);
            echo '<div class="notice notice-success"><p><strong>License Activated Successfully!</strong> Your subscription is active.</p></div>';
            echo '<script>window.location.reload();</script>';
        }
    }
}

// Handle mask toggle
if (isset($_POST['mrc_toggle_mask']) && check_admin_referer('mrc_dashboard')) {
    $mask_id = sanitize_text_field($_POST['mask_id']);
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

    $mask_manager = MRC_Mask_Manager::get_instance();
    $mask_manager->toggle_mask($mask_id, $enabled);

    echo '<div class="notice notice-success"><p>Mask ' . ($enabled ? 'enabled' : 'disabled') . ' successfully!</p></div>';
}

// Handle white page update
if (isset($_POST['mrc_update_landing_page']) && check_admin_referer('mrc_dashboard')) {
    $mask_id = sanitize_text_field($_POST['mask_id']);
    $landing_page = sanitize_text_field($_POST['landing_page']);

    $mask_manager = MRC_Mask_Manager::get_instance();

    if ($landing_page === 'home') {
        $mask_manager->save_mask_config($mask_id, array(
            'landing_page_id' => null,
            'landing_page_type' => 'home',
            'enabled' => true
        ));
    } else {
        $page_id = intval(str_replace(array('page-', 'post-'), '', $landing_page));
        $page_type = strpos($landing_page, 'page-') === 0 ? 'page' : 'post';

        $mask_manager->save_mask_config($mask_id, array(
            'landing_page_id' => $page_id,
            'landing_page_type' => $page_type,
            'enabled' => true
        ));
    }

    echo '<div class="notice notice-success"><p>White page updated successfully!</p></div>';
}

// Handle reload masks
if (isset($_POST['mrc_reload_masks']) && check_admin_referer('mrc_dashboard')) {
    // Check rate limit (30-second cooldown)
    $last_reload = get_transient('mrc_last_reload_masks');
    $cooldown_seconds = 30; // 30 seconds

    if ($last_reload) {
        $time_remaining = $cooldown_seconds - (time() - $last_reload);
        if ($time_remaining > 0) {
            echo '<div class="notice notice-warning"><p>Please wait ' . $time_remaining . ' second' . ($time_remaining > 1 ? 's' : '') . ' before reloading masks again.</p></div>';
        } else {
            // Cooldown expired, proceed with reload
            $mask_manager = MRC_Mask_Manager::get_instance();
            $success = $mask_manager->refresh_masks();

            if ($success) {
                set_transient('mrc_last_reload_masks', time(), $cooldown_seconds);
                echo '<div class="notice notice-success"><p>Masks reloaded successfully!</p></div>';
                echo '<script>window.location.reload();</script>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to reload masks. Please check your license and try again.</p></div>';
            }
        }
    } else {
        // First time or cooldown expired
        $mask_manager = MRC_Mask_Manager::get_instance();
        $success = $mask_manager->refresh_masks();

        if ($success) {
            set_transient('mrc_last_reload_masks', time(), $cooldown_seconds);
            echo '<div class="notice notice-success"><p>Masks reloaded successfully!</p></div>';
            echo '<script>window.location.reload();</script>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to reload masks. Please check your license and try again.</p></div>';
        }
    }
}

// Handle sync analytics
if (isset($_POST['mrc_sync_analytics']) && check_admin_referer('mrc_dashboard')) {
    // Check rate limit (30-second cooldown)
    $last_sync = get_transient('mrc_last_sync_analytics');
    $cooldown_seconds = 30; // 30 seconds

    if ($last_sync) {
        $time_remaining = $cooldown_seconds - (time() - $last_sync);
        if ($time_remaining > 0) {
            echo '<div class="notice notice-warning"><p>Please wait ' . $time_remaining . ' second' . ($time_remaining > 1 ? 's' : '') . ' before syncing analytics again.</p></div>';
        } else {
            // Cooldown expired, proceed with sync
            $analytics_queue = MRC_Analytics_Queue::get_instance();
            $queue = get_option('mrc_analytics_queue', array());
            $queue_count = is_array($queue) ? count($queue) : 0;

            if ($queue_count === 0) {
                echo '<div class="notice notice-info"><p>No analytics to sync. Analytics are queued automatically as visitors browse your site.</p></div>';
            } else {
                $result = $analytics_queue->flush_queue();
                set_transient('mrc_last_sync_analytics', time(), $cooldown_seconds);

                if ($result === true) {
                    echo '<div class="notice notice-success"><p>Analytics synced successfully! ' . $queue_count . ' event' . ($queue_count != 1 ? 's' : '') . ' sent.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to sync analytics. Please check your license and try again.</p></div>';
                }
            }
        }
    } else {
        // First time or cooldown expired
        $analytics_queue = MRC_Analytics_Queue::get_instance();
        $queue = get_option('mrc_analytics_queue', array());
        $queue_count = is_array($queue) ? count($queue) : 0;

        if ($queue_count === 0) {
            echo '<div class="notice notice-info"><p>No analytics to sync. Analytics are queued automatically as visitors browse your site.</p></div>';
        } else {
            $result = $analytics_queue->flush_queue();
            set_transient('mrc_last_sync_analytics', time(), $cooldown_seconds);

            if ($result === true) {
                echo '<div class="notice notice-success"><p>Analytics synced successfully! ' . $queue_count . ' event' . ($queue_count != 1 ? 's' : '') . ' sent.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to sync analytics. Please check your license and try again.</p></div>';
            }
        }
    }
}

// Validate license to ensure status is fresh when viewing dashboard
// Uses 5-minute cached validation - no access token required
$api_client = MRC_API_Client::get_instance();
$is_valid = $api_client->is_license_valid();

// Get data
$license_key = MRC_Security::decrypt(get_option('mrc_license_key'));
$subscription_status = get_option('mrc_subscription_status');
$mask_manager = MRC_Mask_Manager::get_instance();
$mask_configs = $mask_manager->get_mask_configs();
$stats = $mask_manager->get_simple_stats();
$notifications = MRC_Notifications::get_instance();

// Get all pages and posts for dropdown
$pages = get_pages();
$posts = get_posts(array('posts_per_page' => 50));

?>

<div class="wrap">
    <h1>Mr. Cloak</h1>

    <?php
    // Display notifications
    $notifications->render_notifications();
    ?>

    <?php if (empty($license_key)): ?>
        <!-- License Activation Section -->
        <div class="mrc-activation-section" style="background: #fff; padding: 30px; margin: 20px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Welcome to Mr. Cloak!</h2>
            <p>Enter your license key to start filtering traffic on your website.</p>

            <form method="post" action="">
                <?php wp_nonce_field('mrc_dashboard'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mrc_license_key">License Key</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mrc_license_key"
                                   name="mrc_license_key"
                                   class="regular-text"
                                   placeholder="MRC-XXXXXXXX-XXXXXXXX-XXXXXXXX"
                                   pattern="MRC-[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}"
                                   required />
                            <p class="description">
                                Format: MRC-XXXXXXXX-XXXXXXXX-XXXXXXXX<br>
                                Don't have a license? <a href="https://mrcloak.com" target="_blank">Get one here</a>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Activate License', 'primary large', 'mrc_activate_license'); ?>
            </form>
        </div>
    <?php else: ?>

    <?php
    // Check if subscription is inactive
    $is_subscription_inactive = in_array($subscription_status, array('revoked', 'expired', 'suspended', 'canceled', 'past_due'));

    if ($is_subscription_inactive):
    ?>
        <div class="notice notice-error" style="padding: 20px;">
            <h2 style="margin-top: 0;">⚠️ Subscription Inactive</h2>
            <p><strong>Status:</strong> <?php echo esc_html(ucfirst($subscription_status ?? 'Unknown')); ?></p>
            <p>Your subscription is no longer active. Filtering has been disabled automatically.</p>

            <?php if ($subscription_status === 'revoked'): ?>
                <p><strong>This license has been revoked.</strong> Please contact support or generate a new license key.</p>
            <?php elseif ($subscription_status === 'expired'): ?>
                <p>Your subscription has expired. Please renew to continue using Mr. Cloak.</p>
            <?php elseif ($subscription_status === 'past_due'): ?>
                <p>Your payment is past due. Please update your payment method.</p>
            <?php endif; ?>

            <p>
                <a href="https://mrcloak.com/dashboard/billing" target="_blank" class="button button-primary">Manage Subscription</a>
                <form method="post" action="" style="display: inline;" onsubmit="return confirm('This will deactivate the current license. You can then enter a new license key.');">
                    <?php wp_nonce_field('mrc_dashboard'); ?>
                    <button type="submit" name="mrc_deactivate_license" class="button button-secondary">
                        Change License Key
                    </button>
                </form>
            </p>
        </div>
    <?php else: ?>

    <!-- Dashboard Grid -->
    <div class="mrc-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">

        <!-- Subscription Status Card -->
        <div class="mrc-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Subscription</h2>
            <p>
                <span class="mrc-status-badge mrc-status-<?php echo esc_attr($subscription_status); ?>">
                    <?php echo esc_html(ucfirst($subscription_status ?? 'Unknown')); ?>
                </span>
            </p>
            <p><strong>License:</strong><br><code><?php echo esc_html(MRC_API_Client::mask_license_key($license_key)); ?></code></p>
            <p>
                <a href="https://mrcloak.com/dashboard" target="_blank" class="button">Manage Subscription</a>
            </p>
            <form method="post" action="" style="margin-top: 15px;" onsubmit="return confirm('Are you sure you want to deactivate this license? This will disable all filtering and clear your mask configurations.');">
                <?php wp_nonce_field('mrc_dashboard'); ?>
                <button type="submit" name="mrc_deactivate_license" class="button button-secondary">
                    Change License Key
                </button>
            </form>
        </div>

        <!-- Quick Stats Card -->
        <div class="mrc-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Quick Stats</h2>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: bold; color: #46b450;">
                        <?php echo number_format($stats['whitelisted']); ?>
                    </div>
                    <div style="color: #666; font-size: 14px;">Whitelisted</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 32px; font-weight: bold; color: #dc3232;">
                        <?php echo number_format($stats['filtered']); ?>
                    </div>
                    <div style="color: #666; font-size: 14px;">Filtered</div>
                </div>
            </div>
            <p style="margin-top: 15px; text-align: center;">
                <a href="https://mrcloak.com/dashboard/analytics" target="_blank" class="button">View Detailed Analytics</a>
            </p>
            <?php
            // Get pending analytics count
            $analytics_queue = MRC_Analytics_Queue::get_instance();
            $queue = get_option('mrc_analytics_queue', array());
            $pending_count = is_array($queue) ? count($queue) : 0;
            $last_sync = get_transient('mrc_last_sync_analytics');
            $is_sync_disabled = $last_sync && (time() - $last_sync) < 30;
            ?>
            <form method="post" action="" style="margin-top: 10px; text-align: center;" id="mrc-sync-analytics-form">
                <?php wp_nonce_field('mrc_dashboard'); ?>
                <button type="submit"
                        name="mrc_sync_analytics"
                        id="mrc-sync-analytics-btn"
                        class="button button-secondary"
                        <?php echo $is_sync_disabled ? 'disabled' : ''; ?>
                        style="<?php echo $is_sync_disabled ? 'opacity: 0.5; cursor: not-allowed;' : ''; ?>">
                    <?php
                    if ($is_sync_disabled) {
                        $time_remaining = 30 - (time() - $last_sync);
                        echo 'Sync Analytics (' . $time_remaining . 's)';
                    } else {
                        echo 'Sync Analytics Now';
                    }
                    ?>
                    <?php if ($pending_count > 0): ?>
                        <span class="mrc-badge" style="background: #dc3232; color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 11px; margin-left: 5px;">
                            <?php echo $pending_count; ?>
                        </span>
                    <?php endif; ?>
                </button>
            </form>
        </div>



    </div>

    <!-- Masks Section -->
    <div class="mrc-masks-section" style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h2 style="margin-top: 0;">Masks</h2>

        <?php if (empty($mask_configs)): ?>
            <p>No masks found. Please create a mask in your <a href="https://mrcloak.com/dashboard/masks" target="_blank">Mr. Cloak dashboard</a>.</p>
        <?php else: ?>
            <?php foreach ($mask_configs as $mask): ?>
                <?php
                $config = $mask['local_config'];
                $is_enabled = isset($config['enabled']) && $config['enabled'];
                $mask_id = $mask['id'];
                ?>

                <div class="mrc-mask-card" style="border: 2px solid <?php echo $is_enabled ? '#46b450' : '#ddd'; ?>; padding: 20px; margin: 15px 0; border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <h3 style="margin: 0 0 10px 0;">
                                <?php echo esc_html($mask['name']); ?>
                                <?php if ($is_enabled): ?>
                                    <span style="color: #46b450; font-size: 14px; font-weight: normal;">● Active</span>
                                <?php endif; ?>
                            </h3>
                            <p style="margin: 0;"><strong>Offer:</strong> <code><?php echo esc_html($mask['offer_page_url']); ?></code></p>
                        </div>

                        <!-- Toggle Switch -->
                        <form method="post" action="" style="margin-left: 20px;">
                            <?php wp_nonce_field('mrc_dashboard'); ?>
                            <input type="hidden" name="mask_id" value="<?php echo esc_attr($mask_id); ?>">
                            <input type="hidden" name="enabled" value="<?php echo $is_enabled ? '0' : '1'; ?>">
                            <button type="submit"
                                    name="mrc_toggle_mask"
                                    class="button <?php echo $is_enabled ? 'button-secondary' : 'button-primary'; ?>"
                                    style="min-width: 100px;">
                                <?php echo $is_enabled ? 'Disable' : 'Enable'; ?>
                            </button>
                        </form>
                    </div>

                    <?php if ($is_enabled): ?>
                        <!-- White Page Selector -->
                        <form method="post" action="">
                            <?php wp_nonce_field('mrc_dashboard'); ?>
                            <input type="hidden" name="mask_id" value="<?php echo esc_attr($mask_id); ?>">
                            <input type="hidden" name="mrc_update_landing_page" value="1">

                            <table class="form-table" style="margin-top: 15px;">
                                <tr>
                                    <th style="width: 150px;">White Page:</th>
                                    <td>
                                        <select name="landing_page" class="regular-text" onchange="this.form.submit()">
                                            <option value="home" <?php selected($config['landing_page_type'], 'home'); ?>>🏠 Home Page</option>
                                            <optgroup label="Pages">
                                                <?php foreach ($pages as $page): ?>
                                                    <option value="page-<?php echo $page->ID; ?>"
                                                            <?php selected($config['landing_page_id'] == $page->ID && $config['landing_page_type'] === 'page', true); ?>>
                                                        📄 <?php echo esc_html($page->post_title); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <optgroup label="Posts">
                                                <?php foreach ($posts as $post): ?>
                                                    <option value="post-<?php echo $post->ID; ?>"
                                                            <?php selected($config['landing_page_id'] == $post->ID && $config['landing_page_type'] === 'post', true); ?>>
                                                        📝 <?php echo esc_html($post->post_title); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>

                                        <noscript><button type="submit" class="button button-secondary">Update</button></noscript>

                                        <?php
                                        // Get preview URL
                                        $preview_url = home_url('/');
                                        if ($config['landing_page_type'] === 'home') {
                                            $preview_url = home_url('/');
                                        } elseif (!empty($config['landing_page_id'])) {
                                            $preview_url = get_permalink($config['landing_page_id']);
                                        }
                                        ?>

                                        <a href="<?php echo esc_url($preview_url); ?>"
                                           target="_blank"
                                           class="button"
                                           style="margin-left: 10px;">
                                            Preview
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <p style="margin-top: 20px;">
                <a href="https://mrcloak.com/dashboard/masks" target="_blank" class="button button-primary">Create New Mask</a>
            </p>
        <?php endif; ?>

        <!-- Reload Masks button - always visible -->
        <p style="margin-top: 20px; text-align: center;">
            <?php
            $last_reload = get_transient('mrc_last_reload_masks');
            $is_reload_disabled = $last_reload && (time() - $last_reload) < 30;
            ?>
            <form method="post" action="" style="display: inline;" id="mrc-reload-masks-form">
                <?php wp_nonce_field('mrc_dashboard'); ?>
                <button type="submit"
                        name="mrc_reload_masks"
                        id="mrc-reload-masks-btn"
                        class="button button-secondary"
                        <?php echo $is_reload_disabled ? 'disabled' : ''; ?>
                        style="<?php echo $is_reload_disabled ? 'opacity: 0.5; cursor: not-allowed;' : ''; ?>">
                    <?php
                    if ($is_reload_disabled) {
                        $time_remaining = 30 - (time() - $last_reload);
                        echo 'Reload Masks (' . $time_remaining . 's)';
                    } else {
                        echo 'Reload Masks';
                    }
                    ?>
                </button>
            </form>
        </p>
    </div>

    <?php endif; // End subscription active check ?>

    <?php endif; // End license key check ?>
</div>

<style>
.mrc-status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 4px;
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
    transition: box-shadow 0.2s;
}

.mrc-mask-card {
    transition: all 0.2s;
}

.mrc-mask-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
</style>

<script>
jQuery(document).ready(function($) {
    // Handle notification dismissal
    $('.mrc-dismiss-notification').on('click', function(e) {
        e.preventDefault();
        var $notice = $(this).closest('.mrc-notification');
        var notificationId = $notice.data('notification-id');

        $.post(ajaxurl, {
            action: 'mrc_dismiss_notification',
            nonce: '<?php echo wp_create_nonce('mrc_dismiss_notification'); ?>',
            notification_id: notificationId
        }, function() {
            $notice.fadeOut();
        });
    });
});
</script>
