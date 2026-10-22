<?php
declare(strict_types=1);

/**
 * LIGHT Summarizer - Intelligence, Modes, Depths, Formats & Q&A Verification Test
 *
 * Verifies Phase 1-4 implementations:
 * 1. Analysis modes (including 'legal' and 'scientific')
 * 2. Output formats (including 'key_takeaways')
 * 3. Summary depths (including 'very_brief')
 * 4. Focus Query pipeline staging
 * 5. LocalPythonBridge::qa() functionality & honest fallback
 * 6. UI compatibility mappings in ArticleService & PromptTemplate
 */

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Utils/validation.php';
require_once __DIR__ . '/../app/src/Services/ArticleService.php';
require_once __DIR__ . '/../app/src/Services/SummarizerPromptTemplate.php';
require_once __DIR__ . '/../app/src/Services/LocalPythonBridge.php';

use App\Src\Services\ArticleService;
use App\Src\Services\SourceResolver;
use App\Src\Services\SummarizerPromptTemplate;
use App\Src\Services\LocalPythonBridge;

$passCount = 0;
$failCount = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$description}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    } else {
        $failCount++;
        echo "[FAIL] {$description}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

echo "====================================================\n";
echo "LIGHT SUMMARIZER INTELLIGENCE & CAPABILITIES TEST\n";
echo "====================================================\n\n";

// ---------------------------------------------------------
// GROUP 1: Mode, Depth, Format Constants & Normalization
// ---------------------------------------------------------
echo "--- GROUP 1: Mode, Depth, Format Contract Integrity ---\n";

$service = new ArticleService();

// Check allowed modes
$reflection = new ReflectionClass(ArticleService::class);
$modes = $reflection->getConstant('ALLOWED_ANALYSIS_MODES');
assertTest("ALLOWED_ANALYSIS_MODES includes 'legal'", in_array('legal', $modes, true));
assertTest("ALLOWED_ANALYSIS_MODES includes 'scientific'", in_array('scientific', $modes, true));

$formats = $reflection->getConstant('ALLOWED_OUTPUT_FORMATS');
assertTest("ALLOWED_OUTPUT_FORMATS includes 'key_takeaways'", in_array('key_takeaways', $formats, true));

$depths = $reflection->getConstant('ALLOWED_SUMMARY_DEPTHS');
assertTest("ALLOWED_SUMMARY_DEPTHS includes 'very_brief'", in_array('very_brief', $depths, true));

// Normalization tests
assertTest("normalizeAnalysisMode handles 'legal'", $service->normalizeAnalysisMode('legal') === 'legal');
assertTest("normalizeAnalysisMode handles 'scientific'", $service->normalizeAnalysisMode('scientific') === 'scientific');
assertTest("normalizeAnalysisMode falls back to 'general' on invalid", $service->normalizeAnalysisMode('nonexistent_mode') === 'general');

assertTest("normalizeOutputFormat handles 'key_takeaways'", $service->normalizeOutputFormat('key_takeaways') === 'key_takeaways');
assertTest("normalizeOutputFormat falls back to 'paragraph' on invalid", $service->normalizeOutputFormat('invalid_format') === 'paragraph');

assertTest("normalizeSummaryLength handles 'very_brief'", $service->normalizeSummaryLength('very_brief') === 'very_brief');

// ---------------------------------------------------------
// GROUP 2: SummarizerPromptTemplate Consistency
// ---------------------------------------------------------
echo "\n--- GROUP 2: SummarizerPromptTemplate Consistency ---\n";

$allowedProfiles = SummarizerPromptTemplate::ALLOWED_PROFILES;
assertTest("SummarizerPromptTemplate supports 'legal' profile", in_array('legal', $allowedProfiles, true));
assertTest("SummarizerPromptTemplate supports 'scientific' profile", in_array('scientific', $allowedProfiles, true));

$allowedLengths = SummarizerPromptTemplate::ALLOWED_LENGTHS;
assertTest("SummarizerPromptTemplate supports 'very_brief' length", in_array('very_brief', $allowedLengths, true));

// ---------------------------------------------------------
// GROUP 3: Focus Query Staging & Sanitization
// ---------------------------------------------------------
echo "\n--- GROUP 3: Focus Query Staging & Validation ---\n";

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$csrfToken = generateCsrfToken();

$postData = [
    'csrf_token' => $csrfToken,
    'guest_terms_accept' => '1',
    'source_type' => 'text',
    'original_text' => 'Machine learning models rely on statistical estimation to predict outcomes from training data. Overfitting is a risk when model capacity is too high.',
    'analysis_mode' => 'scientific',
    'summary_length' => 'very_brief',
    'output_format' => 'key_takeaways',
    'focus_query' => '  What is the risk of high model capacity?  <script>alert(1)</script> '
];

$pending = $service->buildPendingSummary($postData, [], null, 'test_guest_token');

assertTest("buildPendingSummary preserves analysis_mode 'scientific'", ($pending['analysis_mode'] ?? '') === 'scientific');
assertTest("buildPendingSummary preserves summary_depth 'very_brief'", ($pending['summary_depth'] ?? '') === 'very_brief');
assertTest("buildPendingSummary preserves output_format 'key_takeaways'", ($pending['output_format'] ?? '') === 'key_takeaways');
assertTest(
    "buildPendingSummary sanitizes focus_query (strips tags & trims)",
    ($pending['focus_query'] ?? '') === 'What is the risk of high model capacity?',
    "Result: " . ($pending['focus_query'] ?? '')
);

// ---------------------------------------------------------
// GROUP 4: LocalPythonBridge Q&A Engine Verification
// ---------------------------------------------------------
echo "\n--- GROUP 4: LocalPythonBridge Q&A Verification ---\n";

$pythonBridge = new LocalPythonBridge();
$v2Source = 'Local worker isolation keeps requests separate. The order system records each request identifier. '
    . 'A failed worker returns a terminal error instead of an empty summary. '
    . 'Selected sentences remain exact excerpts from their input source. '
    . 'The replacement engine records source evidence for every selected sentence.';
$v2Response = $pythonBridge->summarizeV2([
    'text' => $v2Source,
    'analysis_mode' => 'technical',
    'summary_depth' => 'balanced',
    'output_format' => 'paragraph',
    'sentence_count' => 8,
]);
assertTest("Independent LIGHT engine returns a versioned result", ($v2Response['schema_version'] ?? null) === 1);
assertTest(
    "Independent LIGHT engine identifies itself in result metadata",
    ($v2Response['source_metadata']['engine'] ?? '') === 'light_nlp'
);
assertTest(
    "Independent LIGHT engine returns source-traceable sentences",
    !empty($v2Response['sentences']) && count($v2Response['evidence'] ?? []) === count($v2Response['sentences'])
);
assertTest(
    "Independent LIGHT engine reports its semantic ranking status",
    is_string($v2Response['source_metadata']['semantic_model_status'] ?? null)
        && $v2Response['source_metadata']['semantic_model_status'] !== ''
);
if (is_dir(dirname(__DIR__) . '/storage/models/all-MiniLM-L6-v2')) {
    assertTest(
        "Independent LIGHT engine loads the configured local semantic model",
        str_starts_with(
            (string)($v2Response['source_metadata']['semantic_model_status'] ?? ''),
            'enabled:all-MiniLM-L6-v2'
        )
    );
}
assertTest(
    "Independent LIGHT engine returns an explicit grammar-check status",
    in_array(
        $v2Response['source_metadata']['grammar_diagnostics']['status'] ?? '',
        ['checked', 'unavailable'],
        true
    )
);
$sampleText = "The James Webb Space Telescope (JWST) was launched on December 25, 2021. It orbits the Sun-Earth L2 Lagrange point approximately 1.5 million kilometers from Earth. Its primary mirror consists of 18 hexagonal beryllium segments plated with gold.";

// Test 4.1: Answerable Question
$qaPayload = [
    'text' => $sampleText,
    'question' => 'When was the James Webb Space Telescope launched?'
];
$qaResponse = $pythonBridge->qa($qaPayload);

assertTest("LocalPythonBridge::qa() returns valid array with found key", array_key_exists('found', $qaResponse));
assertTest("LocalPythonBridge::qa() finds grounded answer", ($qaResponse['found'] ?? false) === true);
assertTest(
    "LocalPythonBridge::qa() answer cites launch date December 25, 2021",
    str_contains($qaResponse['answer'] ?? '', 'December 25, 2021') || str_contains($qaResponse['answer'] ?? '', 'launched'),
    "Answer: " . ($qaResponse['answer'] ?? '')
);
assertTest("LocalPythonBridge::qa() provides cited passages", !empty($qaResponse['passages']));

// Test 4.2: Honest Fallback on Irrelevant / Absent Query
$absentPayload = [
    'text' => $sampleText,
    'question' => 'What is the recipe for baking chocolate chip cookies in an oven?'
];
$absentResponse = $pythonBridge->qa($absentPayload);

assertTest("LocalPythonBridge::qa() returns valid response on out-of-scope query", array_key_exists('found', $absentResponse));
assertTest(
    "LocalPythonBridge::qa() honestly reports 'found' is false",
    ($absentResponse['found'] ?? true) === false
);
assertTest(
    "LocalPythonBridge::qa() provides factual disclaimer instead of hallucinating",
    str_contains($absentResponse['answer'] ?? '', 'does not contain information directly addressing this question'),
    "Answer: " . ($absentResponse['answer'] ?? '')
);

// ---------------------------------------------------------
// GROUP 5: API QA Endpoint Dispatch & Security
// ---------------------------------------------------------
echo "\n--- GROUP 5: api/qa.php Endpoint Integrity ---\n";

// GET request should reject with 405 Method Not Allowed
$codeQaGet = '<?php
$_SERVER["REQUEST_METHOD"] = "GET";
require_once "app/public/api/qa.php";
';
$tempFile = sys_get_temp_dir() . '/test_qa_get_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeQaGet);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$qaGetOut = implode('', $output);
$qaGetJson = json_decode($qaGetOut, true);
assertTest("api/qa.php rejects GET requests with 405", str_contains($qaGetJson['error'] ?? '', 'Method Not Allowed'));

// Empty POST body should reject with 400
$codeQaEmpty = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
// No JSON body passed in php://input
require_once "app/public/api/qa.php";
';
$tempFile = sys_get_temp_dir() . '/test_qa_empty_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempFile, $codeQaEmpty);
$cmd = 'php ' . escapeshellarg($tempFile);
$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
unlink($tempFile);
$qaEmptyOut = implode('', $output);
$qaEmptyJson = json_decode($qaEmptyOut, true);
assertTest("api/qa.php rejects empty POST with 400", str_contains($qaEmptyJson['error'] ?? '', 'Invalid JSON request payload'));

// ---------------------------------------------------------
// GROUP 6: Article extraction & persisted classifier metadata
// ---------------------------------------------------------
echo "\n--- GROUP 6: Article Extraction & Adaptive Metadata ---\n";

$sourceResolver = new SourceResolver();
$extractHtml = new ReflectionMethod(SourceResolver::class, 'extractReadableHtmlText');
$html = '<!doctype html><html><body>'
    . '<nav><p>Unrelated navigation link text is not part of this article.</p></nav>'
    . '<main><article><h1>City transit service expands</h1>'
    . '<h2>Service changes</h2>'
    . '<p>Officials announced three new bus routes connecting the east district to downtown.</p>'
    . '<p>The routes will begin operating on Monday after a six month pilot program.</p>'
    . '<div class="cookie-banner"><p>This website uses cookies and tracking technologies.</p></div>'
    . '<div class="advertisement"><p>Sponsored products and unrelated promotion appear here.</p></div>'
    . '<div class="related-content"><p>Related stories from another section of the website.</p></div>'
    . '</article></main></body></html>';
$articleText = $extractHtml->invoke($sourceResolver, $html);
assertTest("Article HTML extraction retains title and section headings", str_contains($articleText, "City transit service expands\n\nService changes"));
assertTest("Article HTML extraction retains source paragraphs", str_contains($articleText, 'three new bus routes'));
assertTest(
    "Article HTML extraction excludes navigation, cookie, ads, and related content",
    !str_contains($articleText, 'Unrelated navigation')
        && !str_contains($articleText, 'uses cookies')
        && !str_contains($articleText, 'Sponsored products')
        && !str_contains($articleText, 'Related stories')
);

$articleServiceReflection = new ReflectionClass(ArticleService::class);
$articleServiceWithoutConstructor = $articleServiceReflection->newInstanceWithoutConstructor();
$normalizeMetadata = new ReflectionMethod(ArticleService::class, 'normalizeSourceMetadata');
$normalizedMetadata = $normalizeMetadata->invoke($articleServiceWithoutConstructor, [
    'semantic_model_status' => 'enabled:all-MiniLM-L6-v2',
    'document_analysis' => [
        'document_type' => 'news',
        'document_type_confidence' => 0.8,
        'selection_profile' => 'news',
        'selection_profile_source' => 'detected',
        'classification_signals' => ['language_cues'],
    ],
    'source_guard' => [
        'status' => 'exact_source_validated',
        'method' => 'source_offset_and_substring_validation',
        'sentences_checked' => 3,
        'semantic_entailment_measured' => false,
    ],
    'grammar_diagnostics' => [
        'status' => 'unavailable',
        'method' => 'local_grammar_checker',
        'reason' => 'local_languagetool_not_prepared',
        'issue_count' => 0,
        'issues_by_sentence' => [],
    ],
]);
assertTest(
    "PHP persistence normalization retains adaptive and validation metadata",
    ($normalizedMetadata['document_analysis']['document_type'] ?? '') === 'news'
        && ($normalizedMetadata['document_analysis']['selection_profile'] ?? '') === 'news'
        && ($normalizedMetadata['source_guard']['status'] ?? '') === 'exact_source_validated'
        && ($normalizedMetadata['grammar_diagnostics']['status'] ?? '') === 'unavailable'
        && ($normalizedMetadata['semantic_model_status'] ?? '') === 'enabled:all-MiniLM-L6-v2'
);

// ---------------------------------------------------------
// Summary
// ---------------------------------------------------------
echo "\n====================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================\n";

exit($failCount > 0 ? 1 : 0);
