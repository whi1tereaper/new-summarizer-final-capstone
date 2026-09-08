<?php
namespace App\Src\Services;

require_once __DIR__ . '/LocalPythonBridge.php';
require_once __DIR__ . '/../Support/config.php';
require_once __DIR__ . '/../Support/RuntimePaths.php';

use App\Src\Support\RuntimePaths;

final class SourceResolution
{
    public function __construct(
        private string $text,
        private string $inputType,
        private array $temporaryPaths = [],
    ) {}

    public function getText(): string
    {
        return $this->text;
    }

    public function getInputType(): string
    {
        return $this->inputType;
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
    private const CURL_ERROR_OVERSIZED = '__oversized_transfer__';

    private LocalPythonBridge $pythonBridge;
    private ?string $caBundlePath;

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
            return new SourceResolution('', 'text');
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

    private function resolvePdfUrl(string $url): SourceResolution
    {
        // PDFs go through a download-plus-extraction path because the worker reads them from disk.
        $download = $this->downloadBinaryWithRedirectValidation($url, self::MAX_PDF_BYTES);
        $contentType = $this->normalizeContentType($download['content_type'] ?? '');
        $finalUrl = (string)($download['final_url'] ?? $url);

        if (!$this->isPdfResponse($finalUrl, $contentType)) {
            throw new \RuntimeException('The PDF link did not return a readable PDF file.');
        }

        $temporaryPdfPath = (string)($download['file_path'] ?? '');
        if ($temporaryPdfPath === '' || !is_file($temporaryPdfPath)) {
            throw new \RuntimeException('The PDF link could not be downloaded. Paste the article text instead.');
        }

        try {
            $extractedText = $this->pythonBridge->extractPdfText($temporaryPdfPath, 60);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException($this->normalizePdfExtractionError($exception->getMessage()), 0, $exception);
        }

        if (trim($extractedText) === '') {
            throw new \RuntimeException('No readable text was found in the PDF. Scanned PDFs need OCR before summarization.');
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
            throw new \RuntimeException('This URL is not a readable article page. Paste the article text instead.');
        }

        $readableText = $this->extractReadableHtmlText((string)($htmlFetch['body'] ?? ''));
        if ($readableText === '') {
            throw new \RuntimeException('Could not extract readable article text from this URL. Paste the article text instead.');
        }

        return new SourceResolution($readableText, 'url');
    }

    private function looksLikeStandaloneUrl(string $value): bool
    {
        return preg_match('/^(https?:\/\/\S+|www\.\S+)$/i', $value) === 1;
    }

    private function normalizeAndValidateUrl(string $rawUrl): string
    {
        // Normalize common user input like www.example.com, then block malformed or internal targets.
        $normalizedUrl = trim($rawUrl);
        if (str_starts_with(strtolower($normalizedUrl), 'www.')) {
            $normalizedUrl = 'https://' . $normalizedUrl;
        }

        $parts = parse_url($normalizedUrl);
        if (!is_array($parts)) {
            throw new \RuntimeException('Invalid URL');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('Invalid URL');
        }

        if (filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('Invalid URL');
        }

        $this->assertAllowedHost($host);
        $this->assertPublicIpTargets($host);

        return $normalizedUrl;
    }

    private function assertAllowedHost(string $host): void
    {
        // Disallow local hostnames so the URL feature cannot be used to probe the server itself.
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new \RuntimeException('Invalid URL');
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
            throw new \RuntimeException('Invalid URL');
        }

        foreach ($resolvedIps as $ipAddress) {
            if (!$this->isPublicIpAddress($ipAddress)) {
                throw new \RuntimeException('Invalid URL');
            }
        }
    }

    private function isPublicIpAddress(string $ipAddress): bool
    {
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

    private function isPdfResponse(string $url, string $contentType): bool
    {
        if ($contentType === 'application/pdf') {
            return true;
        }

        return $this->isDirectPdfUrl($url);
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
                throw new \RuntimeException('Unsupported source type');
            }

            $curlHandle = curl_init($currentUrl);
            if ($curlHandle === false) {
                fclose($fileHandle);
                @unlink($temporaryFile);
                throw new \RuntimeException('Unsupported source type');
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
                    return $written === false ? 0 : $chunkLength;
                },
                CURLOPT_HTTPHEADER => ['Accept: application/pdf,*/*;q=0.8'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($this->caBundlePath !== null) {
                curl_setopt($curlHandle, CURLOPT_CAINFO, $this->caBundlePath);
            }

            $executionResult = curl_exec($curlHandle);
            $curlError = curl_error($curlHandle);
            $statusCode = (int)curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
            fclose($fileHandle);

            if ($contentLength !== null && $contentLength > $maxBytes) {
                @unlink($temporaryFile);
                throw new \RuntimeException('PDF too large');
            }

            if ($statusCode >= 300 && $statusCode < 400) {
                // Redirect targets are re-checked instead of being followed blindly by cURL.
                @unlink($temporaryFile);
                $redirectLocation = $responseHeaders['location'] ?? '';
                if (!is_string($redirectLocation) || trim($redirectLocation) === '') {
                    throw new \RuntimeException('Invalid URL');
                }

                if ($redirectCount === self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Invalid URL');
                }

                $currentUrl = $this->resolveRedirectLocation($currentUrl, $redirectLocation);
                continue;
            }

            if ($sawOversizedTransfer) {
                @unlink($temporaryFile);
                throw new \RuntimeException('PDF too large');
            }

            if ($executionResult === false || $statusCode < 200 || $statusCode >= 300) {
                @unlink($temporaryFile);
                throw new \RuntimeException('Invalid URL');
            }

            if (!str_starts_with($pdfSignatureBytes, '%PDF-')) {
                @unlink($temporaryFile);
                throw new \RuntimeException('Unsupported source type');
            }

            // Return both the saved file and response metadata so the caller can validate the source type.
            return [
                'final_url' => $currentUrl,
                'content_type' => $responseHeaders['content-type'] ?? '',
                'file_path' => $temporaryFile,
            ];
        }

        throw new \RuntimeException('Invalid URL');
    }

