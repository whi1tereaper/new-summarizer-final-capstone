<?php
namespace App\Src\Utils;

class Privacy
{
    // mask obvious personal data before saving or showing source text
    public static function maskSensitiveText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $masked = $text;
        $patterns = [
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i' => '[EMAIL]',
            '/\b(?:\+?63|0)?9\d{2}[\s.\-]?\d{3}[\s.\-]?\d{4}\b/' => '[PHONE]',
            '/\b(?:student\s*)?id\s*[:#-]?\s*\d{4}[-\s]?\d{3,8}\b/i' => '[STUDENT_ID]',
            '/\b\d{4}[-\s]\d{3,8}\b/' => '[ID_NUMBER]',
            '/\b(?:https?:\/\/|www\.)\S+\b/i' => '[URL]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $masked = preg_replace($pattern, $replacement, $masked) ?? $masked;
        }

        return trim($masked);
    }
}
