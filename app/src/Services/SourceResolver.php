<?php
namespace App\Src\Services;

require_once __DIR__ . '/LocalPythonBridge.php';
require_once __DIR__ . '/../Support/config.php';
require_once __DIR__ . '/../Support/RuntimePaths.php';
require_once __DIR__ . '/../Support/ProcessingError.php';

use App\Src\Support\RuntimePaths;
use App\Src\Support\ProcessingError;

final class SourceResolution
{
    public function __construct(
        private string $text,
        private string $inputType,
        private array $temporaryPaths = [],
        private string $filePath = '',
    ) {}

    public function getText(): string
    {
        return $this->text;
    }

    public function getInputType(): string
    {
        return $this->inputType;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function cleanup(): void
    {
        // Temporary downloads belong to the current request only, so remove them after processing.
        foreach ($this->temporaryPaths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}

class SourceResolver
{
    // URL fetching is intentionally conservative because this code accepts untrusted user input.
    private const USER_AGENT = 'AI Summarizer SourceResolver/1.0';
    private const MAX_REDIRECTS = 3;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const REQUEST_TIMEOUT_SECONDS = 20;
    private const MAX_HTML_BYTES = 5 * 1024 * 1024;
    private const MAX_PDF_BYTES = 30 * 1024 * 1024;
    private LocalPythonBridge $pythonBridge;
    private ?string $caBundlePath;
    private array $validatedAddresses = [];

    public function __construct(?LocalPythonBridge $pythonBridge = null)
    {
        $this->pythonBridge = $pythonBridge ?? new LocalPythonBridge();
        $this->caBundlePath = $this->resolveCaBundlePath();
    }

    public function resolve(string $rawInput): SourceResolution
    {
        // Treat plain text and URLs differently so pasted articles do not pay the network-fetch path.
        $sourceText = trim($rawInput);
        if ($sourceText === '') {
            throw new ProcessingError('INPUT_EMPTY');
        }

        if (!$this->looksLikeStandaloneUrl($sourceText)) {
            return new SourceResolution($sourceText, 'text');
        }

        $normalizedUrl = $this->normalizeAndValidateUrl($sourceText);

        if ($this->isDirectPdfUrl($normalizedUrl)) {
            return $this->resolvePdfUrl($normalizedUrl);
        }

        return $this->resolveHtmlUrl($normalizedUrl);
    }

    public function validateUrl(string $rawUrl): string
    {
        return $this->normalizeAndValidateUrl($rawUrl);
    }

    private function resolvePdfUrl(string $url): SourceResolution
    {
        // PDFs go through a download-plus-extraction path because the worker reads them from disk.
        $download = $this->downloadBinaryWithRedirectValidation($url, self::MAX_PDF_BYTES);
        $temporaryPdfPath = (string)($download['file_path'] ?? '');
        if ($temporaryPdfPath === '' || !is_file($temporaryPdfPath)) {
            throw new ProcessingError('URL_UNREACHABLE');
        }

        if (config('summarizer.engine_version', 'legacy') === 'v2') {
            return new SourceResolution('', 'url', [$temporaryPdfPath], $temporaryPdfPath);
        }

        try {
            $extractedText = $this->pythonBridge->extractPdfText($temporaryPdfPath, 60);
            if (trim($extractedText) === '') {
                throw new ProcessingError('FILE_TEXT_UNAVAILABLE');
            }
        } catch (\Throwable $exception) {
            @unlink($temporaryPdfPath);
            throw ProcessingError::fromThrowable($exception, 'DOCUMENT_EXTRACTION_FAILED');
        }

        // Keep track of the temporary file so the controller can clean it up after the request finishes.
        return new SourceResolution($extractedText, 'url', [$temporaryPdfPath]);
    }

    private function resolveHtmlUrl(string $url): SourceResolution
    {
        // HTML sources are reduced to readable article text before they are treated like normal pasted input.
        $htmlFetch = $this->downloadTextWithRedirectValidation($url, self::MAX_HTML_BYTES);
        $contentType = $this->normalizeContentType($htmlFetch['content_type'] ?? '');
        if ($contentType !== '' && !$this->isHtmlLikeContentType($contentType)) {
            throw new ProcessingError('URL_CONTENT_UNAVAILABLE');
        }

        $body = (string)($htmlFetch['body'] ?? '');
        $readableText = $contentType === 'text/plain'
            ? trim($body)
            : $this->extractReadableHtmlText($body);
        if ($readableText === '') {
            throw new ProcessingError('URL_CONTENT_UNAVAILABLE');
        }

        return new SourceResolution($readableText, 'url');
    }

    private function looksLikeStandaloneUrl(string $value): bool
    {
        return preg_match('/^(?:[a-z][a-z0-9+.-]*:\/\/|www\.)[^\r\n]*$/i', $value) === 1;
    }

    private function normalizeAndValidateUrl(string $rawUrl): string
    {
        // Normalize common user input like www.example.com, then block malformed or internal targets.
        $normalizedUrl = trim($rawUrl);
        if (str_starts_with(strtolower($normalizedUrl), 'www.')) {
            $normalizedUrl = 'https://' . $normalizedUrl;
        }

        if (preg_match('/[\x00-\x20\x7f\\\\]/', $normalizedUrl) === 1) {
            throw new ProcessingError('URL_INVALID');
        }
        $parts = parse_url($normalizedUrl);
        if (!is_array($parts)) {
            throw new ProcessingError('URL_INVALID');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ProcessingError('URL_INVALID');
        }

        if (isset($parts['user']) || isset($parts['pass']) || filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false) {
            throw new ProcessingError('URL_INVALID');
        }

        $this->assertAllowedHost($host);
        $this->assertPublicIpTargets($host);

        return $normalizedUrl;
    }

    private function assertAllowedHost(string $host): void
    {
        // Disallow local hostnames so the URL feature cannot be used to probe the server itself.
        $host = rtrim($host, '.');
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new ProcessingError('URL_UNSAFE');
        }
    }

    private function assertPublicIpTargets(string $host): void
    {
        // Resolve the host and reject private/reserved IPs to reduce SSRF risk.
        $resolvedIps = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $resolvedIps[] = $host;
        } else {
            if (function_exists('dns_get_record')) {
                $dnsRecords = @dns_get_record($host, DNS_A + DNS_AAAA);
                if (is_array($dnsRecords)) {
                    foreach ($dnsRecords as $record) {
                        if (!empty($record['ip']) && is_string($record['ip'])) {
                            $resolvedIps[] = $record['ip'];
                        }
                        if (!empty($record['ipv6']) && is_string($record['ipv6'])) {
                            $resolvedIps[] = $record['ipv6'];
                        }
                    }
                }
            }

            if ($resolvedIps === [] && function_exists('gethostbynamel')) {
                $ipv4Records = @gethostbynamel($host);
                if (is_array($ipv4Records)) {
                    $resolvedIps = array_merge($resolvedIps, $ipv4Records);
                }
            }
        }

        $resolvedIps = array_values(array_unique(array_filter($resolvedIps, 'is_string')));
        if ($resolvedIps === []) {
            throw new ProcessingError('URL_UNREACHABLE');
        }

        foreach ($resolvedIps as $ipAddress) {
            if (!$this->isPublicIpAddress($ipAddress)) {
                throw new ProcessingError('URL_UNSAFE');
            }
        }
        $this->validatedAddresses[$host] = $resolvedIps;
    }

    private function isPublicIpAddress(string $ipAddress): bool
    {
        $packed = @inet_pton($ipAddress);
        if ($packed === false) {
            return false;
        }
        // PHP's range flags do not consistently reject mapped IPv4 or shared
        // address space. Treat mapped addresses according to their IPv4 target.
        if (strlen($packed) === 16) {
            if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                return $this->isPublicIpAddress((string)inet_ntop(substr($packed, 12)));
            }
            if ((ord($packed[0]) & 0xe0) !== 0x20) {
                return false;
            }
        } elseif (ord($packed[0]) >= 224 || (ord($packed[0]) === 100 && (ord($packed[1]) & 0xc0) === 64)) {
            return false;
        }
        return filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function isDirectPdfUrl(string $url): bool
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        return preg_match('/\.pdf$/i', $path) === 1;
    }

