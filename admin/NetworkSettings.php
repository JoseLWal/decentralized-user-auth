<?php
namespace Dua\Admin;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use Dompdf\Dompdf;

// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Renders and manages network-wide plugin settings.
 * Includes cache, token, rate limit configuration and plugin code compilation.
 */
class NetworkSettings {
    
    /**
     * Min/max ranges (in seconds) for plugin settings.
     * Used for input constraints and validation.
     */
    protected $settingRanges = [
        'cache_expiry' => ['min' => 3600, 'max' => 86400],          // 1h–24h
        'roaming_cookie_expiry' => ['min' => 1800, 'max' => 43200], // 30m–12h
        'remote_login_token_expiry' => ['min' => 30, 'max' => 300], // 30s–5m
        'rate_limit_max' => ['min' => 3, 'max' => 10],              // 3s–10s
        'rate_limit_wait' => ['min' => 60, 'max' => 3600],          // 1m–1h
    ];

    /**
     * Registers hooks for rendering and saving network settings.
     * Hooked during plugin initialization.
     */
    public function __construct() {
        // Inject settings UI into network admin.
        add_action('wpmu_options', [$this, 'renderSettingsSection']);

        // Handle settings form submission.
        add_action('update_wpmu_options', [$this, 'saveSettings']);

        // Intercept settings page load to handle compile action.
        add_action('load-settings.php', [$this, 'handleCompileAction']);

        // Display admin notices based on query params.
        add_action('network_admin_notices', [$this, 'renderAdminNotices']);
    }

