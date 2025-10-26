<?php
if (!defined('ABSPATH')) {
    exit;
}

// Handle license activation
if (isset($_POST['mrc_activate_license']) && check_admin_referer('mrc_settings')) {
    $license_key = sanitize_text_field($_POST['mrc_license_key']);

    if (empty($license_key)) {
        echo '<div class="notice notice-error"><p>Please enter a license key.</p></div>';
    } elseif (!MRC_Security::validate_license_key_format($license_key)) {
        echo '<div class="notice notice-error"><p>Invalid license key format. Expected format: MRC-XXXXXXXX-XXXX-XXXX</p></div>';
    } else {
        $api_client = MRC_API_Client::get_instance();
        $domain = parse_url(home_url(), PHP_URL_HOST);

        $response = $api_client->activate_license($license_key, $domain);

        if (isset($response['error'])) {
            // Check for domain security errors with detailed information
            if (isset($response['error_type']) && in_array($response['error_type'], array('domain_not_authorized', 'domain_revoked'))) {
                // Display detailed domain security error
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

                    <?php if (!empty($response['domain'])): ?>
                        <p>
                            <strong>Domain:</strong> <code><?php echo esc_html($response['domain']); ?></code><br>
                            <strong>License:</strong> <code><?php echo esc_html($response['license']); ?></code>
                        </p>
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
                // Standard error message
                echo '<div class="notice notice-error"><p><strong>License Activation Failed:</strong> ' . esc_html($response['error']) . '</p></div>';
            }
        } else {
            update_option('mrc_filtering_enabled', true);
            echo '<div class="notice notice-success"><p><strong>License Activated Successfully!</strong> Your subscription is active.</p></div>';

            // Refresh page to show mask selection
            echo '<script>window.location.reload();</script>';
        }
    }
}

// Handle mask enable/disable
if (isset($_POST['mrc_enable_mask']) && check_admin_referer('mrc_settings')) {
    $mask_id = sanitize_text_field($_POST['mrc_mask_id']);
    $action = $_POST['mrc_mask_action'];

    $api_client = MRC_API_Client::get_instance();

    if ($action === 'enable') {
        $response = $api_client->enable_mask($mask_id);
    } else {
        $response = $api_client->disable_mask($mask_id);
    }

    if (isset($response['error'])) {
        echo '<div class="notice notice-error"><p>' . esc_html($response['error']) . '</p></div>';
    } else {
        echo '<div class="notice notice-success"><p>' . esc_html($response['message']) . '</p></div>';
    }
}

// Handle settings save
if (isset($_POST['mrc_save_settings']) && check_admin_referer('mrc_settings')) {
    $redirect_method = sanitize_text_field($_POST['mrc_redirect_method']);
    update_option('mrc_redirect_method', $redirect_method);

    echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
}

// Handle mask refresh
if (isset($_POST['mrc_refresh_masks']) && check_admin_referer('mrc_settings')) {
    $mask_manager = MRC_Mask_Manager::get_instance();
    $success = $mask_manager->refresh_masks();

    if ($success) {
        echo '<div class="notice notice-success"><p>Masks refreshed successfully!</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Failed to refresh masks. Please check your license key.</p></div>';
    }
}

// Get current settings
$license_key = MRC_Security::decrypt(get_option('mrc_license_key'));
$subscription_status = get_option('mrc_subscription_status');
$subscription_message = get_option('mrc_subscription_message');
$redirect_method = get_option('mrc_redirect_method', 'php_301');
$filtering_enabled = get_option('mrc_filtering_enabled', false);

// Get masks
$mask_manager = MRC_Mask_Manager::get_instance();
$all_masks = $mask_manager->get_all_masks();
$active_mask = $mask_manager->get_active_mask();
$current_domain = MRC_API_Client::normalize_domain(parse_url(home_url(), PHP_URL_HOST));

// Get subscription info
$subscription_info = get_option('mrc_subscription_info', array());

