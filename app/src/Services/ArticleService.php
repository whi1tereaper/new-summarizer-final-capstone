<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Support/config.php';
require_once __DIR__ . '/../Utils/Privacy.php';
require_once __DIR__ . '/../Utils/validation.php';
require_once __DIR__ . '/LocalPythonBridge.php';
require_once __DIR__ . '/SourceResolver.php';
require_once __DIR__ . '/FileUploadService.php';

use App\Src\Database;
use App\Src\Utils\Privacy;
use PDO;

final class ArticleService
{
    // Keep style and size limits here so both the form handler and worker handoff use the same rules.
    public const ALLOWED_STYLES = [
        'standard_paragraph',
        'bullet_points',
        'hybrid',
        'executive_summary',
        'academic_summary',
        'simple_summary',
        'technical_summary',
        'news_summary',
    ];
    public const MIN_SUMMARY_COUNT = 3;
    public const MAX_SUMMARY_COUNT = 15;
    public const MAX_TEXT_BYTES = 200000;

    private PDO $db;
    private FileUploadService $uploadService;
    private SourceResolver $sourceResolver;
    private LocalPythonBridge $pythonBridge;

    public function __construct(
        ?FileUploadService $uploadService = null,
        ?SourceResolver $sourceResolver = null,
        ?LocalPythonBridge $pythonBridge = null
    ) {
        // Dependency injection keeps this service testable, but production still gets sensible defaults.
        $this->db = Database::getInstance()->getConnection();
        $this->uploadService = $uploadService ?? new FileUploadService();
        $this->sourceResolver = $sourceResolver ?? new SourceResolver();
        $this->pythonBridge = $pythonBridge ?? new LocalPythonBridge();
    }

    public function buildPendingSummary(array $request, array $files, ?int $userId, ?string $guestToken): array
    {
        // Normalize raw form input once so later steps can trust the session payload shape.
        $originalText = $this->normalizeOriginalText($request['original_text'] ?? '');
        $filePath = $this->resolveUploadedFilePath($files['pdf_file'] ?? null);
        // A document upload is an explicit source choice. Do not retain a pasted
        // source alongside it, since the worker correctly treats the file as
        // authoritative and retaining both makes the saved source ambiguous.
        if ($filePath !== '') {
            $originalText = '';
        }
        $summaryLength = $this->normalizeSummaryLength(
            $request['summary_length'] ?? null,
            $request['sentence_count'] ?? null
        );

        $documentTitle = trim(preg_replace('/\s+/u', ' ', (string)($request['document_title'] ?? '')) ?? '');
        $selectionMode = trim((string)($request['selection_mode'] ?? ''));

        return [
            'original_text' => $originalText,
            'file_path' => $filePath,
            'sentence_count' => $this->normalizeSummaryCount($request['sentence_count'] ?? 8),
            'summary_length' => $summaryLength,
            'summary_style' => $this->normalizeSummaryStyle($request['summary_style'] ?? 'standard_paragraph'),
            'document_title' => $documentTitle,
            'selection_mode' => $selectionMode,
            'user_id' => $userId,
            'guest_token' => $guestToken,
            'csrf_token' => $request['csrf_token'] ?? '',
        ];
    }

    public function resolvePendingSummarySource(string $originalText, string $filePath): array
    {
        // Uploaded files already have a concrete source path, but pasted URLs still need to be resolved into text.
        if ($filePath !== '') {
            return [
                'text' => $originalText,
                'input_type' => pathinfo($filePath, PATHINFO_EXTENSION),
                'resolved_source' => null,
            ];
        }

        $resolvedSource = $this->sourceResolver->resolve($originalText);
        return [
            'text' => $resolvedSource->getText(),
            'input_type' => $resolvedSource->getInputType(),
            'resolved_source' => $resolvedSource,
        ];
    }

