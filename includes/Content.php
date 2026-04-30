<?php

namespace CodeConfig\IDB;

use CodeConfig\IDB\App\App;
use CodeConfig\IDB\Models\Files;
use CodeConfig\IDB\Shortcode\Notifications;
use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\MimeTypeManager;
use CodeConfig\IDB\Utils\Singleton;

use function count;
use function defined;
use function in_array;

defined('ABSPATH') || exit;

class Content
{
    use Singleton;

    private function doHooks()
    {
        add_filter('query_vars', [$this, 'addQueryVars']);
        add_action('template_redirect', [$this, 'redirectTemplate']);
    }

    public function addQueryVars($vars)
    {
        return array_merge($vars, [
            'ccpidb-share',
            'ccpidb-thumbnail',
            'ccpidb-action',
            'ccpidb-key',
            'ccpidb-name',
            'ccpidb-ext',
        ]);
    }

    public function redirectTemplate()
    {
        foreach ([
            'ccpidb-action'      => fn ($val) => $this->ccpidbUrl(
                $val,
                get_query_var('ccpidb-key', 'full'),
                get_query_var('ccpidb-name', 'unknown'),
                get_query_var('ccpidb-ext', 'jpg')
            ),
        ] as $queryVar => $callback) {
            $value = get_query_var($queryVar);
            if ($value) {
                $callback(sanitize_text_field(wp_unslash($value)));

                return;
            }
        }
    }

    private function ccpidbUrl($action, $key, $name, $ext)
    {

        $explodedAction  = explode('-', $action);
        $action          = reset($explodedAction);
        $shortcodeId     = $explodedAction[1] ?? null;

        if ($action === 'thumbnail') {
            $this->thumbnail($key, $name, $ext, $shortcodeId);

            exit;
        } elseif ($action === 'attachment') {
            $this->attachment($key, $name, $ext, $shortcodeId);
            exit;
        } elseif ($action === 'preview') {
            $this->preview($key, $name, $ext, $shortcodeId);

            exit;
        } elseif ($action === 'share') {
            $this->share($key, $name, $ext, $shortcodeId);

            exit;
        } elseif ($action === 'download') {
            $this->download($key, $name, $ext, $shortcodeId);

            exit;
        } else {
            wp_die('Invalid action specified.', 'Error', ['response' => 400]);
        }
    }

    public function thumbnail($key, $name, $ext, $shortcodeId = null)
    {
        $size  = 'md';
        $parts = explode('-', $name);

        $availableSizes = ccpidbGetAvailableThumbnailSizes();
        $sizeKeys       = array_map('strval', array_keys($availableSizes));

        if (count($parts) > 1) {
            $possibleSize = strtolower(end($parts));

            if (in_array($possibleSize, $sizeKeys, true)) {
                $size = $possibleSize;
                $name = strtolower(implode('-', array_slice($parts, 0, -1)));
            }
        }

        $dimension = '128x128';
        switch ($size) {
            case 'xs':
            case 'sm':
                $dimension = '32x32';
                break;
            case 'md':
            case 'lg':
            case 'xl':
                $dimension = '128x128';
                break;
            default:
                $dimension = '256x256';
                break;
        }

        if (!MimeTypeManager::isThumbnailable($ext)) {

            $defaultIcon = Helpers::defaultIcon($ext, $dimension);
            wp_safe_redirect($defaultIcon);
            exit;
        }

        if ($ext === 'folder' || $ext === 'zip') {
            $folderIcon = Helpers::defaultIcon($ext, $dimension);
            wp_safe_redirect($folderIcon);
            exit;
        }

        $allowedExtensions = ['jpeg', 'png', 'webp'];
        $ext               = in_array($ext, $allowedExtensions, true) ? $ext : 'webp';
        $isCacheable       = Helpers::getSetting('caching.imageCaching', false);

        if ($isCacheable) {
            $cache         = new Cache();

            $cachedFileRaw = $cache->getFileRaw($key, $size, $ext);
            if ($cachedFileRaw) {
                header("Cache-Control: max-age=" . MONTH_IN_SECONDS);
                header("Content-Type: " . MimeTypeManager::getMimeType($ext));
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $cachedFileRaw;
                exit;
            }
        }

        $thumbnailData = App::getInstance()->getThumbnailData($key, $size, $ext);
        if (is_wp_error($thumbnailData)) {
            $file            = App::getInstance()->getFile($key);

            $fileMimeType    = is_wp_error($file) ? 'unknown' : ($file->getMimetype() ?: $file->getExtension());

            $folderIcon = Helpers::defaultIcon($fileMimeType, $dimension);
            wp_safe_redirect($folderIcon);
            exit;
        }

        $file      = $thumbnailData['file'];
        if (is_wp_error($file)) {
            $file            = App::getInstance()->getFile($key);

            $fileMimeType    = is_wp_error($file) ? 'unknown' : ($file->getMimetype() ?: $file->getExtension());

            $folderIcon = Helpers::defaultIcon($fileMimeType, $dimension);
            wp_safe_redirect($folderIcon);
            exit;
        }
        $basename    = $file->getAdditionalData('basename');
        $cleanName   = ccpidbTitleToUrlSlug($basename);
        $decodedName = urldecode($name);

        if ($decodedName !== $cleanName) {
            wp_safe_redirect($file->getIcon());
            exit;
        }

        $this->checkPermission($shortcodeId, $key, 'thumbnail');

        $thumbnailBinaryData = $thumbnailData['fileContents'];

        if (!$thumbnailBinaryData) {
            $this->safeRedirect($this->getUnknownIcon($file->getMimetype() ?? 'image/webp'), 0);
        }

        if ($isCacheable && !empty($cache)) {
            $cache->saveFile($thumbnailData['fileContents'], $key, $size, $ext);
        }

        header("Cache-Control: max-age=" . MONTH_IN_SECONDS);
        header("Content-Type: " . MimeTypeManager::getMimeType($ext));

        // Output binary contents
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $thumbnailData['fileContents'];
        exit;
    }

