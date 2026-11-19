<?php

declare(strict_types=1);

namespace WPE\Support;

/**
 * URL and cache-busting version for files shipped under the plugin's assets/ directory.
 *
 * The version is the file's modification time, so a deployed edit changes the asset URL and
 * browsers/CDNs fetch it again. A missing file falls back to the plugin version.
 */
final class Assets
{
    /**
     * @param string $path Path relative to the plugin root, e.g. 'assets/admin/admin.css'.
     */
    public static function url(string $path): string
    {
        return plugins_url(ltrim($path, '/'), WPE_PLUGIN_FILE);
    }

    /**
     * @param string $path Path relative to the plugin root, e.g. 'assets/admin/admin.css'.
     */
    public static function version(string $path): string
    {
        $file = WPE_PLUGIN_DIR . ltrim($path, '/');
        $mtime = is_readable($file) ? filemtime($file) : false;

        return $mtime !== false ? (string) $mtime : WPE_VERSION;
    }
}
