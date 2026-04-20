<?php

namespace CodeConfig\IDB\Utils;

use CodeConfig\IDB\App\API\Files as APIFiles;
use CodeConfig\IDB\App\App;
use CodeConfig\IDB\Models\Files;
use CodeConfig\IDB\Models\Notices;
use CodeConfig\IDB\Models\Shortcode;

use function in_array;
use function intval;
use function is_array;
use function sprintf;

defined('ABSPATH') || exit();

class Helpers
{
    /**
     * Deactivates the plugin and displays an error message.
     *
     * This method deactivates the plugin and terminates execution with a
     * specified error message. It is typically used when plugin activation
     * fails, providing the user with a link to return to the Plugins page.
     *
     * @param string $message The error message to display to the user.
     */
    public static function deactivateAndNotify($message)
    {
        deactivate_plugins(plugin_basename(CCPIDB_FILE));
        wp_die(
            sprintf(
                '<p>%s</p><p><a href="%s">%s</a></p>',
                esc_html($message),
                esc_url(admin_url('plugins.php')),
                esc_html__('Return to the Plugins page', 'integrate-dropbox')
            ),
            esc_html__('Plugin Activation Failed', 'integrate-dropbox'),
            ['back_link' => true]
        );
    }

    public static function checkPluginRequirements()
    {
        if (version_compare(get_bloginfo('version'), CCPIDB_WP_VERSION, '<')) {
            self::deactivateAndNotify(__('WordPress version ', 'integrate-dropbox') . CCPIDB_WP_VERSION . __(' or higher is required.', 'integrate-dropbox'));
        }

        if (version_compare(PHP_VERSION, CCPIDB_PHP_VERSION, '<')) {
            self::deactivateAndNotify(__('PHP version ', 'integrate-dropbox') . CCPIDB_PHP_VERSION . __(' or higher is required.', 'integrate-dropbox'));
        }
    }

    public static function getVersion()
    {
        return CCPIDB_VERSION;
    }

    public static function getPluginName()
    {
        return CCPIDB_NAME;
    }

    public static function getPluginSlug()
    {
        return 'integrate-dropbox';
    }

    public static function getPluginFile()
    {
        return CCPIDB_FILE;
    }

    public static function getPluginPath()
    {
        return CCPIDB_PATH;
    }

    public static function getPluginUrl()
    {
        return CCPIDB_URL;
    }

    public static function getPluginTextDomain()
    {
        return 'integrate-dropbox';
    }

    public static function getPluginTextDomainPath()
    {
        return dirname(plugin_basename(CCPIDB_FILE)) . '/languages/';
    }

    public static function getInstalledVersion()
    {
        /**
         * New option name: ccpidb_version
         *
         * @since 1.3.0
         */
        $version = get_option('ccpidb_version', false);
        if ($version === false) {

            /**
             * Old option name: integrate_dropbox_version
             *
             * @since 1.0.0
             * @deprecated 1.3.0
             */
            $version = get_option('integrate_dropbox_version', false);
        }
        if ($version === false) {

            /**
             * Old option name: indbox_version
             *
             * @since 1.0.0
             * @deprecated 1.3.0
             */
            $version = get_option('indbox_version', false);
        }

        return $version;
    }

    public static function getInstallTime()
    {
        return get_option('ccpidb_install_time');
    }

    public static function redirectUrl()
    {
        $defaultRedirectUrl = admin_url('admin-ajax.php?action=indbox_authorization');

        $redirectUrl = get_option('ccpidb-redirect-url', $defaultRedirectUrl);

        return apply_filters('ccpidb_redirect_url', $redirectUrl);
    }

