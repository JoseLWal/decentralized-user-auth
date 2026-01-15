<?php
// Prevent direct access.
defined('ABSPATH') || exit;

/**
 * Handles plugin activation and deactivation routines.
 * Creates required schema and installs MU plugin loader.
 */
class DuaActivator {

    /**
     * Runs on plugin activation.
     * Creates schema and installs MU plugin loader.
     *
     * @return void
     */
    public static function activate() {
        self::createUserSchema();
        self::injectSignupHook();
        self::installMuPlugin();
    }

    /**
     * Runs on plugin deactivation.
     * Removes MU plugin loader if present.
     *
     * @return void
     */
    public static function deactivate() {
        self::removeSignupHook();
        self::removeMuPlugin();
    }


    /**
     * Ensures multisite-aware columns and indexes exist across key user tables.
     *
     * Adds `site_id` and `main_id` columns to `users`, `usermeta`, and `signups` tables
     * if missing, and creates indexes for fast lookup. Safe to run multiple times.
     *
     * @return void
     */
    private static function createUserSchema() {
        global $wpdb;

        $schema = [
            $wpdb->prefix . 'users'    => ['site_id', 'main_id'],
            $wpdb->prefix . 'usermeta' => ['site_id'],
            $wpdb->prefix . 'signups'  => ['site_id'],
        ];

        foreach ($schema as $table => $columns) {
            foreach ($columns as $column) {
                self::addColumnIfMissing($table, $column, 'BIGINT(20) UNSIGNED DEFAULT NULL');
                self::addIndexIfMissing($table, $column);
            }
        }
    }

    /**
     * Adds a column to a given table if it does not already exist.
     *
     * Uses INFORMATION_SCHEMA to check for column presence before altering the table.
     * Intended for idempotent schema migrations during plugin activation.
     *
     * @param string $table      Full table name (with prefix).
     * @param string $column     Column name to add.
     * @param string $definition SQL column definition (e.g. 'BIGINT(20) UNSIGNED DEFAULT NULL').
     * @return void
     */
    private static function addColumnIfMissing($table, $column, $definition) {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_NAME = %s AND COLUMN_NAME = %s AND TABLE_SCHEMA = DATABASE()",
                $table,
                $column
            )
        );

        if ((int) $exists === 0) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    /**
     * Adds a non-unique index to a column if it does not already exist.
     *
     * Uses INFORMATION_SCHEMA.STATISTICS to detect existing indexes.
     * Improves query performance for filters like `WHERE site_id = X`.
     *
     * @param string $table  Full table name (with prefix).
     * @param string $column Column name to index.
     * @return void
     */
    private static function addIndexIfMissing($table, $column) {
        global $wpdb;

        $indexExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                 WHERE TABLE_NAME = %s AND COLUMN_NAME = %s AND TABLE_SCHEMA = DATABASE()",
                $table,
                $column
            )
        );

        if ((int) $indexExists === 0) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX ({$column})");
        }
    }


    /**
     * Installs the MU plugin loader file.
     * Ensures the loader is placed in wp-content/mu-plugins.
     *
     * @return void
     */
    private static function installMuPlugin() {
        $muDir  = WP_CONTENT_DIR . '/mu-plugins';
        $source = DUA_PLUGIN_DIR . 'mu-loader/dua-mu-loader.php';
        $target = $muDir . '/dua-mu-loader.php';

        if (!file_exists($muDir)) {
            mkdir($muDir, 0755, true);
        }

        if (file_exists($target)) {
            unlink($target);
        }

        copy($source, $target);
    }

    /**
     * Remove the MU plugin loader file.
     * Ensures the loader is removed from wp-content/mu-plugins.
     *
     * @return void
     */
    private static function removeMuPlugin() {
        $muDir  = WP_CONTENT_DIR . '/mu-plugins';
        $target = $muDir . '/dua-mu-loader.php';

        if (file_exists($target)) {
            unlink($target);
        }
    }

    /**
     * Injects site-scoped signup validation hook into ms-functions.php.
     * Adds a single blank line above and below the injected block.
     * Preserves indentation of the original WordPress comment.
     */
    private static function injectSignupHook() {
        $path = ABSPATH . 'wp-includes/ms-functions.php';
        if (!file_exists($path)) return;

        $contents = file_get_contents($path);
        $anchor   = '// Has someone already signed up for this username?';

        $injection =
            "// Injected by Decentralized User Authentication plugin: site-scoped signup validation override\n" .
            "\t\$result = apply_filters('dua_site_scoped_signup_validation', null, \$user_name, \$user_email);\n" .
            "\tif (is_array(\$result)) {\n" .
            "\t\treturn apply_filters('wpmu_validate_user_signup', \$result);\n" .
            "\t} // Decentralized User Authentication plugin ends here.\n\n";

        if (
            strpos($contents, 'dua_site_scoped_signup_validation') === false &&
            strpos($contents, $anchor) !== false
        ) {
            $indentedAnchor = "\t" . $anchor;
            $contents = str_replace($anchor, $injection . $indentedAnchor, $contents);
            file_put_contents($path, $contents);
        }
    }

    /**
     * Removes injected site-scoped signup validation hook from ms-functions.php.
     * Matches the exact block including comment and spacing.
     */
    private static function removeSignupHook() {
        $path = ABSPATH . 'wp-includes/ms-functions.php';
        if (!file_exists($path)) return;

        $contents = file_get_contents($path);

        $pattern = '/\t\/\/ Injected by Decentralized User Authentication plugin: site-scoped signup validation override\n' .
                   '\t\$result = apply_filters\(\s*\'dua_site_scoped_signup_validation\',\s*null,\s*\$user_name,\s*\$user_email\s*\);\n' .
                   '\tif\s*\(is_array\(\$result\)\)\s*\{\n' .
                   '\t\treturn apply_filters\(\s*\'wpmu_validate_user_signup\',\s*\$result\s*\);\n' .
                   '\t\}\s*\/\/ Decentralized User Authentication plugin ends here\.\n\n/';

        $contents = preg_replace($pattern, '', $contents);
        file_put_contents($path, $contents);
    }
}
