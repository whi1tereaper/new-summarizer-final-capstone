<?php
namespace App\Src\Controllers;

require_once __DIR__ . '/../whitereaper.php';
require_once __DIR__ . '/../Services/ArticleService.php';
require_once __DIR__ . '/../Utils/validation.php';

use App\Src\Services\ArticleService;

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

        $pending = null;
        try {
            // Normalize the incoming form into one session payload the processing page can resume later.
            $pending = $this->articleService->buildPendingSummary($_POST, $_FILES, $userId, $guestToken);
        } catch (\RuntimeException $exception) {
            $this->preserveFormState($_POST);
            $this->redirectWithFlashError($exception->getMessage());
        }

        if ($pending === null) {
            $this->redirectWithFlashError('Failed to prepare summary request.');
        }

        // Store the request before redirecting so the next page can execute the heavy work asynchronously.
        $this->preserveFormState($pending);
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

        // If the staged user was deleted or invalid, gracefully normalize to guest context
        if ($userId !== null && !$this->articleService->userExists((int)$userId)) {
            error_log("[ArticleController] Staged user_id {$userId} does not exist in database; normalizing to guest.");
            $userId = null;
            if ($guestToken === null || $guestToken === '') {
                $guestSessionService = new \App\Src\Services\GuestSessionService();
                $guestToken = $guestSessionService->getCurrentGuestToken();
            }
        }
        $originalText = $pending['original_text'];
        $filePath = $pending['file_path'];
        $summaryCount = $pending['sentence_count'] ?? 8;
        $summaryLength = $pending['summary_depth'] ?? ($pending['summary_length'] ?? 'balanced');
        $summaryStyle = $pending['summary_style'];
        $documentTitle = $pending['document_title'] ?? '';
        $selectionMode = $pending['selection_mode'] ?? '';
        $analysisMode = $pending['analysis_mode'] ?? '';
        $outputFormat = $pending['output_format'] ?? '';
        $useLlmSynthesis = ($pending['use_llm_synthesis'] ?? false) === true;

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
            $this->preserveFormState($pending);
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
                $selectionMode,
                $analysisMode,
                $outputFormat,
                $useLlmSynthesis
            );
        } catch (\RuntimeException $exception) {
            // If summarization fails, remove request artifacts and return a worker-safe error.
            $this->articleService->cleanupTemporaryArtifacts($filePath, $resolvedSource);
            $this->preserveFormState($pending);
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
        $this->preserveFormState($pending);
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
            $userIdInt = filter_var($userId, FILTER_VALIDATE_INT);
            if ($userIdInt !== false && $userIdInt > 0) {
                if ($this->articleService->userExists((int)$userIdInt)) {
                    return [(int)$userIdInt, null];
                }
            }
            // Invalid or deleted user ID in session: purge auth fields and fallback to guest
            unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username']);
        }

        return [null, $_SESSION['guest_token'] ?? null];
    }

    private function redirectWithFlashError(string $message): void
    {
        // Use flash state so the form page can explain the failure after the redirect.
        $_SESSION['flash_error'] = $message;
        header('Location: summarizer.php');
        exit;
    }

    private function preserveFormState(array $values): void
    {
        // Keep retryable options and safe text/URL input across errors; uploaded temp files cannot survive cleanup.
        $state = [];
        foreach (['document_title', 'source_type', 'original_text', 'source_url', 'output_format', 'analysis_mode', 'summary_depth', 'summary_length', 'use_llm_synthesis'] as $key) {
            if (is_string($values[$key] ?? null) && strlen($values[$key]) <= 200000) {
                $state[$key] = $values[$key];
            }
        }
        if (($values['use_llm_synthesis'] ?? false) === true) {
            $state['use_llm_synthesis'] = '1';
        }
        $_SESSION['summarizer_form_state'] = $state;
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
