<?php
declare(strict_types=1);

namespace Placard;

final class Paths
{
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** Writable data directory (config, cache, sessions). Override with PLACARD_DATA_DIR. */
    public static function data(): string
    {
        // PHP-FPM exposes fastcgi_param values in $_SERVER; the CLI uses real environment variables.
        $dir = $_SERVER['PLACARD_DATA_DIR'] ?? getenv('PLACARD_DATA_DIR');
        return rtrim(is_string($dir) && $dir !== '' ? $dir : self::root() . '/data', '/\\');
    }

    public static function configFile(): string
    {
        return self::data() . '/config.json';
    }

    public static function cache(): string
    {
        return self::ensure(self::data() . '/cache');
    }

    public static function sessions(): string
    {
        return self::ensure(self::data() . '/sessions');
    }

    public static function fonts(): string
    {
        return self::root() . '/fonts';
    }

    public static function ensure(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: $dir");
        }
        return $dir;
    }

    /** Write a file atomically (temp file + rename), readable only by the owner where supported. */
    public static function writeAtomic(string $file, string $contents): void
    {
        self::ensure(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException("Cannot write file: $file");
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot replace file: $file");
        }
    }
}
