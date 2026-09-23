<?php
namespace App\Src\Services;
require_once __DIR__ . '/../Support/config.php';
require_once __DIR__ . '/../Support/RuntimePaths.php';

use App\Src\Support\RuntimePaths;

class BarkTtsService
{
    private const FILE_NAME_PATTERN = '/^[a-f0-9]{64}\.wav$/';
    private const FALLBACK_CACHE_DIR = 'ai-summarizer-tts';
    private const AUDIO_TOKEN_SEPARATOR = '|';

    private int $requestTimeoutSeconds;
    private string $audioOutputDir;
    private string $audioPublicUrl;
    private LocalPythonBridge $pythonBridge;

    public function __construct()
    {
        $this->requestTimeoutSeconds = max(
            30,
            (int)config('tts.timeout_seconds', 600)
        );
        $projectRoot = dirname(__DIR__, 3);
        $this->audioOutputDir = $this->resolveAudioOutputDir($projectRoot);
        $this->audioPublicUrl = $this->resolveAudioPublicUrl();
        $this->pythonBridge = new LocalPythonBridge();
    }

    public static function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text);
        return trim($normalized ?? $text);
    }

    public static function normalizeLanguageCode(?string $language): string
    {
        $normalized = strtolower(trim((string)$language));
        return match ($normalized) {
            'tl', 'fil', 'filipino', 'tagalog' => 'tl',
            default => 'en',
        };
    }

    public static function getLanguageLabel(?string $language): string
    {
        return self::normalizeLanguageCode($language) === 'tl' ? 'filipino' : 'english';
    }

    public static function buildCacheFileName(string $text, string $language = 'en'): string
    {
        $normalizedLanguage = self::normalizeLanguageCode($language);
        return hash('sha256', $normalizedLanguage . "\n" . self::normalizeText($text)) . '.wav';
    }

    public static function buildLegacyCacheFileName(string $text): string
    {
        return hash('sha256', self::normalizeText($text)) . '.wav';
    }

    public static function matchesExpectedFileNameForText(
        string $text,
        string $fileName,
        string $language = 'en'
    ): bool {
        $safeFileName = trim($fileName);
        return hash_equals(self::buildCacheFileName($text, $language), $safeFileName)
            || hash_equals(self::buildLegacyCacheFileName($text), $safeFileName);
    }

    public function validateText(string $text): ?string
    {
        if ($text === '') {
            return 'Summary text is required for audio generation.';
        }

        return null;
    }

    public function validateFileName(string $fileName): string
    {
        $normalized = trim($fileName);
        if (!preg_match(self::FILE_NAME_PATTERN, $normalized)) {
            throw new \InvalidArgumentException('Invalid audio file request.');
        }

        return $normalized;
    }

    public function generate(string $summaryText, string $language = 'en'): array
    {
        $normalizedText = self::normalizeText($summaryText);
        $validationError = $this->validateText($normalizedText);
        if ($validationError !== null) {
            throw new \InvalidArgumentException($validationError);
        }

        $normalizedLanguage = self::normalizeLanguageCode($language);
        $decoded = $this->pythonBridge->generateTts(
            $normalizedText,
            $normalizedLanguage,
            $this->requestTimeoutSeconds
        );

        if (empty($decoded['file_name']) || !is_string($decoded['file_name'])) {
            throw new \RuntimeException('Audio file was not returned by the text-to-speech service.');
        }

        return [
            'file_name' => $this->validateFileName($decoded['file_name']),
            'cached' => !empty($decoded['cached']),
            'message' => (string)($decoded['message'] ?? 'Audio ready.'),
            'language' => self::getLanguageLabel($normalizedLanguage),
            'language_code' => $normalizedLanguage,
        ];
    }

    public function buildAudioProxyUrl(int $summaryId, string $fileName, ?string $shareToken = null): string
    {
        $safeFileName = $this->validateFileName($fileName);
        $query = [
            'summary_id' => $summaryId,
            'file' => $safeFileName,
            'token' => self::buildAudioAccessToken($summaryId, $safeFileName, $shareToken),
        ];

        if (is_string($shareToken) && $shareToken !== '') {
            $query['share'] = $shareToken;
        }

        return $this->appendQueryString($this->audioPublicUrl, http_build_query($query));
    }

    public static function buildAudioAccessToken(int $summaryId, string $fileName, ?string $shareToken = null): string
    {
        $safeFileName = trim($fileName);
        $secret = self::resolveAudioTokenSecret();
        if ($secret === '') {
            return '';
        }

        return hash_hmac(
            'sha256',
            $summaryId . self::AUDIO_TOKEN_SEPARATOR . $safeFileName . self::AUDIO_TOKEN_SEPARATOR . (string)$shareToken,
            $secret
        );
    }

    public static function verifyAudioAccessToken(
        int $summaryId,
        string $fileName,
        string $providedToken,
        ?string $shareToken = null
    ): bool {
        $normalizedToken = trim($providedToken);
        if ($normalizedToken === '') {
            return false;
        }

        $expectedToken = self::buildAudioAccessToken($summaryId, $fileName, $shareToken);
        if ($expectedToken === '') {
            return false;
        }

        return hash_equals($expectedToken, $normalizedToken);
    }

    public function fetchAudioStream(string $fileName, ?string $rangeHeader = null): array
    {
        $safeFileName = $this->validateFileName($fileName);
        $audioPath = $this->audioOutputDir . DIRECTORY_SEPARATOR . $safeFileName;
        if (!is_file($audioPath)) {
            return [
                'status' => 404,
                'content_type' => 'text/plain; charset=UTF-8',
                'headers' => [],
                'body' => '',
            ];
        }

        $fileSize = filesize($audioPath);
        $lastModified = filemtime($audioPath) ?: time();
        $start = 0;
        $end = max(0, $fileSize - 1);
        $status = 200;
        $headers = [
            'accept-ranges' => 'bytes',
            'cache-control' => 'public, max-age=31536000, immutable',
            'last-modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ];

        if (
            is_string($rangeHeader)
            && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $matches)
        ) {
            $requestedStart = $matches[1];
            $requestedEnd = $matches[2];

            if ($requestedStart === '' && $requestedEnd !== '') {
                $suffixLength = min($fileSize, (int)$requestedEnd);
                $start = max(0, $fileSize - $suffixLength);
            } elseif ($requestedStart !== '') {
                $start = min((int)$requestedStart, max(0, $fileSize - 1));
            }

            if ($requestedEnd !== '') {
                $end = min((int)$requestedEnd, max(0, $fileSize - 1));
            }

            if ($start > $end) {
                $start = 0;
                $end = max(0, $fileSize - 1);
            } else {
                $status = 206;
                $headers['content-range'] = sprintf('bytes %d-%d/%d', $start, $end, $fileSize);
            }
        }

        $length = max(0, $end - $start + 1);
        $headers['content-length'] = (string)$length;

        $handle = fopen($audioPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open generated audio.');
        }

        if ($start > 0) {
            fseek($handle, $start);
        }

        $remaining = $length;
        $body = '';
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false) {
                fclose($handle);
                throw new \RuntimeException('Unable to read generated audio.');
            }

            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        fclose($handle);

        return [
            'status' => $status,
            'content_type' => 'audio/wav',
            'headers' => $headers,
            'body' => $body,
        ];
    }

    private function resolveAudioOutputDir(string $projectRoot): string
    {
        $candidates = array_filter([
            config('tts.audio_output_dir'),
            config('tts.piper_cache_dir'),
            config('tts.output_dir'),
            RuntimePaths::audioDirectory(),
            $projectRoot . DIRECTORY_SEPARATOR . 'python-engine' . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'tts',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::FALLBACK_CACHE_DIR,
        ]);

        foreach ($candidates as $candidate) {
            $normalized = rtrim((string)$candidate, DIRECTORY_SEPARATOR);
            if ($normalized === '') {
                continue;
            }

            if (!is_dir($normalized) && !@mkdir($normalized, 0775, true) && !is_dir($normalized)) {
                continue;
            }

            if (is_writable($normalized)) {
                return $normalized;
            }
        }

        return rtrim(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::FALLBACK_CACHE_DIR,
            DIRECTORY_SEPARATOR
        );
    }

    private function resolveAudioPublicUrl(): string
    {
        $configured = trim((string)config('tts.audio_public_url', 'tts_audio.php'));
        return $configured !== '' ? $configured : 'tts_audio.php';
    }

    private function appendQueryString(string $baseUrl, string $queryString): string
    {
        if ($queryString === '') {
            return $baseUrl;
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . $queryString;
    }

    private static function resolveAudioTokenSecret(): string
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            return '';
        }

        $viewerKey = '';
        if (isset($_SESSION['user_id'])) {
            $viewerKey = 'user:' . (string)$_SESSION['user_id'];
        } elseif (isset($_SESSION['guest_token'])) {
            $viewerKey = 'guest:' . (string)$_SESSION['guest_token'];
        }

        return $sessionId . self::AUDIO_TOKEN_SEPARATOR . $viewerKey;
    }
}
