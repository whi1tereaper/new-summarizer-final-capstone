<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';

use App\Src\Database;

$db = Database::getInstance()->getConnection();
$passCount = 0;
$failCount = 0;

function assertCondition(bool $condition, string $testName): void
{
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$testName}\n";
        $failCount++;
    }
}

// Keep fixtures private to this connection and remove them even if rendering throws
// or terminates early. A real share token prevents result.php redirecting away.
$db->beginTransaction();
register_shutdown_function(static function () use ($db): void {
    if ($db->inTransaction()) $db->rollBack();
});

try {
    $testGuestToken = bin2hex(random_bytes(32));
    $testShareToken = bin2hex(random_bytes(32));
    $stmt = $db->prepare('
        INSERT INTO summaries (user_id, guest_token, share_token, article_title, input_type, summary_style, summary_length, processing_time, status)
        VALUES (NULL, ?, ?, "Quantum AI Test Paper", "text", "standard_paragraph", "balanced", 0.4, "completed")
    ');
    $stmt->execute([$testGuestToken, $testShareToken]);
    $summaryId = (int) $db->lastInsertId();

    $mainSummary = 'Quantum computing enables quadratic and exponential speedups for specialized problems.';
    $purposeSnippet = 'This study investigates quantum computing applications in cryptographic analysis.';
    $conclusionSnippet = 'The study concludes that post-quantum cryptography must be phased in proactively.';
    $generatedSummaryJson = json_encode([
        'plain_summary' => $mainSummary,
        'blocks' => [$mainSummary],
        'unit' => 'paragraph',
        'overview' => [$purposeSnippet],
        'output_format' => 'paragraph',
        'analysis_mode' => 'academic',
        'keywords' => ['quantum', 'cryptography', 'qubit'],
        'important_terms' => [['term' => 'Qubit', 'meaning' => 'A unit of quantum information.']],
        'conclusion' => $conclusionSnippet,
        'key_points' => ['Shor algorithm factorizes RSA integers in polynomial time.'],
        'structured_summary' => [
            // Regression: recover the real Purpose and Conclusion from mislabeled slots.
            ['label' => 'Purpose', 'text' => $conclusionSnippet],
            ['label' => 'Key Findings', 'text' => 'Simulations showed 4096-bit keys breakable with 20 million physical qubits.'],
            ['label' => 'Conclusion', 'text' => 'Shor algorithm factorizes RSA integers in polynomial time.'],
        ],
        'paragraph_summaries' => [[
            'paragraph_number' => 1,
            'purpose' => 'Introduction',
            'summary' => 'This study examines quantum algorithms for public key cryptosystems.',
        ]],
        'readability' => ['compression_percent' => 74.5, 'estimated_reading_time_minutes' => 2],
        'source_metadata' => ['source_type' => 'Academic Paper', 'paragraph_count' => 6],
        'evidence' => [[
            'section' => 'Introduction',
            'supports' => $mainSummary,
            'excerpt' => 'Quantum algorithms provide different speedups for specialized problem classes.',
        ]],
        'coverage' => [['section' => 'Introduction', 'evidence_count' => 1]],
    ], JSON_THROW_ON_ERROR);
    $originalText = "Paragraph 1: Introduction to quantum algorithms.\n\nParagraph 2: Detailed methodology.\n\nParagraph 3: Experimental outcomes.";
    $db->prepare('INSERT INTO summary_artifacts (summary_id, original_text, generated_summary) VALUES (?, ?, ?)')
        ->execute([$summaryId, $originalText, $generatedSummaryJson]);

    $_GET = ['id' => (string) $summaryId, 'share' => $testShareToken];
    $_SESSION = ['guest_token' => $testGuestToken];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/app/public/result.php?id=' . $summaryId;
    ob_start();
    try {
        require __DIR__ . '/../app/public/result.php';
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    echo "Running Results Page Reading and Disclosure Verification Suite...\n\n";
    $document = new DOMDocument();
    $previousErrorMode = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrorMode);
    $xpath = new DOMXPath($document);
    $byId = static fn (string $id) => $xpath->query('//*[@id="' . $id . '"]')->item(0);
    $hasClass = static fn (string $class) => 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';

    assertCondition($byId('original-summary') !== null, 'Completed result exposes a readable summary');
    assertCondition(str_contains($byId('original-summary')?->textContent ?? '', $mainSummary), 'Full summary is rendered even when a shorter overview is available');
    assertCondition(!str_contains($byId('original-summary')?->textContent ?? '', $purposeSnippet), 'Overview does not replace the selected paragraph output');
    assertCondition($xpath->query('//*[' . $hasClass('result-subnav') . ']')->length === 0, 'Reader is not preceded by a competing section navigation bar');
    assertCondition($xpath->query('//*[' . $hasClass('result-section__num') . ']')->length === 0, 'Reading sections do not carry repeated dashboard numbering');

    foreach (['copy-summary-btn', 'export-pdf-btn', 'play-audio-btn', 'translate-btn'] as $actionId) {
        $actions = $xpath->query('//*[@id="' . $actionId . '"]');
        assertCondition($actions->length === 1, $actionId . ' is available exactly once');
        assertCondition(
            $xpath->query('//*[@id="' . $actionId . '"]/following::*[@id="original-summary"]')->length === 1,
            $actionId . ' is reachable before reading the summary'
        );
    }
    assertCondition($byId('play-audio-btn-tools') === null, 'Audio uses the canonical action without a second hidden tools control');
    assertCondition($byId('summary-status') !== null, 'Action status feedback remains available');
    assertCondition($xpath->query('//*[@id="summary-status"]/following::*[@id="original-summary"]')->length === 1, 'Action feedback is near the reading toolbar');

    foreach (['result-context', 'result-sources', 'result-details'] as $disclosureId) {
        $disclosure = $byId($disclosureId);
        assertCondition($disclosure?->nodeName === 'details', $disclosureId . ' uses a native disclosure');
        assertCondition($disclosure instanceof DOMElement && !$disclosure->hasAttribute('open'), $disclosureId . ' starts collapsed');
        assertCondition($xpath->query('//*[@id="' . $disclosureId . '"]/summary')->length === 1, $disclosureId . ' has a keyboard-accessible summary control');
    }
    assertCondition(str_contains($byId('result-details')?->textContent ?? '', '74.5%'), 'Compression remains available inside optional summary details');
    assertCondition($xpath->query('//*[' . $hasClass('result-stat__bar') . ']')->length === 0, 'Compression is not presented as a prominent score bar');
    assertCondition(str_contains($byId('result-sources')?->textContent ?? '', 'Paragraph 1: Introduction to quantum algorithms.'), 'Source group retains the original document text');
    assertCondition(str_contains($byId('result-sources')?->textContent ?? '', 'Quantum algorithms provide different speedups'), 'Source group retains supporting passages');
    assertCondition(str_contains($byId('result-context')?->textContent ?? '', 'cryptography'), 'Reading context retains document keywords');

    assertCondition($byId('feedback-widget') !== null, 'Summary rating remains available');
    assertCondition($xpath->query('//*[@id="feedback-widget"]/ancestor::details')->length === 0, 'Rating can be reached independently of optional reading panels');
    assertCondition(str_contains($byId('result-context')?->textContent ?? '', $purposeSnippet), 'Section analysis recovers Purpose from its overview instead of duplicating Conclusion');
    assertCondition(str_contains($html, $conclusionSnippet), 'Conclusion retains the actual conclusion text');

    echo "\nSummary: {$passCount} passed, {$failCount} failed.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

if ($failCount > 0) exit(1);
