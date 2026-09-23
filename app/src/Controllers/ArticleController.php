<?php
namespace App\Src\Controllers;

require_once __DIR__ . '/../whitereaper.php';
require_once __DIR__ . '/../Services/ArticleService.php';
require_once __DIR__ . '/../Utils/validation.php';

use App\Src\Services\ArticleService;
use App\Src\Services\TermsAcceptanceService;

class ArticleController
{
    private ArticleService $articleService;

    public function __construct()
    {
        $this->articleService = new ArticleService();
    }

    public function process(): void
    {
        // Only real form posts should create pending work in the session.
        $this->redirectIfNotPost();
        $this->validateCsrfOrRedirect();

        // Resolve who owns the summary before any upload or worker work starts.
        [$userId, $guestToken] = $this->resolveViewerContext();
        $this->validateTermsConsentForRequest($userId);

        $pending = null;
        try {
            // Normalize the incoming form into one session payload the processing page can resume later.
            $pending = $this->articleService->buildPendingSummary($_POST, $_FILES, $userId, $guestToken);
        } catch (\RuntimeException $exception) {
            $this->redirectWithFlashError($exception->getMessage());
        }

        if ($pending === null) {
            $this->redirectWithFlashError('Failed to prepare summary request.');
        }

        // Store the request before redirecting so the next page can execute the heavy work asynchronously.
        $_SESSION['pending_summary'] = $pending;
        header('Location: processing.php');
        exit;
    }

    public function execute(): void
    {
        header('Content-Type: application/json');

        // The processing page should only run after the first step has staged a valid summary request.
        if (empty($_SESSION['pending_summary'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No pending summary found.']);
            exit;
        }

        $pending = $_SESSION['pending_summary'];
        $userId = $pending['user_id'];
        $guestToken = $pending['guest_token'];
        $originalText = $pending['original_text'];
        $filePath = $pending['file_path'];
        $summaryCount = $pending['sentence_count'] ?? 8;
        $summaryLength = $pending['summary_length'] ?? 'balanced';
        $summaryStyle = $pending['summary_style'];
        $documentTitle = $pending['document_title'] ?? '';
        $selectionMode = $pending['selection_mode'] ?? '';

        $resolvedSource = null;
        $summaryInputText = $originalText;
        $inputType = $filePath === '' ? 'text' : pathinfo($filePath, PATHINFO_EXTENSION);
        $processingStartedAt = microtime(true);

        try {
            // Convert pasted URLs into extracted article text before the worker sees the request.
            $sourceResult = $this->articleService->resolvePendingSummarySource($originalText, $filePath);
            $summaryInputText = $sourceResult['text'];
            $inputType = $sourceResult['input_type'];
            $resolvedSource = $sourceResult['resolved_source'];
        } catch (\RuntimeException $exception) {
            // Clean up early when source preparation fails so temporary files do not linger.
            $this->articleService->cleanupTemporaryArtifacts($filePath, null);
            $this->recordFailure($userId, $guestToken, $inputType, $summaryStyle, $summaryLength, $processingStartedAt);
            http_response_code(400);
            echo json_encode(['error' => $exception->getMessage()]);
            exit;
        }

        // Catch empty text-only requests here before invoking the Python worker.
        if ($filePath === '' && $summaryInputText === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Paste article text, article link, or upload a PDF/DOCX file.']);
            exit;
        }

        try {
            // The worker returns the raw summary payload that will later be normalized for storage/display.
            $summaryResult = $this->articleService->requestSummary(
                $summaryInputText,
                $filePath,
                $summaryCount,
                $summaryStyle,
                $summaryLength,
                $documentTitle,
                $selectionMode
            );
        } catch (\RuntimeException $exception) {
            // If summarization fails, remove request artifacts and return a worker-safe error.
            $this->articleService->cleanupTemporaryArtifacts($filePath, $resolvedSource);
            $this->recordFailure($userId, $guestToken, $inputType, $summaryStyle, $summaryLength, $processingStartedAt);
            http_response_code(500);
            echo json_encode(['error' => $exception->getMessage()]);
            exit;
        }

        try {
            // Persist the finished summary before the browser leaves the processing page.
            // Keep the original user source for the result-page reference; use extracted text only
            // when an uploaded file did not provide a separate text field.
            $sourceReference = trim($originalText) !== '' ? $originalText : $summaryInputText;
            $summaryId = $this->articleService->storeSummary(
                $userId,
                $guestToken,
                $summaryResult,
                $inputType,
                $summaryStyle,
                $sourceReference,
                $summaryLength,
                microtime(true) - $processingStartedAt
            );
        } catch (\RuntimeException $exception) {
            // Failed saves should not leave uploads or downloaded sources behind.
            $this->articleService->cleanupTemporaryArtifacts($filePath, $resolvedSource);
            error_log('[ArticleController] Failed to persist summary: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not save summary. Please try again.']);
            exit;
        }

        // Success path: clear staged state, rotate the form token, then send the result redirect back to JS.
        $this->articleService->cleanupTemporaryArtifacts($filePath, $resolvedSource);
        unset($_SESSION['pending_summary']);
        rotateCsrfToken();

        echo json_encode([
            'success' => true,
            'redirect' => "result.php?id={$summaryId}"
        ]);
        exit;
    }

    private function redirectIfNotPost(): void
    {
        // The first step only accepts form submissions, not direct browser visits.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: summarizer.php');
            exit;
        }
    }