?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if (empty($license_key)): ?>
        <!-- License Activation Section -->
        <div class="mrc-activation-section">
            <h2>Activate Your License</h2>
            <p>Enter your Mr. Cloak license key to activate traffic filtering on this website.</p>

            <form method="post" action="">
                <?php wp_nonce_field('mrc_settings'); ?>

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
                                   placeholder="MRC-XXXXXXXX-XXXX-XXXX"
                                   pattern="MRC-[A-Z0-9]{8}-[A-Z0-9]{4}-[A-Z0-9]{4}"
                                   required />
                            <p class="description">
                                Format: MRC-XXXXXXXX-XXXX-XXXX<br>
                                Don't have a license? <a href="https://mrcloak.com/pricing" target="_blank">Get one here</a>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Activate License', 'primary', 'mrc_activate_license'); ?>
            </form>
        </div>
    <?php else: ?>
        <!-- License Status Section -->
        <div class="mrc-status-section" style="background: #fff; border-left: 4px solid <?php echo $subscription_status === 'active' || $subscription_status === 'trialing' ? '#46b450' : '#dc3232'; ?>; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">License Status</h2>

            <table class="form-table">
                <tr>
                    <th>License Key:</th>
                    <td><code><?php echo esc_html($license_key); ?></code></td>
                </tr>
                <tr>
                    <th>Domain:</th>
                    <td><code><?php echo esc_html($current_domain); ?></code></td>
                </tr>
                <tr>
                    <th>Status:</th>
                    <td>
                        <span class="mrc-status-badge mrc-status-<?php echo esc_attr($subscription_status); ?>">
                            <?php echo esc_html(ucfirst($subscription_status)); ?>
                        </span>
                        <?php if ($subscription_message): ?>
                            <p class="description"><?php echo esc_html($subscription_message); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php if (!empty($subscription_info)): ?>
                <tr>
                    <th>Plan:</th>
                    <td><?php echo esc_html($subscription_info['plan']['name'] ?? 'Unknown'); ?></td>
                </tr>

                <?php if (isset($subscription_info['subscription']['trial_days_left']) && $subscription_info['subscription']['trial_days_left'] > 0): ?>
                <tr>
                    <th>Trial:</th>
                    <td><?php echo esc_html($subscription_info['subscription']['trial_days_left']); ?> days remaining</td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
            </table>

            <p>
                <a href="https://mrcloak.com/dashboard" target="_blank" class="button">Manage Subscription</a>
            </p>
        </div>

        <!-- Mask Selection Section -->
        <div class="mrc-masks-section" style="background: #fff; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">Traffic Filtering Masks</h2>

            <?php if (empty($all_masks)): ?>
                <p>No masks found. Please create a mask in your <a href="https://mrcloak.com/dashboard" target="_blank">Mr. Cloak dashboard</a>.</p>
            <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('mrc_settings'); ?>

                    <?php foreach ($all_masks as $mask): ?>
                        <?php
                        $is_active_on_this_domain = isset($mask['active_domain']) &&
                                                     MRC_Security::sanitize_domain($mask['active_domain']) === MRC_Security::sanitize_domain($current_domain);
                        $is_active_elsewhere = isset($mask['active_domain']) &&
                                              !empty($mask['active_domain']) &&
                                              !$is_active_on_this_domain;
                        ?>

                        <div class="mrc-mask-card" style="border: 2px solid <?php echo $is_active_on_this_domain ? '#46b450' : '#ddd'; ?>; padding: 15px; margin: 10px 0; border-radius: 4px;">
                            <h3 style="margin-top: 0;">
                                <?php echo esc_html($mask['name']); ?>
                                <?php if ($is_active_on_this_domain): ?>
                                    <span style="color: #46b450; font-size: 14px;">✓ Active</span>
                                <?php endif; ?>
                            </h3>

                            <p><strong>Offer URL:</strong> <code><?php echo esc_html($mask['offer_page_url']); ?></code></p>

                            <p><strong>Filters:</strong></p>
                            <ul>
                                <?php if (!empty($mask['whitelisted_countries'])): ?>
                                    <li>Countries: <?php echo esc_html(implode(', ', $mask['whitelisted_countries'])); ?></li>
                                <?php endif; ?>

                                <?php if (!empty($mask['whitelisted_languages'])): ?>
                                    <li>Languages: <?php echo esc_html(implode(', ', $mask['whitelisted_languages'])); ?></li>
                                <?php endif; ?>

                                <?php if (!empty($mask['whitelisted_os'])): ?>
                                    <li>Operating Systems: <?php echo esc_html(implode(', ', $mask['whitelisted_os'])); ?></li>
                                <?php endif; ?>

                                <?php if (!empty($mask['whitelisted_browsers'])): ?>
                                    <li>Browsers: <?php echo esc_html(implode(', ', $mask['whitelisted_browsers'])); ?></li>
                                <?php endif; ?>

                                <?php if ($mask['filter_vpn_proxy']): ?>
                                    <li>Block VPN/Proxy: Yes</li>
                                <?php endif; ?>

                                <?php if ($mask['block_ad_review_bots']): ?>
                                    <li>Block Ad Review Bots: Yes</li>
                                <?php endif; ?>

                                <?php if ($mask['block_other_bots']): ?>
                                    <li>Block Social/Messaging Bots: Yes</li>
                                <?php endif; ?>
                            </ul>

                            <?php if ($is_active_elsewhere): ?>
                                <p style="color: #d63638;">
                                    <strong>Note:</strong> This mask is currently active on <?php echo esc_html($mask['active_domain']); ?>.
                                    You must disable it there before enabling it here.
                                </p>
                            <?php else: ?>
                                <input type="hidden" name="mrc_mask_id" value="<?php echo esc_attr($mask['id']); ?>" />
                                <input type="hidden" name="mrc_mask_action" value="<?php echo $is_active_on_this_domain ? 'disable' : 'enable'; ?>" />
                                <button type="submit"
                                        name="mrc_enable_mask"
                                        class="button <?php echo $is_active_on_this_domain ? 'button-secondary' : 'button-primary'; ?>">
                                    <?php echo $is_active_on_this_domain ? 'Disable Mask' : 'Enable Mask'; ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <p>
                        <button type="submit" name="mrc_refresh_masks" class="button">Refresh Masks</button>
                    </p>
                </form>
            <?php endif; ?>

            <p>
                <a href="https://mrcloak.com/dashboard/masks" target="_blank" class="button">Manage Masks in Dashboard</a>
            </p>
        </div>

        <!-- Redirect Settings Section -->
        <div class="mrc-redirect-section" style="background: #fff; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">Redirect Settings</h2>

            <form method="post" action="">
                <?php wp_nonce_field('mrc_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Redirect Method</th>
                        <td>
                            <?php
                            $redirect_methods = MRC_Redirector::get_redirect_methods();
                            foreach ($redirect_methods as $method_key => $method_info):
                            ?>
                                <label style="display: block; margin: 10px 0;">
                                    <input type="radio"
                                           name="mrc_redirect_method"
                                           value="<?php echo esc_attr($method_key); ?>"
                                           <?php checked($redirect_method, $method_key); ?> />
                                    <strong><?php echo esc_html($method_info['label']); ?></strong>
                                    <br>
                                    <span class="description" style="margin-left: 25px;">
                                        <?php echo esc_html($method_info['description']); ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings', 'primary', 'mrc_save_settings'); ?>
            </form>
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

.mrc-mask-card {
    transition: box-shadow 0.2s;
}

.mrc-mask-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
</style>
