<?php
namespace App\Src\Support;

/**
 * Resolves runtime storage and project paths.
 * All paths are derived from app/config/config.php, not hardcoded.
 */
class RuntimePaths
{
    public static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function storageRoot(): string
    {
        return config('storage.root', self::projectRoot() . DIRECTORY_SEPARATOR . 'storage');
    }

    public static function uploadsDirectory(): string
    {
        return config('storage.uploads', self::storageRoot() . DIRECTORY_SEPARATOR . 'uploads');
    }

    public static function audioDirectory(): string
    {
        return config('storage.audio', self::storageRoot() . DIRECTORY_SEPARATOR . 'audio');
    }

    public static function logsDirectory(): string
    {
        return config('storage.logs', self::storageRoot() . DIRECTORY_SEPARATOR . 'logs');
    }

    public static function temporaryDirectory(string $subDir = ''): string
    {
        $base = config('storage.tmp', self::storageRoot() . DIRECTORY_SEPARATOR . 'tmp');
        if ($subDir !== '') {
            return $base . DIRECTORY_SEPARATOR . $subDir;
        }

        return $base;
    }

    /** All directories that may contain uploaded user files. */
    public static function uploadDirectories(): array
    {
        return [self::uploadsDirectory()];
    }

    public static function ensureDirectory(string $path, int $mode = 0775): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return @mkdir($path, $mode, true) && is_writable($path);
    }

    public static function ensureRequiredDirectories(): void
    {
        $dirs = [
            self::storageRoot(),
            self::uploadsDirectory(),
            self::audioDirectory(),
            self::logsDirectory(),
            self::temporaryDirectory(),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }
}
