<?php
namespace App\Src\Support;

/** Public failure contract. Raw diagnostics never become response text. */
class ProcessingError extends \RuntimeException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;
    public readonly string $reference;
    public readonly ?int $retryAfterSeconds;
    private static ?array $catalog = null;

    public function __construct(
        string $errorCode,
        ?\Throwable $previous = null,
        ?string $reference = null,
        ?int $retryAfterSeconds = null
    )
    {
        $catalog = self::catalog();
        $this->errorCode = isset($catalog[$errorCode]) ? $errorCode : 'PROCESSING_FAILED';
        $entry = $catalog[$this->errorCode];
        $this->httpStatus = $entry['http_status'];
        $this->retryAfterSeconds = $this->errorCode === 'TRANSLATION_RATE_LIMITED'
            && $retryAfterSeconds !== null && $retryAfterSeconds > 0
            ? $retryAfterSeconds : null;
        $this->reference = is_string($reference) && preg_match('/\A[a-f0-9]{16,32}\z/i', $reference)
            ? strtolower($reference) : bin2hex(random_bytes(12));
        parent::__construct($entry['explanation'], 0, $previous);
    }

    public static function catalog(): array
    {
        return self::$catalog ??= json_decode(file_get_contents(__DIR__ . '/../../config/error_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function toArray(): array
    {
        $data = ['code' => $this->errorCode] + self::catalog()[$this->errorCode] + ['reference' => $this->reference];
        if ($this->retryAfterSeconds !== null) {
            $data['retry_after_seconds'] = $this->retryAfterSeconds;
        }
        return $data;
    }

    public static function fromThrowable(\Throwable $error, string $fallback = 'PROCESSING_FAILED'): self
    {
        if ($error instanceof self) return $error;
        return new self($fallback, $error);
    }

    public static function fromWorker(array $failure): self
    {
        // Trust only registered codes and opaque references, never supplied prose.
        $code = is_string($failure['code'] ?? null) ? $failure['code'] : 'PROCESSING_FAILED';
        $retryAfter = $failure['retry_after_seconds'] ?? null;
        $validRetryAfter = is_int($retryAfter) && $retryAfter > 0 && $retryAfter <= 9007199254740991
            ? $retryAfter : null;
        return new self($code, null,
            is_string($failure['reference'] ?? null) ? $failure['reference'] : null,
            $code === 'TRANSLATION_RATE_LIMITED' ? $validRetryAfter : null);
    }

    public function log(): void
    {
        // No source text, URLs, paths, or exception messages in operational logs.
        error_log('[LIGHT failure] reference=' . $this->reference . ' code=' . $this->errorCode
            . ' exception=' . ($this->getPrevious() ? get_class($this->getPrevious()) : static::class));
    }
}