    public function preview($key, $name, $ext, $shortcodeId = null)
    {
        if (MimeTypeManager::isVideo($ext) && Helpers::getSetting('advanced.secureVideoPlayback', false)) {
            $wp_referer = wp_get_referer();
            if (empty($wp_referer) || !str_contains($wp_referer, home_url())) {
                $this->denyAccess('Direct access to video preview is denied.', 'Please access the video through the provided links on your website.', 'error');
            }
        }

        $this->urlValidation($key, $name, $ext);
        $this->checkPermission($shortcodeId, $key, 'preview');

        $previewLink = App::getInstance()->getPreview($key);

        if (is_wp_error($previewLink)) {
            $isImage = MimeTypeManager::isImage($ext);
            if (!$isImage) {
                ccpidbGetTemplate('notice-card/permission-denied', [
                    'title'       => __('Preview Not Available', 'integrate-dropbox'),
                    'description' => $previewLink->get_error_message(),
                    'card_status' => 'error',
                ]);
            } else {
                $this->fallbackImage($previewLink->get_error_message(), __('Preview Not Available', 'integrate-dropbox'));
            }

            exit;
        }

        $office_extensions     = ['xls', 'xlsx', 'xlsm', 'doc', 'docx', 'docm', 'ppt', 'pptx', 'pptm', 'pps', 'ppsm', 'ppsx'];
        if (in_array($ext, $office_extensions)) {
            $previewLink  =  'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode($previewLink);
        }

        header("Referrer-Policy: no-referrer");
        header("Content-Type: " . MimeTypeManager::getMimeType($ext));
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        wp_safe_redirect($previewLink);
        exit;
    }