    private function isHtmlLikeContentType(string $contentType): bool
    {
        return $contentType === ''
            || str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml')
            || str_contains($contentType, 'text/plain');
    }

    private function normalizeContentType(string $contentType): string
    {
        $normalized = trim(strtolower($contentType));
        if ($normalized === '') {
            return '';
        }

        $parts = explode(';', $normalized, 2);
        return trim($parts[0]);
    }

    private function downloadBinaryWithRedirectValidation(string $url, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            throw new ProcessingError('WORKER_UNAVAILABLE');
        }
        // Follow a small number of redirects manually so each hop can be revalidated.
        $currentUrl = $url;
        for ($redirectCount = 0; $redirectCount <= self::MAX_REDIRECTS; $redirectCount++) {
            $this->normalizeAndValidateUrl($currentUrl);

            $responseHeaders = [];
            $downloadedBytes = 0;
            $contentLength = null;
            $sawOversizedTransfer = false;
            $pdfSignatureBytes = '';

            $temporaryFile = $this->createTemporaryPdfPath();
            $fileHandle = fopen($temporaryFile, 'wb');
            if ($fileHandle === false) {
                @unlink($temporaryFile);
                throw new ProcessingError('WORKER_UNAVAILABLE');
            }

            $curlHandle = curl_init($currentUrl);
            if ($curlHandle === false) {
                fclose($fileHandle);
                @unlink($temporaryFile);
                throw new ProcessingError('WORKER_UNAVAILABLE');
            }

            curl_setopt_array($curlHandle, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FAILONERROR => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HEADERFUNCTION => function ($curl, string $headerLine) use (&$responseHeaders, &$contentLength): int {
                    // Capture only the headers this request needs for redirect and size checks.
                    $headerLength = strlen($headerLine);
                    $trimmedHeader = trim($headerLine);
                    if ($trimmedHeader === '' || !str_contains($trimmedHeader, ':')) {
                        return $headerLength;
                    }

                    [$name, $value] = explode(':', $trimmedHeader, 2);
                    $normalizedName = strtolower(trim($name));
                    $normalizedValue = trim($value);
                    $responseHeaders[$normalizedName] = $normalizedValue;
                    if ($normalizedName === 'content-length' && ctype_digit($normalizedValue)) {
                        $contentLength = (int)$normalizedValue;
                    }

                    return $headerLength;
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($fileHandle, $maxBytes, &$downloadedBytes, &$sawOversizedTransfer, &$pdfSignatureBytes): int {
                    // Stop oversized downloads early and keep the PDF signature for quick type validation.
                    $chunkLength = strlen($chunk);
                    $downloadedBytes += $chunkLength;
                    if ($downloadedBytes > $maxBytes) {
                        $sawOversizedTransfer = true;
                        return 0;
                    }

                    if (strlen($pdfSignatureBytes) < 5) {
                        $remaining = 5 - strlen($pdfSignatureBytes);
                        $pdfSignatureBytes .= substr($chunk, 0, $remaining);
                    }

                    $written = fwrite($fileHandle, $chunk);
                    return $written === false ? 0 : $written;
                },
                CURLOPT_HTTPHEADER => ['Accept: application/pdf,*/*;q=0.8'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $this->pinValidatedAddress($curlHandle, $currentUrl);
            if ($this->caBundlePath !== null) {
                curl_setopt($curlHandle, CURLOPT_CAINFO, $this->caBundlePath);
            }

            $executionResult = curl_exec($curlHandle);
            $curlErrorCode = curl_errno($curlHandle);
            $statusCode = (int)curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
            curl_close($curlHandle);
            fclose($fileHandle);

            if ($contentLength !== null && $contentLength > $maxBytes) {
                @unlink($temporaryFile);
                throw new ProcessingError('URL_TOO_LARGE');
            }

            if ($statusCode >= 300 && $statusCode < 400) {
                // Redirect targets are re-checked instead of being followed blindly by cURL.
                @unlink($temporaryFile);
                $redirectLocation = $responseHeaders['location'] ?? '';
                if (!is_string($redirectLocation) || trim($redirectLocation) === '') {
                    throw new ProcessingError('URL_CONTENT_UNAVAILABLE');
                }

                if ($redirectCount === self::MAX_REDIRECTS) {
                    throw new ProcessingError('URL_UNREACHABLE');
                }

                $currentUrl = $this->resolveRedirectLocation($currentUrl, $redirectLocation);
                continue;
            }

            if ($sawOversizedTransfer) {
                @unlink($temporaryFile);
                throw new ProcessingError('URL_TOO_LARGE');
            }

            if ($executionResult === false || $statusCode < 200 || $statusCode >= 300) {
                @unlink($temporaryFile);
                if ($executionResult === false) {
                    throw $this->networkFailure($curlErrorCode);
                }
                throw $this->remoteStatusFailure($statusCode);
            }

            if (!str_starts_with($pdfSignatureBytes, '%PDF-')) {
                @unlink($temporaryFile);
                throw new ProcessingError('URL_CONTENT_UNAVAILABLE');
            }

            // Return both the saved file and response metadata so the caller can validate the source type.
            return [
                'final_url' => $currentUrl,
                'content_type' => $responseHeaders['content-type'] ?? '',
                'file_path' => $temporaryFile,
            ];
        }

        throw new ProcessingError('URL_UNREACHABLE');
    }

    private function downloadTextWithRedirectValidation(string $url, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            throw new ProcessingError('WORKER_UNAVAILABLE');
        }
        $currentUrl = $url;
        for ($redirectCount = 0; $redirectCount <= self::MAX_REDIRECTS; $redirectCount++) {
            $this->normalizeAndValidateUrl($currentUrl);

            $responseHeaders = [];
            $downloadedBytes = 0;
            $contentLength = null;
            $sawOversizedTransfer = false;
            $body = '';

            $curlHandle = curl_init($currentUrl);
            if ($curlHandle === false) {
                throw new ProcessingError('WORKER_UNAVAILABLE');
            }

            curl_setopt_array($curlHandle, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FAILONERROR => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => function ($curl, string $headerLine) use (&$responseHeaders, &$contentLength): int {
                    $headerLength = strlen($headerLine);
                    $trimmedHeader = trim($headerLine);
                    if ($trimmedHeader === '' || !str_contains($trimmedHeader, ':')) {
                        return $headerLength;
                    }

                    [$name, $value] = explode(':', $trimmedHeader, 2);
                    $normalizedName = strtolower(trim($name));
                    $normalizedValue = trim($value);
                    $responseHeaders[$normalizedName] = $normalizedValue;
                    if ($normalizedName === 'content-length' && ctype_digit($normalizedValue)) {
                        $contentLength = (int)$normalizedValue;
                    }

                    return $headerLength;
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, $maxBytes, &$downloadedBytes, &$sawOversizedTransfer): int {
                    $chunkLength = strlen($chunk);
                    $downloadedBytes += $chunkLength;
                    if ($downloadedBytes > $maxBytes) {
                        $sawOversizedTransfer = true;
                        return 0;
                    }

                    $body .= $chunk;
                    return $chunkLength;
                },
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/plain;q=0.8,*/*;q=0.5'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $this->pinValidatedAddress($curlHandle, $currentUrl);
            if ($this->caBundlePath !== null) {
                curl_setopt($curlHandle, CURLOPT_CAINFO, $this->caBundlePath);
            }

            $executionResult = curl_exec($curlHandle);
            $curlErrorCode = curl_errno($curlHandle);
            $statusCode = (int)curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
            curl_close($curlHandle);

            if ($contentLength !== null && $contentLength > $maxBytes) {
                throw new ProcessingError('URL_TOO_LARGE');
            }

            if ($statusCode >= 300 && $statusCode < 400) {
                $redirectLocation = $responseHeaders['location'] ?? '';
                if (!is_string($redirectLocation) || trim($redirectLocation) === '') {
                    throw new ProcessingError('URL_CONTENT_UNAVAILABLE');
                }

                if ($redirectCount === self::MAX_REDIRECTS) {
                    throw new ProcessingError('URL_UNREACHABLE');
                }

                $currentUrl = $this->resolveRedirectLocation($currentUrl, $redirectLocation);
                continue;
            }

            if ($sawOversizedTransfer) {
                throw new ProcessingError('URL_TOO_LARGE');
            }

            if ($executionResult === false || $statusCode < 200 || $statusCode >= 300) {
                if ($executionResult === false) {
                    throw $this->networkFailure($curlErrorCode);
                }
                throw $this->remoteStatusFailure($statusCode);
            }

            return [
                'final_url' => $currentUrl,
                'content_type' => $responseHeaders['content-type'] ?? '',
                'body' => $body,
            ];
        }

        throw new ProcessingError('URL_UNREACHABLE');
    }

    /**
     * Pin DNS resolution to the pre-validated IP address to prevent DNS rebinding.
     * Accepts native \CurlHandle in production or test transport fixtures in test suites.
     *
     * @param \CurlHandle|object $curlHandle
     * @param string $url
     * @return void
     */
    private function pinValidatedAddress(object $curlHandle, string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string)$parts['host'], '[]'));
        // Prevent a second DNS answer from changing the destination between
        // validation and connection. TLS still verifies the original hostname.
        $address = $this->validatedAddresses[$host][0];
        $port = (int)($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
        curl_setopt($curlHandle, CURLOPT_PROXY, '');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            curl_setopt($curlHandle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $target]);
        }
    }

