<?php
namespace App\Src\Controllers;

require_once __DIR__ . '/../whitereaper.php';
require_once __DIR__ . '/../Services/ArticleService.php';
require_once __DIR__ . '/../Services/SummaryJobService.php';
require_once __DIR__ . '/../Utils/validation.php';
require_once __DIR__ . '/../Support/ProcessingError.php';

use App\Src\Services\ArticleService;
use App\Src\Services\SummaryJobService;
use App\Src\Services\FileUploadService;
use App\Src\Support\ProcessingError;
use App\Src\Support\ProcessingDiagnostics;

class ArticleController
{
    private ArticleService $articleService;
    private SummaryJobService $jobService;
    public function __construct()
    {
        $this->articleService = new ArticleService();
        $this->jobService = new SummaryJobService();
    }

    public function process(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Location: summarizer.php');
            return;
        }
        $pending = null;
        ProcessingDiagnostics::begin();
        ProcessingDiagnostics::stage('request_validation', self::class, 'process');
        try {
            $maxPost = FileUploadService::iniBytes((string)ini_get('post_max_size'));
            if ($maxPost > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxPost) throw new ProcessingError('INPUT_TOO_LARGE');
            $this->requireMutation();
            [$userId, $guestToken] = $this->resolveViewerContext();
            $pending = $this->articleService->buildPendingSummary($_POST, $_FILES, $userId, $guestToken);
            unset($pending['csrf_token']);
            ProcessingDiagnostics::stage('job_queue', SummaryJobService::class, 'ensureJobForPending');
            $this->jobService->purgeExpiredPayloads();
            $job = $this->jobService->ensureJobForPending($pending, $userId, $guestToken);
            if ($job['job_id'] !== $pending['job_id']) {
                $this->articleService->cleanupTemporaryArtifacts((string)$pending['file_path'], null);
                $stored = json_decode((string)$job['payload'], true);
                if (is_array($stored) && $stored !== []) $pending = $stored;
            }
            $pending['job_id'] = $job['job_id'];
            $this->preserveFormState($pending);
            $_SESSION['pending_summary'] = array_intersect_key($pending, array_flip([
                'job_id', 'source_type', 'original_file_name', 'document_title',
                'output_format', 'analysis_mode', 'summary_depth', 'focus_query', 'use_llm_synthesis',
            ]));
            unset($_SESSION['flash_error'], $_SESSION['flash_failure']);
            $redirect = $job['state'] === 'completed' && !empty($job['result_id'])
                ? 'result.php?id=' . (int)$job['result_id'] : 'processing.php';
            if ($this->wantsJson()) $this->json(['success' => true, 'job_id' => $job['job_id'], 'redirect' => $redirect]);
            else header('Location: ' . $redirect, true, 303);
        } catch (\Throwable $error) {
            if ($pending !== null) $this->articleService->cleanupTemporaryArtifacts((string)($pending['file_path'] ?? ''), null);
            $this->preserveFormState($_POST);
            $failure = ProcessingError::fromThrowable($error);
            $failure->log();
            $_SESSION['flash_failure'] = $failure->toArray();
            $_SESSION['flash_error'] = $failure->getMessage();
            if ($this->wantsJson()) $this->emitFailure($failure);
            else header('Location: summarizer.php', true, 303);
        }
    }

    public function execute(): void
    {
        try {
            $this->requireMutation();
            $job = $this->ownedRequestedJob();
            if (in_array($job['state'], ['queued', 'processing'], true)) {
                $this->jobService->startBackgroundWorker($job['job_id']);
                $job = $this->jobService->getJob($job['job_id']) ?? $job;
            }
            $this->emitJobStatus($job);
        } catch (\Throwable $error) {
            $this->emitFailure(ProcessingError::fromThrowable($error));
        }
    }

    public function status(): void
    {
        try { $this->emitJobStatus($this->ownedRequestedJob()); }
        catch (\Throwable $error) { $this->emitFailure(ProcessingError::fromThrowable($error)); }
    }

    public function retry(): void
    {
        try {
            $this->requireMutation();
            $job = $this->ownedRequestedJob();
            if ($this->jobService->canRetry($job)) {
                $job = $this->jobService->retryJob($job);
                $this->jobService->startBackgroundWorker($job['job_id']);
                $job = $this->jobService->getJob($job['job_id']) ?? $job;
            }
            $this->emitJobStatus($job);
        } catch (\Throwable $error) { $this->emitFailure(ProcessingError::fromThrowable($error)); }
    }

    public function handleBackgroundJob(string $jobId): void
    {
        ProcessingDiagnostics::begin($jobId);
        ProcessingDiagnostics::stage('job_lookup', SummaryJobService::class, 'getJob');
        $job = $this->jobService->getJob($jobId);
        if ($job === null || !$this->jobService->markProcessing($jobId)) return;
        $pending = json_decode((string)($job['payload'] ?? ''), true);
        $filePath = is_array($pending) ? (string)($pending['file_path'] ?? '') : '';
        $resolvedSource = null;
        try {
            if (!is_array($pending) || $pending === []) throw new ProcessingError('REQUEST_INVALID');
            $this->articleService->reserveSummaryAccess($jobId);
            ProcessingDiagnostics::stage('source_resolution', ArticleService::class, 'resolvePendingSummarySource');
            $source = $this->articleService->resolvePendingSummarySource((string)($pending['original_text'] ?? ''), $filePath);
            $summaryInputText = (string)($source['text'] ?? '');
            $filePath = (string)($source['file_path'] ?? $filePath);
            $resolvedSource = $source['resolved_source'] ?? null;
            if ($filePath === '' && trim($summaryInputText) === '') throw new ProcessingError('INPUT_EMPTY');
            $summaryLength = (string)($pending['summary_depth'] ?? $pending['summary_length'] ?? 'balanced');
            $summaryStyle = (string)($pending['summary_style'] ?? 'standard_paragraph');
            ProcessingDiagnostics::stage('python_bridge', ArticleService::class, 'requestSummary');
            $result = $this->articleService->requestSummary(
                $summaryInputText, $filePath, (int)($pending['sentence_count'] ?? 8), $summaryStyle, $summaryLength,
                (string)($pending['document_title'] ?? ''), (string)($pending['selection_mode'] ?? ''),
                (string)($pending['analysis_mode'] ?? ''), (string)($pending['output_format'] ?? ''),
                !empty($pending['use_llm_synthesis']), 0, (string)($pending['focus_query'] ?? ''), $jobId
            );
            ProcessingDiagnostics::engine($result['source_metadata']['engine_routing']['selected_engine']
                ?? config('summarizer.engine_version', 'legacy'));
            ProcessingDiagnostics::stage('response_validation', ArticleService::class, 'storeSummary');
            $this->articleService->storeSummary(
                isset($job['user_id']) ? (int)$job['user_id'] : null, $job['guest_token'] ?? null,
                $result, (string)($source['input_type'] ?? 'text'), $summaryStyle, $summaryInputText, $summaryLength, null, $jobId
            );
        } catch (\Throwable $error) {
            $this->jobService->failJob($jobId, $error, 'failed', getmypid());
        } finally {
            $this->articleService->releaseSummaryAccess();
            $this->articleService->cleanupTemporaryArtifacts($filePath, $resolvedSource);
        }
    }

    public function cancel(): void
    {
        try {
            $this->requireMutation();
            $job = $this->ownedRequestedJob();
            if ($job['state'] === 'completed') { $this->emitJobStatus($job); return; }
            $payload = json_decode((string)$job['payload'], true) ?: [];
            $this->jobService->cancelJob($job['job_id']);
            $fresh = $this->jobService->getJob($job['job_id']);
            if ($fresh !== null && $fresh['state'] !== 'cancelled') {
                $this->emitJobStatus($fresh);
                return;
            }
            (new \App\Src\Services\LocalPythonBridge())->cancelJob($job['job_id']);
            $this->articleService->cleanupTemporaryArtifacts((string)($payload['file_path'] ?? ''), null);
            $this->json(['success' => true, 'cancelled' => true, 'job_id' => $job['job_id']]);
        } catch (\Throwable $error) { $this->emitFailure(ProcessingError::fromThrowable($error)); }
    }

    private function ownedRequestedJob(): array
    {
        $jobId = $_GET['job_id'] ?? $_POST['job_id'] ?? $_SESSION['pending_summary']['job_id'] ?? '';
        if (!is_string($jobId) || !preg_match('/\A[a-f0-9]{32}\z/', $jobId)) throw new ProcessingError('JOB_NOT_FOUND');
        [$viewerId, $viewerGuest] = $this->resolveViewerContext();
        $job = $this->jobService->getJobForOwner($jobId, $viewerId, $viewerGuest);
        if ($job === null) throw new ProcessingError('JOB_NOT_FOUND');
        return $job;
    }

    private function emitJobStatus(array $job): void
    {
        $state = (string)$job['state'];
        $response = ['success' => true, 'job_id' => $job['job_id'], 'status' => $state, 'can_retry' => false];
        $http = 200;
        if (in_array($state, ['failed', 'cancelled', 'timed_out'], true)) {
            $failure = $this->jobService->failureForJob($job);
            $response['success'] = false;
            $response['failure'] = $failure->toArray();
            $response['error'] = $failure->getMessage();
            $response['can_retry'] = $this->jobService->canRetry($job);
            $http = $failure->httpStatus;
        } elseif ($state === 'completed' && !empty($job['result_id'])) {
            $response['redirect'] = 'result.php?id=' . (int)$job['result_id'];
            if (($_SESSION['pending_summary']['job_id'] ?? '') === $job['job_id']) {
                unset($_SESSION['summarizer_form_state'], $_SESSION['summarizer_form_saved_at']);
                $_SESSION['pending_summary'] = ['job_id' => $job['job_id']];
            }
        }
        $this->json($response, $http);
    }

    private function requireMutation(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            $this->emitFailure(new ProcessingError('REQUEST_INVALID'), 405);
            exit;
        }
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrfToken($_POST['csrf_token'])) throw new ProcessingError('ACCESS_DENIED');
    }

    private function resolveViewerContext(): array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId !== null && filter_var($userId, FILTER_VALIDATE_INT) && $this->articleService->userExists((int)$userId)) return [(int)$userId, null];
        if ($userId !== null) throw new ProcessingError('AUTH_REQUIRED');
        return [null, $_SESSION['guest_token'] ?? null];
    }

    private function wantsJson(): bool
    {
        return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private function json(array $response, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($status);
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function emitFailure(ProcessingError $failure, ?int $status = null): void
    {
        $failure->log();
        $this->json(['success' => false, 'error' => $failure->getMessage(), 'failure' => $failure->toArray()], $status ?? $failure->httpStatus);
    }

    private function preserveFormState(array $values): void
    {
        $state = [];
        foreach (['document_title', 'source_type', 'original_text', 'source_url', 'output_format', 'analysis_mode',
            'summary_depth', 'summary_length', 'use_llm_synthesis', 'focus_query', 'sentence_count', 'summary_style', 'request_token'] as $key) {
            if (is_string($values[$key] ?? null) && strlen($values[$key]) <= ArticleService::MAX_TEXT_BYTES) $state[$key] = $values[$key];
        }
        if (($values['use_llm_synthesis'] ?? false) === true) $state['use_llm_synthesis'] = '1';
        $_SESSION['summarizer_form_state'] = $state;
        $_SESSION['summarizer_form_saved_at'] = time();
    }
}