    public function share($combinedKey, $name, $ext, $shortcodeId = null)
    {
        $explodedKey = explode('-', $combinedKey);
        $fileKey     = $explodedKey[0] ?? null;
        $linkKey     = $explodedKey[1] ?? null;

        if (empty($fileKey) || empty($linkKey)) {
            wp_die('File key is required.', 'Error', ['response' => 400]);
        }

        $this->urlValidation($fileKey, $name, $ext);
        $password = '';

        if (isset($_SERVER['REQUEST_METHOD']) && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) === 'POST' && isset($_POST['ccpidb-password-nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ccpidb-password-nonce'])), 'ccpidb_password_nonce') && isset($_POST['ccpidb-download-password'])) {
            $password = sanitize_text_field(wp_unslash($_POST['ccpidb-download-password']));
        }

        $isValidLink = Files::getInstance()->validateSharedLink("$fileKey-$linkKey", $password);

        if (empty($isValidLink)) {
            // wp_die('Invalid or expired share link.', 'Error', ['response' => 400]);
            ccpidbGetTemplate('notice-card/permission-denied', [
                'title'       => __('Invalid Share Link', 'integrate-dropbox'),
                'description' => $isValidLink->get_error_message(),
                'card_status' => 'error',
            ]);
            exit;
        }

        if (is_wp_error($isValidLink)) {
            if ($isValidLink->get_error_code() === 'password_required' || $isValidLink->get_error_code() === 'invalid_password') {
                ccpidbGetTemplate('content-password', [
                    'code'      => $isValidLink->get_error_code(),
                    'message'   => $isValidLink->get_error_message(),
                    'fileKey'   => $fileKey,
                    'name'      => $name,
                    'fieldName' => 'ccpidb-download-password',
                ]);
                exit;
            } else {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not output, used internally
                // wp_die($isValidLink->get_error_message(), 'Error', ['response' => 400]);
                ccpidbGetTemplate('notice-card/permission-denied', [
                    'title'       => __('Invalid Share Link', 'integrate-dropbox'),
                    'description' => $isValidLink->get_error_message(),
                    'card_status' => 'error',
                ]);
                exit;
            }
        }

        if ($ext === 'folder' || $ext === 'zip') {
            $shareLink = App::getInstance()->getShareLink($fileKey);
            wp_safe_redirect($shareLink);
            exit;
        }

        $embedLink = App::getInstance()->getEmbeddedLink($fileKey);

        if (is_wp_error($embedLink)) {
            return $embedLink->get_error_message();
        }

        Notifications::notify(
            Notifications::VIEW_SHARE_FILE,
            $shortcodeId,
            $fileKey,
        );

        if (MimeTypeManager::isVideo($ext) || MimeTypeManager::isAudio($ext)) {
            echo '<video width="100%" height="100%" controls style="border:none;">
                    <source src="' . esc_url($embedLink) . '" type="' . esc_attr(MimeTypeManager::getMimeType($ext)) . '">
                    ' . esc_html__('Your browser does not support the video tag.', 'integrate-dropbox') . '
                </video>';
            exit;
        }

        if (MimeTypeManager::isImage($ext)) {
            echo '<img src="' . esc_url($embedLink) . '" alt="' . esc_attr($name) . '" style="max-width:100%; height:auto; border:none;" />';
            exit;
        }

        echo '<iframe src="' . esc_url($embedLink) . '" width="100%" height="100%" style="border:none;"></iframe>';
        exit;
    }

    private function downloadWithGeneratedLink($fileKey, $linkKey, $name, $ext, $shortcodeId = null)
    {
        if (empty($fileKey) || empty($linkKey)) {
            wp_die('File key is required.', 'Error', ['response' => 400]);
        }

        $this->urlValidation($fileKey, $name, $ext);
        $password = '';

        if (isset($_SERVER['REQUEST_METHOD']) && sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) === 'POST' && isset($_POST['ccpidb-password-nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ccpidb-password-nonce'])), 'ccpidb_password_nonce') && isset($_POST['ccpidb-download-password'])) {
            $password = sanitize_text_field(wp_unslash($_POST['ccpidb-download-password']));
        }

        $isValidLink = Files::getInstance()->validateDownloadLink("$fileKey-$linkKey", $password);
        if (empty($isValidLink)) {
            ccpidbGetTemplate('notice-card/permission-denied', [
                'title'       => __('Invalid Download URL', 'integrate-dropbox'),
                'description' => __('Invalid or expired download link.', 'integrate-dropbox'),
                'card_status' => 'error',
            ]);
            exit;
        }

        if (is_wp_error($isValidLink)) {
            if ($isValidLink->get_error_code() === 'password_required' || $isValidLink->get_error_code() === 'invalid_password') {
                ccpidbGetTemplate('content-password', [
                    'code'      => $isValidLink->get_error_code(),
                    'message'   => $isValidLink->get_error_message(),
                    'fileKey'   => $fileKey,
                    'name'      => $name,
                    'fieldName' => 'ccpidb-download-password',
                ]);
                exit;
            } else {
                ccpidbGetTemplate('notice-card/permission-denied', [
                    'title'       => __('Invalid Download URL', 'integrate-dropbox'),
                    'description' => $isValidLink->get_error_message(),
                    'card_status' => 'error',
                ]);
                exit;
            }
        }

        $downloadLink = App::getInstance()->downloadLink($fileKey);

        if (is_wp_error($downloadLink)) {
            ccpidbGetTemplate('notice-card/permission-denied', [
                'title'       => __('Error', 'integrate-dropbox'),
                'description' => $downloadLink->get_error_message(),
                'card_status' => 'error',
            ]);
            exit;
        }

        Notifications::notify(
            Notifications::DOWNLOAD,
            $shortcodeId,
            $fileKey,
        );

        wp_safe_redirect($downloadLink);
        exit;
    }

    public function download($key, $name, $ext, $shortcodeId = null)
    {
        $explodedKey = explode('-', $key);
        $fileKey     = $explodedKey[0] ?? null;
        $linkKey     = $explodedKey[1] ?? null;
        if (!empty($fileKey) && !empty($linkKey)) {
            return $this->downloadWithGeneratedLink($fileKey, $linkKey, $name, $ext, $shortcodeId);
        }

        $this->urlValidation($key, $name, $ext);

        $this->checkPermission($shortcodeId, $key, 'download');

        $downloadLink = App::getInstance()->downloadLink($key);

        if (is_wp_error($downloadLink)) {
            ccpidbGetTemplate('notice-card/permission-denied', [
                'title'       => __('Error', 'integrate-dropbox'),
                'description' => $downloadLink->get_error_message(),
                'card_status' => 'error',
            ]);
            exit;
        }

        Notifications::notify(
            Notifications::DOWNLOAD,
            $shortcodeId,
            $key,
        );

        wp_safe_redirect($downloadLink);
        exit;
    }

    private function checkPermission($shortcodeId, $key, $action)
    {
        if (ccpidbHasUserAccessPage('file_browser')) {
            return true;
        }

        $mediaLibraryFiles = Helpers::getSetting('integrations.mediaLibrary.folders', []);

        if (!empty($mediaLibraryFiles)) {
            if (Helpers::validateFileKey($key, $mediaLibraryFiles)) {
                return true;
            }
        }

        if (empty($shortcodeId)) {
            ccpidbGetTemplate('notice-card/permission-denied', [
                'title'       => __('Permission Denied', 'integrate-dropbox'),
                'description' => __('You do not have permission to access this file. Shortcode ID is missing.', 'integrate-dropbox'),
                'card_status' => 'error',
            ]);
            exit;
        }

        if (Helpers::hasShortcodePermission($shortcodeId, $action, $key)) {
            return true;
        }

        ccpidbGetTemplate('notice-card/permission-denied', [
            'title'       => "#" . $shortcodeId . " - " . __('Permission Denied', 'integrate-dropbox'),
            'description' => __('You do not have permission to access this file.', 'integrate-dropbox'),
            'card_status' => 'error',
        ]);
        exit;
    }

    private function urlValidation($key, $name, $ext)
    {
        $file      = App::getInstance()->getFile($key);
        if (is_wp_error($file)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not output, used internally
            wp_die($file->get_error_message(), 'Error | ' . $file->get_error_code(), ['response' => 404]);
        }

        $suffixes = ['-xs', '-sm', '-md', '-lg', '-xl', '-2xl', '-3xl', '-4xl', '-5xl'];

        $cleanSlug = static function (string $value) use ($suffixes): string {
            return ccpidbTitleToUrlSlug(str_replace($suffixes, '', $value));
        };

        $cleanName = $cleanSlug($file->getAdditionalData('basename'));
        $name      = $cleanSlug(urldecode($name));

        if ($name !== $cleanName) {
            wp_safe_redirect($file->getIcon());
            exit;
        }

        if ($ext !== $file->getExtension() && ($ext !== 'zip' && $file->getExtension() === 'folder')) {
            wp_safe_redirect($file->getIcon());
            exit;
        }
    }

    /* -------------------------
    * Helpers
    * ------------------------- */

    private function safeRedirect(string $url, $cache = HOUR_IN_SECONDS, $status = 302): void
    {
        header("Referrer-Policy: no-referrer");
        header("Cache-Control: public, max-age={$cache}");
        wp_safe_redirect($url, $status, CCPIDB_NAME . ' Safe Redirect');
        exit;
    }

    private function safeProxy(string $url, $cache = HOUR_IN_SECONDS): void
    {
        header("Referrer-Policy: no-referrer");
        $response = wp_remote_get($url, [
            'timeout'     => 15,
            'redirection' => 5,
            'sslverify'   => false,
        ]);
        if (is_wp_error($response)) {
            $this->safeRedirect($this->getUnknownIcon('image/jpeg'), 0);
            exit;
        }
        $data        = wp_remote_retrieve_body($response);
        $contentType = wp_remote_retrieve_header($response, 'content-type');

        // Whitelist of safe content types to prevent XSS and other security issues
        $allowedContentTypes = [
            'application/vnd.google-apps.spreadsheet',
            'application/vnd.google-apps.folder',
            'application/vnd.google-apps.document',
            'application/vnd.google-apps.presentation',
            'application/vnd.google-apps.script',
            'application/vnd.google-apps.form',
            'application/vnd.google-apps.drawing',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/pdf',
            'text/plain',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'audio/mpeg',
            'audio/wav',
            'video/mp4',
            'video/x-msvideo',
        ];

        // Extract the base content type (remove charset and other parameters)
        $baseContentType = $contentType ? explode(';', $contentType)[0] : '';
        $baseContentType = trim($baseContentType);

        // Validate content type is in the allowed list
        if (!in_array($baseContentType, $allowedContentTypes, true)) {
            $this->safeRedirect($this->getUnknownIcon('image/jpeg'), 0);
            exit;
        }

        if ($data) {
            header("Content-Type: {$baseContentType}");
            header("Cache-Control: public, max-age={$cache}");
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $data;
        } else {
            $this->safeRedirect($this->getUnknownIcon('image/jpeg'), 0);
        }
        exit;
    }

    private function denyAccess(string $message = 'Access denied!', string $description = 'You do not have permission to access this file.', string $cardStatus = 'error'): void
    {
        ccpidbGetTemplate('notice-card/permission-denied', [
            'title'       => $message,
            'description' => esc_html($description),
            'card_status' => $cardStatus,
        ]);
        exit;
    }

    private function getUnknownIcon(string $mimeType = 'application/octet-stream'): string
    {
        return CCPIDB_ASSETS . '/images/icons/file.png';
    }

    /* -------------------------
    * Attachment
    * ------------------------- */
    public function attachment(string $key, string $name, string $ext, ?string $shortcodeId = null): void
    {
        $explodeName    = explode('-', $name);
        $size           = end($explodeName);
        $isThumbnail    = in_array($size, ['xs', 'sm', 'md', 'lg', 'xl'], true);

        if (MimeTypeManager::isImage($ext) || $isThumbnail) {
            $this->thumbnail($key, $name, $ext, null);
        } else {
            $this->preview($key, $name, $ext, null);
        }
    }

    private function fallbackImage($desc, $title = 'Preview Not Available')
    {
        status_header(404);
        nocache_headers();
        header('Content-Type: image/png');

        $width  = 800;
        $height = 400;

        $img = imagecreatetruecolor($width, $height);

        imagealphablending($img, false);
        imagesavealpha($img, true);

        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        $bg_color    = imagecolorallocate($img, 245, 247, 250);
        $title_color = imagecolorallocate($img, 40, 40, 40);
        $text_color  = imagecolorallocate($img, 110, 110, 110);

        imagefilledrectangle($img, 0, 0, $width, $height, $bg_color);

        $card_height = 220;
        $card_y      = ($height - $card_height) / 2;

        $title_font  = 5;
        $title_width = strlen($title) * imagefontwidth($title_font);
        $title_x     = ($width - $title_width) / 2;
        $title_y     = $card_y + 70;

        imagestring(
            $img,
            $title_font,
            $title_x,
            $title_y,
            $title,
            $title_color
        );

        $max_chars       = 100;
        $max_line_length = 50;
        $max_lines       = 3;

        if (strlen($desc) > $max_chars) {
            $desc = substr($desc, 0, $max_chars - 3) . '...';
        }

        $lines = explode("\n", wordwrap($desc, $max_line_length, "\n", true));

        if (count($lines) > $max_lines) {
            $lines          = array_slice($lines, 0, $max_lines);
            $lines[2]       = substr($lines[2], 0, 47) . '...';
        }

        $line_y      = $card_y + 110;
        $line_height = 20;
        $text_font   = 3;

        foreach ($lines as $line) {
            $line_width = strlen($line) * imagefontwidth($text_font);
            $line_x     = ($width - $line_width) / 2;

            imagestring(
                $img,
                $text_font,
                $line_x,
                $line_y,
                $line,
                $text_color
            );

            $line_y += $line_height;
        }

        imagepng($img);
        imagedestroy($img);
    }
}
