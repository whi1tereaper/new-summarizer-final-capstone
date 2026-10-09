<?php
declare(strict_types=1);

namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/SummarizerPromptTemplate.php';
require_once __DIR__ . '/LocalPythonBridge.php';

use App\Src\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;

/**
 * Service for on-demand, combinable (profile, length) document summarization with relational caching.
 */
class DocumentSummaryService
{
    public const ALLOWED_PROFILES = SummarizerPromptTemplate::ALLOWED_PROFILES;
    public const ALLOWED_LENGTHS = SummarizerPromptTemplate::ALLOWED_LENGTHS;
    public const MAX_TEXT_BYTES = 200000;
    public const NONSENSE_NOTE = 'source is too short to expand';

    private PDO $db;
    private LocalPythonBridge $pythonBridge;

    public function __construct(?PDO $db = null, ?LocalPythonBridge $pythonBridge = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->pythonBridge = $pythonBridge ?? new LocalPythonBridge();
    }

    /**
     * Store a source document in the database and return its documentId.
     */
    public function createDocument(string $text, ?int $userId = null, ?string $guestToken = null): int
    {
        $normalizedText = trim($text);
        if ($normalizedText === '') {
            throw new InvalidArgumentException("Document text cannot be empty.");
        }

        if (strlen($normalizedText) > self::MAX_TEXT_BYTES) {
            throw new InvalidArgumentException("Document text exceeds maximum allowed size of " . self::MAX_TEXT_BYTES . " bytes.");
        }

        $stmt = $this->db->prepare(
            'INSERT INTO documents (content, user_id, guest_token, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$normalizedText, $userId, $guestToken]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Retrieve a document record by ID.
     */
    public function getDocument(int $documentId): ?array
    {
        if ($documentId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM documents WHERE id = ? LIMIT 1');
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Validate profile and length, throwing InvalidArgumentException on invalid values.
     */
    public function validateProfileAndLength(string $profile, string $length): array
    {
        $normProfile = strtolower(trim($profile));
        $normLength = strtolower(trim($length));

        if (!in_array($normProfile, self::ALLOWED_PROFILES, true)) {
            throw new InvalidArgumentException(
                "Invalid profile '{$profile}'. Allowed profiles: " . implode(', ', self::ALLOWED_PROFILES) . '.'
            );
        }

        if (!in_array($normLength, self::ALLOWED_LENGTHS, true)) {
            throw new InvalidArgumentException(
                "Invalid length '{$length}'. Allowed lengths: " . implode(', ', self::ALLOWED_LENGTHS) . '.'
            );
        }

        return [$normProfile, $normLength];
    }

    /**
     * Guard against nonsense requests where expanding a short source is unfeasible without hallucinating.
     */
    public function isNonsenseRequest(string $sourceText, string $length): bool
    {
        $normLength = strtolower(trim($length));
        $words = $this->countWords($sourceText);

        if ($words < 10) {
            return true;
        }
        if ($words < 50 && $normLength === 'comprehensive') {
            return true;
        }
        if ($words < 25 && $normLength === 'detailed') {
            return true;
        }

        return false;
    }

    /**
     * Compute actual word count in code.
     */
    public function countWords(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }
        $words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    /**
     * Compute compression ratio: 1 - (summary_words / source_words) clamped to [0.00, 1.00].
     */
    public function calculateCompressionRatio(int $sourceWordCount, int $summaryWordCount): float
    {
        if ($sourceWordCount <= 0) {
            return 0.0;
        }
        $ratio = 1.0 - ($summaryWordCount / $sourceWordCount);
        return round(max(0.0, min(1.0, $ratio)), 2);
    }

    /**
     * Compute estimated reading time based on standard 200 words per minute.
     */
    public function calculateReadingTimeSeconds(int $wordCount): int
    {
        if ($wordCount <= 0) {
            return 0;
        }
        return (int) round(($wordCount / 200.0) * 60);
    }

    /**
     * Refactored generation function: (sourceText, profile, length) -> { summary, metadata }.
     */
    public function generateSummary(string $sourceText, string $profile, string $length): array
    {
        [$normProfile, $normLength] = $this->validateProfileAndLength($profile, $length);
        $cleanSource = trim($sourceText);
        $sourceWordCount = $this->countWords($cleanSource);

        if ($this->isNonsenseRequest($cleanSource, $normLength)) {
            $summaryText = self::NONSENSE_NOTE;
        } else {
            try {
                $workerResult = $this->pythonBridge->generateSummary($cleanSource, $normProfile, $normLength);
                $summaryText = trim($workerResult['summary'] ?? '');
                if ($summaryText === '') {
                    $summaryText = trim($workerResult['plain_summary'] ?? '');
                }
            } catch (\Throwable $e) {
                error_log("[DocumentSummaryService] Worker generation failed: " . $e->getMessage());
                // Fallback attempt via standard summarize contract
                try {
                    $workerResult = $this->pythonBridge->summarize([
                        'text' => $cleanSource,
                        'selection_mode' => $normProfile,
                        'summary_depth' => $normLength,
                        'summary_length' => $normLength,
                    ]);
                    $summaryText = trim($workerResult['plain_summary'] ?? '');
                } catch (\Throwable $inner) {
                    throw new RuntimeException("Summarization engine failed: " . $e->getMessage(), 0, $e);
                }
            }
        }

        if ($summaryText === '') {
            $summaryText = self::NONSENSE_NOTE;
        }

        $summaryWordCount = $this->countWords($summaryText);
        $compressionRatio = $this->calculateCompressionRatio($sourceWordCount, $summaryWordCount);
        $readingTime = $this->calculateReadingTimeSeconds($summaryWordCount);

        $metadata = [
            'profile' => $normProfile,
            'length' => $normLength,
            'wordCount' => $summaryWordCount,
            'sourceWordCount' => $sourceWordCount,
            'compressionRatio' => $compressionRatio,
            'estimatedReadingTimeSeconds' => $readingTime,
        ];

        return [
            'summary' => $summaryText,
            'metadata' => $metadata,
        ];
    }

    /**
     * On-demand cached generation for a specific (documentId, profile, length) combination.
     */
    public function getOrGenerateSummary(int $documentId, string $profile, string $length): array
    {
        [$normProfile, $normLength] = $this->validateProfileAndLength($profile, $length);

        $document = $this->getDocument($documentId);
        if ($document === null) {
            throw new OutOfBoundsException("Document with ID {$documentId} not found.");
        }

        // 1. Check cache first
        $cacheStmt = $this->db->prepare(
            'SELECT * FROM document_summaries WHERE document_id = ? AND profile = ? AND length = ? LIMIT 1'
        );
        $cacheStmt->execute([$documentId, $normProfile, $normLength]);
        $cachedRow = $cacheStmt->fetch(PDO::FETCH_ASSOC);

        if ($cachedRow) {
            return $this->formatSummaryResponse($cachedRow, true);
        }

        // 2. Generate on cache miss
        $generated = $this->generateSummary($document['content'], $normProfile, $normLength);
        $summaryText = $generated['summary'];
        $meta = $generated['metadata'];

        // 3. Store in cache table
        $insertStmt = $this->db->prepare(
            'INSERT INTO document_summaries (document_id, profile, length, summary, word_count, source_word_count, compression_ratio, estimated_reading_time_seconds, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                summary = VALUES(summary),
                word_count = VALUES(word_count),
                source_word_count = VALUES(source_word_count),
                compression_ratio = VALUES(compression_ratio),
                estimated_reading_time_seconds = VALUES(estimated_reading_time_seconds)'
        );
        $insertStmt->execute([
            $documentId,
            $normProfile,
            $normLength,
            $summaryText,
            $meta['wordCount'],
            $meta['sourceWordCount'],
            $meta['compressionRatio'],
            $meta['estimatedReadingTimeSeconds'],
        ]);

        $rowStmt = $this->db->prepare(
            'SELECT * FROM document_summaries WHERE document_id = ? AND profile = ? AND length = ? LIMIT 1'
        );
        $rowStmt->execute([$documentId, $normProfile, $normLength]);
        $storedRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

        return $this->formatSummaryResponse($storedRow ?: [
            'profile' => $normProfile,
            'length' => $normLength,
            'summary' => $summaryText,
            'word_count' => $meta['wordCount'],
            'source_word_count' => $meta['sourceWordCount'],
            'compression_ratio' => $meta['compressionRatio'],
            'estimated_reading_time_seconds' => $meta['estimatedReadingTimeSeconds'],
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ], false);
    }

    /**
     * Create document and synchronously return the default summary (profile: "general", length: "balanced").
     */
    public function createDocumentWithDefaultSummary(string $text, ?int $userId = null, ?string $guestToken = null): array
    {
        $documentId = $this->createDocument($text, $userId, $guestToken);
        $defaultSummary = $this->getOrGenerateSummary($documentId, 'general', 'balanced');

        return array_merge([
            'documentId' => $documentId,
        ], $defaultSummary, [
            'defaultSummary' => $defaultSummary,
        ]);
    }

    /**
     * Retrieve all combinations generated so far for a document.
     */
    public function getSummaries(int $documentId): array
    {
        $document = $this->getDocument($documentId);
        if ($document === null) {
            throw new OutOfBoundsException("Document with ID {$documentId} not found.");
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM document_summaries WHERE document_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->formatSummaryResponse($row, true);
        }

        return $results;
    }

    /**
     * Batch generate or retrieve multiple requested combinations.
     */
    public function batchGetOrGenerateSummaries(int $documentId, array $combinations): array
    {
        $document = $this->getDocument($documentId);
        if ($document === null) {
            throw new OutOfBoundsException("Document with ID {$documentId} not found.");
        }

        if (empty($combinations)) {
            return [];
        }

        // Validate all combination specs before executing any work
        $normalizedCombinations = [];
        foreach ($combinations as $idx => $combo) {
            if (!is_array($combo) || !isset($combo['profile']) || !isset($combo['length'])) {
                throw new InvalidArgumentException("Combination at index {$idx} must contain 'profile' and 'length'.");
            }
            [$p, $l] = $this->validateProfileAndLength((string)$combo['profile'], (string)$combo['length']);
            $normalizedCombinations[] = ['profile' => $p, 'length' => $l];
        }

        $results = [];
        foreach ($normalizedCombinations as $combo) {
            $results[] = $this->getOrGenerateSummary($documentId, $combo['profile'], $combo['length']);
        }

        return $results;
    }

    private function formatSummaryResponse(array $row, bool $cached): array
    {
        $createdAt = $row['created_at'] ?? 'now';
        try {
            $dt = new DateTimeImmutable((string)$createdAt, new DateTimeZone('UTC'));
            $generatedAt = $dt->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
        }

        return [
            'profile' => (string)($row['profile'] ?? ''),
            'length' => (string)($row['length'] ?? ''),
            'summary' => (string)($row['summary'] ?? ''),
            'wordCount' => (int)($row['word_count'] ?? 0),
            'sourceWordCount' => (int)($row['source_word_count'] ?? 0),
            'compressionRatio' => (float)($row['compression_ratio'] ?? 0.0),
            'estimatedReadingTimeSeconds' => (int)($row['estimated_reading_time_seconds'] ?? 0),
            'cached' => $cached,
            'generatedAt' => $generatedAt,
        ];
    }
}