    private function networkFailure(int $curlErrorCode): ProcessingError
    {
        if ($curlErrorCode === CURLE_OPERATION_TIMEDOUT) {
            return new ProcessingError('URL_TIMEOUT');
        }

        // PHP/libcurl versions expose certificate verification code 60 under
        // different constant names (XAMPP commonly uses CURLE_SSL_CACERT).
        $certificateErrors = [60];
        foreach (['CURLE_PEER_FAILED_VERIFICATION', 'CURLE_SSL_CACERT', 'CURLE_SSL_CERTPROBLEM', 'CURLE_SSL_CACERT_BADFILE'] as $name) {
            if (defined($name)) $certificateErrors[] = constant($name);
        }
        if (in_array($curlErrorCode, $certificateErrors, true)) {
            return new ProcessingError('URL_TLS_FAILED');
        }

        return new ProcessingError('URL_UNREACHABLE');
    }

    private function remoteStatusFailure(int $statusCode): ProcessingError
    {
        if (in_array($statusCode, [401, 403, 407, 451], true)) {
            return new ProcessingError('URL_ACCESS_RESTRICTED');
        }
        if ($statusCode === 429) {
            return new ProcessingError('URL_RATE_LIMITED');
        }
        return new ProcessingError($statusCode >= 500 || $statusCode === 0
            ? 'URL_UNREACHABLE'
            : 'URL_CONTENT_UNAVAILABLE');
    }

