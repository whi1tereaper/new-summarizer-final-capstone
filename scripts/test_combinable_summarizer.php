<?php
declare(strict_types=1);

/**
 * Verification test suite for combinable (Profile, Length) summarizer refactoring.
 *
 * Verifies:
 * 1. Document creation returns documentId and synchronous default summary (general, balanced).
 * 2. Requesting a new (profile, length) combination generates and stores it with cached: false.
 * 3. Requesting the same combination a second time hits the cache with cached: true.
 * 4. Invalid profile and invalid length return 400 Bad Request with clear messages.
 * 5. Nonsense requests on short source text return guard note ("source is too short to expand").
 * 6. GET /api/documents/:documentId/summaries returns every combination generated so far.
 * 7. POST /api/documents/:documentId/summaries/batch generates uncached and returns all requested combinations.
 * 8. Controller dispatch cleanly processes all HTTP routes and returns correct status codes.
 */

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
require_once __DIR__ . '/../app/src/Services/DocumentSummaryService.php';
require_once __DIR__ . '/../app/src/Services/SummarizerPromptTemplate.php';
require_once __DIR__ . '/../app/src/Controllers/DocumentSummaryController.php';

use App\Src\Database;
use App\Src\Services\DocumentSummaryService;
use App\Src\Services\SummarizerPromptTemplate;
use App\Src\Controllers\DocumentSummaryController;

$passed = 0;
$failed = 0;

function assertSummaryTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [PASS] {$description}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
    } else {
        $failed++;
        echo " [FAIL] {$description}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
    }
}

echo "====================================================\n";
echo "COMBINABLE (PROFILE, LENGTH) SUMMARIZER TEST SUITE\n";
echo "====================================================\n\n";

$db = Database::getInstance()->getConnection();
$service = new DocumentSummaryService($db);

$sampleDocument = <<<TEXT
Artificial intelligence has rapidly transitioned from research laboratories into foundational infrastructure across industries. In modern software engineering, generative AI assistants aid developers in drafting code, generating unit tests, and producing system documentation. Natural language models identify security vulnerabilities, refactor obsolete syntax, and recommend architectural improvements in real time.

However, enterprise adoption introduces critical challenges surrounding deterministic correctness, data privacy, and maintainability. AI code generation models are prone to subtle logical hallucinations, security misconfigurations, and reliance on deprecated third-party libraries. Engineering organizations must implement robust automated testing, human-in-the-loop review boundaries, and static analysis gates to ensure production resilience.

Ultimately, artificial intelligence does not eliminate the need for experienced software engineers. Instead, it elevates the software engineering role toward system architecture, security verification, algorithmic constraints, and domain-specific trade-offs.
TEXT;

// ---------------------------------------------------------
// TEST GROUP 1: Input Validation & Nonsense Request Guarding
// ---------------------------------------------------------
echo "--- TEST GROUP 1: Input Validation & Nonsense Request Guarding ---\n";

// 1.1 Invalid profile validation
$invalidProfileCaught = false;
$invalidProfileMessage = '';
try {
    $service->validateProfileAndLength('invalid_profile', 'balanced');
} catch (\InvalidArgumentException $e) {
    $invalidProfileCaught = true;
    $invalidProfileMessage = $e->getMessage();
}
assertSummaryTest(
    "Invalid profile throws 400 InvalidArgumentException",
    $invalidProfileCaught && str_contains($invalidProfileMessage, "Invalid profile 'invalid_profile'"),
    $invalidProfileMessage
);

// 1.2 Invalid length validation
$invalidLengthCaught = false;
$invalidLengthMessage = '';
try {
    $service->validateProfileAndLength('executive', 'gigantic');
} catch (\InvalidArgumentException $e) {
    $invalidLengthCaught = true;
    $invalidLengthMessage = $e->getMessage();
}
assertSummaryTest(
    "Invalid length throws 400 InvalidArgumentException",
    $invalidLengthCaught && str_contains($invalidLengthMessage, "Invalid length 'gigantic'"),
    $invalidLengthMessage
);

// 1.3 Empty text validation
$emptyTextCaught = false;
try {
    $service->createDocument("   \n\t   ");
} catch (\InvalidArgumentException $e) {
    $emptyTextCaught = true;
}
assertSummaryTest("Empty document text is rejected", $emptyTextCaught);

// 1.4 Nonsense request guard: 'comprehensive' on short source
$shortText = "The meeting was adjourned at noon due to scheduling conflicts.";
$nonsenseResult = $service->generateSummary($shortText, 'general', 'comprehensive');
assertSummaryTest(
    "Nonsense guard: 'comprehensive' on short source returns guard note",
    str_contains(strtolower($nonsenseResult['summary']), 'source is too short to expand'),
    "Summary: '{$nonsenseResult['summary']}'"
);

