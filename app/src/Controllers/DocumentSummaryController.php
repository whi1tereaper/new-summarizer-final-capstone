<?php
declare(strict_types=1);

namespace App\Src\Controllers;

require_once __DIR__ . '/../Services/DocumentSummaryService.php';

use App\Src\Services\DocumentSummaryService;
use InvalidArgumentException;
use OutOfBoundsException;
use Throwable;

/**
 * Controller handling combinable document and summary API endpoints.
 */
class DocumentSummaryController
{
    private DocumentSummaryService $service;
    private int $lastStatusCode = 200;

    public function __construct(?DocumentSummaryService $service = null)
    {
        $this->service = $service ?? new DocumentSummaryService();
    }

    public function getLastStatusCode(): int
    {
        return $this->lastStatusCode;
    }

    /**
     * Dispatch an incoming request based on HTTP method and URI.
     */
    public function dispatch(?string $method = null, ?string $uri = null, ?array $body = null): void
    {
        $method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawUri = $uri ?? ($_SERVER['REQUEST_URI'] ?? '/api/documents');
        $path = parse_url($rawUri, PHP_URL_PATH) ?? '/';
        $path = rawurldecode($path);

        try {
            // Match /api/documents routes
            if (preg_match('#/api/documents(?P<subpath>/.*)?$#i', $path, $matches)) {
                $subpath = trim((string)($matches['subpath'] ?? ''), '/');

                // 1. POST /api/documents
                if ($subpath === '') {
                    if ($method === 'POST') {
                        $this->handleCreateDocument($body);
                        return;
                    }
                    $this->sendJson(405, ['error' => "Method {$method} not allowed for /api/documents. Expected POST."]);
                    return;
                }

                // 2. /api/documents/:documentId/summaries/batch
                if (preg_match('#^(\d+)/summaries/batch$#i', $subpath, $m)) {
                    $documentId = (int)$m[1];
                    if ($method === 'POST') {
                        $this->handleBatchSummaries($documentId, $body);
                        return;
                    }
                    $this->sendJson(405, ['error' => "Method {$method} not allowed for /api/documents/{$documentId}/summaries/batch. Expected POST."]);
                    return;
                }

                // 3. /api/documents/:documentId/summaries
                if (preg_match('#^(\d+)/summaries$#i', $subpath, $m)) {
                    $documentId = (int)$m[1];
                    if ($method === 'POST') {
                        $this->handleGetOrGenerateSummary($documentId, $body);
                        return;
                    }
                    if ($method === 'GET') {
                        $this->handleGetSummaries($documentId);
                        return;
                    }
                    $this->sendJson(405, ['error' => "Method {$method} not allowed for /api/documents/{$documentId}/summaries. Expected GET or POST."]);
                    return;
                }

                // 4. /api/documents/:documentId
                if (preg_match('#^(\d+)$#i', $subpath, $m)) {
                    $documentId = (int)$m[1];
                    if ($method === 'GET') {
                        $doc = $this->service->getDocument($documentId);
                        if ($doc === null) {
                            $this->sendJson(404, ['error' => "Document with ID {$documentId} not found."]);
                            return;
                        }
                        $this->sendJson(200, [
                            'documentId' => (int)$doc['id'],
                            'content' => $doc['content'],
                            'createdAt' => $doc['created_at'],
                        ]);
                        return;
                    }
                }
            }

            $this->sendJson(404, ['error' => 'Endpoint not found.']);
        } catch (InvalidArgumentException $e) {
            $this->sendJson(400, ['error' => $e->getMessage()]);
        } catch (OutOfBoundsException $e) {
            $this->sendJson(404, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[DocumentSummaryController] Server error: ' . $e->getMessage());
            $this->sendJson(500, ['error' => 'Internal server error: ' . $e->getMessage()]);
        }
    }

    /**
     * POST /api/documents
     */
    public function handleCreateDocument(?array $body = null): void
    {
        $payload = $body ?? $this->readJsonBody();
        $text = (string)($payload['text'] ?? '');

        if (trim($text) === '') {
            $this->sendJson(400, ['error' => "The 'text' field is required and cannot be empty."]);
            return;
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $guestToken = $_SESSION['guest_token'] ?? null;

        $wantsStream = $this->wantsStreaming($payload);

        if ($wantsStream) {
            $this->streamCreateDocumentResponse($text, $userId, $guestToken);
            return;
        }

        $result = $this->service->createDocumentWithDefaultSummary($text, $userId, $guestToken);
        $this->sendJson(201, $result);
    }

    /**
     * POST /api/documents/:documentId/summaries
     */
    public function handleGetOrGenerateSummary(int $documentId, ?array $body = null): void
    {
        $payload = $body ?? $this->readJsonBody();

        if (!isset($payload['profile']) || !is_string($payload['profile'])) {
            $this->sendJson(400, ['error' => "The 'profile' field is required. Allowed profiles: " . implode(', ', DocumentSummaryService::ALLOWED_PROFILES) . '.']);
            return;
        }

        if (!isset($payload['length']) || !is_string($payload['length'])) {
            $this->sendJson(400, ['error' => "The 'length' field is required. Allowed lengths: " . implode(', ', DocumentSummaryService::ALLOWED_LENGTHS) . '.']);
            return;
        }

        $result = $this->service->getOrGenerateSummary($documentId, $payload['profile'], $payload['length']);
        $this->sendJson(200, $result);
    }

    /**
     * GET /api/documents/:documentId/summaries
     */
    public function handleGetSummaries(int $documentId): void
    {
        $summaries = $this->service->getSummaries($documentId);
        $this->sendJson(200, $summaries);
    }

    /**
     * POST /api/documents/:documentId/summaries/batch
     */
    public function handleBatchSummaries(int $documentId, ?array $body = null): void
    {
        $payload = $body ?? $this->readJsonBody();
        $combinations = $payload['combinations'] ?? null;

        if (!is_array($combinations) || empty($combinations)) {
            $this->sendJson(400, ['error' => "The 'combinations' field must be a non-empty array of {profile, length} objects."]);
            return;
        }

        $results = $this->service->batchGetOrGenerateSummaries($documentId, $combinations);
        $this->sendJson(200, $results);
    }

    /**
     * Check whether the client requested SSE or chunked streaming.
     */
    private function wantsStreaming(array $payload): bool
    {
        if (!empty($payload['stream'])) {
            return true;
        }
        if (isset($_GET['stream']) && filter_var($_GET['stream'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'text/event-stream');
    }

    /**
     * Stream synchronous default-summary creation using Server-Sent Events.
     */
    private function streamCreateDocumentResponse(string $text, ?int $userId, ?string $guestToken): void
    {
        // Disable output buffering for live chunk flushing
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $documentId = $this->service->createDocument($text, $userId, $guestToken);

        echo "event: document\ndata: " . json_encode(['documentId' => $documentId]) . "\n\n";
        flush();

        $summaryResult = $this->service->getOrGenerateSummary($documentId, 'general', 'balanced');
        $summaryText = $summaryResult['summary'];

        // Stream text in small sentence/clause chunks
        $chunks = preg_split('/(?<=[.?!])\s+/u', $summaryText, -1, PREG_SPLIT_NO_EMPTY) ?: [$summaryText];
        foreach ($chunks as $chunk) {
            echo "event: chunk\ndata: " . json_encode(['chunk' => $chunk]) . "\n\n";
            flush();
            usleep(25000); // 25ms delay for smooth frontend rendering
        }

        $finalPayload = array_merge([
            'documentId' => $documentId,
        ], $summaryResult, [
            'defaultSummary' => $summaryResult,
        ]);

        echo "event: done\ndata: " . json_encode($finalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
        exit;
    }

    /**
     * Read and decode JSON request body.
     */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $_POST ?: [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException("Malformed JSON in request body.");
        }

        return $decoded;
    }

    /**
     * Send JSON response and exit cleanly.
     */
    private function sendJson(int $statusCode, array $data): void
    {
        $this->lastStatusCode = $statusCode;
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        } else {
            @http_response_code($statusCode);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (PHP_SAPI !== 'cli') {
            exit;
        }
    }
}
