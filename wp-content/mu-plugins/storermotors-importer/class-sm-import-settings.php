<?php
/**
 * Settings resolution for the vehicle feed importer.
 *
 * FTP credentials are read from wp-config.php constants when defined, so the
 * password never lands in the database (and therefore never in a SQL dump).
 * Anything not defined as a constant falls back to the options row.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Settings {

    const OPTION = 'sm_vehicle_import_settings';

    /** Settings backed by a wp-config.php constant. */
    const CONSTANTS = [
        'ftp_host'   => 'SM_FEED_FTP_HOST',
        'ftp_user'   => 'SM_FEED_FTP_USER',
        'ftp_pass'   => 'SM_FEED_FTP_PASS',
        'ftp_path'   => 'SM_FEED_FTP_PATH',
        'ftp_scheme' => 'SM_FEED_FTP_SCHEME',
        'local_path' => 'SM_FEED_LOCAL_PATH',
    ];

    public static function defaults() {
        return [
            // A drop folder on this server. Set, it wins over the FTP settings:
            // the vendor uploads over their own FTP account and the importer
            // reads the package straight off disk.
            'local_path'          => '',
            'require_sentinel'    => true,

            'ftp_host'            => '',
            'ftp_user'            => '',
            'ftp_pass'            => '',
            'ftp_path'            => '/',
            'ftp_scheme'          => 'ftp',
            'expected_user_id'    => 'SML',
            'schedule'            => 'sm_hourly',
            'schedule_enabled'    => true,

            // Reconciliation guards. See §8 of the plan.
            'min_ratio'           => 0.5,
            'max_draft_ratio'     => 0.30,
            'max_skip_ratio'      => 0.20,

            // Weekly finance calculation.
            'weekly_enabled'      => true,
            'weekly_term_months'  => 60,
            'weekly_rate'         => 12.95,
            'weekly_fees'         => 395,
            'weekly_deposit'      => 0,

            // Privacy: VIN/chassis are imported but hidden by default.
            'show_vin'            => false,

            'purge_after_days'    => 0, // 0 = never purge drafted vehicles' images
        ];
    }

    /**
     * Resolve a single setting. Constants win over stored options.
     */
    public static function get($key) {
        if (isset(self::CONSTANTS[$key]) && defined(self::CONSTANTS[$key])) {
            return constant(self::CONSTANTS[$key]);
        }

        $stored   = get_option(self::OPTION, []);
        $defaults = self::defaults();

        if (is_array($stored) && array_key_exists($key, $stored) && $stored[$key] !== '') {
            return $stored[$key];
        }

        return $defaults[$key] ?? null;
    }

    /**
     * True when the value is pinned in wp-config.php and the admin UI should
     * render it read-only.
     */
    public static function is_locked($key) {
        return isset(self::CONSTANTS[$key]) && defined(self::CONSTANTS[$key]);
    }

    public static function update(array $values) {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        // Never write a value that a constant already owns.
        foreach (array_keys(self::CONSTANTS) as $key) {
            if (self::is_locked($key)) {
                unset($values[$key]);
            }
        }

        update_option(self::OPTION, array_merge($stored, $values), false);
    }

    /**
     * Absolute path to the import staging root, created and hardened on first use.
     */
    public static function staging_dir() {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . 'sm-vehicle-import';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Block direct web access to staged feed data.
        $guards = [
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security><requestFiltering><denyUrlSequences><add sequence=\".\" /></denyUrlSequences></requestFiltering></security></system.webServer></configuration>\n",
            'index.html' => '',
        ];
        foreach ($guards as $name => $contents) {
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                file_put_contents($path, $contents);
            }
        }

        return $dir;
    }

    /**
     * Local feed archive to import when no FTP host is configured.
     *
     * Lets the whole pipeline be exercised before Motorcentral credentials
     * exist. Ignored the moment a host is set.
     */
    public static function fixture_path() {
        if (self::get('ftp_host') || self::get('local_path')) {
            return '';
        }

        $fixtures = glob(self::staging_dir() . '/fixtures/*.zip');

        return $fixtures ? $fixtures[0] : '';
    }
}