// 1.5 Parameterized prompt template rendering
$prompt = SummarizerPromptTemplate::renderPrompt('executive', 'brief', $sampleDocument);
assertSummaryTest(
    "Prompt template renders per-combination parameters",
    str_contains($prompt, "Profile: Executive") && str_contains($prompt, "Length: Brief") && str_contains($prompt, "Summary:"),
    "Rendered length: " . strlen($prompt) . " bytes"
);

// ---------------------------------------------------------
// TEST GROUP 2: Document Creation & Synchronous Default Summary
// ---------------------------------------------------------
echo "\n--- TEST GROUP 2: Document Creation & Synchronous Default Summary ---\n";

$createResult = $service->createDocumentWithDefaultSummary($sampleDocument);
$docId = $createResult['documentId'] ?? 0;

assertSummaryTest("Document creation returns integer documentId", $docId > 0, "Document ID: {$docId}");
assertSummaryTest(
    "Synchronous default summary has profile 'general' and length 'balanced'",
    ($createResult['profile'] ?? '') === 'general' && ($createResult['length'] ?? '') === 'balanced',
    "Profile: {$createResult['profile']}, Length: {$createResult['length']}"
);
assertSummaryTest("Default summary text is generated and non-empty", !empty($createResult['summary']), "Words: " . ($createResult['wordCount'] ?? 0));
assertSummaryTest("Initial generation reports cached = false", ($createResult['cached'] ?? null) === false);
assertSummaryTest("sourceWordCount is computed in code", ($createResult['sourceWordCount'] ?? 0) > 100, "Source words: " . ($createResult['sourceWordCount'] ?? 0));
assertSummaryTest("compressionRatio is computed in code", isset($createResult['compressionRatio']) && $createResult['compressionRatio'] > 0.0, "Ratio: " . ($createResult['compressionRatio'] ?? 0));
assertSummaryTest("estimatedReadingTimeSeconds is computed in code", isset($createResult['estimatedReadingTimeSeconds']) && $createResult['estimatedReadingTimeSeconds'] > 0, "Reading time: " . ($createResult['estimatedReadingTimeSeconds'] ?? 0) . "s");

// ---------------------------------------------------------
// TEST GROUP 3: On-Demand Cached Generation
// ---------------------------------------------------------
echo "\n--- TEST GROUP 3: On-Demand Cached Generation & Cache Hit Verification ---\n";

// 3.1 Requesting same combination twice hits cache the second time
// Combination: (executive, brief)
$firstRun = $service->getOrGenerateSummary($docId, 'executive', 'brief');
assertSummaryTest("First request for (executive, brief) generates with cached = false", $firstRun['cached'] === false, "Generated at: {$firstRun['generatedAt']}");

$secondRun = $service->getOrGenerateSummary($docId, 'executive', 'brief');
assertSummaryTest("Second request for (executive, brief) hits cache with cached = true", $secondRun['cached'] === true);
assertSummaryTest(
    "Cached summary text exactly matches first generation",
    $firstRun['summary'] === $secondRun['summary'] && $firstRun['wordCount'] === $secondRun['wordCount'],
    "WordCount: {$secondRun['wordCount']}"
);
assertSummaryTest("Cached generatedAt timestamp is preserved", $firstRun['generatedAt'] === $secondRun['generatedAt']);

// 3.2 Requesting a different combination generates on demand
$technicalBalanced = $service->getOrGenerateSummary($docId, 'technical', 'balanced');
assertSummaryTest("Distinct combination (technical, balanced) generates with cached = false", $technicalBalanced['cached'] === false);

// ---------------------------------------------------------
// TEST GROUP 4: Collection State Retrieval for Frontend Restore
// ---------------------------------------------------------
echo "\n--- TEST GROUP 4: History Collection State Retrieval ---\n";

$allSummaries = $service->getSummaries($docId);
assertSummaryTest("getSummaries returns all generated combinations for document", count($allSummaries) >= 3, "Count: " . count($allSummaries));

$profilesReturned = array_column($allSummaries, 'profile');
$lengthsReturned = array_column($allSummaries, 'length');
assertSummaryTest("Contains 'general' and 'balanced'", in_array('general', $profilesReturned, true) && in_array('balanced', $lengthsReturned, true));
assertSummaryTest("Contains 'executive' and 'brief'", in_array('executive', $profilesReturned, true) && in_array('brief', $lengthsReturned, true));
assertSummaryTest("Contains 'technical' and 'balanced'", in_array('technical', $profilesReturned, true));

// ---------------------------------------------------------
// TEST GROUP 5: Batch Generation for Compare View
// ---------------------------------------------------------
echo "\n--- TEST GROUP 5: Batch Endpoint (Compare View) ---\n";

