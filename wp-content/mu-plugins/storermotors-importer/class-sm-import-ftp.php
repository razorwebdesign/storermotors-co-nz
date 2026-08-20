<?php
/**
 * Remote feed retrieval over FTP/FTPS/SFTP.
 *
 * Uses cURL rather than ext/ftp (frequently not compiled on shared hosts) or
 * WP_Filesystem_FTPext (buffers whole files into memory — a 23 MB archive as a
 * PHP string is not acceptable). CURLOPT_FILE streams to disk at constant
 * memory regardless of feed size.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Import_FTP {

    const SENTINEL = 'END.XML';

    /**
     * Check the server and, when a new feed is waiting, download it.
     *
     * @param bool $force Ignore the unchanged-feed fingerprint.
     * @return array|null|WP_Error  null when there is nothing new to do.
     */
    public static function poll($force = false) {
        $listing = self::listing();
        if (is_wp_error($listing)) {
            return $listing;
        }

        // The vendor writes END.XML once the transfer completes. Honour that
        // contract rather than guessing from timestamps.
        if (!self::has_sentinel($listing)) {
            SM_Import_Log::info('Feed is not ready — END.XML has not been written yet.');
            return null;
        }

        $archive = self::newest_archive($listing);
        if (!$archive) {
            SM_Import_Log::warn('END.XML is present but no ZIP archive was found alongside it.');
            return null;
        }

        $fingerprint = md5($archive['name'] . '|' . $archive['size'] . '|' . $archive['mtime']);
        if (!$force && $fingerprint === get_option(SM_Import_Runner::FINGERPRINT_OPTION, '')) {
            SM_Import_Log::info(sprintf('Feed "%s" is unchanged since the last run.', $archive['name']));
            return null;
        }

        // A server that writes the sentinel early would otherwise let us read a
        // half-uploaded archive. Confirm the size has settled.
        sleep(10);
        $recheck = self::listing();
        if (is_wp_error($recheck)) {
            return $recheck;
        }
        $again = self::newest_archive($recheck);
        if (!$again || (int) $again['size'] !== (int) $archive['size']) {
            SM_Import_Log::info('Feed archive is still being uploaded — will retry on the next run.');
            return null;
        }

        $local = self::download($archive);
        if (is_wp_error($local)) {
            return $local;
        }

        update_option(SM_Import_Runner::FINGERPRINT_OPTION, $fingerprint, false);

        // Best-effort: clear the sentinel so the vendor's next drop is
        // unambiguous. Read-only accounts simply fail here, which is fine —
        // the fingerprint is the real idempotency guarantee.
        self::delete_remote(self::SENTINEL);

        return [
            'path'        => $local,
            'name'        => $archive['name'],
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Parse a full LIST response. NLST would give names only, and the size and
     * mtime are needed for the fingerprint and the stability check.
     *
     * @return array|WP_Error [ ['name'=>…,'size'=>int,'mtime'=>string], … ]
     */
    public static function listing() {
        $url = self::base_url();
        if (is_wp_error($url)) {
            return $url;
        }

        $handle = self::handle($url);
        if (is_wp_error($handle)) {
            return $handle;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 30);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            return new WP_Error('sm_import_ftp_list', sprintf('Could not list the feed directory: %s', $error));
        }
        curl_close($handle);

        return self::parse_listing($body);
    }

    /**
     * Both common LIST dialects:
     *   -rw-r--r-- 1 user group 23484804 Aug 11 14:04 FTP Option.zip   (unix)
     *   08-11-26  02:04PM             23484804 FTP Option.zip          (dos)
     */
    private static function parse_listing($body) {
        $entries = [];

        foreach (preg_split('/\r\n|\n|\r/', (string) $body) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^[\-dl]([rwxst\-]{9})\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w{3}\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $m)) {
                $entries[] = ['name' => trim($m[4]), 'size' => (int) $m[2], 'mtime' => trim($m[3])];
                continue;
            }

            if (preg_match('/^(\d{2}-\d{2}-\d{2,4}\s+[\d:]+(?:AM|PM))\s+(?:<DIR>|(\d+))\s+(.+)$/i', $line, $m)) {
                if ($m[2] === '') {
                    continue; // directory
                }
                $entries[] = ['name' => trim($m[3]), 'size' => (int) $m[2], 'mtime' => trim($m[1])];
                continue;
            }

            // Bare name (some servers answer LIST like NLST).
            if (!preg_match('/\s/', $line) || preg_match('/\.(zip|xml)$/i', $line)) {
                $entries[] = ['name' => trim($line), 'size' => 0, 'mtime' => ''];
            }
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

        // Prefer the largest — feed archives are dominated by the photo payload,
        // and mtime formats vary too much between servers to sort on reliably.
        usort($candidates, function ($a, $b) {
            return $b['size'] <=> $a['size'];
        });

        return $candidates[0];
    }

    /** Stream a remote file to the staging directory. */
    private static function download(array $archive) {
        $url = self::base_url();
        if (is_wp_error($url)) {
            return $url;
        }

        $target = SM_Import_Settings::staging_dir() . '/incoming-' . gmdate('Ymd-His') . '.zip';
        $fp     = fopen($target, 'wb');
        if (!$fp) {
            return new WP_Error('sm_import_ftp_write', sprintf('Could not open %s for writing.', $target));
        }

        $handle = self::handle($url . rawurlencode($archive['name']));
        if (is_wp_error($handle)) {
            fclose($fp);
            unlink($target);
            return $handle;
        }

        curl_setopt($handle, CURLOPT_FILE, $fp);
        curl_setopt($handle, CURLOPT_TIMEOUT, 300);
        // Abort a connection that stalls below 1 KB/s for a minute.
        curl_setopt($handle, CURLOPT_LOW_SPEED_LIMIT, 1024);
        curl_setopt($handle, CURLOPT_LOW_SPEED_TIME, 60);

        $ok    = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($fp);

        if ($ok === false) {
            unlink($target);
            return new WP_Error('sm_import_ftp_download', sprintf('Feed download failed: %s', $error));
        }

        // A short file means a truncated transfer, which must never reach the
        // reconciliation stage.
        $written = filesize($target);
        if ($archive['size'] > 0 && $written !== (int) $archive['size']) {
            unlink($target);
            return new WP_Error('sm_import_ftp_truncated', sprintf(
                'Feed download is incomplete: expected %s, received %s.',
                size_format((int) $archive['size']), size_format((int) $written)
            ));
        }

        SM_Import_Log::info(sprintf('Downloaded %s (%s).', $archive['name'], size_format((int) $written)));

        return $target;
    }

    private static function delete_remote($filename) {
        $url = self::base_url();
        if (is_wp_error($url)) {
            return false;
        }

        $handle = self::handle($url);
        if (is_wp_error($handle)) {
            return false;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_NOBODY, true);
        curl_setopt($handle, CURLOPT_POSTQUOTE, ['DELE ' . $filename]);
        curl_setopt($handle, CURLOPT_TIMEOUT, 30);

        $ok = curl_exec($handle);
        curl_close($handle);

        return $ok !== false;
    }

    /** Configured, authenticated cURL handle. */
    private static function handle($url) {
        if (!function_exists('curl_init')) {
            return new WP_Error('sm_import_no_curl', 'PHP cURL is not available on this server, so the feed cannot be downloaded.');
        }

        $user = (string) SM_Import_Settings::get('ftp_user');
        $pass = (string) SM_Import_Settings::get('ftp_pass');

        $handle = curl_init($url);

        curl_setopt($handle, CURLOPT_USERPWD, $user . ':' . $pass);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($handle, CURLOPT_FTP_USE_EPSV, true);          // passive
        curl_setopt($handle, CURLOPT_FTP_FILEMETHOD, CURLFTPMETHOD_SINGLECWD);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_CAINFO, ABSPATH . WPINC . '/certificates/ca-bundle.crt');

        $scheme = strtolower((string) SM_Import_Settings::get('ftp_scheme'));
        if ($scheme === 'ftps') {
            // Explicit AUTH TLS, upgrading opportunistically so a plain-FTP
            // server does not hard-fail.
            curl_setopt($handle, CURLOPT_USE_SSL, CURLUSESSL_TRY);
        }

        return $handle;
    }

    private static function base_url() {
        $host = trim((string) SM_Import_Settings::get('ftp_host'));
        if ($host === '') {
            return new WP_Error('sm_import_no_host', 'No feed FTP host is configured. Set SM_FEED_FTP_HOST in wp-config.php.');
        }

        $scheme = strtolower((string) SM_Import_Settings::get('ftp_scheme'));
        if (!in_array($scheme, ['ftp', 'ftps', 'sftp'], true)) {
            $scheme = 'ftp';
        }
        // FTPS is explicit AUTH TLS over the ftp:// scheme; sftp:// is its own.
        $prefix = ($scheme === 'sftp') ? 'sftp://' : 'ftp://';

        $host = preg_replace('#^\w+://#', '', $host);
        $path = trim((string) SM_Import_Settings::get('ftp_path'));
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path .= '/';
        }

        return $prefix . $host . $path;
    }

    /**
     * Connection test for the admin screen: list the directory and report what
     * is actually there.
     */
    public static function test() {
        $listing = self::listing();
        if (is_wp_error($listing)) {
            return $listing;
        }

        return [
            'entries'  => $listing,
            'sentinel' => self::has_sentinel($listing),
            'archive'  => self::newest_archive($listing),
        ];
    }
}
