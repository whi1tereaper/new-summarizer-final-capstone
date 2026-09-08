<?php
namespace App\Src\Utils;

class PasswordPolicy
{
    public static function validate(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (strlen($password) > 72) {
            return 'Password must not exceed 72 characters.';
        }

        $hasNumber    = (bool) preg_match('/[0-9]/', $password);
        $hasUpper     = (bool) preg_match('/[A-Z]/', $password);
        $hasSpecial   = (bool) preg_match('/[^a-zA-Z0-9]/', $password);

        if (!$hasNumber) {
            return 'Password must contain at least one number.';
        }

        // Medium minimum: number + at least one of (uppercase, special char)
        if (!$hasUpper && !$hasSpecial) {
            return 'Password is too weak. Add an uppercase letter or a special character (e.g. @, #, !).';
        }

        return null;
    }

    public static function getStrength(string $password): string
    {
        if (strlen($password) < 8) {
            return 'weak';
        }
        $hasNumber  = (bool) preg_match('/[0-9]/', $password);
        $hasUpper   = (bool) preg_match('/[A-Z]/', $password);
        $hasSpecial = (bool) preg_match('/[^a-zA-Z0-9]/', $password);

        $score = (int)$hasNumber + (int)$hasUpper + (int)$hasSpecial;
        if ($score >= 3) return 'strong';
        if ($score >= 2) return 'medium';
        return 'weak';
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