$batchCombinations = [
    ['profile' => 'executive', 'length' => 'brief'],       // Already cached
    ['profile' => 'academic', 'length' => 'detailed'],     // Not cached
    ['profile' => 'news', 'length' => 'brief'],            // Not cached
];

$batchResults = $service->batchGetOrGenerateSummaries($docId, $batchCombinations);
assertSummaryTest("Batch generation returns all 3 requested combinations", count($batchResults) === 3);

$cachedStates = array_column($batchResults, 'cached');
// First item was already cached, items 2 and 3 are newly generated
assertSummaryTest("Batch preserves cached state for existing items (cached = true)", $cachedStates[0] === true);
assertSummaryTest("Batch generates missing items on demand (cached = false)", $cachedStates[1] === false && $cachedStates[2] === false);

// ---------------------------------------------------------
// TEST GROUP 6: HTTP Controller Endpoints & Error Handling
// ---------------------------------------------------------
echo "\n--- TEST GROUP 6: HTTP Controller Endpoints & Status Codes ---\n";

$controller = new DocumentSummaryController($service);

// 6.1 Test invalid profile via controller dispatch
$capturedResponse = '';
try {
    ob_start();
    $controller->dispatch('POST', "/api/documents/{$docId}/summaries", [
        'profile' => 'astrology',
        'length' => 'balanced',
    ]);
} finally {
    $code = $controller->getLastStatusCode();
    $capturedResponse = ob_get_clean();
}
$decoded = json_decode($capturedResponse, true);
assertSummaryTest(
    "Controller returns 400 for invalid profile",
    $code === 400 && str_contains($decoded['error'] ?? '', "Invalid profile 'astrology'"),
    "Status: {$code}, Error: " . ($decoded['error'] ?? '')
);

// 6.2 Test invalid length via controller dispatch
try {
    ob_start();
    $controller->dispatch('POST', "/api/documents/{$docId}/summaries", [
        'profile' => 'academic',
        'length' => 'infinite',
    ]);
} finally {
    $code = $controller->getLastStatusCode();
    $capturedResponse = ob_get_clean();
}
$decoded = json_decode($capturedResponse, true);
assertSummaryTest(
    "Controller returns 400 for invalid length",
    $code === 400 && str_contains($decoded['error'] ?? '', "Invalid length 'infinite'"),
    "Status: {$code}, Error: " . ($decoded['error'] ?? '')
);

// 6.3 Test non-existent document returns 404
try {
    ob_start();
    $controller->dispatch('GET', "/api/documents/999999/summaries");
} finally {
    $code = $controller->getLastStatusCode();
    $capturedResponse = ob_get_clean();
}
assertSummaryTest("Controller returns 404 for non-existent document", $code === 404, "Status: {$code}");

// 6.4 Test unsupported HTTP method returns 405
try {
    ob_start();
    $controller->dispatch('DELETE', "/api/documents");
} finally {
    $code = $controller->getLastStatusCode();
    $capturedResponse = ob_get_clean();
}
assertSummaryTest("Controller returns 405 for unsupported method on /api/documents", $code === 405, "Status: {$code}");

// 6.5 Test GET /api/documents/:documentId/summaries via controller
try {
    ob_start();
    $controller->dispatch('GET', "/api/documents/{$docId}/summaries");
} finally {
    $code = $controller->getLastStatusCode();
    $capturedResponse = ob_get_clean();
}
$allRetrieved = json_decode($capturedResponse, true);
assertSummaryTest(
    "Controller GET /api/documents/:id/summaries returns 200 with array",
    $code === 200 && is_array($allRetrieved) && count($allRetrieved) >= 4,
    "Status: {$code}, Count: " . (is_array($allRetrieved) ? count($allRetrieved) : 0)
);

// ---------------------------------------------------------
// TEST GROUP 7: Python Engine Refactored Generation Function
// ---------------------------------------------------------
echo "\n--- TEST GROUP 7: Python Generation Function & Contract ---\n";

$bridge = new App\Src\Services\LocalPythonBridge();
$pyDecoded = $bridge->generateSummary($sampleDocument, 'study', 'balanced');

assertSummaryTest(
    "Python worker generate-summary returns { summary, metadata } contract",
    isset($pyDecoded['summary'], $pyDecoded['metadata']) && !empty($pyDecoded['summary']),
    "WordCount: " . ($pyDecoded['metadata']['wordCount'] ?? 0)
);
assertSummaryTest(
    "Python metadata includes wordCount, sourceWordCount, compressionRatio, estimatedReadingTimeSeconds",
    isset($pyDecoded['metadata']['wordCount'], $pyDecoded['metadata']['sourceWordCount'], $pyDecoded['metadata']['compressionRatio'], $pyDecoded['metadata']['estimatedReadingTimeSeconds'])
);

echo "\n====================================================\n";
echo "SUMMARY TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