    /**
     * Renders the plugin settings section in network admin.
     * Hooked into 'wpmu_options'.
     */
    public function renderSettingsSection() {
        // Fetch current values for each setting.
        $roaming_secret_key = dua_get_roaming_secret_key();
        $is_default_roaming_secret_key = ($roaming_secret_key === 'dua-super-consistent-network-secret');

        $fields = [
            'cache_expiry' => ['label' => 'Cache Expiry', 'value' => dua_get_cache_expiry()],
            'roaming_cookie_expiry' => ['label' => 'Roaming Cookie Expiry', 'value' => dua_get_roaming_cookie_expiry()],
            'remote_login_token_expiry' => ['label' => 'Remote Login Token Expiry', 'value' => dua_get_remote_login_token_expiry()],
            'rate_limit_max' => ['label' => 'Max Login Attempts', 'value' => dua_get_rate_limit_max()],
            'rate_limit_wait' => ['label' => 'Rate Limit Wait Time', 'value' => dua_get_rate_limit_wait()],
        ];

        echo '<h2 id="dua-settings">' . esc_html__('Decentralized User Authentication', 'decentralized-user-auth') . '</h2>';
        echo '<table class="form-table">';

        // Render each setting as a numeric input field.
        foreach ($fields as $key => $field) {
            $range = $this->settingRanges[$key];
            ?>
            <tr>
                <th scope="row">
                    <label for="dua_<?php echo esc_attr($key); ?>">
                        <?php echo esc_html($field['label']); ?>
                    </label>
                </th>
                <td>
                    <input type="number"
                           name="dua_<?php echo esc_attr($key); ?>"
                           id="dua_<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($field['value']); ?>"
                           min="<?php echo esc_attr($range['min']); ?>"
                           max="<?php echo esc_attr($range['max']); ?>" />
                    <span><?php echo esc_html__('Seconds', 'decentralized-user-auth'); ?></span>
                    <p class="description">
                        <?php
                        // Show validation range as helper text.
                        printf(
                            esc_html__('Must be between %1$d seconds and %2$d seconds.', 'decentralized-user-auth'),
                            esc_html($range['min']),
                            esc_html($range['max'])
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <?php
        }        
        ?>

        <!-- Render the roaming secret key as a separate row. -->
        <tr>
            <th scope="row">
                <label for="dua_roaming_secret_key">
                    <?php esc_html_e('Roaming Secret Key', 'decentralized-user-auth'); ?>
                </label>
            </th>
            <td>
                <input type="text"
                       name="dua_roaming_secret_key"
                       id="dua_roaming_secret_key"
                       value="<?php echo esc_attr($roaming_secret_key); ?>"
                       class="regular-text"
                       readonly />
                <p class="description">
                    <?php esc_html_e('Used to sign roaming cookies across subsites. Keep this secret.', 'decentralized-user-auth'); ?>
                </p>

                <?php if ($is_default_roaming_secret_key): ?>
                    <button type="button" class="button" id="dua-generate-secret-key">
                        <?php esc_html_e('Generate New Key', 'decentralized-user-auth'); ?>
                    </button>

                    <p class="description" style="color: #d63638;">
                        <?php esc_html_e('You are using the default key. Please generate a new one and login again.', 'decentralized-user-auth'); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>

        <!-- Render compile button as a separate row. -->
        <tr>
            <th scope="row"><label><?php esc_html_e('Compile Plugin Code', 'decentralized-user-auth'); ?></label></th>
            <td>
                <button id="dua-compile-code-button"
                        type="button"
                        class="button button-secondary"
                        data-redirect="<?php echo esc_url(network_admin_url('settings.php?action=compile-code')); ?>">
                    <?php esc_html_e('Compile', 'decentralized-user-auth'); ?>
                </button>
            </td>
        </tr>
        <?php
        echo '</table>';
    }

    /**
     * Saves plugin settings submitted from the network admin form.
     * Validates input and updates site options.
     */
    public function saveSettings() {
        // Verify nonce for security.
        if (!check_admin_referer('siteoptions')) {
            wp_redirect(network_admin_url('settings.php?error=security'));
            exit;
        }

        $keys = array_keys($this->settingRanges);
        $final = [];

        // Validate each submitted value against its range.
        foreach ($keys as $key) {
            $raw = absint($_POST['dua_' . $key] ?? 0);
            $range = $this->settingRanges[$key];

            if ($raw < $range['min'] || $raw > $range['max']) {
                wp_redirect(network_admin_url('settings.php?error=invalid_input'));
                exit;
            }

            $final[$key] = $raw;
        }

        // Save validated values and clear related transients.
        foreach ($final as $key => $value) {
            update_site_option('dua_' . $key, $value);
            delete_transient('dua_' . $key . '_cached');
        }

        // Save roaming secret key
        if (isset($_POST['dua_roaming_secret_key'])) {
            $key = sanitize_text_field($_POST['dua_roaming_secret_key']);
            update_site_option('dua_roaming_secret_key', $key);
            delete_transient('dua_roaming_secret_key_cached');
        }

        wp_redirect(network_admin_url('settings.php?updated=true'));
        exit;
    }

    /**
     * Renders admin notices based on query parameters.
     * Displays success or error messages after actions.
     */
    public function renderAdminNotices() {
        // Handle error messages.
        if (isset($_GET['error'])) {
            switch ($_GET['error']) {
                case 'security':
                    echo '<div class="notice notice-error"><p>' . esc_html__('Security check failed. Please try again.', 'decentralized-user-auth') . '</p></div>';
                    break;
                case 'unauthorized':
                    echo '<div class="notice notice-error"><p>' . esc_html__('You are not authorized to compile plugin code.', 'decentralized-user-auth') . '</p></div>';
                    break;
                case 'invalid_input':
                    echo '<div class="notice notice-error"><p>' . esc_html__('Invalid settings submitted. Please check your values in the ', 'decentralized-user-auth') . ' <a href="' . esc_url(network_admin_url('settings.php#dua-settings')) . '">' . esc_html__('Decentralized User Authentication Section', 'decentralized-user-auth') . '</a>.</p></div>';
                    break;
            }
        }

        // Handle success message after compilation.
        if (isset($_GET['compiled'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Plugin code compiled successfully.', 'decentralized-user-auth') . '</p></div>';
        }
    }

    /**
     * Handles the compile-code action triggered from the settings page.
     * Validates permission and triggers plugin code compilation.
     */
    public function handleCompileAction() {
        // Only proceed if in network admin and action matches.
        if (!is_network_admin() || ($_GET['action'] ?? '') !== 'compile-code') {
            return;
        }

        // Check user capability before compiling.
        if (!current_user_can('manage_network')) {
            wp_redirect(network_admin_url('settings.php?error=unauthorized'));
            exit;
        }

        // Run compilation and redirect with success flag.
        self::compilePluginCode();
        wp_redirect(network_admin_url('settings.php?compiled=true'));
        exit;
    }

    /**
     * Compiles all plugin PHP files into a single debug file.
     * Excludes vendor and misc directories. Outputs to TXT or PDF.
     */
    public static function compilePluginCode() {
        ### $basePath      = DUA_PLUGIN_DIR;
        ### $excludedDirs  = [realpath($basePath . '/z-misc'), realpath($basePath . '/vendor')];

        // Ludicrousdb Plugin for now.
        $basePath      = 'C:\laragon\www\multisite\wp-content\plugins\ludicrousdb-manager';
        $excludedDirs  = [realpath($basePath . '/z-misc'), realpath($basePath . '/vendor')];

        $timestamp     = date('Y-m-d H:i:s');
        $outputFormats = ['txt' => false, 'pdf' => true];

        // Initialize compiled output with header.
        $compiledText = "// Compiled Plugin Code\n// Timestamp: {$timestamp}\n\n";

        // Recursively scan plugin directory for PHP files.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue; // Skip non-PHP files.
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

        // Save as TXT if enabled.
        if (!empty($outputFormats['txt'])) {
            $txtPath = $basePath . '/z-misc/compiled-code.txt';
            file_put_contents($txtPath, $compiledText);
        }

        // Save as PDF if enabled.
        if (!empty($outputFormats['pdf'])) {
            $pdfPath = $basePath . '/z-misc/compiled-code.pdf';

            // Wrap in <pre> to preserve formatting.
            $compiledHtml = "<pre style='white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; margin: 0;'>" . htmlspecialchars($compiledText) . "</pre>";

            // Generate PDF using Dompdf.
            $dompdf = new Dompdf();
            $dompdf->loadHtml($compiledHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            file_put_contents($pdfPath, $dompdf->output());
        }
    }
}