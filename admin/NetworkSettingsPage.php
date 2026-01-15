<?php
namespace Dua\Admin;

use Dompdf\Dompdf;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Renders and manages network-wide plugin settings.
 * Includes cache, token, rate limit configuration, roaming key management,
 * and plugin code compilation.
 */
class NetworkSettingsPage {

    /**
     * Min/max ranges (in seconds) for plugin settings.
     * Used for input constraints and validation.
     */
    protected $settingRanges = [
        'cache_expiry' => ['min' => 3600, 'max' => 86400],          // 1h–24h
        'roaming_cookie_expiry' => ['min' => 1800, 'max' => 43200], // 30m–12h
        'remote_login_token_expiry' => ['min' => 30, 'max' => 300], // 30s–5m
        'rate_limit_max' => ['min' => 3, 'max' => 10],              // 3–10 attempts
        'rate_limit_wait' => ['min' => 60, 'max' => 3600],          // 1m–1h
    ];

    /**
     * Registers hooks for rendering the settings page and handling form actions.
     * Hooked during plugin initialization.
     */
    public function __construct() {
        // Register custom network admin page.
        add_action('network_admin_menu', [$this, 'registerNetworkPage']);

        // Display admin notices after actions.
        add_action('network_admin_notices', [$this, 'renderAdminNotices']);

        // Handle settings form submission.
        add_action('network_admin_edit_dua_save_settings', [$this, 'saveSettings']);

        // Handle plugin compilation action.
        add_action('network_admin_edit_dua_compile_code', [$this, 'handleCompileAction']);
    }

    /**
     * Registers a menu page in the Network Admin.
     *
     * @return void
     */
    public function registerNetworkPage() {
        add_menu_page(
            __('DUA Settings', 'decentralized-user-auth'),
            __('DUA Settings', 'decentralized-user-auth'),
            'manage_network_options',
            'dua-settings',
            [$this, 'renderSettingsPage'],
            'dashicons-admin-network',
            30
        );
    }

