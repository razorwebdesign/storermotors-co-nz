<?php
/**
 * Local drop-folder feed retrieval.
 *
 * The vendor uploads the package onto this server over their own restricted
 * FTP account, so there is no reason to loop back out through an FTP client to
 * read a file that is already on disk. Same contract as SM_Import_FTP: a
 * readiness signal, a settled file size, and a fingerprint so an unchanged
 * package is never imported twice.
 *
 * Only the sentinel is consumed. The vendor's archive is copied, never moved or
 * deleted, so a failed run leaves the original in place to diagnose.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_Local {

    const SENTINEL = 'END.XML';

    /** An upload this recent may still be in flight when no sentinel is used. */
    const MIN_AGE = 120;

    public static function configured() {
        return self::path() !== '';
    }

    public static function path() {
        return trim((string) SM_Import_Settings::get('local_path'));
    }

    /**
     * Check the drop folder and, when a new package is waiting, stage it.
     *
     * @param bool $force Ignore the unchanged-feed fingerprint.
     * @return array|null|WP_Error  null when there is nothing new to do.
     */
    public static function poll($force = false) {
        $dir = self::dir();
        if (is_wp_error($dir)) {
            return $dir;
        }

        $listing  = self::listing($dir);
        $sentinel = self::has_sentinel($listing);
        $required = (bool) SM_Import_Settings::get('require_sentinel');

        if ($required && !$sentinel) {
            SM_Import_Log::info('Feed is not ready — END.XML has not been written to the drop folder yet.');
            return null;
        }

        $archive = self::newest_archive($listing);
        if (!$archive) {
            if ($sentinel) {
                SM_Import_Log::warn('END.XML is present but no ZIP archive was found alongside it.');
            } else {
                SM_Import_Log::info('No feed archive is waiting in the drop folder.');
            }
            return null;
        }

        // Without a sentinel the only evidence the upload finished is that the
        // file has stopped changing, so give it a moment to settle first.
        if (!$sentinel && (time() - (int) $archive['mtime']) < self::MIN_AGE) {
            SM_Import_Log::info(sprintf(
                'Feed archive %s was modified less than %d seconds ago — waiting for the upload to finish.',
                $archive['name'], self::MIN_AGE
            ));
            return null;
        }

        $fingerprint = md5($archive['name'] . '|' . $archive['size'] . '|' . $archive['mtime']);
        if (!$force && $fingerprint === get_option(SM_Import_Runner::FINGERPRINT_OPTION, '')) {
            SM_Import_Log::info(sprintf('Feed "%s" is unchanged since the last run.', $archive['name']));
            return null;
        }

        // A vendor that writes the sentinel early would otherwise let us read a
        // half-uploaded archive. Confirm the size has settled.
        sleep(10);
        clearstatcache(true, $archive['path']);
        if (!file_exists($archive['path']) || filesize($archive['path']) !== (int) $archive['size']) {
            SM_Import_Log::info('Feed archive is still being uploaded — will retry on the next run.');
            return null;
        }

        $target = SM_Import_Settings::staging_dir() . '/incoming-' . gmdate('Ymd-His') . '.zip';
        if (!copy($archive['path'], $target)) {
            return new WP_Error('sm_import_local_copy', sprintf(
                'Could not copy %s into the staging directory. Check that PHP can read the drop folder.',
                $archive['name']
            ));
        }

        // A short copy means a file that changed underneath us, which must never
        // reach the reconciliation stage.
        $written = filesize($target);
        if ($written !== (int) $archive['size']) {
            unlink($target);
            return new WP_Error('sm_import_local_truncated', sprintf(
                'Staged copy of %s is incomplete: expected %s, got %s.',
                $archive['name'], size_format((int) $archive['size']), size_format((int) $written)
            ));
        }

        update_option(SM_Import_Runner::FINGERPRINT_OPTION, $fingerprint, false);

        SM_Import_Log::info(sprintf('Staged %s (%s) from the drop folder.', $archive['name'], size_format((int) $written)));

        // Best-effort: clear the sentinel so the vendor's next drop is
        // unambiguous. A read-only folder simply fails here, which is fine —
        // the fingerprint is the real idempotency guarantee.
        if ($sentinel) {
            @unlink($dir . '/' . self::SENTINEL);
        }

        return [
            'path'        => $target,
            'name'        => $archive['name'],
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Resolve and sanity-check the configured drop folder.
     *
     * @return string|WP_Error
     */
    public static function dir() {
        $path = self::path();
        if ($path === '') {
            return new WP_Error('sm_import_no_local_path', 'No feed drop folder is configured. Set SM_FEED_LOCAL_PATH in wp-config.php.');
        }

        // A relative path would resolve against whatever cron's working
        // directory happens to be.
        if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path)) {
            return new WP_Error('sm_import_local_relative', sprintf('Feed drop folder "%s" must be an absolute path.', $path));
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return new WP_Error('sm_import_local_missing', sprintf('Feed drop folder "%s" does not exist or is not a directory.', $path));
        }
        if (!is_readable($real)) {
            return new WP_Error('sm_import_local_unreadable', sprintf(
                'Feed drop folder "%s" is not readable by PHP. Check that it belongs to the same system user as the site.', $real
            ));
        }

        // Inside the document root the archive is a public download unless the
        // web server is explicitly told otherwise. Worth saying out loud.
        if (strpos(wp_normalize_path($real) . '/', wp_normalize_path(ABSPATH)) === 0) {
            SM_Import_Log::warn(sprintf(
                'Feed drop folder %s is inside the web root — confirm the web server refuses to serve it, or move it outside the document root.', $real
            ));
        }

        return $real;
    }

    /**
     * Plain files in the drop folder, with the size and mtime the fingerprint
     * and readiness checks need.
     *
     * @return array [ ['name'=>…,'path'=>…,'size'=>int,'mtime'=>int], … ]
     */
    public static function listing($dir) {
        $entries = [];

        foreach ((array) scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            $entries[] = [
                'name'  => $name,
                'path'  => $path,
                'size'  => (int) filesize($path),
                'mtime' => (int) filemtime($path),
            ];
        }

        return $entries;
    }

    private static function has_sentinel(array $listing) {
        foreach ($listing as $entry) {
            if (strcasecmp($entry['name'], self::SENTINEL) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The most recently modified archive.
     *
     * Unlike the FTP path — where LIST mtime formats vary too much between
     * servers to sort on, so the largest file wins — filemtime() is exact here.
     * Preferring the newest matters when the vendor uses unique filenames: the
     * largest archive could be last week's, and its fingerprint would match,
     * stalling every subsequent import.
     */
    private static function newest_archive(array $listing) {
        $candidates = [];
        foreach ($listing as $entry) {
            if (preg_match('/\.zip$/i', $entry['name'])) {
                $candidates[] = $entry;
            }
        }

        if (!$candidates) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            return ($b['mtime'] <=> $a['mtime']) ?: ($b['size'] <=> $a['size']);
        });

        return $candidates[0];
    }

    /**
     * Readiness check for the admin screen: report what is actually in the
     * folder. Same shape as SM_Import_FTP::test() so the screen renders either.
     */
    public static function test() {
        $dir = self::dir();
        if (is_wp_error($dir)) {
            return $dir;
        }

        $listing = self::listing($dir);

        return [
            'entries'  => $listing,
            'sentinel' => self::has_sentinel($listing),
            'archive'  => self::newest_archive($listing),
        ];
    }
}
