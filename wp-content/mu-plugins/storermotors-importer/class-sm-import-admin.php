<?php
/**
 * Admin surface: the Feed Import screen, the extra vehicle meta boxes, and the
 * front-end features block.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Admin {

    const PAGE       = 'sm-vehicle-import';
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_sm_vehicle_import_run', [__CLASS__, 'handle_run']);
        add_action('admin_post_sm_vehicle_import_save', [__CLASS__, 'handle_save']);
        add_action('wp_ajax_sm_vehicle_import_tick', [__CLASS__, 'handle_tick']);
        add_action('admin_notices', [__CLASS__, 'notices']);

        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('save_post_vehicle', [__CLASS__, 'save_extras']);

        add_action('init', [__CLASS__, 'register_blocks'], 25);
        add_action('wp_enqueue_scripts', [__CLASS__, 'styles'], 20);
    }

    /**
     * Styles for the two blocks this plugin adds. Kept here rather than in the
     * branding plugin's CSS string so the importer stays self-contained.
     */
    public static function styles() {
        wp_register_style('sm-import-blocks', false);
        wp_enqueue_style('sm-import-blocks');
        wp_add_inline_style('sm-import-blocks', '
.sm-single-vehicle-features { margin-top: 32px; }
.sm-features-heading {
    font-family: var(--sm-font-body);
    font-size: 20px;
    font-weight: 600;
    margin: 0 0 14px;
}
.sm-vehicle-features {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    list-style: none;
    margin: 0;
    padding: 0;
}
.sm-vehicle-feature {
    font-size: 14px;
    color: var(--sm-dark);
    background: var(--sm-light-2);
    border: 1px solid var(--sm-border);
    border-radius: 6px;
    padding: 6px 12px;
}
.sm-vehicle-feature::before {
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    content: "\\f00c";
    color: var(--sm-yellow-dark);
    margin-right: 8px;
    font-size: 11px;
}
.sm-finance-disclaimer {
    font-size: 12px !important;
    line-height: 1.5;
    color: var(--sm-gray-light);
    margin: 6px 0 20px;
    max-width: 620px;
}
');
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=vehicle',
            'Feed Import',
            'Feed Import',
            self::CAPABILITY,
            self::PAGE,
            [__CLASS__, 'render']
        );
    }

    // ─── Handlers ────────────────────────────────────────────────────────────

    public static function handle_run() {
        check_admin_referer('sm_vehicle_import_run');
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You do not have permission to run the vehicle feed import.', 403);
        }

        $action = isset($_POST['sm_action']) ? sanitize_key($_POST['sm_action']) : 'run';
        $notice = 'ran';

        if ($action === 'test') {
            $test = SM_Import_FTP::test();
            set_transient('sm_vehicle_import_test', is_wp_error($test) ? ['error' => $test->get_error_message()] : $test, 120);
            $notice = 'tested';
        } elseif ($action === 'reconcile') {
            // Explicit override: the operator has reviewed the counts.
            $summary = SM_Import_Runner::run(['trigger' => 'manual (reconcile override)', 'force' => true]);
            $notice  = is_wp_error($summary) ? 'error' : 'ran';
        } else {
            $summary = SM_Import_Runner::run([
                'trigger'  => 'manual',
                'force'    => !empty($_POST['sm_force']),
                'dry_run'  => !empty($_POST['sm_dry_run']),
                'zip_path' => self::fixture_path(),
            ]);
            $notice = is_wp_error($summary) ? 'error' : 'ran';
            if (is_wp_error($summary)) {
                set_transient('sm_vehicle_import_error', $summary->get_error_message(), 120);
            }
        }

        wp_safe_redirect(add_query_arg('sm_import', $notice, wp_get_referer() ?: admin_url('edit.php?post_type=vehicle&page=' . self::PAGE)));
        exit;
    }

    public static function handle_save() {
        check_admin_referer('sm_vehicle_import_save');
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You do not have permission to change these settings.', 403);
        }

        $in = wp_unslash($_POST);

        SM_Import_Settings::update([
            'ftp_host'           => sanitize_text_field($in['ftp_host'] ?? ''),
            'ftp_user'           => sanitize_text_field($in['ftp_user'] ?? ''),
            'ftp_pass'           => (string) ($in['ftp_pass'] ?? ''),
            'ftp_path'           => sanitize_text_field($in['ftp_path'] ?? '/'),
            'ftp_scheme'         => in_array($in['ftp_scheme'] ?? '', ['ftp', 'ftps', 'sftp'], true) ? $in['ftp_scheme'] : 'ftp',
            'schedule'           => in_array($in['schedule'] ?? '', ['sm_every_15min', 'sm_hourly', 'sm_twice_daily'], true) ? $in['schedule'] : 'sm_hourly',
            'schedule_enabled'   => !empty($in['schedule_enabled']),
            'min_ratio'          => max(0, min(1, (float) ($in['min_ratio'] ?? 0.5))),
            'max_draft_ratio'    => max(0, min(1, (float) ($in['max_draft_ratio'] ?? 0.30))),
            'weekly_enabled'     => !empty($in['weekly_enabled']),
            'weekly_term_months' => absint($in['weekly_term_months'] ?? 60),
            'weekly_rate'        => (float) ($in['weekly_rate'] ?? 12.95),
            'weekly_fees'        => (float) ($in['weekly_fees'] ?? 395),
            'weekly_deposit'     => (float) ($in['weekly_deposit'] ?? 0),
            'show_vin'           => !empty($in['show_vin']),
        ]);

        // Pick up a changed interval immediately.
        wp_clear_scheduled_hook(SM_Import_Runner::HOOK_CHECK);
        SM_Import_Runner::schedule_events();

        wp_safe_redirect(add_query_arg('sm_import', 'saved', wp_get_referer() ?: admin_url('edit.php?post_type=vehicle&page=' . self::PAGE)));
        exit;
    }

    /** Drives the progress bar during a manual run. */
    public static function handle_tick() {
        check_ajax_referer('sm_vehicle_import_tick');
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'Not permitted.'], 403);
        }

        $result = SM_Import_Media::process_queue(20);
        wp_send_json_success($result);
    }

    private static function fixture_path() {
        return SM_Import_Settings::fixture_path();
    }

    public static function notices() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $runs = SM_Import_Log::runs();
        $last = $runs[0] ?? null;

        if ($last && !empty($last['errors'])) {
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>Vehicle feed import failed.</strong> %s <a href="%s">View the log</a>.</p></div>',
                esc_html($last['errors'][0]),
                esc_url(admin_url('edit.php?post_type=vehicle&page=' . self::PAGE))
            );
        } elseif ($last && !empty($last['skipped_reconcile'])) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>Vehicle feed:</strong> reconciliation was skipped on the last run, so no vehicles were drafted. <a href="%s">Review and override</a>.</p></div>',
                esc_url(admin_url('edit.php?post_type=vehicle&page=' . self::PAGE))
            );
        }
    }

    // ─── Screen ──────────────────────────────────────────────────────────────

    public static function render() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You do not have permission to view this page.', 403);
        }

        $runs      = SM_Import_Log::runs();
        $last      = $runs[0] ?? null;
        $next      = wp_next_scheduled(SM_Import_Runner::HOOK_CHECK);
        $queued    = SM_Import_Media::queue_remaining();
        $fixture   = self::fixture_path();
        $test      = get_transient('sm_vehicle_import_test');
        $error     = get_transient('sm_vehicle_import_error');
        delete_transient('sm_vehicle_import_test');
        delete_transient('sm_vehicle_import_error');
        ?>
        <div class="wrap">
            <h1>Vehicle Feed Import</h1>

            <?php if ($error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) : ?>
                <div class="notice notice-warning">
                    <p><strong>WP-Cron is running on page loads.</strong> On a quiet site the import will run at unpredictable times, possibly while a customer is waiting for a page. Set <code>define('DISABLE_WP_CRON', true);</code> in <code>wp-config.php</code> and add a server cron hitting <code>wp-cron.php</code> every 15 minutes.</p>
                </div>
            <?php endif; ?>

            <h2>Status</h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                    <tr><td><strong>Last run</strong></td><td><?php echo $last ? esc_html($last['started']) . ' (' . esc_html($last['trigger']) . ')' : 'Never'; ?></td></tr>
                    <tr><td><strong>Next scheduled run</strong></td><td><?php echo $next ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i:s')) : 'Not scheduled'; ?></td></tr>
                    <tr><td><strong>Photos awaiting import</strong></td><td><?php echo (int) $queued; ?> vehicle<?php echo $queued === 1 ? '' : 's'; ?></td></tr>
                    <tr><td><strong>Import lock</strong></td><td><?php echo SM_Import_Runner::is_locked() ? 'Held — a run is in progress' : 'Free'; ?></td></tr>
                    <tr><td><strong>Feed source</strong></td><td><?php
                        echo SM_Import_Settings::get('ftp_host')
                            ? esc_html(SM_Import_Settings::get('ftp_scheme') . '://' . SM_Import_Settings::get('ftp_host') . SM_Import_Settings::get('ftp_path'))
                            : ($fixture ? 'Local fixture: <code>' . esc_html(basename($fixture)) . '</code>' : '<em>Not configured</em>');
                    ?></td></tr>
                </tbody>
            </table>

            <?php if ($test) : ?>
                <h2>Connection test</h2>
                <?php if (!empty($test['error'])) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html($test['error']); ?></p></div>
                <?php else : ?>
                    <p>END.XML present: <strong><?php echo !empty($test['sentinel']) ? 'yes' : 'no'; ?></strong><?php
                        if (!empty($test['archive'])) {
                            printf(' — newest archive: <code>%s</code> (%s)', esc_html($test['archive']['name']), esc_html(size_format((int) $test['archive']['size'])));
                        }
                    ?></p>
                    <pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:220px;overflow:auto"><?php
                        foreach ($test['entries'] as $entry) {
                            printf("%-40s %12s\n", esc_html($entry['name']), esc_html($entry['size'] ? size_format((int) $entry['size']) : ''));
                        }
                    ?></pre>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Run</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sm_vehicle_import_run'); ?>
                <input type="hidden" name="action" value="sm_vehicle_import_run" />
                <p>
                    <label><input type="checkbox" name="sm_dry_run" value="1" /> Dry run — parse and report without writing anything</label><br />
                    <label><input type="checkbox" name="sm_force" value="1" /> Force — re-import even if the feed has not changed</label>
                </p>
                <p>
                    <button type="submit" name="sm_action" value="run" class="button button-primary">Run import now</button>
                    <button type="submit" name="sm_action" value="test" class="button">Test connection</button>
                    <button type="submit" name="sm_action" value="reconcile" class="button" onclick="return confirm('This will draft every published vehicle missing from the current feed, bypassing the safety limits. Continue?');">Reconcile now (override)</button>
                </p>
            </form>

            <?php if ($queued) : ?>
                <h2>Photo import progress</h2>
                <div id="sm-import-progress" style="max-width:520px">
                    <div style="background:#e5e5e5;border-radius:3px;height:22px;overflow:hidden">
                        <div id="sm-import-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s"></div>
                    </div>
                    <p id="sm-import-status">Starting…</p>
                </div>
                <script>
                (function () {
                    var total = <?php echo (int) $queued; ?>, bar = document.getElementById('sm-import-bar'),
                        status = document.getElementById('sm-import-status');
                    function tick() {
                        var body = new FormData();
                        body.append('action', 'sm_vehicle_import_tick');
                        body.append('_ajax_nonce', '<?php echo esc_js(wp_create_nonce('sm_vehicle_import_tick')); ?>');
                        fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (r) {
                                if (!r.success) { status.textContent = 'Import failed — check the log.'; return; }
                                var left = r.data.remaining, pct = total ? Math.round((total - left) / total * 100) : 100;
                                bar.style.width = pct + '%';
                                status.textContent = r.data.finished
                                    ? 'Photo import complete.'
                                    : left + ' vehicle(s) remaining…';
                                if (!r.data.finished) { setTimeout(tick, 3000); }
                                else { setTimeout(function () { location.reload(); }, 1500); }
                            })
                            .catch(function () { status.textContent = 'Lost contact with the server.'; });
                    }
                    setTimeout(tick, 500);
                }());
                </script>
            <?php endif; ?>

            <h2>Settings</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sm_vehicle_import_save'); ?>
                <input type="hidden" name="action" value="sm_vehicle_import_save" />
                <table class="form-table" role="presentation">
                    <tr><th colspan="2"><h3 style="margin:0">Connection</h3></th></tr>
                    <?php
                    self::field('ftp_host', 'FTP host', 'text');
                    self::field('ftp_user', 'Username', 'text');
                    self::field('ftp_pass', 'Password', 'password');
                    self::field('ftp_path', 'Remote directory', 'text');
                    ?>
                    <tr>
                        <th scope="row">Protocol</th>
                        <td>
                            <?php $scheme = SM_Import_Settings::get('ftp_scheme'); $locked = SM_Import_Settings::is_locked('ftp_scheme'); ?>
                            <select name="ftp_scheme" <?php disabled($locked); ?>>
                                <?php foreach (['ftp' => 'FTP (plain)', 'ftps' => 'FTPS (explicit TLS)', 'sftp' => 'SFTP (SSH)'] as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($scheme, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($locked) : ?><p class="description">Set in wp-config.php.</p><?php endif; ?>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h3 style="margin:0">Schedule</h3></th></tr>
                    <tr>
                        <th scope="row">Automatic import</th>
                        <td>
                            <label><input type="checkbox" name="schedule_enabled" value="1" <?php checked(SM_Import_Settings::get('schedule_enabled')); ?> /> Check the server on a schedule</label><br />
                            <select name="schedule">
                                <?php foreach (['sm_every_15min' => 'Every 15 minutes', 'sm_hourly' => 'Hourly', 'sm_twice_daily' => 'Twice daily'] as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected(SM_Import_Settings::get('schedule'), $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h3 style="margin:0">Safety limits</h3></th></tr>
                    <tr>
                        <th scope="row">Minimum feed size</th>
                        <td>
                            <input type="number" step="0.05" min="0" max="1" name="min_ratio" value="<?php echo esc_attr(SM_Import_Settings::get('min_ratio')); ?>" class="small-text" />
                            <p class="description">Skip reconciliation if the feed holds less than this fraction of the previous run's vehicle count. Protects against a truncated download emptying the site.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Maximum drafted per run</th>
                        <td>
                            <input type="number" step="0.05" min="0" max="1" name="max_draft_ratio" value="<?php echo esc_attr(SM_Import_Settings::get('max_draft_ratio')); ?>" class="small-text" />
                            <p class="description">Skip reconciliation if more than this fraction of published vehicles are missing from the feed.</p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h3 style="margin:0">Weekly finance</h3></th></tr>
                    <tr>
                        <th scope="row">Calculate weekly repayments</th>
                        <td>
                            <label><input type="checkbox" name="weekly_enabled" value="1" <?php checked(SM_Import_Settings::get('weekly_enabled')); ?> /> Derive a weekly figure from each vehicle's price</label>
                            <p class="description"><strong>The feed carries no repayment figure</strong>, so this is calculated. Any advertised repayment must show the rate, term, deposit and fees used — the vehicle page renders that disclaimer automatically. Hand-entered figures are never overwritten.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Terms</th>
                        <td>
                            <label>Term <input type="number" name="weekly_term_months" value="<?php echo esc_attr(SM_Import_Settings::get('weekly_term_months')); ?>" class="small-text" /> months</label>
                            <label style="margin-left:12px">Rate <input type="number" step="0.01" name="weekly_rate" value="<?php echo esc_attr(SM_Import_Settings::get('weekly_rate')); ?>" class="small-text" />% p.a.</label>
                            <label style="margin-left:12px">Fees $<input type="number" step="0.01" name="weekly_fees" value="<?php echo esc_attr(SM_Import_Settings::get('weekly_fees')); ?>" class="small-text" /></label>
                            <label style="margin-left:12px">Deposit $<input type="number" step="0.01" name="weekly_deposit" value="<?php echo esc_attr(SM_Import_Settings::get('weekly_deposit')); ?>" class="small-text" /></label>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h3 style="margin:0">Privacy</h3></th></tr>
                    <tr>
                        <th scope="row">VIN and chassis</th>
                        <td>
                            <label><input type="checkbox" name="show_vin" value="1" <?php checked(SM_Import_Settings::get('show_vin')); ?> /> Show on public vehicle pages</label>
                            <p class="description">Off by default. Published VINs are scraped for vehicle cloning and resold by history-report sites.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Recent runs</h2>
            <table class="widefat striped">
                <thead><tr><th>Started</th><th>Trigger</th><th>Vehicles</th><th>Added</th><th>Updated</th><th>Unchanged</th><th>Drafted</th><th>Photos</th><th>Notes</th></tr></thead>
                <tbody>
                <?php if (!$runs) : ?>
                    <tr><td colspan="9">No imports have run yet.</td></tr>
                <?php else : foreach ($runs as $run) : ?>
                    <tr>
                        <td><?php echo esc_html($run['started']); ?></td>
                        <td><?php echo esc_html($run['trigger']); ?></td>
                        <td><?php echo (int) $run['items']; ?></td>
                        <td><?php echo (int) $run['added']; ?></td>
                        <td><?php echo (int) $run['updated']; ?></td>
                        <td><?php echo (int) $run['unchanged']; ?></td>
                        <td><?php echo (int) $run['drafted']; ?></td>
                        <td><?php printf('+%d / %d skipped', (int) $run['images_added'], (int) $run['images_skipped']); ?></td>
                        <td>
                            <?php if (!empty($run['errors'])) : ?>
                                <span style="color:#b32d2e"><?php echo esc_html(implode(' · ', array_slice($run['errors'], 0, 2))); ?></span>
                            <?php elseif (!empty($run['warnings'])) : ?>
                                <details><summary><?php echo count($run['warnings']); ?> warning(s)</summary><ul style="margin:6px 0 0 16px;list-style:disc">
                                    <?php foreach ($run['warnings'] as $warning) : ?><li><?php echo esc_html($warning); ?></li><?php endforeach; ?>
                                </ul></details>
                            <?php else : ?>
                                <span style="color:#00733f">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function field($key, $label, $type) {
        $locked = SM_Import_Settings::is_locked($key);
        $value  = $locked ? '' : (string) SM_Import_Settings::get($key);
        ?>
        <tr>
            <th scope="row"><label for="sm-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="<?php echo esc_attr($type); ?>" id="sm-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                       value="<?php echo esc_attr($value); ?>" class="regular-text"
                       <?php disabled($locked); ?> <?php echo $locked ? 'placeholder="Set in wp-config.php"' : ''; ?> />
                <?php if ($locked) : ?><p class="description">Set in <code>wp-config.php</code> and cannot be edited here.</p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    // ─── Vehicle meta boxes ──────────────────────────────────────────────────

    public static function meta_boxes() {
        add_meta_box('vehicle_extras', 'Vehicle Extras', [__CLASS__, 'render_extras'], 'vehicle', 'normal', 'default');
        add_meta_box('vehicle_feed_data', 'Feed Data', [__CLASS__, 'render_feed_data'], 'vehicle', 'side', 'low');
    }

    /** Editable fields the feed populates but the original meta box lacks. */
    public static function render_extras($post) {
        $fields = [
            '_vehicle_colour'      => ['label' => 'Colour', 'type' => 'text', 'placeholder' => 'e.g. Silver'],
            '_vehicle_doors'       => ['label' => 'Doors', 'type' => 'text', 'placeholder' => 'e.g. 5'],
            '_vehicle_variant'     => ['label' => 'Variant / Trim', 'type' => 'text', 'placeholder' => 'e.g. AWD Hybrid'],
            '_vehicle_wof_expiry'  => ['label' => 'WOF Expiry', 'type' => 'date'],
            '_vehicle_rego_expiry' => ['label' => 'Rego Expiry', 'type' => 'date'],
            '_vehicle_condition'   => ['label' => 'Condition', 'type' => 'select', 'options' => ['Used', 'New']],
        ];

        wp_nonce_field('sm_vehicle_extras', 'sm_vehicle_extras_nonce');
        echo '<table class="form-table" style="width:100%;">';
        foreach ($fields as $key => $field) {
            $value = get_post_meta($post->ID, $key, true);
            echo '<tr><th style="width:200px;padding:8px 0;"><label for="' . esc_attr($key) . '">' . esc_html($field['label']) . '</label></th><td style="padding:8px 0;">';

            if ($field['type'] === 'select') {
                echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" style="width:200px;">';
                echo '<option value="">— Select —</option>';
                foreach ($field['options'] as $option) {
                    echo '<option value="' . esc_attr($option) . '"' . selected($value, $option, false) . '>' . esc_html($option) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input type="' . esc_attr($field['type']) . '" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($field['placeholder'] ?? '') . '" style="width:300px;" />';
            }

            echo '</td></tr>';
        }
        echo '</table>';
        echo '<p class="description">These are overwritten from the feed on every import. The Vehicle Details box above holds the fields you curate by hand.</p>';
    }

    public static function save_extras($post_id) {
        if (!isset($_POST['sm_vehicle_extras_nonce']) || !wp_verify_nonce($_POST['sm_vehicle_extras_nonce'], 'sm_vehicle_extras')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        foreach (['_vehicle_colour', '_vehicle_doors', '_vehicle_variant', '_vehicle_wof_expiry', '_vehicle_rego_expiry', '_vehicle_condition'] as $key) {
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key])));
            }
        }
    }

    /** Read-only view of what the feed sent. Never hand-edited. */
    public static function render_feed_data($post) {
        $rows = [
            'Stock number' => get_post_meta($post->ID, '_vehicle_stock_no', true),
            'VIN'          => get_post_meta($post->ID, '_vehicle_vin', true),
            'Chassis'      => get_post_meta($post->ID, '_vehicle_chassis', true),
            'Location'     => get_post_meta($post->ID, '_vehicle_location', true),
            'Feed variant' => get_post_meta($post->ID, '_vehicle_variant_raw', true),
        ];

        $drafted = get_post_meta($post->ID, '_vehicle_import_drafted_at', true);
        if ($drafted) {
            $rows['Drafted (sold?)'] = $drafted;
        }

        if (!array_filter($rows)) {
            echo '<p>This vehicle was created by hand — the feed importer will never modify or draft it.</p>';
            return;
        }

        echo '<table style="width:100%;font-size:12px;">';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            echo '<tr><td style="padding:3px 0;color:#666;">' . esc_html($label) . '</td><td style="padding:3px 0;text-align:right;"><code>' . esc_html($value) . '</code></td></tr>';
        }
        echo '</table>';

        $raw = get_post_meta($post->ID, '_vehicle_import_raw', true);
        if ($raw) {
            echo '<details style="margin-top:8px;"><summary style="cursor:pointer;">Raw feed record</summary>';
            echo '<pre style="max-height:220px;overflow:auto;font-size:11px;background:#f6f7f7;padding:6px;">' . esc_html(wp_json_encode(json_decode($raw, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
        }
    }

    // ─── Front-end block ─────────────────────────────────────────────────────

    public static function register_blocks() {
        register_block_type('sm/vehicle-features', [
            'uses_context'    => ['postId'],
            'render_callback' => function ($attrs, $content, $block) {
                $post_id = $block->context['postId'] ?? get_the_ID();
                $terms   = wp_get_object_terms($post_id, 'vehicle_feature', ['fields' => 'names']);

                if (is_wp_error($terms) || !$terms) {
                    return '';
                }
                sort($terms);

                $chips = '';
                foreach ($terms as $term) {
                    $chips .= '<li class="sm-vehicle-feature">' . esc_html($term) . '</li>';
                }

                // Heading lives here rather than in the template so a vehicle
                // with no listed features renders nothing at all.
                return '<div class="sm-single-vehicle-features">'
                    . '<h2 class="sm-features-heading">Features</h2>'
                    . '<ul class="sm-vehicle-features">' . $chips . '</ul>'
                    . '</div>';
            },
        ]);

        // Renders wherever a weekly repayment is advertised. Required
        // disclosure, not decoration.
        register_block_type('sm/vehicle-finance-disclaimer', [
            'uses_context'    => ['postId'],
            'render_callback' => function ($attrs, $content, $block) {
                $post_id = $block->context['postId'] ?? get_the_ID();
                $weekly  = get_post_meta($post_id, '_vehicle_weekly', true);
                if (!$weekly) {
                    return '';
                }

                return '<p class="sm-finance-disclaimer">' . esc_html(self::disclaimer_text()) . '</p>';
            },
        ]);
    }

    public static function disclaimer_text() {
        return sprintf(
            'Weekly repayment is an estimate only, based on a %d month term at %s%% p.a. fixed, a $%s deposit and $%s in establishment fees, on approved credit. Lending criteria, terms and conditions apply. The actual amount will depend on your circumstances.',
            (int) SM_Import_Settings::get('weekly_term_months'),
            rtrim(rtrim(number_format((float) SM_Import_Settings::get('weekly_rate'), 2), '0'), '.'),
            number_format((float) SM_Import_Settings::get('weekly_deposit')),
            number_format((float) SM_Import_Settings::get('weekly_fees'))
        );
    }
}

SM_Import_Admin::init();
