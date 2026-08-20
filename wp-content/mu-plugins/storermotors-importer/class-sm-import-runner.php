<?php
/**
 * Import orchestration, run locking, and cron wiring.
 *
 * Fail-closed ordering: download → verify → extract → parse → validation gate →
 * upsert → enqueue images → reconcile. Reconciliation is last and only runs
 * after a clean parse, so a bad feed can never empty the website.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Runner {

    const LOCK_OPTION        = 'sm_vehicle_import_lock';
    const FINGERPRINT_OPTION = 'sm_vehicle_import_last_fingerprint';
    const LOCK_TIMEOUT       = 1800; // 30 minutes

    const HOOK_CHECK  = 'sm_vehicle_import_check';
    const HOOK_IMAGES = 'sm_vehicle_import_process_images';
    const HOOK_GC     = 'sm_vehicle_import_gc';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'schedules']);
        add_action('init', [__CLASS__, 'schedule_events'], 30);

        add_action(self::HOOK_CHECK, [__CLASS__, 'run_scheduled']);
        add_action(self::HOOK_IMAGES, [__CLASS__, 'run_image_slice']);
        add_action(self::HOOK_GC, [__CLASS__, 'run_gc']);
    }

    public static function schedules($schedules) {
        $schedules['sm_every_15min'] = ['interval' => 900,   'display' => 'Every 15 minutes (Storer feed)'];
        $schedules['sm_hourly']      = ['interval' => 3600,  'display' => 'Hourly (Storer feed)'];
        $schedules['sm_twice_daily'] = ['interval' => 43200, 'display' => 'Twice daily (Storer feed)'];
        return $schedules;
    }

    public static function schedule_events() {
        $enabled  = (bool) SM_Import_Settings::get('schedule_enabled');
        $schedule = (string) SM_Import_Settings::get('schedule');

        if (!$enabled) {
            wp_clear_scheduled_hook(self::HOOK_CHECK);
        } else {
            $next = wp_next_scheduled(self::HOOK_CHECK);
            // Reschedule when the interval setting has changed.
            if ($next && wp_get_schedule(self::HOOK_CHECK) !== $schedule) {
                wp_clear_scheduled_hook(self::HOOK_CHECK);
                $next = false;
            }
            if (!$next) {
                wp_schedule_event(time() + 300, $schedule, self::HOOK_CHECK);
            }
        }

        if (!wp_next_scheduled(self::HOOK_GC)) {
            wp_schedule_event(time() + 3600, 'daily', self::HOOK_GC);
        }
    }

    // ─── Entry points ────────────────────────────────────────────────────────

    /** Cron entry: pull from FTP if a new feed is waiting. */
    public static function run_scheduled() {
        self::run(['trigger' => 'cron']);
    }

    /** Cron entry: keep working the image queue until it drains. */
    public static function run_image_slice() {
        $result = SM_Import_Media::process_queue(20);

        if (!$result['finished']) {
            wp_schedule_single_event(time() + 60, self::HOOK_IMAGES);
        }
    }

    public static function run_gc() {
        SM_Import_Package::gc();
        SM_Import_Log::prune();
    }

    /**
     * Run an import.
     *
     * @param array $args {
     *     @type string $trigger    cron|manual
     *     @type string $zip_path   Import this local file instead of pulling FTP.
     *     @type bool   $force      Ignore the unchanged-feed fingerprint.
     *     @type bool   $dry_run    Parse and report without writing anything.
     * }
     * @return array|WP_Error Run summary.
     */
    public static function run(array $args = []) {
        $args = array_merge([
            'trigger'  => 'manual',
            'zip_path' => '',
            'force'    => false,
            'dry_run'  => false,
        ], $args);

        if (!self::acquire_lock()) {
            return new WP_Error('sm_import_locked', 'An import is already running. Try again in a few minutes.');
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        SM_Import_Log::start($args['trigger'] . ($args['dry_run'] ? ' (dry run)' : ''));

        try {
            $result = self::execute($args);
        } catch (Throwable $e) {
            SM_Import_Log::error(sprintf('Unhandled failure: %s', $e->getMessage()));
            $result = new WP_Error('sm_import_exception', $e->getMessage());
        } finally {
            $summary = SM_Import_Log::finish();
            self::release_lock();
        }

        if (is_wp_error($result)) {
            SM_Import_Log::notify_failure($result->get_error_message());
            return $result;
        }

        return $summary;
    }

    private static function execute(array $args) {
        // 1. Obtain the feed.
        $zip_path = $args['zip_path'];
        $cleanup_zip = false;

        // Before Motorcentral credentials exist, fall back to the bundled
        // fixture so the pipeline can be exercised end to end.
        if ($zip_path === '') {
            $fixture = SM_Import_Settings::fixture_path();
            if ($fixture !== '') {
                SM_Import_Log::info(sprintf('No FTP host configured — importing the local fixture %s.', basename($fixture)));
                $zip_path = $fixture;
            }
        }

        if ($zip_path === '') {
            $ready = SM_Import_FTP::poll($args['force']);
            if (is_wp_error($ready)) {
                return $ready;
            }
            if ($ready === null) {
                SM_Import_Log::info('No new feed waiting on the server.');
                return true;
            }

            SM_Import_Log::set('fingerprint', $ready['fingerprint']);
            $zip_path    = $ready['path'];
            $cleanup_zip = true;
        }

        // 2. Extract and validate.
        $package = SM_Import_Package::extract($zip_path);
        if ($cleanup_zip && file_exists($zip_path)) {
            unlink($zip_path);
        }
        if (is_wp_error($package)) {
            return $package;
        }

        // 3. Parse.
        $items = SM_Import_Parser::parse($package['xml']);
        if (is_wp_error($items)) {
            SM_Import_Package::cleanup($package['run_dir']);
            return $items;
        }

        SM_Import_Log::set('items', count($items));

        // 4. Fold the photo set into each vehicle's change signature.
        $images_by_stock = SM_Import_Media::group_by_stock($package['images']);
        foreach ($items as $stock => &$item) {
            $list = $images_by_stock[(string) $stock] ?? [];
            $item['image_signature'] = SM_Import_Media::signature($list);
        }
        unset($item);

        if ($args['dry_run']) {
            SM_Import_Log::info(sprintf(
                'Dry run: %d vehicles parsed, %d photos indexed. Nothing was written.',
                count($items), count($package['images'])
            ));
            SM_Import_Package::cleanup($package['run_dir']);
            return true;
        }

        // 5. Upsert.
        wp_defer_term_counting(true);
        $lookup = SM_Import_Vehicles::lookup_map();
        $jobs   = [];

        foreach ($items as $stock => $item) {
            $stock   = (string) $stock;
            $post_id = $lookup[$stock] ?? 0;

            try {
                $result = SM_Import_Vehicles::upsert($item, $post_id);
            } catch (Throwable $e) {
                // One malformed vehicle must not abort the whole run.
                SM_Import_Log::error(sprintf('StockNo %s failed: %s', $stock, $e->getMessage()));
                SM_Import_Log::bump('skipped');
                continue;
            }

            if ($result['status'] === 'skipped' || !$result['post_id']) {
                continue;
            }

            // Unchanged vehicles need no photo work at all.
            if ($result['status'] !== 'unchanged' && !empty($images_by_stock[$stock])) {
                $jobs[] = [
                    'post_id' => $result['post_id'],
                    'stock'   => $stock,
                    'images'  => $images_by_stock[$stock],
                ];
            }
        }
        wp_defer_term_counting(false);

        // A systematically bad feed should not reach reconciliation.
        $skipped = (int) SM_Import_Log::get('skipped');
        $max_skip = (float) SM_Import_Settings::get('max_skip_ratio');
        if (count($items) > 0 && $skipped > count($items) * $max_skip) {
            SM_Import_Package::cleanup($package['run_dir']);
            return new WP_Error('sm_import_too_many_skipped', sprintf(
                '%d of %d vehicles failed to import — aborting before reconciliation.',
                $skipped, count($items)
            ));
        }

        // 6. Queue photo work. The run directory is removed once it drains.
        if ($jobs) {
            SM_Import_Media::queue([
                'run_dir' => $package['run_dir'],
                'jobs'    => $jobs,
            ]);
            SM_Import_Log::info(sprintf('Queued photo sync for %d vehicles.', count($jobs)));

            if ($args['trigger'] === 'cron') {
                wp_schedule_single_event(time() + 30, self::HOOK_IMAGES);
            }
        } else {
            SM_Import_Package::cleanup($package['run_dir']);
        }

        // 7. Reconcile — last, and guarded.
        SM_Import_Vehicles::reconcile(array_map('strval', array_keys($items)), $package['sentinel']);

        return true;
    }

    // ─── Locking ─────────────────────────────────────────────────────────────

    /**
     * add_option() is atomic — it fails when the row already exists — unlike
     * a get_option()/update_option() pair, which races.
     */
    private static function acquire_lock() {
        if (add_option(self::LOCK_OPTION, time(), '', 'no')) {
            return true;
        }

        $held = (int) get_option(self::LOCK_OPTION, 0);
        if ($held && (time() - $held) > self::LOCK_TIMEOUT) {
            SM_Import_Log::warn(sprintf('Clearing a stale import lock held since %s.', gmdate('Y-m-d H:i:s', $held)));
            update_option(self::LOCK_OPTION, time(), false);
            return true;
        }

        return false;
    }

    private static function release_lock() {
        delete_option(self::LOCK_OPTION);
    }

    public static function is_locked() {
        return (bool) get_option(self::LOCK_OPTION, 0);
    }
}

SM_Import_Runner::init();