    private function createTemporaryPdfPath(): string
    {
        $temporaryDirectory = RuntimePaths::temporaryDirectory('source-resolver');
        if (!RuntimePaths::ensureDirectory($temporaryDirectory, 0775)) {
            throw new ProcessingError('WORKER_UNAVAILABLE');
        }

        $basePath = tempnam($temporaryDirectory, 'remote_pdf_');
        if ($basePath === false) {
            throw new ProcessingError('WORKER_UNAVAILABLE');
        }

        $pdfPath = $basePath . '.pdf';
        if (!@rename($basePath, $pdfPath)) {
            @unlink($basePath);
            throw new ProcessingError('WORKER_UNAVAILABLE');
        }

        return $pdfPath;
    }

    private function resolveCaBundlePath(): ?string
    {
        $candidates = [
            ini_get('curl.cainfo') ?: null,
            ini_get('openssl.cafile') ?: null,
            getenv('CURL_CA_BUNDLE') ?: null,
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Lib' . DIRECTORY_SEPARATOR . 'site-packages' . DIRECTORY_SEPARATOR . 'certifi' . DIRECTORY_SEPARATOR . 'cacert.pem',
        ];

        $linuxCertifiMatches = glob(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'python*' . DIRECTORY_SEPARATOR . 'site-packages' . DIRECTORY_SEPARATOR . 'certifi' . DIRECTORY_SEPARATOR . 'cacert.pem'
        );
        if (is_array($linuxCertifiMatches)) {
            $candidates = array_merge($candidates, $linuxCertifiMatches);
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $normalizedCandidate = trim($candidate);
            if (is_file($normalizedCandidate) && is_readable($normalizedCandidate)) {
                return $normalizedCandidate;
            }
        }

        return null;
    }