    public function requestSummary(
        string $originalText,
        string $filePath,
        int $sentenceCount,
        string $summaryStyle,
        string $summaryLength = 'balanced',
        string $documentTitle = '',
        string $selectionMode = ''
    ): array {
        try {
            // Send only the worker-facing data contract; preprocessing flags stay server-owned.
            $preprocessing = $this->summaryPreprocessingOptions($selectionMode);
            return $this->pythonBridge->summarize([
                'text' => $originalText,
                'file_path' => $filePath,
                'sentence_count' => $sentenceCount,
                'summary_length' => $this->normalizeSummaryLength($summaryLength, $sentenceCount),
                'summary_style' => $summaryStyle,
                'selection_mode' => $selectionMode !== '' ? $selectionMode : $summaryStyle,
                'document_title' => $documentTitle,
                'preprocessing' => $preprocessing,
            ], 60);
        } catch (\RuntimeException $exception) {
            // Preserve the detailed worker failure in logs while giving controllers a cleaner message.
            error_log('[ArticleService] Summarization error: ' . $exception->getMessage());
            throw new \RuntimeException($this->normalizeWorkerErrorMessage($exception->getMessage()), 0, $exception);
        }
    }

    public function storeSummary(
        ?int $userId,
        ?string $guestToken,
        array $summaryResult,
        string $inputType,
        string $summaryStyle,
        string $originalText,
        string $summaryLength = 'balanced',
        ?float $processingTime = null
    ): string {
        $summaryResult = $this->normalizeContractSummaryResult($summaryResult);

        // Normalize every optional worker field before storing anything in JSON or the database.
        $summaryBlocks = $this->normalizeSummaryBlocks($summaryResult['sentences'] ?? []);
        $overview = $this->normalizeTextList($summaryResult['overview'] ?? []);
        $plainSummary = $this->normalizePlainSummary(
            $summaryResult['plain_summary'] ?? '',
            $overview,
            $summaryBlocks,
            $summaryResult['conclusion'] ?? ''
        );
        $keywords = $this->normalizeKeywords($summaryResult['keywords'] ?? []);
        $importantTerms = $this->normalizeImportantTerms($summaryResult['important_terms'] ?? []);
        $keyPoints = $this->normalizeKeywords($summaryResult['key_points'] ?? []);
        $overallSummaryBullets = $this->normalizeOverallSummaryBullets($summaryResult['overall_summary_bullets'] ?? []);
        $structuredSummary = $this->normalizeStructuredSummary($summaryResult['structured_summary'] ?? []);
        $paragraphSummaries = $this->normalizeParagraphSummaries($summaryResult['paragraph_summaries'] ?? []);
        $conclusion = $this->normalizeConclusion($summaryResult['conclusion'] ?? '');
        $articleType = $this->normalizeArticleType($summaryResult['article_type'] ?? '');
        $readability = $this->normalizeReadabilityData($summaryResult['readability'] ?? []);
        $summaryMethod = $this->normalizeSummaryMethod($summaryResult['summary_method'] ?? []);
        $sourceMetadata = $this->normalizeSourceMetadata($summaryResult['source_metadata'] ?? []);
        $rawText = $summaryResult['raw_text'] ?? $originalText;
        $storedSourceText = Privacy::maskSensitiveText((string)$rawText);
        $title = trim((string)($summaryResult['title'] ?? 'Generated Summary')) ?: 'Generated Summary';
        $excludedSections = $this->normalizeTextList($summaryResult['excluded_sections'] ?? []);
        $originalWordCount = $this->countWords($originalText);
        $summaryWordCount = $this->countWords($plainSummary);

        // Store one structured payload so the result page can render richer views without extra tables.
        $summaryJson = json_encode([
            'unit' => 'sentence',
            'blocks' => $summaryBlocks,
            'overview' => $overview,
            'plain_summary' => $plainSummary,
            'overall_summary_bullets' => $overallSummaryBullets,
            'keywords' => $keywords,
            'important_terms' => $importantTerms,
            'article_type' => $articleType,
            'key_points' => $keyPoints,
            'conclusion' => $conclusion,
            'structured_summary' => $structuredSummary,
            'paragraph_summaries' => $paragraphSummaries,
            'excluded_sections' => $excludedSections,
            'readability' => $readability,
            'summary_method' => $summaryMethod,
            'source_metadata' => $sourceMetadata,
            'selection_mode' => (string)($summaryResult['selection_mode'] ?? ''),
            'profile_label' => (string)($summaryResult['profile_label'] ?? ''),
            'active_profile_weights' => (array)($summaryResult['active_profile_weights'] ?? []),
            'validation_passed' => (bool)($summaryResult['validation_passed'] ?? true),
            'validation_notes' => (array)($summaryResult['validation_notes'] ?? []),
            'original_word_count' => $originalWordCount,
            'summary_word_count' => $summaryWordCount,
            'processing_time' => $processingTime,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($summaryJson === false) {
            throw new \RuntimeException('Could not encode generated summary.');
        }

        // Prepared statements keep summary content out of raw SQL and preserve the insert contract.
        try {
            $this->db->beginTransaction();

            $statement = $this->db->prepare(
                'INSERT INTO summaries (user_id, guest_token, share_token, article_title, input_type, summary_style, summary_length, article_category, original_word_count, summary_word_count, processing_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            // Share tokens let the app expose a stable public identifier without leaking numeric IDs.
            $shareToken = bin2hex(random_bytes(32));
            $statement->execute([
                $userId,
                $guestToken,
                $shareToken,
                $title,
                $inputType,
                $summaryStyle,
                $this->normalizeSummaryLength($summaryLength),
                $articleType !== '' ? $articleType : null,
                $originalWordCount,
                $summaryWordCount,
                $processingTime,
                'completed'
            ]);

            $summaryId = $this->db->lastInsertId();

            $artifactStatement = $this->db->prepare(
                'INSERT INTO summary_artifacts (summary_id, original_text, generated_summary) VALUES (?, ?, ?)'
            );
            $artifactStatement->execute([
                $summaryId,
                $storedSourceText,
                $summaryJson,
            ]);

            $this->db->commit();
            return (string)$summaryId;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[ArticleService] Failed to store summary: ' . $e->getMessage());
            throw new \RuntimeException('Failed to store summary: ' . $e->getMessage(), 0, $e);
        }
    }

    private function normalizeContractSummaryResult(array $summaryResult): array
    {
        if (!isset($summaryResult['thematic_paragraphs']) || !is_array($summaryResult['thematic_paragraphs'])) {
            return $summaryResult;
        }

        $meta = is_array($summaryResult['summary_meta'] ?? null)
            ? $summaryResult['summary_meta']
            : [];
        $overview = trim((string)($summaryResult['executive_overview'] ?? ''));
        $paragraphs = [];
        $sentences = [];

        foreach ($summaryResult['thematic_paragraphs'] as $paragraph) {
            if (!is_array($paragraph)) {
                continue;
            }

            $content = trim((string)($paragraph['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $paragraphs[] = [
                'paragraph_number' => (int)($paragraph['paragraph_id'] ?? count($paragraphs) + 1),
                'section' => trim((string)($paragraph['tag'] ?? '')),
                'purpose' => trim((string)($paragraph['headline'] ?? '')),
                'main_idea' => $content,
                'supporting_details' => [],
                'keywords' => [],
                'summary' => $content,
            ];
            $sentences[] = $content;
        }

        $keyPoints = [];
        foreach ($summaryResult['key_findings'] ?? [] as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $value = trim((string)($finding['value'] ?? ''));
            $context = trim((string)($finding['context'] ?? ''));
            if ($value !== '' && $context !== '') {
                $keyPoints[] = $value . ': ' . $context;
            } elseif ($context !== '') {
                $keyPoints[] = $context;
            }
        }

        return array_merge($summaryResult, [
            'title' => trim((string)($meta['title'] ?? '')) ?: 'Generated Summary',
            'article_type' => trim((string)($meta['doc_type'] ?? 'academic')),
            'sentences' => $sentences,
            'overview' => $overview === '' ? [] : [$overview],
            'plain_summary' => $overview !== '' ? $overview : implode(' ', $sentences),
            'key_points' => $keyPoints,
            'conclusion' => $sentences !== [] ? end($sentences) : '',
            'paragraph_summaries' => $paragraphs,
        ]);
    }

    public function cleanupTemporaryArtifacts(string $filePath, ?SourceResolution $resolvedSource): void
    {
        // Always clean both upload-backed files and URL-download leftovers from the same exit point.
        $this->uploadService->removeUploadedFile($filePath);
        $resolvedSource?->cleanup();
    }

    public function recordFailedSummary(?int $userId, ?string $guestToken, string $inputType, string $summaryStyle, string $summaryLength, float $processingTime): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO summaries (user_id, guest_token, share_token, article_title, input_type, summary_style, summary_length, processing_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $guestToken,
            bin2hex(random_bytes(32)),
            'Unsuccessful summary request',
            in_array($inputType, ['text', 'pdf', 'docx', 'url'], true) ? $inputType : 'text',
            $summaryStyle,
            $this->normalizeSummaryLength($summaryLength),
            $processingTime,
            'failed',
        ]);
    }

    private function countWords(string $text): int
    {
        $text = trim(strip_tags($text));
        if ($text === '') {
            return 0;
        }

        preg_match_all('/[\p{L}\p{N}]+(?:[\x27-][\p{L}\p{N}]+)*/u', $text, $matches);
        return count($matches[0] ?? []);
    }

    private function resolveUploadedFilePath(?array $uploadedFile): string
    {
        // An empty upload is valid when the user pasted text or a URL instead.
        if (empty($uploadedFile) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        return $this->uploadService->saveUploadedFile($uploadedFile);
    }

    public function normalizeSummaryLength(mixed $value, mixed $fallbackCount = null): string
    {
        $allowed = ['brief', 'short', 'balanced', 'detailed', 'comprehensive'];
        if (is_string($value)) {
            $cleaned = strtolower(trim($value));
            if (in_array($cleaned, $allowed, true)) {
                return $cleaned;
            }
        }

        if ($fallbackCount !== null) {
            $count = (int)$fallbackCount;
            if ($count <= 3) return 'brief';
            if ($count <= 6) return 'short';
            if ($count <= 9) return 'balanced';
            if ($count <= 13) return 'detailed';
            return 'comprehensive';
        }

        return 'balanced';
    }

    private function normalizeSummaryCount(mixed $value): int
    {
        // Clamp the requested length so the backend stays within the UI-supported range.
        $summaryCount = (int)$value;
        return max(self::MIN_SUMMARY_COUNT, min(self::MAX_SUMMARY_COUNT, $summaryCount));
    }

    private function normalizeOriginalText(mixed $value): string
    {
        // Reject non-string input quietly, then enforce a practical size limit before worker processing.
        if (!is_string($value)) {
            return '';
        }

        $text = trim($value);
        if ($text !== '' && strlen($value) > self::MAX_TEXT_BYTES) {
            throw new \RuntimeException('Text input is too long. Limit: 195 KB.');
        }

        return $text;
    }

    private function normalizeSummaryStyle(mixed $value): string
    {
        // Fall back to the default style if the request is missing or tampered with.
        $summaryStyle = is_string($value) ? $value : 'standard_paragraph';
        return in_array($summaryStyle, self::ALLOWED_STYLES, true)
            ? $summaryStyle
            : 'standard_paragraph';
    }

    private function normalizeWorkerErrorMessage(string $message): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        return $normalized !== '' ? $normalized : 'Summarization failed. Please try again later.';
    }

    private function summaryPreprocessingOptions(string $selectionMode = ''): array
    {
        // Derive mode-appropriate preprocessing flags from the selection profile.
        // The profile resolution logic lives in the Python pipeline; here we apply
        // a lightweight mapping so that different summarization modes get
        // genuinely different text-cleaning behaviour (not just different
        # scoring weights).
        $mode = $selectionMode ?: 'general';

        $lowercase = in_array($mode, [
            'academic_summary', 'executive_summary', 'technical_summary',
            'news_summary', 'simple_summary',
        ], true);

        $removeStopwords = in_array($mode, [
            'academic_summary', 'executive_summary', 'technical_summary',
            'simple_summary',
        ], true);

        $removePunctuation = in_array($mode, [
            'academic_summary', 'executive_summary', 'technical_summary',
            'news_summary',
        ], true);

        // Bullet points and hybrid keep stopwords/punctuation to preserve
        # quoted key phrases and natural reading flow.
        $tokenize = false;
        $lemmatize = false;

        return [
            'remove_visual_artifacts' => true,
            'remove_email_addresses' => true,
            'remove_known_noise' => true,
            'normalize_whitespace' => true,
            'lowercase' => $lowercase,
            'remove_punctuation' => $removePunctuation,
            'remove_stopwords' => $removeStopwords,
            'tokenize' => $tokenize,
            'lemmatize' => $lemmatize,
        ];
    }

    private function normalizeSummaryBlocks(mixed $summaryBlocks): array
    {
        // A summary without actual content is treated as a worker failure, not as a valid empty result.
        if (!is_array($summaryBlocks)) {
            throw new \RuntimeException('Summarization failed. Please try again later.');
        }

        $normalized = array_values(array_filter(
            array_map(static fn($block) => trim((string)$block), $summaryBlocks),
            static fn($block) => $block !== ''
        ));

        if ($normalized === []) {
            throw new \RuntimeException('No summary could be generated from the provided text.');
        }

        return $normalized;
    }

    private function normalizeKeywords(mixed $keywords): array
    {
        // Keyword arrays are optional metadata, so invalid shapes collapse to an empty list.
        if (!is_array($keywords)) {
            return [];
        }

        return array_values(array_unique($this->normalizeTextList($keywords)));
    }

    private function normalizeOverallSummaryBullets(mixed $bullets): array
    {
        // Bullet arrays are populated only for bullet_points and hybrid styles.
        // For paragraph styles the Python engine returns [], which is stored as-is.
        if (!is_array($bullets)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($item) => trim((string)$item), $bullets),
            static fn($item) => $item !== ''
        ));
    }

    private function normalizeImportantTerms(mixed $terms): array
    {
        // Keep only complete term/meaning pairs so the result page does not render half-filled glossary rows.
        if (!is_array($terms)) {
            return [];
        }

        $normalized = [];
        foreach ($terms as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $term = trim((string)($entry['term'] ?? ''));
            $meaning = trim((string)($entry['meaning'] ?? ''));
            if ($term === '' || $meaning === '') {
                continue;
            }

            $normalized[] = [
                'term' => $term,
                'meaning' => $meaning,
            ];
        }

        return $normalized;
    }

    private function normalizeTextList(mixed $items): array
    {
        // Trim and drop blanks once instead of making every caller repeat the same cleanup.
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($item) => trim((string)$item), $items),
            static fn($item) => $item !== ''
        ));
    }

    private function normalizePlainSummary(mixed $value, array $overview, array $summaryBlocks, mixed $conclusion): string
    {
        // Fall back to a stitched plain summary so older or partial worker payloads still render usefully.
        $plainSummary = trim((string)$value);
        if ($plainSummary !== '') {
            return $plainSummary;
        }

        $segments = array_merge($overview, $summaryBlocks, [trim((string)$conclusion)]);
        $segments = array_values(array_filter($segments, static fn($segment) => $segment !== ''));
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $segments)) ?? implode(' ', $segments));
    }

    private function normalizeStructuredSummary(mixed $structuredSummary): array
    {
        // Structured sections are optional, so keep only fully labeled blocks.
        if (!is_array($structuredSummary)) {
            return [];
        }

        $normalized = [];
        foreach ($structuredSummary as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = trim((string)($entry['label'] ?? ''));
            $text = trim((string)($entry['text'] ?? ''));
            if ($label === '' || $text === '') {
                continue;
            }

            $normalized[] = [
                'label' => $label,
                'text' => $text,
            ];
        }

        return $normalized;
    }

    private function normalizeConclusion(mixed $value): string
    {
        return trim((string)$value);
    }

    private function normalizeReadabilityData(mixed $value): array
    {
        // Only copy known numeric metrics so unexpected worker keys do not leak into stored output.
        if (!is_array($value)) {
            return [];
        }

        $allowedKeys = [
            'original_word_count',
            'summary_word_count',
            'compression_percent',
            'estimated_reading_time_minutes',
            'estimated_source_reading_time_minutes',
        ];
        $normalized = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $value) || !is_numeric($value[$key])) {
                continue;
            }

            $numericValue = (float)$value[$key];
            $normalized[$key] = str_contains($key, 'percent')
                ? max(0, round($numericValue, 1))
                : max(0, (int)round($numericValue));
        }

        return $normalized;
    }

    private function normalizeSummaryMethod(mixed $value): array
    {
        // Preserve worker diagnostics that help explain how a summary was produced without trusting raw shapes.
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        if (is_string($value['name'] ?? null)) {
            $normalized['name'] = trim($value['name']);
        }
        if (is_string($value['topic_component'] ?? null)) {
            $normalized['topic_component'] = trim($value['topic_component']);
        }
        if (is_string($value['redundancy_reduction'] ?? null)) {
            $normalized['redundancy_reduction'] = trim($value['redundancy_reduction']);
        }
        if (is_string($value['scoring_strategy'] ?? null)) {
            $normalized['scoring_strategy'] = trim($value['scoring_strategy']);
        }
        if (array_key_exists('fallback_used', $value)) {
            $normalized['fallback_used'] = (bool)$value['fallback_used'];
        }
        if (is_array($value['pipeline'] ?? null)) {
            $normalized['pipeline'] = $this->normalizeTextList($value['pipeline']);
        }
        if (is_array($value['scoring_weights'] ?? null)) {
            $normalizedWeights = [];
            foreach ($value['scoring_weights'] as $weightKey => $weightValue) {
                if (!is_string($weightKey) || !is_numeric($weightValue)) {
                    continue;
                }
                $normalizedWeights[$weightKey] = max(0, round((float)$weightValue, 2));
            }
            if ($normalizedWeights !== []) {
                $normalized['scoring_weights'] = $normalizedWeights;
            }
        }

        return $normalized;
    }

    private function normalizeSourceMetadata(mixed $value): array
    {
        // Source metadata is filtered to a known set so the database keeps a stable JSON shape.
        if (!is_array($value)) {
            return [];
        }

        $stringKeys = [
            'source_type',
            'title',
            'article_type',
            'article_type_key',
            'engine',
            'scoring_strategy',
        ];
        $numericKeys = [
            'original_word_count',
            'cleaned_word_count',
            'paragraph_count',
            'candidate_sentence_count',
        ];

        $normalized = [];
        foreach ($stringKeys as $key) {
            if (!is_string($value[$key] ?? null)) {
                continue;
            }
            $text = trim($value[$key]);
            if ($text !== '') {
                $normalized[$key] = $text;
            }
        }

        foreach ($numericKeys as $key) {
            if (!array_key_exists($key, $value) || !is_numeric($value[$key])) {
                continue;
            }
            $normalized[$key] = max(0, (int)round((float)$value[$key]));
        }

        if (array_key_exists('fallback_used', $value)) {
            $normalized['fallback_used'] = (bool)$value['fallback_used'];
        }

        return $normalized;
    }

    private function normalizeArticleType(mixed $value): string
    {
        return trim((string)$value);
    }

    private function normalizeParagraphSummaries(mixed $paragraphSummaries): array
    {
        // Paragraph-level output should skip broken entries instead of letting partial rows reach the UI.
        if (!is_array($paragraphSummaries)) {
            return [];
        }

        $normalized = [];
        foreach ($paragraphSummaries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $paragraphNumber = (int)($entry['paragraph_number'] ?? 0);
            $purpose = trim((string)($entry['purpose'] ?? ''));
            $mainIdea = trim((string)($entry['main_idea'] ?? ''));
            $summary = trim((string)($entry['summary'] ?? ''));
            if ($paragraphNumber < 1 || $summary === '') {
                continue;
            }

            $supportingDetails = $this->normalizeTextList($entry['supporting_details'] ?? []);
            $keywords = $this->normalizeKeywords($entry['keywords'] ?? []);

            $normalized[] = [
                'paragraph_number' => $paragraphNumber,
                'section' => trim((string)($entry['section'] ?? $purpose)),
                'purpose' => $purpose !== '' ? $purpose : 'General Explanation',
                'main_idea' => $mainIdea,
                'supporting_details' => $supportingDetails,
                'keywords' => $keywords,
                'summary' => $summary,
            ];
        }

        return $normalized;
    }
}
