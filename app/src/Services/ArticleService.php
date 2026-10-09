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
    public const ALLOWED_SOURCE_TYPES = ['file', 'text', 'url'];
    public const MIN_SUMMARY_COUNT = 3;
    public const MAX_SUMMARY_COUNT = 15;
    public const MAX_TEXT_BYTES = 200000;
    public const ALLOWED_ANALYSIS_MODES = ['general', 'academic', 'executive', 'study', 'technical', 'news'];
    public const ALLOWED_OUTPUT_FORMATS = ['paragraph', 'bullets', 'hybrid', 'structured'];
    public const ALLOWED_SUMMARY_DEPTHS = ['brief', 'short', 'balanced', 'detailed', 'comprehensive'];

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
        // The declared source method is authoritative; reject conflicting raw requests before saving uploads.
        $sourceType = is_string($request['source_type'] ?? null) ? strtolower(trim($request['source_type'])) : '';
        if (!in_array($sourceType, self::ALLOWED_SOURCE_TYPES, true)) {
            throw new \RuntimeException('Choose a file, pasted text, or a URL as your source.');
        }
        $pastedText = $this->normalizeOriginalText($request['original_text'] ?? '');
        $sourceUrl = is_string($request['source_url'] ?? null) ? trim($request['source_url']) : '';
        $upload = $files['pdf_file'] ?? null;
        $hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($sourceType === 'file' && ($pastedText !== '' || $sourceUrl !== '')) {
            throw new \RuntimeException('Only one source can be submitted at a time.');
        }
        if ($sourceType === 'text' && ($hasUpload || $sourceUrl !== '')) {
            throw new \RuntimeException('Only one source can be submitted at a time.');
        }
        if ($sourceType === 'url' && ($hasUpload || $pastedText !== '')) {
            throw new \RuntimeException('Only one source can be submitted at a time.');
        }
        if ($sourceType === 'text' && $pastedText === '') {
            throw new \RuntimeException('Paste document text to continue.');
        }
        if ($sourceType === 'url' && ($sourceUrl === '' || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string)parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new \RuntimeException('Enter a valid HTTP or HTTPS article URL.');
        }
        $requestedDepth = $request['summary_depth'] ?? ($request['summary_length'] ?? 'balanced');
        if (!is_string($requestedDepth) || !in_array(strtolower(trim($requestedDepth)), self::ALLOWED_SUMMARY_DEPTHS, true)) {
            throw new \RuntimeException('Choose a supported summary depth.');
        }
        $requestedMode = $request['analysis_mode'] ?? ($request['selection_mode'] ?? 'general');
        if (!is_string($requestedMode) || !in_array(strtolower(trim($requestedMode)), self::ALLOWED_ANALYSIS_MODES, true)) {
            throw new \RuntimeException('Choose a supported analysis mode.');
        }
        $requestedFormat = $request['output_format'] ?? 'paragraph';
        if (!is_string($requestedFormat) || !in_array(strtolower(trim($requestedFormat)), self::ALLOWED_OUTPUT_FORMATS, true)) {
            throw new \RuntimeException('Choose a supported output format.');
        }
        $rawSynthesisChoice = $request['use_llm_synthesis'] ?? '';
        if (!is_string($rawSynthesisChoice) || !in_array($rawSynthesisChoice, ['', '1'], true)) {
            throw new \RuntimeException('Choose a supported synthesis setting.');
        }
        $useLlmSynthesis = $rawSynthesisChoice === '1';
        if ($useLlmSynthesis && !config('summarizer.llm_available', false)) {
            throw new \RuntimeException('Language-model synthesis is not configured.');
        }
        $originalText = match ($sourceType) {
            'text' => $pastedText,
            'url' => $sourceUrl,
            default => '',
        };
        $filePath = $sourceType === 'file' ? $this->resolveUploadedFilePath($upload) : '';
        if ($sourceType === 'file' && $filePath === '') {
            throw new \RuntimeException('Choose a PDF or DOCX file to continue.');
        }
        // Display metadata only: storage and worker input continue to use the generated file path.
        $originalFileName = '';
        if ($sourceType === 'file' && is_string($upload['name'] ?? null)) {
            $uploadName = basename(str_replace('\\', '/', $upload['name']));
            $uploadName = trim(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $uploadName) ?? '');
            preg_match('/\A.{0,240}/us', $uploadName, $displayNameMatch);
            $originalFileName = $displayNameMatch[0] ?? '';
        }
        $summaryDepth = $this->normalizeSummaryLength(
            $request['summary_depth'] ?? ($request['summary_length'] ?? null),
            $request['sentence_count'] ?? null
        );

        $rawDocumentTitle = is_string($request['document_title'] ?? null) ? $request['document_title'] : '';
        $documentTitle = trim(preg_replace('/\s+/u', ' ', $rawDocumentTitle) ?? '');
        $analysisMode = $this->normalizeAnalysisMode(
            $request['analysis_mode'] ?? ($request['selection_mode'] ?? '')
        );
        $outputFormat = $this->normalizeOutputFormat($request['output_format'] ?? '');
        $selectionMode = $analysisMode;
        $summaryStyle = $this->normalizeSummaryStyle($request['summary_style'] ?? 'standard_paragraph');
        if (!empty($request['output_format'])) {
            $summaryStyle = match ($outputFormat) {
                'bullets' => 'bullet_points',
                'hybrid' => 'hybrid',
                default => 'standard_paragraph',
            };
        }

        return [
            'original_text' => $originalText,
            'file_path' => $filePath,
            'original_file_name' => $originalFileName,
            'source_type' => $sourceType,
            'source_url' => $sourceType === 'url' ? $sourceUrl : '',
            'sentence_count' => $this->normalizeSummaryCount($request['sentence_count'] ?? 8),
            // summary_depth is canonical; summary_length is kept for existing
            // session, database, and analytics contracts.
            'summary_depth' => $summaryDepth,
            'summary_length' => $summaryDepth,
            'summary_style' => $summaryStyle,
            'document_title' => $documentTitle,
            'selection_mode' => $selectionMode,
            'analysis_mode' => $analysisMode,
            'output_format' => $outputFormat,
            'use_llm_synthesis' => $useLlmSynthesis,
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
        string $selectionMode = '',
        string $analysisMode = '',
        string $outputFormat = '',
        bool $useLlmSynthesis = false,
        int $targetWordBudget = 0
    ): array {
        try {
            // Send only the worker-facing data contract; preprocessing flags stay server-owned.
            $preprocessing = $this->summaryPreprocessingOptions($selectionMode);
            return $this->pythonBridge->summarize([
                'text' => $originalText,
                'file_path' => $filePath,
                'sentence_count' => $sentenceCount,
                'summary_length' => $this->normalizeSummaryLength($summaryLength, $sentenceCount),
                'summary_depth' => $this->normalizeSummaryLength($summaryLength, $sentenceCount),
                'summary_style' => $summaryStyle,
                'selection_mode' => $selectionMode !== '' ? $selectionMode : $summaryStyle,
                'analysis_mode' => $this->normalizeAnalysisMode($analysisMode !== '' ? $analysisMode : $selectionMode),
                'output_format' => $this->normalizeOutputFormat($outputFormat),
                'document_title' => $documentTitle,
                'preprocessing' => $preprocessing,
                'use_llm_synthesis' => $useLlmSynthesis,
                'target_word_budget' => max(0, min(50000, $targetWordBudget)),
            ], max(60, (int)config('python.timeout', 120)));
        } catch (\RuntimeException $exception) {
            // Preserve the detailed worker failure in logs while giving controllers a cleaner message.
            error_log('[ArticleService] Summarization error: ' . $exception->getMessage());
            throw new \RuntimeException($this->normalizeWorkerErrorMessage($exception->getMessage()), 0, $exception);
        }
    }

    public function userExists(int $userId): bool
    {
        try {
            $statement = $this->db->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            return (bool)$statement->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[ArticleService] userExists check failed: ' . $e->getMessage());
            return false;
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
        if ($userId !== null && !$this->userExists((int)$userId)) {
            error_log("[ArticleService] Non-existent userId {$userId} passed to storeSummary; falling back to guest.");
            $userId = null;
        }

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
        $retrieval = $this->normalizeRetrievalMetadata(
            $summaryResult['retrieval_metadata'] ?? ($summaryResult['retrieval'] ?? [])
        );
        $evidence = $this->normalizeEvidence($summaryResult['evidence'] ?? []);
        $coverage = $this->normalizeCoverage($summaryResult['coverage'] ?? []);
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
            'retrieval' => $retrieval,
            'retrieval_metadata' => $retrieval,
            'evidence' => $evidence,
            'coverage' => $coverage,
            'selection_mode' => (string)($summaryResult['selection_mode'] ?? ''),
            'analysis_mode' => (string)($summaryResult['analysis_mode'] ?? ($summaryResult['selection_mode'] ?? '')),
            'output_format' => (string)($summaryResult['output_format'] ?? 'paragraph'),
            'summary_depth' => $this->normalizeSummaryLength(
                $summaryResult['summary_depth'] ?? ($summaryResult['summary_length'] ?? $summaryLength)
            ),
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
        if ($userId !== null && !$this->userExists((int)$userId)) {
            $userId = null;
        }

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
        $allowed = self::ALLOWED_SUMMARY_DEPTHS;
        $numericMap = [
            '0' => 'brief',
            '1' => 'short',
            '2' => 'balanced',
            '3' => 'detailed',
            '4' => 'comprehensive',
        ];

        if (is_string($value)) {
            $cleaned = strtolower(trim($value));
            if (in_array($cleaned, $allowed, true)) {
                return $cleaned;
            }
            if (isset($numericMap[$cleaned])) {
                return $numericMap[$cleaned];
            }
        } elseif (is_int($value) && isset($numericMap[(string)$value])) {
            return $numericMap[(string)$value];
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

    public function normalizeAnalysisMode(mixed $value): string
    {
        $mode = strtolower(trim((string)$value));
        $aliases = [
            'academic_summary' => 'academic',
            'executive_summary' => 'executive',
            'simple_summary' => 'study',
            'technical_summary' => 'technical',
            'news_summary' => 'news',
            'standard_paragraph' => 'general',
            'bullet_points' => 'general',
            'hybrid' => 'general',
        ];
        $mode = $aliases[$mode] ?? $mode;
        return in_array($mode, self::ALLOWED_ANALYSIS_MODES, true) ? $mode : 'general';
    }

    public function normalizeOutputFormat(mixed $value): string
    {
        $format = strtolower(trim((string)$value));
        $aliases = [
            'standard_paragraph' => 'paragraph',
            'bullet_points' => 'bullets',
            'executive_summary' => 'hybrid',
        ];
        $format = $aliases[$format] ?? $format;
        return in_array($format, self::ALLOWED_OUTPUT_FORMATS, true) ? $format : 'paragraph';
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
        foreach (['synthesis_status', 'synthesis_model', 'synthesis_verification'] as $key) {
            if (is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
                $normalized[$key] = trim($value[$key]);
            }
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
            'engine_version',
            'source_type',
            'title',
            'article_type',
            'article_type_key',
            'engine',
            'scoring_strategy',
            'summary_length',
            'summary_depth',
            'evidence_level',
        ];
        $numericKeys = [
            'summary_schema_version',
            'original_word_count',
            'cleaned_word_count',
            'paragraph_count',
            'candidate_sentence_count',
            'target_sentence_count',
            'topic_coverage_target',
            'topics_detected',
            'topics_covered',
            'sections_detected',
            'sections_represented',
            'evidence_count',
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

        if (is_array($value['synthesis'] ?? null)) {
            $synthesis = [];
            foreach (['status', 'provider', 'model', 'endpoint_scope', 'verification', 'verification_limit', 'rejection_reason'] as $key) {
                if (is_string($value['synthesis'][$key] ?? null) && trim($value['synthesis'][$key]) !== '') {
                    $synthesis[$key] = trim($value['synthesis'][$key]);
                }
            }
            foreach (['requested', 'enabled'] as $key) {
                if (array_key_exists($key, $value['synthesis'])) {
                    $synthesis[$key] = (bool)$value['synthesis'][$key];
                }
            }
            if (isset($value['synthesis']['sentences']) && is_numeric($value['synthesis']['sentences'])) {
                $synthesis['sentences'] = max(0, (int)$value['synthesis']['sentences']);
            }
            if ($synthesis !== []) {
                $normalized['synthesis'] = $synthesis;
            }
        }

        if (is_array($value['fact_validation'] ?? null)) {
            $factValidation = [];
            foreach (['status', 'method', 'interpretation', 'error'] as $key) {
                if (is_string($value['fact_validation'][$key] ?? null) && trim($value['fact_validation'][$key]) !== '') {
                    $factValidation[$key] = trim($value['fact_validation'][$key]);
                }
            }
            foreach (['sentences_checked', 'ledger_entries'] as $key) {
                if (isset($value['fact_validation'][$key]) && is_numeric($value['fact_validation'][$key])) {
                    $factValidation[$key] = max(0, (int)$value['fact_validation'][$key]);
                }
            }
            if (is_array($value['fact_validation']['issues'] ?? null)) {
                $issues = [];
                foreach (array_slice($value['fact_validation']['issues'], 0, 100) as $issue) {
                    if (!is_array($issue) || !is_string($issue['issue'] ?? null)) {
                        continue;
                    }
                    $normalizedIssue = ['issue' => trim($issue['issue'])];
                    foreach (['summary_sentence_id', 'source_sentence_id'] as $key) {
                        if (array_key_exists($key, $issue) && ($issue[$key] === null || is_numeric($issue[$key]))) {
                            $normalizedIssue[$key] = $issue[$key] === null ? null : max(0, (int)$issue[$key]);
                        }
                    }
                    $issues[] = $normalizedIssue;
                }
                $factValidation['issues'] = $issues;
            }
            if ($factValidation !== []) {
                $normalized['fact_validation'] = $factValidation;
            }
        }

        if (is_array($value['document_analysis'] ?? null)) {
            $rawAnalysis = $value['document_analysis'];
            $analysis = [];
            foreach ([
                'document_type', 'confidence_method', 'research_stage',
            ] as $key) {
                if (is_string($rawAnalysis[$key] ?? null) && trim($rawAnalysis[$key]) !== '') {
                    $analysis[$key] = trim($rawAnalysis[$key]);
                }
            }
            if (isset($rawAnalysis['document_type_confidence']) && is_numeric($rawAnalysis['document_type_confidence'])) {
                $analysis['document_type_confidence'] = max(0, min(1, (float)$rawAnalysis['document_type_confidence']));
            }
            foreach ([
                'results_section_available', 'results_available', 'conclusion_available',
            ] as $key) {
                if (array_key_exists($key, $rawAnalysis)) {
                    $analysis[$key] = (bool)$rawAnalysis[$key];
                }
            }
            if (isset($rawAnalysis['claim_count']) && is_numeric($rawAnalysis['claim_count'])) {
                $analysis['claim_count'] = max(0, (int)$rawAnalysis['claim_count']);
            }
            foreach (['classification_signals', 'missing_information'] as $key) {
                if (is_array($rawAnalysis[$key] ?? null)) {
                    $analysis[$key] = $this->normalizeTextList($rawAnalysis[$key]);
                }
            }
            if (is_array($rawAnalysis['detected_structure'] ?? null)) {
                $sections = [];
                foreach (array_slice($rawAnalysis['detected_structure'], 0, 150) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $section = [];
                    foreach (['section_id', 'heading', 'role', 'section_key', 'confidence'] as $key) {
                        if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                            $section[$key] = trim($row[$key]);
                        }
                    }
                    foreach (['source_sentence_ids', 'paragraph_ids'] as $key) {
                        if (is_array($row[$key] ?? null)) {
                            $section[$key] = array_slice($this->normalizeTextList($row[$key]), 0, 500);
                        }
                    }
                    if (array_key_exists('page', $row) && is_numeric($row['page']) && (int)$row['page'] > 0) {
                        $section['page'] = (int)$row['page'];
                    }
                    if ($section !== []) {
                        $sections[] = $section;
                    }
                }
                $analysis['detected_structure'] = $sections;
            }
            if ($analysis !== []) {
                $normalized['document_analysis'] = $analysis;
            }
        }

        if (is_array($value['summary_plan'] ?? null)) {
            $rawPlan = $value['summary_plan'];
            $plan = [];
            foreach (['mode', 'depth'] as $key) {
                if (is_string($rawPlan[$key] ?? null) && trim($rawPlan[$key]) !== '') {
                    $plan[$key] = trim($rawPlan[$key]);
                }
            }
            foreach (['results_available', 'conclusion_available'] as $key) {
                if (array_key_exists($key, $rawPlan)) {
                    $plan[$key] = (bool)$rawPlan[$key];
                }
            }
            foreach (['supported_claim_types', 'missing_information'] as $key) {
                if (is_array($rawPlan[$key] ?? null)) {
                    $plan[$key] = $this->normalizeTextList($rawPlan[$key]);
                }
            }
            if (is_array($rawPlan['planned_sections'] ?? null)) {
                $sections = [];
                foreach (array_slice($rawPlan['planned_sections'], 0, 20) as $row) {
                    if (!is_array($row) || !is_string($row['label'] ?? null)) {
                        continue;
                    }
                    $sections[] = [
                        'label' => trim($row['label']),
                        'claim_types' => $this->normalizeTextList($row['claim_types'] ?? []),
                    ];
                }
                $plan['planned_sections'] = $sections;
            }
            if ($plan !== []) {
                $normalized['summary_plan'] = $plan;
            }
        }

        if (is_array($value['source_guard'] ?? null)) {
            $rawGuard = $value['source_guard'];
            $guard = [];
            foreach (['status', 'method'] as $key) {
                if (is_string($rawGuard[$key] ?? null) && trim($rawGuard[$key]) !== '') {
                    $guard[$key] = trim($rawGuard[$key]);
                }
            }
            foreach (['sentences_checked', 'sentences_restored_to_source', 'sentences_omitted_without_source_match'] as $key) {
                if (isset($rawGuard[$key]) && is_numeric($rawGuard[$key])) {
                    $guard[$key] = max(0, (int)$rawGuard[$key]);
                }
            }
            if (array_key_exists('semantic_entailment_measured', $rawGuard)) {
                $guard['semantic_entailment_measured'] = (bool)$rawGuard['semantic_entailment_measured'];
            }
            if (is_array($rawGuard['issues'] ?? null)) {
                $issues = [];
                foreach ($rawGuard['issues'] as $key => $count) {
                    if (is_string($key) && is_numeric($count)) {
                        $issues[$key] = max(0, (int)$count);
                    }
                }
                $guard['issues'] = $issues;
            }
            if ($guard !== []) {
                $normalized['source_guard'] = $guard;
            }
        }

        return $normalized;
    }

    private function normalizeRetrievalMetadata(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach (['enabled', 'fallback_used'] as $key) {
            if (array_key_exists($key, $value)) {
                $normalized[$key] = (bool)$value[$key];
            }
        }
        foreach (['backend', 'status', 'reason', 'fallback_reason', 'profile', 'summary_depth', 'evidence_mapping_status'] as $key) {
            if (is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
                $normalized[$key] = trim($value[$key]);
            }
        }
        foreach (['chunks_considered', 'chunks_retrieved', 'queries_used', 'chunk_count', 'retrieved_chunk_count', 'retrieval_breadth'] as $key) {
            if (array_key_exists($key, $value) && is_numeric($value[$key])) {
                $normalized[$key] = max(0, (int)round((float)$value[$key]));
            }
        }
        foreach (['coverage_ratio', 'semantic_score_adjustment_limit'] as $key) {
            if (array_key_exists($key, $value) && is_numeric($value[$key])) {
                $normalized[$key] = max(0, min(1, (float)$value[$key]));
            }
        }
        if (is_array($value['retrieved_sections'] ?? null)) {
            $normalized['retrieved_sections'] = $this->normalizeTextList($value['retrieved_sections']);
        }

        return $normalized;
    }

    private function normalizeEvidence(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        $indexByIdentity = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $excerpt = trim((string)($entry['excerpt'] ?? $entry['text'] ?? ''));
            $supports = trim((string)($entry['supports'] ?? $entry['summary_text'] ?? $entry['summary_sentence'] ?? ''));
            $section = trim((string)($entry['section'] ?? ''));
            if ($excerpt === '' || $section === '') {
                continue;
            }

            $item = [
                'section' => $section,
                'excerpt' => $excerpt,
            ];
            if ($supports !== '') {
                $item['supports'] = $supports;
            }
            foreach ([
                'chunk_id', 'summary_unit', 'page', 'paragraph_index', 'source_sentence',
                'summary_sentence', 'source_sentence_id', 'source_paragraph_id', 'section_id',
                'claim_type', 'claim_status',
            ] as $key) {
                if (is_string($entry[$key] ?? null) && trim($entry[$key]) !== '') {
                    $item[$key] = trim($entry[$key]);
                } elseif (is_numeric($entry[$key] ?? null)) {
                    $item[$key] = (int)$entry[$key];
                }
            }
            foreach (['summary_sentence_index', 'semantic_relevance', 'profile_relevance', 'relevance_score'] as $key) {
                if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                    $item[$key] = str_contains($key, 'relevance')
                        ? max(0, min(1, (float)$entry[$key]))
                        : max(0, (int)$entry[$key]);
                }
            }
            if (array_key_exists('retrieved', $entry)) {
                $item['retrieved'] = (bool)$entry['retrieved'];
            }
            if (is_array($entry['source_sentence_indices'] ?? null)) {
                $indices = [];
                foreach ($entry['source_sentence_indices'] as $index) {
                    if (is_numeric($index) && (int)$index >= 0) {
                        $indices[] = (int)$index;
                    }
                }
                if ($indices !== []) {
                    $item['source_sentence_indices'] = array_values(array_unique($indices));
                }
            }

            $identity = isset($item['chunk_id'])
                ? 'chunk:' . (string)$item['chunk_id']
                : 'excerpt:' . $section . '|' . $excerpt;
            if (isset($indexByIdentity[$identity])) {
                $existingIndex = $indexByIdentity[$identity];
                if ($supports !== '') {
                    $existingSupports = trim((string)($normalized[$existingIndex]['supports'] ?? ''));
                    if ($existingSupports === '') {
                        $normalized[$existingIndex]['supports'] = $supports;
                    } elseif ($existingSupports !== $supports
                        && !str_contains($existingSupports, $supports)) {
                        $normalized[$existingIndex]['supports'] = $existingSupports . ' ' . $supports;
                    }
                }
                continue;
            }

            $indexByIdentity[$identity] = count($normalized);
            $normalized[] = $item;
        }

        return $normalized;
    }

    private function normalizeCoverage(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $section = trim($entry);
                if ($section !== '') {
                    $normalized[] = ['section' => $section];
                }
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }

            $section = trim((string)($entry['section'] ?? $entry['label'] ?? ''));
            if ($section === '') {
                continue;
            }
            $item = ['section' => $section];
            if (isset($entry['evidence_count']) && is_numeric($entry['evidence_count'])) {
                $item['evidence_count'] = max(0, (int)$entry['evidence_count']);
            }
            if (is_string($entry['status'] ?? null) && trim($entry['status']) !== '') {
                $item['status'] = trim($entry['status']);
            }
            $normalized[] = $item;
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
