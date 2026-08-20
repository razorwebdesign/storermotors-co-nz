<?php
/**
 * Plugin Name: Storer Motors Vehicle Feed Importer
 * Description: Pulls the Motorcentral/SML vehicle feed from FTP and syncs it into the `vehicle` custom post type.
 *
 * MU-plugins are not loaded recursively, so this stub requires the class files.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SM_IMPORT_VERSION', '1.0.0');
define('SM_IMPORT_DIR', __DIR__ . '/storermotors-importer');

foreach ([
    'class-sm-import-settings.php',
    'class-sm-import-log.php',
    'class-sm-import-package.php',
    'class-sm-import-parser.php',
    'class-sm-import-vehicles.php',
    'class-sm-import-media.php',
    'class-sm-import-ftp.php',
    'class-sm-import-runner.php',
    'class-sm-import-admin.php',
] as $sm_import_file) {
    require_once SM_IMPORT_DIR . '/' . $sm_import_file;
}
unset($sm_import_file);
