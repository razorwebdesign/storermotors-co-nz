<?php
/**
 * Feed package handling: validate, extract, unwrap the nested images ZIP.
 *
 * The Motorcentral outer ZIP holds three entries:
 *   SML.XML          the vehicle data
 *   SML-NN-Data.ZIP  a nested ZIP of {StockNo}_{n}.jpg photos
 *   END.XML          the transfer-complete sentinel
 *
 * Both archives are flat, so any entry name containing a path separator is
 * treated as hostile and aborts the run.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Package {

    /** Flat, boring filenames only. No slashes, no dot-dot, no absolute paths. */
    const SAFE_ENTRY = '/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,120}$/';

    const MAX_ENTRIES     = 2000;
    const MAX_UNCOMPRESSED = 524288000; // 500 MB
    const IMAGE_PATTERN   = '/^(\d+)_(\d+)\.jpe?g$/i';

    /**
     * Extract a feed ZIP into a fresh run directory.
     *
     * @return array|WP_Error {
     *     @type string $run_dir  Absolute path to the run directory.
     *     @type string $xml      Absolute path to the outer SML.XML.
     *     @type array  $images   [ "{stock}_{n}" => ['path'=>…,'stock'=>…,'n'=>…,'crc'=>…] ]
     *     @type bool   $sentinel Whether END.XML was present.
     * }
     */
    public static function extract($zip_path) {
        if (!file_exists($zip_path)) {
            return new WP_Error('sm_import_missing', sprintf('Feed archive not found: %s', $zip_path));
        }

        $verified = self::verify($zip_path);
        if (is_wp_error($verified)) {
            return $verified;
        }

        $free = @disk_free_space(SM_Import_Settings::staging_dir());
        if ($free !== false && $free < filesize($zip_path) * 4) {
            return new WP_Error('sm_import_disk', sprintf(
                'Insufficient disk space: %s free, need roughly %s.',
                size_format((float) $free), size_format(filesize($zip_path) * 4)
            ));
        }

        $run_dir = self::new_run_dir();
        $outer   = $run_dir . '/outer';
        $images  = $run_dir . '/images';
        wp_mkdir_p($outer);
        wp_mkdir_p($images);

        $unzipped = self::unzip($zip_path, $outer);
        if (is_wp_error($unzipped)) {
            self::cleanup($run_dir);
            return $unzipped;
        }

        // Data always comes from the outer XML — the nested ZIP carries a
        // duplicate copy that we deliberately ignore.
        $xml = self::find_file($outer, '/^SML\.XML$/i');
        if (!$xml) {
            self::cleanup($run_dir);
            return new WP_Error('sm_import_no_xml', 'Feed archive contains no SML.XML.');
        }

        $sentinel = (bool) self::find_file($outer, '/^END\.XML$/i');

        // The inner ZIP name embeds a sequence number (SML-06-Data.ZIP), so glob
        // for it rather than hard-coding the current one.
        $inner = self::find_file($outer, '/\.zip$/i');
        $image_map = [];

        if ($inner) {
            $crcs = self::crc_index($inner);
            if (is_wp_error($crcs)) {
                self::cleanup($run_dir);
                return $crcs;
            }

            $unzipped = self::unzip($inner, $images);
            if (is_wp_error($unzipped)) {
                self::cleanup($run_dir);
                return $unzipped;
            }

            // Freeing the archives here roughly halves peak disk usage.
            unlink($inner);

            $image_map = self::index_images($images, $crcs);
        } else {
            SM_Import_Log::warn('Feed archive contains no nested images ZIP — importing data only.');
        }

        return [
            'run_dir'  => $run_dir,
            'xml'      => $xml,
            'images'   => $image_map,
            'sentinel' => $sentinel,
        ];
    }

    /**
     * Confirm the archive is complete and internally consistent. A truncated
     * download must never reach the reconciliation stage.
     */
    public static function verify($zip_path) {
        if (!class_exists('ZipArchive')) {
            return true; // PclZip fallback path; unzip_file() will validate.
        }

        $zip  = new ZipArchive();
        $open = $zip->open($zip_path, ZipArchive::CHECKCONS);
        if ($open !== true) {
            return new WP_Error('sm_import_corrupt', sprintf(
                'Feed archive failed consistency check (ZipArchive code %s): %s',
                $open, basename($zip_path)
            ));
        }

        $entries = $zip->numFiles;
        $total   = 0;
        for ($i = 0; $i < $entries; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            $total += (int) $stat['size'];

            $name = $stat['name'];
            if (!preg_match(self::SAFE_ENTRY, $name)) {
                $zip->close();
                return new WP_Error('sm_import_unsafe_entry', sprintf(
                    'Refusing to extract: archive entry "%s" is not a plain filename.', $name
                ));
            }
        }
        $zip->close();

        if ($entries > self::MAX_ENTRIES) {
            return new WP_Error('sm_import_too_many', sprintf('Archive holds %d entries, limit is %d.', $entries, self::MAX_ENTRIES));
        }
        if ($total > self::MAX_UNCOMPRESSED) {
            return new WP_Error('sm_import_too_big', sprintf('Archive expands to %s, limit is %s.', size_format($total), size_format(self::MAX_UNCOMPRESSED)));
        }

        return true;
    }

    /**
     * Map entry name => CRC32, read from the central directory without
     * decompressing anything. Used to detect rephotographed vehicles.
     */
    public static function crc_index($zip_path) {
        $index = [];

        if (!class_exists('ZipArchive')) {
            return $index; // Dedupe degrades to filename-only.
        }

        $zip  = new ZipArchive();
        $open = $zip->open($zip_path, ZipArchive::CHECKCONS);
        if ($open !== true) {
            return new WP_Error('sm_import_corrupt_inner', sprintf(
                'Nested images archive failed consistency check (code %s).', $open
            ));
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            if (!preg_match(self::SAFE_ENTRY, $stat['name'])) {
                $zip->close();
                return new WP_Error('sm_import_unsafe_entry', sprintf(
                    'Refusing to extract: nested archive entry "%s" is not a plain filename.', $stat['name']
                ));
            }
            $index[strtolower($stat['name'])] = sprintf('%08x', $stat['crc']);
        }
        $zip->close();

        return $index;
    }

    /**
     * Build the image work list. Only {digits}_{digits}.jpg qualifies, which
     * excludes Thumbs.db and the duplicate SML.XML by construction.
     */
    private static function index_images($dir, array $crcs) {
        $map = [];

        foreach ((array) scandir($dir) as $name) {
            if (!preg_match(self::IMAGE_PATTERN, $name, $m)) {
                continue;
            }

            $stock = $m[1];
            $n     = (int) $m[2];
            $key   = $stock . '_' . $n;

            $map[$key] = [
                'path'  => $dir . '/' . $name,
                'name'  => $name,
                'stock' => $stock,
                'n'     => $n,
                'crc'   => $crcs[strtolower($name)] ?? '',
            ];
        }

        return $map;
    }

    /**
     * Extract via unzip_file(), which prefers ZipArchive, falls back to PclZip,
     * and performs its own free-space check.
     */
    private static function unzip($zip_path, $destination) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // The staging directory is ours; go straight to the filesystem rather
        // than prompting for FTP credentials.
        $force_direct = function () { return 'direct'; };
        add_filter('filesystem_method', $force_direct, 999);

        WP_Filesystem();
        $result = unzip_file($zip_path, $destination);

        remove_filter('filesystem_method', $force_direct, 999);

        if (is_wp_error($result)) {
            return new WP_Error('sm_import_unzip', sprintf(
                'Could not extract %s: %s', basename($zip_path), $result->get_error_message()
            ));
        }

        return true;
    }

    private static function find_file($dir, $pattern) {
        foreach ((array) scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match($pattern, $name)) {
                return $dir . '/' . $name;
            }
        }
        return null;
    }

    private static function new_run_dir() {
        $dir = SM_Import_Settings::staging_dir() . '/run-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        wp_mkdir_p($dir);
        return $dir;
    }

    /** Recursively remove a run directory. */
    public static function cleanup($dir) {
        $staging = SM_Import_Settings::staging_dir();

        // Never delete outside the staging root.
        if (!$dir || strpos(wp_normalize_path($dir), wp_normalize_path($staging) . '/run-') !== 0) {
            return false;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        return rmdir($dir);
    }

    /** Remove abandoned run directories. Called by the daily GC cron. */
    public static function gc($max_age = DAY_IN_SECONDS) {
        $cutoff = time() - $max_age;
        foreach ((array) glob(SM_Import_Settings::staging_dir() . '/run-*', GLOB_ONLYDIR) as $dir) {
            if (filemtime($dir) < $cutoff) {
                self::cleanup($dir);
            }
        }
    }
}
