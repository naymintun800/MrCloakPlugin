<?php
/**
 * Plugin Updater Class
 *
 * Handles automatic updates from GitHub releases with license validation
 *
 * @package MrCloak
 * @since 3.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class MRC_Plugin_Updater {
    /**
     * Plugin file path
     */
    private $plugin_file;

    /**
     * Plugin slug
     */
    private $plugin_slug;

    /**
     * GitHub repository information
     */
    private $github_repo = 'naymintun800/MrCloakPlugin';

    /**
     * Update transient name
     */
    private $transient_name = 'mrc_github_update_check';

    /**
     * Cache duration in seconds (12 hours)
     */
    private $cache_duration = 43200;

    /**
     * Constructor
     *
     * @param string $plugin_file Main plugin file path
     */
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_pre_download', array($this, 'validate_license_before_download'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'purge_update_cache'), 10, 2);
    }

    /**
     * Check for plugin updates
     *
     * @param object $transient Update transient object
     * @return object Modified transient
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Check if license is valid before checking for updates
        if (!$this->is_license_valid()) {
            error_log('Mr. Cloak: Update check skipped - invalid license');
            return $transient;
        }

        // Get cached update data
        $update_data = get_transient($this->transient_name);

        if (false === $update_data) {
            // Fetch fresh update data from GitHub
            $update_data = $this->fetch_github_release();

            if ($update_data && !isset($update_data['error'])) {
                // Cache the result
                set_transient($this->transient_name, $update_data, $this->cache_duration);
            } else {
                // Cache error state for 1 hour to prevent hammering GitHub
                set_transient($this->transient_name, array('error' => true), 3600);
                return $transient;
            }
        }

        // Check if error state is cached
        if (isset($update_data['error'])) {
            return $transient;
        }

        // Compare versions
        $current_version = MRC_VERSION;
        $new_version = isset($update_data['version']) ? $update_data['version'] : '';

        error_log('Mr. Cloak: Update check - Current: ' . $current_version . ', Available: ' . $new_version);

        if (!empty($new_version) && version_compare($current_version, $new_version, '<')) {
            // New version available
            error_log('Mr. Cloak: Update available! ' . $current_version . ' -> ' . $new_version);

            $plugin_data = array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $new_version,
                'url' => $update_data['html_url'],
                'package' => $update_data['download_url'],
                'tested' => isset($update_data['tested']) ? $update_data['tested'] : '6.4',
                'requires' => isset($update_data['requires']) ? $update_data['requires'] : '5.0',
                'requires_php' => isset($update_data['requires_php']) ? $update_data['requires_php'] : '7.4',
            );

            $transient->response[$this->plugin_slug] = (object) $plugin_data;
        } else {
            error_log('Mr. Cloak: Plugin is up to date');
        }

        return $transient;
    }

    /**
     * Fetch latest release from GitHub
     *
     * @return array|false Release data or false on failure
     */
    private function fetch_github_release() {
        $api_url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';

        $response = wp_remote_get($api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/Mr-Cloak-Plugin'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Mr. Cloak Update Check Error: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('Mr. Cloak Update Check: GitHub API returned status ' . $status_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['tag_name'])) {
            return false;
        }

        // Parse version from tag name (e.g., "v3.0.2" -> "3.0.2")
        $version = ltrim($data['tag_name'], 'v');

        // Find the mr-cloak.zip asset
        $download_url = '';
        if (isset($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (isset($asset['name'], $asset['browser_download_url']) && $asset['name'] === 'mr-cloak.zip') {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }

            if (empty($download_url)) {
                foreach ($data['assets'] as $asset) {
                    if (!isset($asset['name'], $asset['browser_download_url'])) {
                        continue;
                    }

                    if (substr($asset['name'], -4) === '.zip') {
                        $download_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }
        }

        // Fallback to latest download URL if asset not found
        if (empty($download_url)) {
            $download_url = 'https://github.com/' . $this->github_repo . '/releases/latest/download/mr-cloak.zip';
        }

        return array(
            'version' => $version,
            'download_url' => $download_url,
            'html_url' => isset($data['html_url']) ? $data['html_url'] : '',
            'body' => isset($data['body']) ? $data['body'] : '',
            'published_at' => isset($data['published_at']) ? $data['published_at'] : '',
            'tested' => '6.4',
            'requires' => '5.0',
            'requires_php' => '7.4'
        );
    }

    /**
     * Provide plugin information for the update screen
     *
     * @param false|object|array $result The result object or array
     * @param string $action The type of information being requested
     * @param object $args Plugin API arguments
     * @return false|object Modified result
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        // Get update data
        $update_data = get_transient($this->transient_name);

        if (false === $update_data || isset($update_data['error'])) {
            $update_data = $this->fetch_github_release();
        }

        if (!$update_data || isset($update_data['error'])) {
            return $result;
        }

        // Convert markdown to HTML for changelog
        $changelog = $this->convert_markdown_to_html($update_data['body']);

        $plugin_info = array(
            'name' => 'Mr. Cloak',
            'slug' => dirname($this->plugin_slug),
            'version' => $update_data['version'],
            'author' => '<a href="https://mrcloak.com">Mr. Cloak</a>',
            'homepage' => 'https://mrcloak.com',
            'download_link' => $update_data['download_url'],
            'requires' => $update_data['requires'],
            'tested' => $update_data['tested'],
            'requires_php' => $update_data['requires_php'],
            'last_updated' => $update_data['published_at'],
            'sections' => array(
                'description' => 'Advanced traffic filtering and cloaking plugin powered by Mr. Cloak SaaS platform.',
                'changelog' => $changelog,
            ),
        );

        return (object) $plugin_info;
    }

    /**
     * Validate license before allowing update download
     *
     * @param bool $reply Whether to bail without returning the package
     * @param string $package The package file name
     * @param object $upgrader The WP_Upgrader instance
     * @return bool|WP_Error
     */
    public function validate_license_before_download($reply, $package, $upgrader) {
        // Check if this is our plugin
        if (!isset($upgrader->skin->plugin) || $upgrader->skin->plugin !== $this->plugin_slug) {
            return $reply;
        }

        // Validate license
        if (!$this->is_license_valid()) {
            return new WP_Error(
                'mrc_license_invalid',
                'Cannot update Mr. Cloak plugin: Your license is invalid or expired. Please activate a valid license first.'
            );
        }

        return $reply;
    }

    /**
     * Check if license is valid
     *
     * @return bool
     */
    private function is_license_valid() {
        $license_key = MRC_Security::decrypt(get_option('mrc_license_key'));

        if (empty($license_key)) {
            return false;
        }

        $api_client = MRC_API_Client::get_instance();
        return $api_client->is_license_valid();
    }

    /**
     * Purge update cache after plugin update
     *
     * @param WP_Upgrader $upgrader_object
     * @param array $options
     */
    public function purge_update_cache($upgrader_object, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins']) && is_array($options['plugins'])) {
                foreach ($options['plugins'] as $plugin) {
                    if ($plugin === $this->plugin_slug) {
                        delete_transient($this->transient_name);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Convert markdown to HTML (basic implementation)
     *
     * @param string $markdown Markdown text
     * @return string HTML
     */
    private function convert_markdown_to_html($markdown) {
        if (empty($markdown)) {
            return '<p>No changelog available.</p>';
        }

        // Basic markdown conversion
        $html = $markdown;

        // Headers
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

        // Lists
        $html = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);

        // Line breaks
        $html = nl2br($html);

        return $html;
    }

    /**
     * Manual update check (can be called programmatically)
     *
     * @return array|false Update data or false
     */
    public function force_update_check() {
        delete_transient($this->transient_name);
        $update_data = $this->fetch_github_release();

        if ($update_data && !isset($update_data['error'])) {
            set_transient($this->transient_name, $update_data, $this->cache_duration);
            return $update_data;
        }

        return false;
    }
}
