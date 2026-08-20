<?php
/**
 * Run logging: rolling summaries in an option, verbose detail in dated files.
 *
 * No custom DB table — at one run per hour the option holds everything the
 * admin screen needs, and the files carry the per-vehicle detail.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Log {

    const OPTION      = 'sm_vehicle_import_log';
    const MAX_RUNS    = 20;
    const RETAIN_DAYS = 30;

    /** Counters and messages for the run currently in flight. */
    private static $run = null;

    /**
     * Counters raised while no run is in flight.
     *
     * Photo import happens in later cron slices or AJAX ticks, long after the
     * run that queued the work has been summarised. Those tallies are held here
     * and folded back into the most recent run by flush_deferred().
     */
    private static $deferred = [];

    public static function start($trigger = 'manual') {
        self::$run = [
            'started'           => current_time('mysql'),
            'finished'          => '',
            'trigger'           => $trigger,
            'fingerprint'       => '',
            'items'             => 0,
            'added'             => 0,
            'updated'           => 0,
            'unchanged'         => 0,
            'skipped'           => 0,
            'drafted'           => 0,
            'republished'       => 0,
            'images_added'      => 0,
            'images_skipped'    => 0,
            'images_replaced'   => 0,
            'skipped_reconcile' => false,
            'warnings'          => [],
            'errors'            => [],
        ];
        self::line('INFO', sprintf('Run started (trigger: %s)', $trigger));
    }

    public static function is_running() {
        return self::$run !== null;
    }

    /** Increment a counter on the in-flight run, or defer it if none is active. */
    public static function bump($key, $by = 1) {
        if (self::$run !== null) {
            if (isset(self::$run[$key])) {
                self::$run[$key] += $by;
            }
            return;
        }

        self::$deferred[$key] = (self::$deferred[$key] ?? 0) + $by;
    }

    /**
     * Fold deferred counters into the most recent stored run.
     *
     * Called once per queue slice rather than per photo, so a 257-image import
     * costs one option write per slice instead of 257.
     */
    public static function flush_deferred() {
        if (!self::$deferred) {
            return;
        }

        // A run is in flight — let it own the counters instead.
        if (self::$run !== null) {
            foreach (self::$deferred as $key => $value) {
                if (isset(self::$run[$key])) {
                    self::$run[$key] += $value;
                }
            }
            self::$deferred = [];
            return;
        }

        $runs = get_option(self::OPTION, []);
        if (!is_array($runs) || !$runs) {
            self::$deferred = [];
            return;
        }

        foreach (self::$deferred as $key => $value) {
            if (isset($runs[0][$key])) {
                $runs[0][$key] += $value;
            }
        }
        update_option(self::OPTION, $runs, false);

        self::$deferred = [];
    }

    public static function set($key, $value) {
        if (self::$run !== null) {
            self::$run[$key] = $value;
        }
    }

    public static function get($key) {
        return self::$run[$key] ?? null;
    }

    public static function info($message) {
        self::line('INFO', $message);
    }

    public static function warn($message) {
        if (self::$run !== null) {
            self::$run['warnings'][] = $message;
        }
        self::line('WARNING', $message);
    }

    public static function error($message) {
        if (self::$run !== null) {
            self::$run['errors'][] = $message;
        }
        self::line('ERROR', $message);
    }

    /**
     * An unmapped enum value from the feed. Logged loudly so a new body type or
     * fuel type surfaces the day it first appears rather than weeks later when
     * the filter dropdowns look wrong.
     */
    public static function unmapped($field, $value, $stock_no) {
        self::warn(sprintf('UNMAPPED %s "%s" (StockNo %s) — value discarded, extend the map in class-sm-import-parser.php', $field, $value, $stock_no));
    }

    /** Close the run out and push its summary onto the rolling log. */
    public static function finish() {
        if (self::$run === null) {
            return null;
        }

        self::$run['finished'] = current_time('mysql');
        self::line('INFO', sprintf(
            'Run finished — %d items: %d added, %d updated, %d unchanged, %d skipped, %d drafted, %d republished. Images: %d added, %d skipped, %d replaced. %d warnings, %d errors.',
            self::$run['items'], self::$run['added'], self::$run['updated'], self::$run['unchanged'],
            self::$run['skipped'], self::$run['drafted'], self::$run['republished'],
            self::$run['images_added'], self::$run['images_skipped'], self::$run['images_replaced'],
            count(self::$run['warnings']), count(self::$run['errors'])
        ));

        $runs = get_option(self::OPTION, []);
        if (!is_array($runs)) {
            $runs = [];
        }
        array_unshift($runs, self::$run);
        $runs = array_slice($runs, 0, self::MAX_RUNS);
        update_option(self::OPTION, $runs, false);

        $summary  = self::$run;
        self::$run = null;

        return $summary;
    }

    public static function runs() {
        $runs = get_option(self::OPTION, []);
        return is_array($runs) ? $runs : [];
    }

    /** Append one line to today's log file. */
    private static function line($level, $message) {
        $dir = SM_Import_Settings::staging_dir() . '/logs';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $path = $dir . '/sm-import-' . gmdate('Y-m-d') . '.log';
        $line = sprintf("[%s] %-7s %s\n", current_time('mysql'), $level, $message);

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Email the site admin when a run fails, rate-limited to once a day so a
     * persistently unreachable FTP server cannot flood the inbox.
     */
    public static function notify_failure($message) {
        if (get_transient('sm_vehicle_import_notified')) {
            return;
        }

        $sent = wp_mail(
            get_option('admin_email'),
            sprintf('[%s] Vehicle feed import failed', get_bloginfo('name')),
            sprintf(
                "The vehicle feed import did not complete.\n\n%s\n\nNo vehicles were drafted. Review the log at:\n%s\n",
                $message,
                admin_url('edit.php?post_type=vehicle&page=sm-vehicle-import')
            )
        );

        if ($sent) {
            set_transient('sm_vehicle_import_notified', 1, DAY_IN_SECONDS);
        }
    }

    /** Drop log files older than the retention window. Called by the GC cron. */
    public static function prune() {
        $dir = SM_Import_Settings::staging_dir() . '/logs';
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - (self::RETAIN_DAYS * DAY_IN_SECONDS);
        foreach ((array) glob($dir . '/sm-import-*.log') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
