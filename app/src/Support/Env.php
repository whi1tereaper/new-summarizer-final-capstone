<?php
namespace App\Src\Support;

final class Env
{
    private static bool $loaded = false;
    private static array $values = [];

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        $envPath = $path ?: self::projectRoot() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath) || !is_readable($envPath)) {
            self::$loaded = true;
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $cleanedValue = trim($value);
            $cleanedValue = self::stripWrappingQuotes($cleanedValue);

            self::$values[$name] = $cleanedValue;
            $_ENV[$name] = $cleanedValue;
            $_SERVER[$name] = $cleanedValue;
            putenv($name . '=' . $cleanedValue);
        }

        self::$loaded = true;
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        self::load();

        if (!array_key_exists($name, self::$values)) {
            return $default;
        }

        return self::$values[$name];
    }

    public static function string(string $name, string $default = ''): string
    {
        $value = self::get($name, $default);
        return is_string($value) ? trim($value) : $default;
    }

    public static function requireString(string $name): string
    {
        self::load();

        if (!self::exists($name)) {
            throw new \RuntimeException("Missing required environment variable: {$name}");
        }

        $value = self::get($name, '');
        return is_string($value) ? trim($value) : trim((string)$value);
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name, null);
        if ($value === null) {
            return $default;
        }

        $normalized = is_string($value) ? trim($value) : (string)$value;
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return (int)$normalized;
    }

    public static function requireInt(string $name): int
    {
        self::load();

        if (!self::exists($name)) {
            throw new \RuntimeException("Missing required environment variable: {$name}");
        }

        $value = self::get($name, null);
        $normalized = is_string($value) ? trim($value) : (string)$value;
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException("Environment variable {$name} must be a valid integer.");
        }

        return (int)$normalized;
    }

    public static function bool(string $name, bool $default): bool
    {
        $value = self::get($name, null);
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    public static function requireBool(string $name): bool
    {
        self::load();

        if (!self::exists($name)) {
            throw new \RuntimeException("Missing required environment variable: {$name}");
        }

        $normalized = strtolower(trim((string)self::get($name, '')));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \RuntimeException("Environment variable {$name} must be a valid boolean.");
    }

    public static function boolString(string $name, bool $default): string
    {
        return self::bool($name, $default) ? 'true' : 'false';
    }

    public static function requireBoolString(string $name): string
    {
        return self::requireBool($name) ? 'true' : 'false';
    }

    public static function requirePath(string $name): string
    {
        $value = self::requireString($name);
        if ($value === '') {
            throw new \RuntimeException("Environment variable {$name} cannot be empty.");
        }

        return self::resolvePath($value);
    }

    public static function optionalPath(string $name): string
    {
        $value = self::requireString($name);
        if ($value === '') {
            return '';
        }

        return self::resolvePath($value);
    }

    private static function exists(string $name): bool
    {
        self::load();
        return array_key_exists($name, self::$values);
    }

    private static function stripWrappingQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' || $first === "'") && $first === $last) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function resolvePath(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        if (self::isAbsolutePath($normalized)) {
            return $normalized;
        }

        return self::projectRoot() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