    private function resolveRedirectLocation(string $currentUrl, string $redirectLocation): string
    {
        $target = trim($redirectLocation);
        if ($target === '') {
            throw new ProcessingError('URL_CONTENT_UNAVAILABLE');
        }

        if (preg_match('#^https?://#i', $target) === 1) {
            return $target;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
            throw new ProcessingError('URL_UNSAFE');
        }

        $currentParts = parse_url($currentUrl);
        if (!is_array($currentParts)) {
            throw new ProcessingError('URL_INVALID');
        }

        $scheme = (string)($currentParts['scheme'] ?? 'https');
        $host = (string)($currentParts['host'] ?? '');
        $port = isset($currentParts['port']) ? ':' . $currentParts['port'] : '';
        if ($host === '') {
            throw new ProcessingError('URL_INVALID');
        }

        if (str_starts_with($target, '//')) {
            return $scheme . ':' . $target;
        }

        if (str_starts_with($target, '/')) {
            return $scheme . '://' . $host . $port . $target;
        }

        $currentPath = (string)($currentParts['path'] ?? '/');
        $baseDirectory = preg_replace('#/[^/]*$#', '/', $currentPath) ?: '/';
        $combinedPath = $this->normalizeRelativePath($baseDirectory . $target);

        return $scheme . '://' . $host . $port . $combinedPath;
    }

