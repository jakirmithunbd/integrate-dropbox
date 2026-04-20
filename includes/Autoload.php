<?php

namespace CodeConfig\IDB;

defined('ABSPATH') or exit('No direct script access allowed');

class Autoload
{
    public static function register()
    {
        spl_autoload_register([self::class, 'loadClass']);
    }

    /**
     * PSR-4 style autoloader for the CodeConfig\IGD namespace.
     *
     * @param string $class Fully qualified class name.
     */
    private static function loadClass($class)
    {
        $prefixes = self::getAutoloadPaths();

        foreach ($prefixes as $prefix => $dirs) {

            if (strpos($class, $prefix) !== 0) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $filePath      = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

            foreach ($dirs as $dir) {
                $fullPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filePath;
                if (is_file($fullPath)) {
                    require_once $fullPath;

                    return;
                }
            }
        }
    }

    /**
     * Maps namespace prefixes to their corresponding base directories.
     *
     * @return array
     */
    private static function getAutoloadPaths()
    {
        return [
            // Order matters: more specific namespaces first
            'CodeConfig\\IDB\\Dropbox\\'                          => [CCPIDB_VENDORS . '/Dropbox'],
            'CodeConfig\\IDB\\Models\\'                           => [CCPIDB_MODELS],
            'CodeConfig\\IDB\\App\\'                              => [CCPIDB_APP],
            'CodeConfig\\IDB\\'                                   => [CCPIDB_INCLUDES],
            'CodeConfig\\IntegrateDropbox\\App\\'                 => [CCPIDB_PATH . 'legacy'],
            'CodeConfig\\IntegrateDropbox\\SDK\\Models\\'         => [CCPIDB_PATH . 'legacy']
        ];
    }
}
