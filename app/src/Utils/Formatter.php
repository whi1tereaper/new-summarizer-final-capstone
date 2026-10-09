<?php
namespace App\Src\Utils;

// turns stored summary blocks into display formats
class Formatter
{
    /**
     * Render stored summary blocks as HTML using the requested display style.
     *
     * All text content is escaped with htmlspecialchars() inside the private
     * render methods (renderParagraph, renderBullets, renderHybrid). Callers
     * MUST echo the return value directly and MUST NOT pass it through
     * htmlspecialchars() again — doing so will double-encode entities.
     *
     * @param array  $summaryData Decoded JSON summary payload from the database.
     * @param string $style       One of: standard_paragraph, bullet_points, hybrid,
     *                            academic_summary, simple_summary.
     * @return string Pre-escaped HTML safe for direct output. Do not re-escape.
     */
    public static function toHtml(array $summaryData, string $style): string
    {
        [$blocks, $unit] = self::extractSummaryBlocksFromDecoded($summaryData);
        if ($blocks === []) {
            return '';
        }

        $preserveParagraphs = $unit === 'paragraph';
        switch ($style) {
            case 'bullet_points':
                return self::renderBullets($blocks);

            case 'hybrid':
                return self::renderHybrid($blocks, $preserveParagraphs);

            case 'technical_summary':
            case 'news_summary':
            case 'academic_summary':
            case 'simple_summary':
            case 'standard_paragraph':
            default:
                return self::renderParagraph($blocks, $preserveParagraphs);
        }
    }