    private function downloadTextWithRedirectValidation(string $url, int $maxBytes): array
    {
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
                throw new \RuntimeException('Unsupported source type');
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
            if ($this->caBundlePath !== null) {
                curl_setopt($curlHandle, CURLOPT_CAINFO, $this->caBundlePath);
            }

            $executionResult = curl_exec($curlHandle);
            $curlError = curl_error($curlHandle);
            $statusCode = (int)curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);

            if ($contentLength !== null && $contentLength > $maxBytes) {
                throw new \RuntimeException('Unsupported source type');
            }

            if ($statusCode >= 300 && $statusCode < 400) {
                $redirectLocation = $responseHeaders['location'] ?? '';
                if (!is_string($redirectLocation) || trim($redirectLocation) === '') {
                    throw new \RuntimeException('Invalid URL');
                }

                if ($redirectCount === self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Invalid URL');
                }

                $currentUrl = $this->resolveRedirectLocation($currentUrl, $redirectLocation);
                continue;
            }

            if ($sawOversizedTransfer) {
                throw new \RuntimeException('Unsupported source type');
            }

            if ($executionResult === false || $statusCode < 200 || $statusCode >= 300) {
                $normalizedError = trim($curlError);
                throw new \RuntimeException($normalizedError === '' ? 'Invalid URL' : 'Invalid URL');
            }

            return [
                'final_url' => $currentUrl,
                'content_type' => $responseHeaders['content-type'] ?? '',
                'body' => $body,
            ];
        }

        throw new \RuntimeException('Invalid URL');
    }

    private function createTemporaryPdfPath(): string
    {
        $temporaryDirectory = RuntimePaths::temporaryDirectory('source-resolver');
        if (!RuntimePaths::ensureDirectory($temporaryDirectory, 0775)) {
            throw new \RuntimeException('Temporary storage is not writable.');
        }

        $basePath = tempnam($temporaryDirectory, 'remote_pdf_');
        if ($basePath === false) {
            throw new \RuntimeException('Unsupported source type');
        }

        $pdfPath = $basePath . '.pdf';
        if (!@rename($basePath, $pdfPath)) {
            @unlink($basePath);
            throw new \RuntimeException('Unsupported source type');
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
            throw new \RuntimeException('Invalid URL');
        }

        if (preg_match('#^https?://#i', $target) === 1) {
            return $target;
        }

        $currentParts = parse_url($currentUrl);
        if (!is_array($currentParts)) {
            throw new \RuntimeException('Invalid URL');
        }

        $scheme = (string)($currentParts['scheme'] ?? 'https');
        $host = (string)($currentParts['host'] ?? '');
        $port = isset($currentParts['port']) ? ':' . $currentParts['port'] : '';
        if ($host === '') {
            throw new \RuntimeException('Invalid URL');
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
        foreach ($xpath->query('//script|//style|//nav|//header|//footer|//aside|//form|//noscript|//svg') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $contentNode = $xpath->query('//article[1] | //main[1] | //body[1]')->item(0);
        if (!$contentNode instanceof \DOMNode) {
            return '';
        }

        $paragraphs = [];
        foreach ($xpath->query('.//p', $contentNode) as $paragraphNode) {
            $paragraphText = $this->normalizeWhitespace($paragraphNode->textContent ?? '');
            if ($paragraphText === '' || str_word_count($paragraphText) < 6) {
                continue;
            }
            $paragraphs[] = $paragraphText;
        }

        if ($paragraphs !== []) {
            return implode("\n\n", $paragraphs);
        }

        $fallbackText = $this->normalizeWhitespace($contentNode->textContent ?? '');
        if ($fallbackText === '' || str_word_count($fallbackText) < 6) {
            return '';
        }

        return $fallbackText;
    }

    private function normalizePdfExtractionError(string $message): string
    {
        $normalized = strtolower(trim($message));
        if (
            str_contains($normalized, 'scanned')
            || str_contains($normalized, 'image-only')
            || str_contains($normalized, 'does not contain readable text')
            || str_contains($normalized, 'does not contain enough readable text')
        ) {
            return 'No readable text was found in the PDF. Scanned PDFs need OCR before summarization.';
        }

        if (str_contains($normalized, 'not found')) {
            return 'Invalid URL';
        }

        if (str_contains($normalized, 'not a pdf') || str_contains($normalized, 'valid text-based pdf')) {
            return 'Unsupported source type';
        }

        return 'No readable text found';
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