    private function normalizeRelativePath(string $path): string
    {
        $segments = explode('/', $path);
        $normalizedSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalizedSegments);
                continue;
            }

            $normalizedSegments[] = $segment;
        }

        return '/' . implode('/', $normalizedSegments);
    }

    private function extractReadableHtmlText(string $html): string
    {
        $trimmedHtml = trim($html);
        if ($trimmedHtml === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($trimmedHtml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (\Throwable $throwable) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlSetting);
            return '';
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlSetting);

        $xpath = new \DOMXPath($document);
        $nonArticleContent = $xpath->query(
            '//script|//style|//nav|//header|//footer|//aside|//form|//noscript|//svg'
            . '|//sup[contains(concat(" ", normalize-space(@class), " "), " reference ")]'
            . '|//*[@role="navigation" or @role="banner" or @role="complementary" or @role="dialog"]'
            . '|//*[not(self::html) and not(self::body) and ('
            . '  contains(concat(" ", normalize-space(@class), " "), " reflist ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " references ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " navbox ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " infobox ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " hatnote ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " mw-editsection ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " catlinks ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " shortdescription ")'
            . '  or contains(concat(" ", normalize-space(@class), " "), " toc ")'
            . '  or @id="toc"'
            . '  or contains(@class, "mw-empty-elt")'
            . ')]'
            . '|//*[not(self::html) and not(self::body) and ('
            . '  contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "cookie")'
            . '  or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "consent")'
            . '  or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "advertisement")'
            . '  or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "sponsored")'
            . '  or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "related-content")'
            . '  or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "newsletter")'
            . ')]'
        );
        foreach ($nonArticleContent as $node) {
            $node->parentNode?->removeChild($node);
        }

        $contentNode = $xpath->query('//*[@id="mw-content-text"]//*[contains(@class, "mw-parser-output")]')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//main')->item(0)
            ?? $xpath->query('//body')->item(0);
        if (!$contentNode instanceof \DOMNode) {
            return '';
        }

        $contentBlocks = [];
        $skipAfterSections = ['references', 'external links', 'see also', 'further reading', 'notes', 'sources', 'bibliography'];
        $isSkipping = false;

        foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//p|.//li', $contentNode) as $contentNodePart) {
            $blockText = $this->normalizeWhitespace($contentNodePart->textContent ?? '');
            $tagName = strtolower($contentNodePart->nodeName);
            $isHeading = in_array($tagName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);

            if ($isHeading) {
                $cleanHeading = strtolower(trim((string)preg_replace('/[^a-z0-9 ]/i', '', $blockText)));
                if (in_array($cleanHeading, $skipAfterSections, true)) {
                    $isSkipping = true;
                    continue;
                }
            }

            if ($isSkipping) {
                continue;
            }

            // Strip residual inline bracket citation markers like [1], [24], [note 1]
            $blockText = trim((string)preg_replace('/\[\s*(?:\d+|[a-zA-Z]|note\s*\d+)\s*\]/i', '', $blockText));
            $blockText = $this->normalizeWhitespace($blockText);

            $wordCount = str_word_count($blockText);
            $minimumWords = $tagName === 'li' ? 3 : 6;
            if ($blockText === '' || ($isHeading ? $wordCount > 16 : $wordCount < $minimumWords)) {
                continue;
            }
            $contentBlocks[] = $blockText;
        }

        if ($contentBlocks !== []) {
            return implode("\n\n", $contentBlocks);
        }

        $fallbackText = $this->normalizeWhitespace($contentNode->textContent ?? '');
        $fallbackText = trim((string)preg_replace('/\[\s*(?:\d+|[a-zA-Z]|note\s*\d+)\s*\]/i', '', $fallbackText));
        if ($fallbackText === '' || str_word_count($fallbackText) < 6) {
            return '';
        }

        return $fallbackText;
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
