<?php
if (!defined('ABSPATH')) {
    exit;
}

// Handle settings save
if (isset($_POST['mrc_save_settings']) && check_admin_referer('mrc_settings')) {
    $redirect_method = sanitize_text_field($_POST['mrc_redirect_method']);
    update_option('mrc_redirect_method', $redirect_method);

    // Save IP whitelist
    $ip_whitelist = isset($_POST['mrc_whitelisted_ips']) ? sanitize_textarea_field($_POST['mrc_whitelisted_ips']) : '';
    $ips = array_filter(array_map('trim', explode("\n", $ip_whitelist)));
    update_option('mrc_whitelisted_ips', $ips);

    echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
}

// Get current settings
$redirect_method = get_option('mrc_redirect_method', 'php_301');
$whitelisted_ips = get_option('mrc_whitelisted_ips', array());
$license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if (empty($license_key)): ?>
        <div class="notice notice-warning">
            <p><strong>License not activated.</strong> Please <a href="<?php echo admin_url('admin.php?page=mr-cloak'); ?>">activate your license</a> on the Dashboard first.</p>
        </div>
    <?php else: ?>

    <form method="post" action="">
        <?php wp_nonce_field('mrc_settings'); ?>

        <!-- Redirect Settings Section -->
        <div class="mrc-settings-section" style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">Redirect Method</h2>
            <p>Choose how whitelisted visitors are redirected to your offer page.</p>

            <table class="form-table">
                <tr>
                    <th scope="row">Redirect Method</th>
                    <td>
                        <?php
                        $redirect_methods = MRC_Redirector::get_redirect_methods();
                        foreach ($redirect_methods as $method_key => $method_info):
                        ?>
                            <label style="display: block; margin: 15px 0;">
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
        </div>

        <!-- IP Whitelist Section -->
        <div class="mrc-settings-section" style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0;">IP Whitelist</h2>
            <p>Add IP addresses that should bypass all filtering. Useful for testing or excluding specific IPs.</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="mrc_whitelisted_ips">Whitelisted IPs</label>
                    </th>
                    <td>
                        <textarea id="mrc_whitelisted_ips"
                                  name="mrc_whitelisted_ips"
                                  rows="10"
                                  class="large-text code"
                                  placeholder="Enter one IP address per line&#10;Example:&#10;192.168.1.100&#10;203.0.113.50"><?php echo esc_textarea(implode("\n", $whitelisted_ips)); ?></textarea>
                        <p class="description">
                            Enter one IP address per line. These IPs will always bypass filtering.<br>
                            <strong>Note:</strong> WordPress administrators are automatically exempted from filtering.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button('Save Settings', 'primary large', 'mrc_save_settings'); ?>
    </form>

    <?php endif; ?>
</div>

<style>
.mrc-settings-section:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
    transition: box-shadow 0.2s;
}
</style>
