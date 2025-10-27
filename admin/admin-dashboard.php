<?php
if (!defined('ABSPATH')) {
    exit;
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

// Handle landing page update
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

    echo '<div class="notice notice-success"><p>Landing page updated successfully!</p></div>';
}

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
            <p><strong>License:</strong><br><code><?php echo esc_html($license_key); ?></code></p>
            <p>
                <a href="https://mrcloak.com/dashboard" target="_blank" class="button">Manage Subscription</a>
            </p>
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
                        <!-- Landing Page Selector -->
                        <form method="post" action="">
                            <?php wp_nonce_field('mrc_dashboard'); ?>
                            <input type="hidden" name="mask_id" value="<?php echo esc_attr($mask_id); ?>">

                            <table class="form-table" style="margin-top: 15px;">
                                <tr>
                                    <th style="width: 150px;">Landing Page:</th>
                                    <td>
                                        <select name="landing_page" class="regular-text" onchange="this.form.submit()">
                                            <option value="home" <?php selected($config['landing_page_type'], 'home'); ?>>🏠 Home Page</option>
                                            <optgroup label="Pages">
                                                <?php foreach ($pages as $page): ?>
                                                    <option value="page-<?php echo $page->ID; ?>"
                                                            <?php selected($config['landing_page_id'], $page->ID); ?>>
                                                        📄 <?php echo esc_html($page->post_title); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <optgroup label="Posts">
                                                <?php foreach ($posts as $post): ?>
                                                    <option value="post-<?php echo $post->ID; ?>"
                                                            <?php selected($config['landing_page_id'], $post->ID); ?>>
                                                        📝 <?php echo esc_html($post->post_title); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>

                                        <button type="submit" name="mrc_update_landing_page" class="button button-secondary" style="display: none;">Update</button>

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
    </div>

    <?php endif; ?>
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