    private function validateCsrfOrRedirect(): void
    {
        // Reject forged form posts before touching uploads, sessions, or worker resources.
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            $this->redirectWithFlashError('Invalid security token. Please try again.');
        }
    }

    private function resolveViewerContext(): array
    {
        // Logged-in users and guests save history differently, so resolve that identity once here.
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId !== null) {
            return [$userId, null];
        }

        return [null, $_SESSION['guest_token'] ?? null];
    }

    private function validateTermsConsentForRequest(int|string|null $userId): void
    {
        // Logged-in users can be forced through a new terms version even if they already have accounts.
        if ($userId !== null) {
            if (TermsAcceptanceService::currentUserNeedsAcceptance()) {
                $acceptedInRequest = isAcceptedCheckboxValue($_POST['guest_terms_accept'] ?? null);
                if (!$acceptedInRequest) {
                    $this->redirectWithFlashError('You must accept the Terms and Conditions before using the summarizer.');
                }
                (new TermsAcceptanceService())->acceptCurrentUserTerms();
            }

            return;
        }

        // Guests must both review the full page and actively accept before they can send content.
        if (empty($_SESSION['guest_terms_reviewed'])) {
            $this->redirectWithFlashError('You must read the full Terms and Conditions before using the summarizer.');
        }

        $acceptedInRequest = isAcceptedCheckboxValue($_POST['guest_terms_accept'] ?? null);
        $acceptedInSession = !empty($_SESSION['guest_terms_accepted']);
        if (!$acceptedInRequest && !$acceptedInSession) {
            $this->redirectWithFlashError('You must accept the Terms and Conditions before using the summarizer.');
        }

        $_SESSION['guest_terms_accepted'] = true;
        $_SESSION['guest_terms_accepted_at'] = date('Y-m-d H:i:s');
    }

    private function redirectWithFlashError(string $message): void
    {
        // Use flash state so the form page can explain the failure after the redirect.
        $_SESSION['flash_error'] = $message;
        header('Location: summarizer.php');
        exit;
    }

    private function recordFailure(?int $userId, ?string $guestToken, string $inputType, string $summaryStyle, string $summaryLength, float $startedAt): void
    {
        try {
            $this->articleService->recordFailedSummary($userId, $guestToken, $inputType, $summaryStyle, $summaryLength, microtime(true) - $startedAt);
        } catch (\Throwable $exception) {
            error_log('[ArticleController] Failed to record analytics failure: ' . $exception->getMessage());
        }
    }
}

if (PHP_SAPI !== 'cli') {
    // Public route entry: create one controller and branch between stage-one form handling and stage-two execution.
    $controller = new ArticleController();
    if (isset($_GET['action']) && $_GET['action'] === 'execute') {
        $controller->execute();
    } else {
        $controller->process();
    }
}