    /**
     * Renders the Dua Settings form on its own network admin page.
     *
     * @return void
     */
    public function renderSettingsPage() {
        // Fetch current values for each setting.
        $roaming_secret_key = dua_get_roaming_secret_key();
        $is_default_key = ($roaming_secret_key === 'dua-super-consistent-network-secret');

        $fields = [
            'cache_expiry' => ['label' => 'Cache Expiry', 'value' => dua_get_cache_expiry()],
            'roaming_cookie_expiry' => ['label' => 'Roaming Cookie Expiry', 'value' => dua_get_roaming_cookie_expiry()],
            'remote_login_token_expiry' => ['label' => 'Remote Login Token Expiry', 'value' => dua_get_remote_login_token_expiry()],
            'rate_limit_max' => ['label' => 'Max Login Attempts', 'value' => dua_get_rate_limit_max()],
            'rate_limit_wait' => ['label' => 'Rate Limit Wait Time', 'value' => dua_get_rate_limit_wait()],
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Decentralized User Authentication Settings', 'decentralized-user-auth'); ?></h1>
            <form method="post" action="<?php echo esc_url(network_admin_url('edit.php?action=dua_save_settings')); ?>">
                <?php wp_nonce_field('dua_network_settings'); ?>
                <table class="form-table">
                    <?php foreach ($fields as $key => $field): $range = $this->settingRanges[$key]; ?>
                        <tr>
                            <th><label for="dua_<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
                            <td>
                                <input type="number"
                                       name="dua_<?php echo esc_attr($key); ?>"
                                       id="dua_<?php echo esc_attr($key); ?>"
                                       value="<?php echo esc_attr($field['value']); ?>"
                                       min="<?php echo esc_attr($range['min']); ?>"
                                       max="<?php echo esc_attr($range['max']); ?>" />
                                <span><?php esc_html_e('Seconds', 'decentralized-user-auth'); ?></span>
                                <p class="description">
                                    <?php printf(
                                        esc_html__('Must be between %1$d and %2$d seconds.', 'decentralized-user-auth'),
                                        esc_html($range['min']),
                                        esc_html($range['max'])
                                    ); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Roaming secret key field -->
                    <tr>
                        <th><label for="dua_roaming_secret_key"><?php esc_html_e('Roaming Secret Key', 'decentralized-user-auth'); ?></label></th>
                        <td>
                            <input type="text"
                                   name="dua_roaming_secret_key"
                                   id="dua_roaming_secret_key"
                                   value="<?php echo esc_attr($roaming_secret_key); ?>"
                                   class="regular-text"
                                   readonly />
                            <p class="description"><?php esc_html_e('Used to sign roaming cookies across subsites. Keep this secret.', 'decentralized-user-auth'); ?></p>
                            <?php if ($is_default_key): ?>
                                <button type="button" class="button" id="dua-generate-secret-key"><?php esc_html_e('Generate New Key', 'decentralized-user-auth'); ?></button>
                                <p class="description" style="color: #d63638;"><?php esc_html_e('You are using the default key. Please generate a new one.', 'decentralized-user-auth'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Compile plugin code button -->
                    <tr>
                        <th><?php esc_html_e('Compile Plugin Code', 'decentralized-user-auth'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(network_admin_url('edit.php?action=dua_compile_code')); ?>" class="button button-secondary">
                                <?php esc_html_e('Compile', 'decentralized-user-auth'); ?>
                            </a>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'decentralized-user-auth')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handles saving of plugin settings submitted from the Dua Settings page.
     * Validates input ranges and updates site options.
     *
     * @return void
     */
    public function saveSettings() {
        // Verify nonce for security.
        check_admin_referer('dua_network_settings');

        $keys = array_keys($this->settingRanges);
        $final = [];

        // Validate each submitted value against its defined range.
        foreach ($keys as $key) {
            $raw = absint($_POST['dua_' . $key] ?? 0);
            $range = $this->settingRanges[$key];

            if ($raw < $range['min'] || $raw > $range['max']) {
                wp_redirect(network_admin_url('settings.php?page=dua-settings&error=invalid_input'));
                exit;
            }

            $final[$key] = $raw;
        }

        // Save validated values and clear related transients.
        foreach ($final as $key => $value) {
            update_site_option('dua_' . $key, $value);
            delete_transient('dua_' . $key . '_cached');
        }

        // Save roaming secret key if present.
        if (isset($_POST['dua_roaming_secret_key'])) {
            $key = sanitize_text_field($_POST['dua_roaming_secret_key']);
            update_site_option('dua_roaming_secret_key', $key);
            delete_transient('dua_roaming_secret_key_cached');
        }

        wp_redirect(network_admin_url('settings.php?page=dua-settings&updated=true'));
        exit;
    }

    /**
     * Renders admin notices based on query parameters.
     * Displays success or error messages after form actions.
     *
     * @return void
     */
    public function renderAdminNotices() {
        // Display error messages.
        if (isset($_GET['error'])) {
            switch ($_GET['error']) {
                case 'invalid_input':
                    echo '<div class="notice notice-error"><p>' . esc_html__('Invalid settings submitted.', 'decentralized-user-auth') . '</p></div>';
                    break;
                case 'unauthorized':
                    echo '<div class="notice notice-error"><p>' . esc_html__('You are not authorized to compile plugin code.', 'decentralized-user-auth') . '</p></div>';
                    break;
            }
        }

        // Display success messages.
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully.', 'decentralized-user-auth') . '</p></div>';
        }

        if (isset($_GET['compiled'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Plugin code compiled successfully.', 'decentralized-user-auth') . '</p></div>';
        }
    }

    /**
     * Handles the compile-code action triggered from the Dua Settings page.
     * Validates permission and triggers plugin code compilation.
     *
     * @return void
     */
    public function handleCompileAction() {
        // Ensure the current user has permission to compile.
        if (!current_user_can('manage_network_options')) {
            wp_redirect(network_admin_url('settings.php?page=dua-settings&error=unauthorized'));
            exit;
        }

        // Run compilation and redirect with success flag.
        self::compilePluginCode();
        wp_redirect(network_admin_url('settings.php?page=dua-settings&compiled=true'));
        exit;
    }

    /**
     * Compiles all plugin PHP files into a single debug file.
     * Excludes vendor and misc directories. Outputs to TXT or PDF.
     *
     * @return void
     */
    public static function compilePluginCode() {
        // Define plugin base path and excluded directories.
        ### $basePath      = DUA_PLUGIN_DIR;
        ### $excludedDirs  = [realpath($basePath . '/z-misc'), realpath($basePath . '/vendor')];

        // Ludicrousdb Plugin for now.
        $basePath      = 'C:\laragon\www\multisite\wp-content\plugins\ludicrousdb-manager';
        $excludedDirs  = [realpath($basePath . '/z-misc'), realpath($basePath . '/vendor')];

        // Define output formats.
        $outputFormats = [
            'txt' => false, // Set to true to enable TXT output
            'pdf' => true,  // Set to true to enable PDF output
        ];

        // Generate timestamp for header.
        $timestamp = date('Y-m-d H:i:s');

        // Initialize compiled output with header.
        $compiledText = "// Compiled Plugin Code\n// Timestamp: {$timestamp}\n\n";

        // Recursively scan plugin directory for PHP files.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            // Skip non-PHP files.
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();

            // Skip excluded directories.
            foreach ($excludedDirs as $excluded) {
                if (strpos($realPath, $excluded) === 0) {
                    continue 2;
                }
            }

            // Append file path and contents to compiled output.
            $relativePath = str_replace($basePath, '', $realPath);
            $compiledText .= "// File: {$relativePath}\n";
            $compiledText .= file_get_contents($realPath) . "\n\n";
        }

        // Save compiled output as TXT if enabled.
        if ($outputFormats['txt']) {
            $txtPath = $basePath . '/z-misc/compiled-code.txt';
            file_put_contents($txtPath, $compiledText);
        }

        // Save compiled output as PDF if enabled.
        if ($outputFormats['pdf']) {
            $pdfPath = $basePath . '/z-misc/compiled-code.pdf';

            // Wrap content in <pre> to preserve formatting.
            $compiledHtml = "<pre style='white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; margin: 0;'>" .
                            htmlspecialchars($compiledText) .
                            "</pre>";

            // Generate PDF using Dompdf.
            $dompdf = new Dompdf();
            $dompdf->loadHtml($compiledHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Save PDF to disk.
            file_put_contents($pdfPath, $dompdf->output());
        }
    }
}