    // plain text version for translation and audio
    public static function toPlainTextFromStoredSummary(string $storedSummary): string
    {
        $decoded = json_decode($storedSummary, true);
        if (is_array($decoded)) {
            $plainSummary = trim((string)($decoded['plain_summary'] ?? ''));
            if ($plainSummary !== '') {
                return self::normalizeWhitespace($plainSummary);
            }

            [$blocks] = self::extractSummaryBlocksFromDecoded($decoded);
            if ($blocks !== []) {
                $segments = $blocks;
                $conclusion = trim((string)($decoded['conclusion'] ?? ''));
                if ($conclusion !== '' && !self::containsSimilarText($segments, $conclusion)) {
                    $segments[] = $conclusion;
                }

                return self::normalizeWhitespace(implode(' ', $segments));
            }
        }

        return self::normalizeWhitespace(
            html_entity_decode(strip_tags($storedSummary), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }

    // plain text that matches the rendered Overall Summary section
    public static function toOverallSummaryTextFromStoredSummary(string $storedSummary): string
    {
        $decoded = json_decode($storedSummary, true);
        if (is_array($decoded)) {
            [$blocks, $unit] = self::extractSummaryBlocksFromDecoded($decoded);
            if ($blocks !== []) {
                $joiner = $unit === 'paragraph' ? "\n\n" : ' ';
                return self::normalizeWhitespace(implode($joiner, $blocks));
            }
        }

        return self::toPlainTextFromStoredSummary($storedSummary);
    }

    public static function extractKeywordsFromStoredSummary(string $storedSummary): array
    {
        return self::extractStringListField($storedSummary, 'keywords');
    }

    public static function extractImportantTermsFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded) || !is_array($decoded['important_terms'] ?? null)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded['important_terms'] as $entry) {
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

    public static function extractKeyPointsFromStoredSummary(string $storedSummary): array
    {
        return self::extractStringListField($storedSummary, 'key_points');
    }

    public static function extractOverviewFromStoredSummary(string $storedSummary): array
    {
        return self::extractStringListField($storedSummary, 'overview');
    }

    public static function extractOverallSummaryBulletsFromStoredSummary(string $storedSummary): array
    {
        return self::extractStringListField($storedSummary, 'overall_summary_bullets');
    }

    public static function extractArticleTypeFromStoredSummary(string $storedSummary): string
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string)($decoded['article_type'] ?? ''));
    }

    public static function extractProfileDataFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded)) {
            return [];
        }

        return [
            'selection_mode' => trim((string)($decoded['selection_mode'] ?? '')),
            'analysis_mode' => trim((string)($decoded['analysis_mode'] ?? ($decoded['selection_mode'] ?? ''))),
            'summary_depth' => trim((string)($decoded['summary_depth'] ?? '')),
            'output_format' => trim((string)($decoded['output_format'] ?? '')),
            'profile_label' => trim((string)($decoded['profile_label'] ?? '')),
            'active_profile_weights' => is_array($decoded['active_profile_weights'] ?? null) ? $decoded['active_profile_weights'] : [],
            'validation_passed' => (bool)($decoded['validation_passed'] ?? true),
            'validation_notes' => is_array($decoded['validation_notes'] ?? null) ? $decoded['validation_notes'] : [],
            'structured_summary' => is_array($decoded['structured_summary'] ?? null) ? $decoded['structured_summary'] : [],
            'article_type' => trim((string)($decoded['article_type'] ?? '')),
        ];
    }

    public static function extractRetrievalFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded)) {
            return [];
        }

        return is_array($decoded['retrieval_metadata'] ?? null)
            ? $decoded['retrieval_metadata']
            : (is_array($decoded['retrieval'] ?? null) ? $decoded['retrieval'] : []);
    }

    public static function extractEvidenceFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        return is_array($decoded) && is_array($decoded['evidence'] ?? null)
            ? $decoded['evidence']
            : [];
    }

    public static function extractCoverageFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        return is_array($decoded) && is_array($decoded['coverage'] ?? null)
            ? $decoded['coverage']
            : [];
    }

    public static function extractConclusionFromStoredSummary(string $storedSummary): string
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded)) {
            return '';
        }

        return trim((string)($decoded['conclusion'] ?? ''));
    }

    public static function extractParagraphSummariesFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded) || !is_array($decoded['paragraph_summaries'] ?? null)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded['paragraph_summaries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $paragraphNumber = (int)($entry['paragraph_number'] ?? 0);
            $summary = trim((string)($entry['summary'] ?? ''));
            if ($paragraphNumber < 1 || $summary === '') {
                continue;
            }

            $purpose = trim((string)($entry['purpose'] ?? 'General Explanation'));
            $mainIdea = trim((string)($entry['main_idea'] ?? ''));

            $supportingDetails = [];
            if (is_array($entry['supporting_details'] ?? null)) {
                foreach ($entry['supporting_details'] as $detail) {
                    $cleaned = trim((string)$detail);
                    if ($cleaned !== '') {
                        $supportingDetails[] = $cleaned;
                    }
                }
            }

            $keywords = [];
            if (is_array($entry['keywords'] ?? null)) {
                foreach ($entry['keywords'] as $keyword) {
                    $cleaned = trim((string)$keyword);
                    if ($cleaned !== '') {
                        $keywords[] = $cleaned;
                    }
                }
            }

            $sectionLabel = trim((string)($entry['section'] ?? $purpose));

            $normalized[] = [
                'paragraph_number' => $paragraphNumber,
                'section' => $sectionLabel,
                'purpose' => $purpose,
                'main_idea' => $mainIdea,
                'supporting_details' => $supportingDetails,
                'keywords' => $keywords,
                'summary' => $summary,
            ];
        }

        return $normalized;
    }

    public static function extractReadabilityFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded) || !is_array($decoded['readability'] ?? null)) {
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
            if (!array_key_exists($key, $decoded['readability']) || !is_numeric($decoded['readability'][$key])) {
                continue;
            }

            $numericValue = (float)$decoded['readability'][$key];
            $normalized[$key] = str_contains($key, 'percent')
                ? max(0, round($numericValue, 1))
                : max(0, (int)round($numericValue));
        }

        return $normalized;
    }

    public static function extractSourceMetadataFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded) || !is_array($decoded['source_metadata'] ?? null)) {
            return [];
        }

        $metadata = $decoded['source_metadata'];
        $stringKeys = [
            'source_type',
            'title',
            'article_type',
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
            $value = trim((string)($metadata[$key] ?? ''));
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        foreach ($numericKeys as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $normalized[$key] = max(0, (int)round((float)$metadata[$key]));
            }
        }

        if (array_key_exists('fallback_used', $metadata)) {
            $normalized['fallback_used'] = (bool)$metadata['fallback_used'];
        }

        if (is_array($metadata['synthesis'] ?? null)) {
            $synthesis = [];
            foreach (['status', 'provider', 'model', 'endpoint_scope', 'verification', 'verification_limit', 'rejection_reason'] as $key) {
                $value = trim((string)($metadata['synthesis'][$key] ?? ''));
                if ($value !== '') {
                    $synthesis[$key] = $value;
                }
            }
            foreach (['requested', 'enabled'] as $key) {
                if (array_key_exists($key, $metadata['synthesis'])) {
                    $synthesis[$key] = (bool)$metadata['synthesis'][$key];
                }
            }
            if (isset($metadata['synthesis']['sentences']) && is_numeric($metadata['synthesis']['sentences'])) {
                $synthesis['sentences'] = max(0, (int)$metadata['synthesis']['sentences']);
            }
            if ($synthesis !== []) {
                $normalized['synthesis'] = $synthesis;
            }
        }

        if (is_array($metadata['fact_validation'] ?? null)) {
            $factValidation = [];
            foreach (['status', 'method', 'interpretation', 'error'] as $key) {
                $value = trim((string)($metadata['fact_validation'][$key] ?? ''));
                if ($value !== '') {
                    $factValidation[$key] = $value;
                }
            }
            foreach (['sentences_checked', 'ledger_entries'] as $key) {
                if (isset($metadata['fact_validation'][$key]) && is_numeric($metadata['fact_validation'][$key])) {
                    $factValidation[$key] = max(0, (int)$metadata['fact_validation'][$key]);
                }
            }
            if (is_array($metadata['fact_validation']['issues'] ?? null)) {
                $factValidation['issues'] = [];
                foreach (array_slice($metadata['fact_validation']['issues'], 0, 100) as $issue) {
                    if (!is_array($issue) || !is_string($issue['issue'] ?? null)) {
                        continue;
                    }
                    $normalizedIssue = ['issue' => trim($issue['issue'])];
                    foreach (['summary_sentence_id', 'source_sentence_id'] as $key) {
                        if (array_key_exists($key, $issue) && ($issue[$key] === null || is_numeric($issue[$key]))) {
                            $normalizedIssue[$key] = $issue[$key] === null ? null : max(0, (int)$issue[$key]);
                        }
                    }
                    $factValidation['issues'][] = $normalizedIssue;
                }
            }
            if ($factValidation !== []) {
                $normalized['fact_validation'] = $factValidation;
            }
        }

        return $normalized;
    }

    public static function buildReadabilityInfo(string $sourceText, string $summaryText): array
    {
        $originalWordCount = self::countWords($sourceText);
        $summaryWordCount = self::countWords($summaryText);
        $compressionPercent = 0.0;
        if ($originalWordCount > 0) {
            $compressionPercent = max(0.0, round((1 - ($summaryWordCount / $originalWordCount)) * 100, 1));
        }

        return [
            'original_word_count' => $originalWordCount,
            'summary_word_count' => $summaryWordCount,
            'compression_percent' => $compressionPercent,
            'estimated_reading_time_minutes' => self::estimateReadingTimeMinutes($summaryWordCount),
            'estimated_source_reading_time_minutes' => self::estimateReadingTimeMinutes($originalWordCount),
        ];
    }

    public static function extractStructuredSummaryFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded) || !is_array($decoded['structured_summary'] ?? null)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded['structured_summary'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = trim((string)($entry['label'] ?? ''));
            $text = trim((string)($entry['text'] ?? ''));
            if ($label === '' || $text === '') {
                continue;
            }

            $displayLabel = self::normalizeStructuredSummaryLabel($label);
            if ($displayLabel === '') {
                continue;
            }

            $normalized[] = [
                'label' => $displayLabel,
                'text' => $text,
            ];
        }

        return $normalized;
    }

    private static function normalizeStructuredSummaryLabel(string $label): string
    {
        $normalizedLabel = strtolower(trim($label));
        if ($normalizedLabel === 'main topic') {
            return 'Main Idea';
        }
        if (in_array($normalizedLabel, ['key evidence', 'conclusion'], true)) {
            return '';
        }

        return $label;
    }

    public static function extractBlocksFromStoredSummary(string $storedSummary): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded)) {
            return [];
        }

        [$blocks] = self::extractSummaryBlocksFromDecoded($decoded);
        return $blocks;
    }

    public static function textsAreSimilar(string $left, string $right): bool
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right
            || str_contains($left, $right)
            || str_contains($right, $left)
            || self::hasHighTokenOverlap($left, $right);
    }

    private static function renderParagraph(array $blocks, bool $preserveParagraphs = false): string
    {
        if (!$preserveParagraphs) {
            $text = implode(' ', array_map('htmlspecialchars', $blocks));
            return "<p>{$text}</p>";
        }

        $html = '';
        foreach ($blocks as $block) {
            $html .= '<p>' . htmlspecialchars($block) . "</p>\n";
        }

        return rtrim($html);
    }

    private static function renderBullets(array $blocks): string
    {
        $html = "<ul>\n";
        foreach ($blocks as $block) {
            $safe = htmlspecialchars($block);
            $html .= "    <li>{$safe}</li>\n";
        }
        $html .= "</ul>";
        return $html;
    }

    // first few summary blocks as paragraphs, the rest as bullets
    private static function renderHybrid(array $blocks, bool $preserveParagraphs = false): string
    {
        $total = count($blocks);
        if ($total < 2) {
            // tiny summaries read better as one paragraph
            return self::renderParagraph($blocks, $preserveParagraphs);
        }

        $pCount = max(1, floor($total / 3));
        
        $pBlocks = array_slice($blocks, 0, $pCount);
        $bBlocks = array_slice($blocks, $pCount);

        $html = self::renderParagraph($pBlocks, $preserveParagraphs);
        $html .= "\n" . self::renderBullets($bBlocks);

        return $html;
    }

    private static function extractSummaryBlocksFromDecoded(array $decoded): array
    {
        if (self::isListArray($decoded)) {
            return [self::normalizeBlocks($decoded), 'sentence'];
        }

        $unit = is_string($decoded['unit'] ?? null) ? $decoded['unit'] : 'sentence';
        $blocks = $decoded['blocks'] ?? $decoded['sentences'] ?? null;
        if (!is_array($blocks)) {
            return [[], $unit];
        }

        return [self::normalizeBlocks($blocks), $unit];
    }

    private static function normalizeBlocks(array $blocks): array
    {
        return array_values(array_filter(
            array_map(
                static fn ($block) => trim((string)$block),
                $blocks
            ),
            static fn ($block) => $block !== ''
        ));
    }

    private static function isListArray(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $expectedIndex = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expectedIndex) {
                return false;
            }
            $expectedIndex++;
        }

        return true;
    }

    private static function normalizeWhitespace(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text);
        return trim($normalized ?? $text);
    }

    private static function countWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
        return count($matches[0] ?? []);
    }

    private static function estimateReadingTimeMinutes(int $wordCount): int
    {
        if ($wordCount <= 0) {
            return 0;
        }

        return max(1, (int)ceil($wordCount / 200));
    }

    private static function containsSimilarText(array $segments, string $candidate): bool
    {
        foreach ($segments as $segment) {
            if (self::textsAreSimilar((string)$segment, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function hasHighTokenOverlap(string $left, string $right): bool
    {
        $leftTokens = self::normalizeComparableTokens($left);
        $rightTokens = self::normalizeComparableTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);
        $overlap = count($intersection) / min(count($leftTokens), count($rightTokens));
        return $overlap >= 0.7;
    }

    private static function normalizeComparableTokens(string $text): array
    {
        $normalized = strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $tokens = preg_split('/\s+/u', trim((string)$normalized));
        if (!is_array($tokens)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $tokens,
            static fn ($token) => $token !== ''
        )));
    }

    public static function extractStringListFieldPublic(string $storedSummary, string $field): array
    {
        return self::extractStringListField($storedSummary, $field);
    }

    private static function extractStringListField(string $storedSummary, string $field): array
    {
        $decoded = json_decode($storedSummary, true);
        if (!is_array($decoded) || !is_array($decoded[$field] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn ($value) => trim((string)$value),
                $decoded[$field]
            ),
            static fn ($value) => $value !== ''
        ));
    }
}
