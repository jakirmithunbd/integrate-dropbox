<?php

defined('ABSPATH') or exit('Direct access to this file is not allowed.');

/**
 * Plugin version information
 */
define('CCPIDB_DB_VERSION', '1.0.0');
define('CCPIDB_OPTIONS_VERSION', '1.0.0');
define('CCPIDB_VERSION', '1.3.10');
define('CCPIDB_PLUGIN_BASE', plugin_basename(CCPIDB_FILE));

/**
 * Plugin URLs
 */
define('CCPIDB_URL', plugin_dir_url(CCPIDB_FILE));
define('CCPIDB_ASSETS', CCPIDB_URL . 'assets');
// define('CCPIDB_ASSETS);
define('CCPIDB_PLUGIN_URL', 'https://codeconfig.dev/integrate-dropbox/');
define('CCPIDB_INCLUDES_URL', CCPIDB_URL . 'includes');
define('CCPIDB_INTEGRATIONS_URL', CCPIDB_INCLUDES_URL . '/Integrations');
define('CCPIDB_BLOCKS_URL', CCPIDB_INTEGRATIONS_URL . '/Blocks');


/**
 * Plugin directory paths
 */
define('CCPIDB_PATH', plugin_dir_path(CCPIDB_FILE));
define('CCPIDB_APP', CCPIDB_PATH . 'app');
define('CCPIDB_INCLUDES', CCPIDB_PATH . 'includes');
define('CCPIDB_INTEGRATIONS', CCPIDB_INCLUDES . '/Integrations');
define('CCPIDB_MODELS', CCPIDB_PATH . 'models');
define('CCPIDB_UPDATES', CCPIDB_INCLUDES . '/Updates');
define('CCPIDB_VENDORS', CCPIDB_PATH . 'vendors');


/**
 * Plugin author information
 */
define('CCPIDB_AUTHOR', 'CodeConfig');
define('CCPIDB_AUTHOR_URL', 'https://codeconfig.dev/');

/**
 * Plugin capabilities and access
 */
define('CCPIDB_ACCESS_CAP', 'manage_ccpidb_files');

/**
 * Plugin localization
 */
define('CCPIDB_TEXTDOMAIN', 'integrate-dropbox');
define('CCPIDB_TEXTDOMAIN_PATH', dirname(plugin_basename(CCPIDB_FILE)) . '/languages/');

/**
 * Plugin naming and slug
 */
define('CCPIDB_NAME', 'Integrate Dropbox');
define('CCPIDB_OPTIONS_NAME', 'ccpidb_settings');
define('CCPIDB_SLUG', CCPIDB_TEXTDOMAIN);

/**
 * Plugin minimum requirements
 */
define('CCPIDB_PHP_VERSION', '7.4');
define('CCPIDB_WP_VERSION', '6.2');

/**
 * Plugin database
 */
define('CCPIDB_DB_PREFIX', 'ccpidb_');

/**
 * Chunk size for file uploads (5 MB)
 */
define('CCPIDB_CHUNK_SIZE', 5 * 1024 * 1024);

define('CCPIDB_UNSET', '[unset]');

/**
 * Plugin documentation URL
 */
define('CCPIDB_DOCUMENTATION_URL', 'https://codeconfig.dev/docs-category/integrate-dropbox/');