    public static function getSettings(array $keys = [])
    {
        $savedSettings = get_option(CCPIDB_OPTIONS_NAME);

        $defaultSettings = ccpidbGetDefaultSettings();
        $userAccess      = ccpidbGetCurrentUserAccess();

        $mediaLibraryFolders = $savedSettings['integrations']['mediaLibrary']['folders'] ?? $defaultSettings['integrations']['mediaLibrary']['folders'] ?? [];

        if (!empty($userAccess['folders']) && is_array($userAccess['folders']) && !empty($userAccess['pages']) && is_array($userAccess['pages']) && in_array('media_library', $userAccess['pages'], true) && !empty($mediaLibraryFolders) && is_array($mediaLibraryFolders)) {
            $userAccessFolder    = $userAccess['folders'];
            $mediaLibraryFolders = array_filter($mediaLibraryFolders, function ($folder) use ($userAccessFolder) {
                return in_array($folder, $userAccessFolder, true);
            });
            $savedSettings['integrations']['mediaLibrary']['folders'] = array_values(array_unique($mediaLibraryFolders));
        }

        $settings = wp_parse_args($savedSettings, $defaultSettings);

        if (empty($keys)) {
            return $settings;
        }

        $filteredSettings = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $settings)) {
                $filteredSettings[$key] = $settings[$key];
            }
        }

        return $filteredSettings;
    }

    public static function getSetting($key = null, $defaultValue = null)
    {
        $settings = self::getSettings();

        if ($key === null) {
            return $settings;
        }

        if (strpos($key, '.') !== false) {
            $keys  = explode('.', $key);
            $value = $settings;

            foreach ($keys as $innerKey) {
                if (!is_array($value) || !array_key_exists($innerKey, $value)) {
                    return $defaultValue;
                }
                $value = $value[$innerKey];
            }

            return $value;
        }

        return $settings[$key] ?? $defaultValue;
    }

    public static function updateSetting($key, $value)
    {
        $settings = self::getSettings();

        if (strpos($key, '.') !== false) {
            $keys   = explode('.', $key);
            $temp   = &$settings;

            foreach ($keys as $innerKey) {
                if (!is_array($temp)) {
                    $temp = [];
                }
                if (!array_key_exists($innerKey, $temp)) {
                    $temp[$innerKey] = [];
                }
                $temp = &$temp[$innerKey];
            }

            $temp = $value;
        } else {
            $settings[$key] = $value;
        }

        return self::updateSettings($settings);
    }

    public static function updateSettings($data)
    {
        return update_option(CCPIDB_OPTIONS_NAME, $data);
    }

    /**
     * Recursively applies a callback function to a given data structure.
     *
     * @param mixed $data The data structure to process.
     * @param string $callback The callback function to apply. Defaults to 'sanitize_text_field'.
     * @param array $options An array of options to customize the processing.
     *
     * Options:
     *
     * - `process_objects`: Whether to process object properties. Defaults to false.
     * - `process_nulls`: Whether to apply callback to null values. Defaults to false.
     * - `process_booleans`: Whether to apply callback to boolean values. Defaults to false.
     * - `process_numbers`: Whether to apply callback to numeric values. Defaults to false.
     * - `preserve_keys`: Whether to preserve array keys. Defaults to true.
     * - `max_depth`: Maximum recursion depth. Defaults to 100.
     * - `skip_types`: Array of types to skip processing.
     * - `only_types`: Array of types to only process (if specified).
     * - `key_callback`: Optional callback for array keys.
     * - `filter_callback`: Optional filter callback to determine if item should be processed.
     *
     * @return mixed The processed data structure.
     *
     * @throws \InvalidArgumentException If the callback is not a valid callable function.
     */
    public static function recursiveMap($data, $callback = 'sanitize_text_field', $options = [])
    {
        // Default options
        $defaultOptions = [
            'process_objects'  => false,        // Whether to process object properties
            'process_nulls'    => false,          // Whether to apply callback to null values
            'process_booleans' => false,       // Whether to apply callback to boolean values
            'process_numbers'  => false,        // Whether to apply callback to numeric values
            'preserve_keys'    => true,           // Whether to preserve array keys
            'max_depth'        => 100,               // Maximum recursion depth
            'skip_types'       => [],               // Array of types to skip processing
            'only_types'       => [],               // Array of types to only process (if specified)
            'key_callback'     => null,           // Optional callback for array keys
            'filter_callback'  => null,        // Optional filter callback to determine if item should be processed
            'skip'             => []
        ];

        $options = wp_parse_args($options, $defaultOptions);

        // Validate callback
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Callback must be a valid callable function');
        }

        // Internal recursive function with depth tracking
        $processItem = function ($item, $currentDepth = 0) use (&$processItem, $callback, $options) {
            if ($currentDepth >= $options['max_depth']) {
                return $item;
            }

            $itemType = gettype($item);

            foreach ($options['skip'] as $skipWord) {
                if (is_string($item) && stripos($item, $skipWord) !== false) {
                    return $item;
                }
            }

            // Check if type should be skipped
            if (!empty($options['skip_types']) && in_array($itemType, $options['skip_types'])) {
                return $item;
            }

            if (!empty($options['only_types']) && !in_array($itemType, $options['only_types'])) {
                return $item;
            }

            if ($options['filter_callback'] !== null && is_callable($options['filter_callback'])) {
                if (!call_user_func($options['filter_callback'], $item, $itemType, $currentDepth)) {
                    return $item;
                }
            }

            switch ($itemType) {
                case 'array':
                    $result = [];
                    foreach ($item as $key => $value) {
                        $processedKey = ($options['key_callback'] !== null && is_callable($options['key_callback']))
                            ? call_user_func($options['key_callback'], $key)
                            : $key;

                        $processedValue = $processItem($value, $currentDepth + 1);

                        if ($options['preserve_keys']) {
                            $result[$processedKey] = $processedValue;
                        } else {
                            $result[] = $processedValue;
                        }
                    }

                    return $result;

                case 'object':
                    if (!$options['process_objects']) {
                        return $item;
                    }

                    $result = clone $item;
                    foreach (get_object_vars($result) as $property => $value) {
                        $result->$property = $processItem($value, $currentDepth + 1);
                    }

                    return $result;

                case 'NULL':
                    return $options['process_nulls'] ? call_user_func($callback, $item) : $item;

                case 'boolean':
                    return $options['process_booleans'] ? call_user_func($callback, $item) : $item;

                case 'integer':
                case 'double':
                    return $options['process_numbers'] ? call_user_func($callback, $item) : $item;

                case 'string':
                    return call_user_func($callback, $item);

                case 'resource':
                case 'resource (closed)':
                    return $item; // Never process resources

                default:
                    return call_user_func($callback, $item);
            }
        };

        return $processItem($data);
    }

    /**
     * Format a timestamp according to the current locale and timezone.
     *
     * @param int $timestamp
     * @param bool $isShort
     * @return string
     */
    public static function formatDateTime($timestamp, $isShort = true)
    {
        $localTime = get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp));
        $now       = time();

        if (!$isShort) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($localTime));
        }

        if ($timestamp > ($now - 86400)) {
            return date_i18n(get_option('time_format'), strtotime($localTime));
        } elseif ($timestamp > strtotime('first day of january this year')) {
            return date_i18n(str_replace([', Y', ',Y', 'Y-', '-Y', '/Y', 'Y/', ' Y'], '', get_option('date_format')), strtotime($localTime));
        } else {
            return date_i18n(get_option('date_format'), strtotime($localTime));
        }
    }

    public static function getPathinfo($path)
    {
        $ret = [
            'dirname'   => '',
            'basename'  => '',
            'extension' => '',
            'filename'  => '',
        ];

        if (empty($path)) {
            return $ret;
        }

        preg_match(
            '%^(.*?)[\\\\/]*(([^/\\\\]*?)(\.([^\.\\\\/]+?)|))[\\\\/\.]*$%im',
            $path,
            $m
        );

        $ret['dirname']   = empty($m[1]) ? '/' : $m[1];
        $ret['basename']  = $m[2] ?? '';
        $ret['filename']  = $m[3] ?? '';
        $ret['extension'] = $m[5] ?? '';

        // Handle case: path ends with a dot like "file."
        if (substr($path, -1) === '.') {
            $ret['basename'] .= '.';
            $ret['extension'] = '';
        }

        return $ret;
    }

    public static function encode($input, $key = null, $prefix = 'IDB')
    {

        if (self::isEncoded($input, $prefix)) {
            return $input;
        }

        if (null === $key) {
            $key = get_option('ccpidb_encryption_key', 'ccpIdb');
        }

        $base64Encoded = base64_encode($input);

        $keyLength  = strlen($key);
        $xorEncoded = '';
        for ($i = 0, $len = strlen($base64Encoded); $i < $len; $i++) {
            $xorEncoded .= chr(ord($base64Encoded[$i]) ^ ord($key[$i % $keyLength]));
        }

        $hexEncoded = bin2hex($xorEncoded);

        return "CCP{$prefix}{$hexEncoded}";
    }

    public static function isEncoded($input, $prefix = 'IDB')
    {
        $prefix       = "CCP{$prefix}";
        $prefixLength = strlen($prefix);

        return substr($input, 0, $prefixLength) === $prefix;
    }

    public static function decode($input, $key = null, $prefix = "IDB")
    {
        if (null === $key) {
            $key = get_option('ccpidb_encryption_key', 'ccpIdb');
        }

        $prefix       = "CCP{$prefix}";
        $prefixLength = strlen($prefix);

        if (substr($input, 0, $prefixLength) === $prefix) {
            $input = substr($input, $prefixLength);
        } else {
            return false;
        }

        $xorEncoded = hex2bin($input);
        if ($xorEncoded === false) {
            return false;
        }

        $keyLength     = strlen($key);
        $base64Encoded = '';
        for ($i = 0, $len = strlen($xorEncoded); $i < $len; $i++) {
            $base64Encoded .= chr(ord($xorEncoded[$i]) ^ ord($key[$i % $keyLength]));
        }

        $decoded = base64_decode($base64Encoded);

        return $decoded;
    }

    public static function duplicateItems(array $input)
    {
        if (empty($input) || !is_array($input)) {
            return [];
        }

        return array_unique(array_diff_assoc($input, array_unique($input)));
    }

    private static function sanitize_nested_array($data)
    {
        $sanitize_data = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitize_data[$key] = sanitize_text_field(wp_unslash($value));
            } elseif (is_array($value)) {
                $sanitize_data[$key] = self::sanitize_nested_array($value);
            }
        }

        return $sanitize_data;
    }

    public static function sanitization($data)
    {
        $sanitize_data = '';

        if (is_array($data)) {

            $sanitize_data = self::sanitize_nested_array($data);
        } elseif (is_string($data)) {

            $sanitize_data = sanitize_text_field(wp_unslash($data));
        }

        return $sanitize_data;
    }

    public static function validateShortcodeKey($shortcodeId, $fileKey)
    {
        $allowedFileKeys = Shortcode::getInstance()->getShortcode($shortcodeId, "data.source.fileKeys");

        if (is_wp_error($allowedFileKeys) || empty($allowedFileKeys)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => 'Shortcode file keys not found',
                'description' => "No file keys found for shortcode ID: {$shortcodeId}"
            ]);

            return false;
        }

        $filteredKeys = [];
        foreach ($allowedFileKeys as $item) {
            if (isset($item['fileKey'])) {
                $filteredKeys[] = $item['fileKey'];
            }
        }

        if (empty($filteredKeys)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => 'Shortcode file keys empty',
                'description' => "No valid file keys found for shortcode ID: {$shortcodeId}"
            ]);

            return false;
        }

        if (empty($fileKey) || $fileKey === '/') {
            return $filteredKeys;
        }

        $validate = self::validateFileKey($fileKey, $filteredKeys);
        if (is_wp_error($validate) || $validate === false) {
            return false;
        }

        return $fileKey;

    }

    public static function validateFileKey($targetFileKey, $allowedKeys)
    {
        if (in_array($targetFileKey, $allowedKeys, true)) {
            return true;
        }

        $breadcrumbTrail = Files::getInstance()->getBreadcrumbByKey($targetFileKey);

        if (empty($breadcrumbTrail)) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => 'File key not found',
                'description' => "No breadcrumb found for file key: {$targetFileKey}"
            ]);

            return false;
        }

        $breadcrumbKeys = array_column($breadcrumbTrail, 'fileKey');
        $checkedParents = [];

        foreach ($breadcrumbKeys as $parentKey) {
            $checkedParents[] = $parentKey;

            if (in_array($parentKey, $allowedKeys, true)) {
                return array_filter($breadcrumbTrail, fn ($crumb) => in_array($crumb['fileKey'], $checkedParents, true));
            }
        }

        return false;
    }

    /**
     * Summary of defaultIcon
     * @param string $mimetype
     * @param string $size Allowed values: '32x32', '128x128', '256x256'
     * @param bool $is_dir
     * @return string
     */
    public static function defaultIcon($mimetypeOrExtension = 'word', $size = '32x32', $is_dir = false)
    {
        $allowedSizes = ['32x32',  '128x128', '256x256'];

        if (!in_array($size, $allowedSizes)) {
            $size = '32x32';
        }

        $mimetype = $mimetypeOrExtension;

        if (false === strpos($mimetype ?? '', '/')) {
            $mimetype = MimeTypeManager::getMimeType($mimetypeOrExtension);
        }

        $icon = 'unknown';

        if ($is_dir || $mimetype === 'folder') {
            $icon = 'folder';
        } elseif (empty($mimetype)) {
            $icon = 'unknown';
        } elseif (false !== strpos($mimetype, 'word')) {
            $icon = 'application-msword';
        } elseif (false !== strpos($mimetype, 'excel') || false !== strpos($mimetype, 'spreadsheet')) {
            $icon = 'application-vnd.ms-excel';
        } elseif (false !== strpos($mimetype, 'powerpoint') || false !== strpos($mimetype, 'presentation')) {
            $icon = 'application-vnd.ms-powerpoint';
        } elseif (false !== strpos($mimetype, 'access') || false !== strpos($mimetype, 'mdb')) {
            $icon = 'application-vnd.ms-access';
        } elseif (
            false !== strpos($mimetype, 'photoshop')
            || 'application/psd' === $mimetype
            || 'image/psd'       === $mimetype
        ) {
            $icon = 'application-x-photoshop';
        } elseif (
            false    !== strpos($mimetype, 'illustrator')
            || false !== strpos($mimetype, 'postscript')
            || false !== strpos($mimetype, 'svg')
        ) {
            $icon = 'vector';
        } elseif (false !== strpos($mimetype, 'image')) {
            $icon = 'image-x-generic';
        } elseif (false !== strpos($mimetype, 'audio')) {
            $icon = 'audio-x-generic';
        } elseif (false !== strpos($mimetype, 'video')) {
            $icon = 'video-x-generic';
        } elseif (false !== strpos($mimetype, 'pdf')) {
            $icon = 'application-pdf';
        } elseif (
            false    !== strpos($mimetype, 'zip')
            || false !== strpos($mimetype, 'archive')
            || false !== strpos($mimetype, 'tar')
            || false !== strpos($mimetype, 'compressed')
        ) {
            $icon = 'application-zip';
        } elseif (
            false    !== strpos($mimetype, 'html')
            || false !== strpos($mimetype, 'application/x-httpd-php')
            || false !== strpos($mimetype, 'application/javascript')
        ) {
            $icon = 'text-xml';
        } elseif (
            false    !== strpos($mimetype, 'application/exe')
            || false !== strpos($mimetype, 'application/x-msdownload')
            || false !== strpos($mimetype, 'application/x-exe')
            || false !== strpos($mimetype, 'application/x-winexe')
            || false !== strpos($mimetype, 'application/msdos-windows')
            || false !== strpos($mimetype, 'application/x-executable')
        ) {
            $icon = 'application-x-executable';
        } elseif (
            false    !== strpos($mimetype, 'url')
            || false !== strpos($mimetype, 'shortcut')
        ) {
            $icon = 'shortcut';
        } elseif (false !== strpos($mimetype, 'text')) {
            $icon = 'text-x-generic';
        }

        return esc_url(CCPIDB_ASSETS . "/icons/{$size}/{$icon}.png");
    }

    public static function hasShortcodePermission($shortcodeId, $action, $key = null)
    {

        if (empty($shortcodeId) || empty($action)) {
            return false;
        }

        $type = Shortcode::getInstance()->getShortcode($shortcodeId, "type");

        if (!empty($key) && '/' !== $key) {
            $fileKey_thumbnailKey = Shortcode::getInstance()->getShortcode($shortcodeId, "data.source.fileKeys");
            if (is_wp_error($fileKey_thumbnailKey) || empty($fileKey_thumbnailKey)) {
                return false;
            }

            $fileKeys      = [];
            foreach ($fileKey_thumbnailKey as $item) {
                if (isset($item['fileKey'])) {
                    if ($action === 'thumbnail' && isset($item['thumbnailKey']) && $item['thumbnailKey'] === $key) {
                        return true;
                    }
                    $fileKeys[] = $item['fileKey'];
                }
            }

            if (!self::validateFileKey($key, $fileKeys)) {
                return false;
            }

            if ($action === 'thumbnail' || $action === 'getFolder') {
                return true;
            }
        } else {
            if (in_array($action, ['create_shared_link', 'copy', 'move', 'delete', 'rename'], true)) {
                return false;
            } elseif ($action === 'tree') {
                $permissions = Shortcode::getInstance()->getShortcode($shortcodeId, 'data.permissions');
                if (is_wp_error($permissions) || empty($permissions)) {
                    return false;
                }

                if (($permissions['copy']['enable'] ?? false) || ($permissions['move']['enable'] ?? false)) {
                    return true;
                }

                return true;
            }

            if (($action === 'newFolder' || $action === 'upload') && $type === 'file-browser') {
                $rootUpload = Shortcode::getInstance()->getShortcode($shortcodeId, "data.advanced.fileBrowser.headerOptions.root_upload");
                if (is_wp_error($rootUpload) || empty($rootUpload)) {
                    return false;
                }
            }
        }

        // Special case for previewing self type shortcodes
        if ('preview' === $action && in_array($type, ['embed-documents', 'media-player'], true)) {
            return true;
        } elseif ('upload' === $action && $type === 'file-uploader') {
            return true;
        } elseif ('search' === $action && $type === 'search-box') {
            return true;
        }

        $permission = Shortcode::getInstance()->getShortcode($shortcodeId, "data.permissions.{$action}");

        if (is_wp_error($permission) || empty($permission) || empty($permission['enable'])) {
            return false;
        }

        if (isset($permission['userAccess']) && $permission['userAccess'] === 'everyone') {
            return true;
        }

        if (! is_user_logged_in()) {
            return false;
        }

        if (empty($permission['displayFor'])) {
            return true;
        }

        $currentUser = wp_get_current_user();

        if (empty($currentUser->user_login)) {
            return false;
        }

        $userName = $currentUser->user_login;

        $displayFor = $permission['displayFor'];

        $loggedInUserType = $permission['loggedInUserType'] ?? 'users';

        if ('users' === $loggedInUserType) {
            $isPermission = in_array($userName, $displayFor, true);

            if (! $isPermission) {
                return false;
            }

            return true;
        }

        if ('roles' === $loggedInUserType) {
            $userRoles = $currentUser->roles;

            if (empty($userRoles)) {
                return false;
            }

            foreach ($userRoles as $role) {
                if (in_array($role, $displayFor, true)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    public static function shareLinkToPreviewLink($link)
    {
        $link = str_replace('/s/', '/s/raw/', $link);
        if (strpos($link, 'scl/fi/') !== false) {
            if (strpos($link, '&raw=1') === false) {
                $link .= '&raw=1';
            }
        }

        return $link;
    }

    public static function shareLinkToDownloadLink($link)
    {
        $link = str_replace(['/s/', 'dl=0'], ['/s/raw/', 'dl=1'], $link);

        return $link;
    }

    public static function getAspectRatio($width, $height)
    {
        // Return aspect ratio as "W:H" (e.g., "16:9", "4:3")
        if (!is_numeric($width) || !is_numeric($height) || $width <= 0 || $height <= 0) {
            return '';
        }
        // Calculate GCD
        $gcd = function ($a, $b) use (&$gcd) {
            return ($b == 0) ? $a : $gcd($b, $a % $b);
        };

        $divisor = $gcd($width, $height);
        $w       = intval($width / $divisor);
        $h       = intval($height / $divisor);

        return "$w:$h";
    }

    public static function generateFileMediaInfo()
    {
        // Prevent duplicate runs
        if (get_transient('ccpidb_generate_file_media_info_running')) {
            return;
        }

        // Lock for 30 minutes
        set_transient(
            'ccpidb_generate_file_media_info_running',
            time(),
            30 * MINUTE_IN_SECONDS
        );

        try {

            $scheduledKeys = get_option('ccpidb_schedule_media_info_file_keys', []);
            update_option('ccpidb_schedule_media_info_file_keys', []);

            if (empty($scheduledKeys) || !is_array($scheduledKeys)) {
                return;
            }

            $app = App::getInstance();

            // Remove duplicates
            $scheduledKeys = array_values(array_unique($scheduledKeys));

            // Process in batches (important)
            $batchSize   = 50;
            $currentKeys = array_slice($scheduledKeys, 0, $batchSize);
            $remaining   = array_slice($scheduledKeys, $batchSize);

            $filesData = $app->getFilesByKeys($currentKeys, [
                'returnType' => 'array',
                'from'       => 'cache',
                'page'       => 1,
                'recursive'  => false,
                'perPage'    => $batchSize,
            ]);

            if (is_wp_error($filesData) || empty($filesData['files'])) {
                return;
            }

            $files     = $filesData['files'];
            $firstFile = $files[0] ?? [];

            if (empty($firstFile['accountId'])) {
                return;
            }

            $accountId         = $firstFile['accountId'];
            $fileMediaMetaData = [];

            $apiFiles = new APIFiles($accountId);

            /**
             * --------------------------------
             * IMAGE FILES (thumbnail based)
             * --------------------------------
             */
            $imageFiles = array_filter($files, function ($file) {
                return !empty($file['hasOwnThumbnail'])
                    && empty($file['thumbnailRatio'])
                    && !MimeTypeManager::isVideo($file['mimeType']);
            });

            if (!empty($imageFiles)) {
                $paths    = array_column($imageFiles, 'path');

                $thumbnails = $apiFiles->getThumbnails($paths, 'w2048h1536');

                if (!is_wp_error($thumbnails)) {
                    foreach ($thumbnails as $thumb) {
                        $path      = $thumb['metadata']['path_lower']          ?? '';
                        $id        = $thumb['metadata']['id']                  ?? '';
                        $binary    = base64_decode($thumb['thumbnail'] ?? '')  ?? '';

                        if (empty($binary) || strlen($binary) > 10 * 1024 * 1024) {
                            continue;
                        }

                        $info = @getimagesizefromstring($binary);

                        if (!$info || empty($info[0]) || empty($info[1])) {
                            continue;
                        }

                        $file = current(array_filter(
                            $files,
                            fn ($f) =>
                            ($f['path'] ?? '') === $path && ($f['fileId'] ?? '') === $id
                        ));

                        if (!$file) {
                            continue;
                        }

                        $fileMediaMetaData[] = [
                            'width'        => $info[0],
                            'height'       => $info[1],
                            'aspectRatio'  => self::getAspectRatio($info[0], $info[1]),
                            'attachmentId' => $file['metaData']['attachmentId'] ?? 0,
                            'accountId'    => $accountId,
                            'path'         => $path,
                            'id'           => $id,
                            'duration'     => 0,
                            'extension'    => '',
                        ];
                    }
                }
            }

            /**
             * --------------------------------
             * VIDEO / AUDIO FILES
             * --------------------------------
             */
            $mediaFiles = array_filter($files, function ($file) {
                return empty($file['metaData']['mediaInfo']['duration'])
                    && MimeTypeManager::isVideo($file['mimeType']);
            });

            if (!empty($mediaFiles)) {
                foreach ($mediaFiles as $mediaFile) {
                    $fileObj = $app->getFile($mediaFile['fileKey'], 'server');

                    if (!$fileObj || is_wp_error($fileObj)) {
                        continue;
                    }

                    $mediaInfo = $fileObj->getAdditionalData('mediaInfo', []);

                    $width    = $mediaInfo['dimensions']['width']  ?? 0;
                    $height   = $mediaInfo['dimensions']['height'] ?? 0;
                    $duration = $mediaInfo['duration']             ?? 0;

                    $fileMediaMetaData[] = [
                        'width'        => $width,
                        'height'       => $height,
                        'aspectRatio'  => self::getAspectRatio($width, $height),
                        'attachmentId' => $fileObj->getMetaData('attachmentId'),
                        'accountId'    => $fileObj->getAccountId(),
                        'path'         => $fileObj->getPath(),
                        'id'           => $fileObj->getId(),
                        'duration'     => $duration,
                        'extension'    => $fileObj->getExtension(),
                    ];
                }
            }

            $filterFolders = array_filter($files, function ($file) {
                return intval($file['isDir'] ?? 0) === 1;
            });

            if (!empty($filterFolders)) {
                foreach ($filterFolders as $folder) {
                    $files = $apiFiles->getFolder($folder['path'], [], 'array', false);
                }
            }

            /**
             * --------------------------------
             * SAVE METADATA
             * --------------------------------
             */
            foreach ($fileMediaMetaData as $data) {

                if (!empty($data['attachmentId'])) {
                    $meta = wp_get_attachment_metadata($data['attachmentId']);

                    if (!empty($meta)) {
                        $meta['width']  = $data['width'];
                        $meta['height'] = $data['height'];

                        if (!empty($data['duration'])) {
                            $meta['length']           = self::mileSecondsToSeconds($data['duration']);
                            $meta['length_formatted'] = self::mileSecondsToTimeFormat($data['duration']);
                            $meta['fileformat']       = $data['extension'];
                        }

                        wp_update_attachment_metadata($data['attachmentId'], $meta);
                    }
                }

                Files::getInstance()->updateFile(
                    [
                        'path'      => $data['path'],
                        'fileId'    => $data['id'],
                        'accountId' => $data['accountId'],
                    ],
                    ['thumbnailRatio' => $data['aspectRatio']],
                    ['%s'],
                    ['%s', '%s', '%s']
                );

                $mediaInfo = [];

                if (!empty($data['width']) && !empty($data['height'])) {
                    $mediaInfo['dimensions'] = [
                        'width'  => $data['width'],
                        'height' => $data['height'],
                    ];
                    $mediaInfo['aspectRatio'] = $data['aspectRatio'];
                }

                if (!empty($data['duration'])) {
                    $mediaInfo['duration']  = $data['duration'];
                }

                Files::getInstance()->updateMetaData(
                    $data['path'],
                    $data['accountId'],
                    [
                        'mediaInfo' => $mediaInfo,
                    ]
                );
            }

            /**
             * --------------------------------
             * RESCHEDULE IF NEEDED
             * --------------------------------
             */
            if (!empty($remaining)) {
                self::registerScheduledMediaInfoGeneration($remaining, 5);
            }

        } catch (\Throwable $e) {
            Notices::getInstance()->add([
                'type'        => 'error',
                'title'       => __('Error Generating Media Info', 'integrate-dropbox'),
                /* translators: %s: Error message */
                'description' => sprintf(__('An error occurred during media info generation: %s', 'integrate-dropbox'), $e->getMessage()),
            ]);
        } finally {
            delete_transient('ccpidb_generate_file_media_info_running');
        }
    }

    public static function registerScheduledMediaInfoGeneration(array $fileKeys, $delay = 5)
    {
        if (empty($fileKeys)) {
            return;
        }

        if (wp_next_scheduled('ccpidb_generate_file_media_info_event')) {
            wp_clear_scheduled_hook('ccpidb_generate_file_media_info_event');
        }

        $scheduleFileKey = 'ccpidb_schedule_media_info_file_keys';
        $getExisting     = get_option($scheduleFileKey, []);
        $updatedFileKeys = array_unique(array_merge($getExisting, $fileKeys));
        update_option($scheduleFileKey, $updatedFileKeys);

        wp_schedule_single_event(time() + $delay, 'ccpidb_generate_file_media_info_event');
    }

    public static function mileSecondsToSeconds($milliseconds)
    {
        return floor($milliseconds / 1000);
    }

    public static function mileSecondsToTimeFormat($milliseconds)
    {
        $seconds      = floor($milliseconds / 1000);
        $minutes      = floor($seconds / 60);
        $hours        = floor($minutes / 60);
        $remainingSec = $seconds % 60;
        $remainingMin = $minutes % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $remainingMin, $remainingSec);
        }

        return sprintf('%02d:%02d', $remainingMin, $remainingSec);
    }
}
