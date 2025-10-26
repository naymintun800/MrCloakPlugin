<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mr. Cloak Redirector
 *
 * Handles visitor redirection based on mask rules
 * Supports multiple redirect methods: PHP header, JavaScript, Meta refresh
 */
class MRC_Redirector {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Handle visitor based on processing result
     *
     * @param array $result Processing result from Mask Manager
     */
    public function handle_visitor($result) {
        switch ($result['action']) {
            case 'redirect_to_offer':
                $this->redirect_to_offer($result['redirect_url']);
                break;

            case 'block':
            case 'allow_safe_page':
            case 'safe_page':
            default:
                $this->show_safe_page();
                break;
        }
    }

    /**
     * Redirect whitelisted visitor to offer page
     *
     * @param string $redirect_url Offer page URL from mask
     */
    private function redirect_to_offer($redirect_url) {
        if (empty($redirect_url)) {
            $this->show_safe_page();
            return;
        }

        // Get redirect method from settings
        $redirect_method = get_option('mrc_redirect_method', 'php_301');

        switch ($redirect_method) {
            case 'php_302':
                wp_redirect($redirect_url, 302);
                exit;

            case 'php_307':
                wp_redirect($redirect_url, 307);
                exit;

            case 'javascript':
                $this->javascript_redirect($redirect_url);
                exit;

            case 'meta':
                $this->meta_redirect($redirect_url);
                exit;

            case 'php_301':
            default:
                wp_redirect($redirect_url, 301);
                exit;
        }
    }

    /**
     * Show safe/maintenance page to blocked visitors and bots
     */
    private function show_safe_page() {
        $mask_manager = MRC_Mask_Manager::get_instance();
        $html = $mask_manager->get_safe_page_html();

        // Prevent WordPress from loading
        status_header(200);
        nocache_headers();

        echo $html;
        exit;
    }

    /**
     * Redirect using JavaScript
     *
     * @param string $url Redirect URL
     */
    private function javascript_redirect($url) {
        $safe_url = esc_url($url);
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Redirecting...</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .loader {
            width: 50px;
            height: 50px;
            border: 4px solid #e0e0e0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    <script type="text/javascript">
        window.location.href = "' . $safe_url . '";
    </script>
</head>
<body>
    <div class="loader"></div>
    <noscript>
        <p>If you are not redirected automatically, <a href="' . $safe_url . '">click here</a>.</p>
    </noscript>
</body>
</html>';

        echo $html;
    }

    /**
     * Redirect using Meta refresh
     *
     * @param string $url Redirect URL
     */
    private function meta_redirect($url) {
        $safe_url = esc_url($url);
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="0;url=' . $safe_url . '">
    <title>Redirecting...</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .loader {
            width: 50px;
            height: 50px;
            border: 4px solid #e0e0e0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="loader"></div>
    <p>If you are not redirected automatically, <a href="' . $safe_url . '">click here</a>.</p>
</body>
</html>';

        echo $html;
    }

    /**
     * Get redirect method options for settings
     *
     * @return array Redirect method options
     */
    public static function get_redirect_methods() {
        return array(
            'php_301' => array(
                'label' => 'PHP Header (301 Permanent)',
                'description' => 'Fast server-side redirect. Tells search engines the page has permanently moved.'
            ),
            'php_302' => array(
                'label' => 'PHP Header (302 Temporary)',
                'description' => 'Fast server-side redirect. Tells search engines the page has temporarily moved.'
            ),
            'php_307' => array(
                'label' => 'PHP Header (307 Temporary)',
                'description' => 'Similar to 302 but preserves the HTTP method (useful for forms).'
            ),
            'javascript' => array(
                'label' => 'JavaScript Redirect',
                'description' => 'Client-side redirect using JavaScript. Works even if headers already sent.'
            ),
            'meta' => array(
                'label' => 'Meta Refresh',
                'description' => 'HTML meta tag redirect. Works without JavaScript. Most compatible method.'
            )
        );
    }
}
