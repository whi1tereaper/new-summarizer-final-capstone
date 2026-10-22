<?php
ob_start();

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/LocalPythonBridge.php';
require_once __DIR__ . '/../src/Services/FileUploadService.php';
require_once __DIR__ . '/../src/Services/SourceResolver.php';
require_once __DIR__ . '/../src/Services/RateLimiter.php';
require_once __DIR__ . '/../src/Services/TermsAcceptanceService.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Utils/validation.php';
require_once __DIR__ . '/../src/Support/ProcessingError.php';

use App\Src\Database;
use App\Src\Services\FileUploadService;
use App\Src\Services\LocalPythonBridge;
use App\Src\Services\RateLimiter;
use App\Src\Services\SourceResolver;
use App\Src\Support\RuntimePaths;
use App\Src\Utils\Formatter;
use App\Src\Support\ProcessingError;

function nutshellJson(int $status, array $body): void
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function nutshellError(int $status, string $code, string $message = '', ?ProcessingError $failure = null): array
{
    // Keep legacy status/error keys for existing Nutshell clients, with the same
    // safe, centralized failure object used by the summarization workflow.
    $failure ??= new ProcessingError(match ($code) {
        'method_not_allowed', 'invalid_request_key', 'invalid_summary_id' => 'REQUEST_INVALID',
        'registration_required' => 'AUTH_REQUIRED',
        'csrf_invalid', 'account_inactive' => 'ACCESS_DENIED',
        'terms_required' => 'TERMS_REQUIRED',
        'summary_not_found' => 'RESULT_UNAVAILABLE',
        'summary_ineligible' => 'SUMMARY_INELIGIBLE',
        'ambiguous_input' => 'SOURCE_CONFLICT',
        'invalid_input' => 'INPUT_EMPTY',
        'source_too_large' => 'INPUT_TOO_LARGE',
        'insufficient_content' => 'INPUT_TOO_SHORT',
        'rate_limited' => 'RATE_LIMITED',
        default => 'PROCESSING_FAILED',
    });
    $httpStatus = $code === 'method_not_allowed' ? 405 : $failure->httpStatus;
    return [$httpStatus, ['success' => false, 'status' => $code, 'error' => $code,
        'message' => $failure->getMessage(), 'failure' => $failure->toArray()]];
}

function nutshellPreviousFailure(array $row): array
{
    $reason = (string)($row['failure_reason'] ?? '');
    // Old output_validation rows prove a quality failure; old worker_error rows
    // do not establish whether the source or infrastructure actually failed.
    $failure = new ProcessingError($reason === 'output_validation' ? 'SUMMARY_QUALITY_FAILED' : $reason);
    return nutshellError($failure->httpStatus, 'generation_failed', '', $failure);
}

function nutshellWordCount(string $text): int
{
    preg_match_all('/[\p{L}\p{N}]+(?:[\x{2019}\x27][\p{L}\p{N}]+)*/u', $text, $matches);
    return count($matches[0] ?? []);
}

function nutshellGenerate(): array
{
    $savedFile = '';
    $resolvedSource = null;
    $db = null;
    $transaction = false;
    $summaryId = null;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return nutshellError(405, 'method_not_allowed', 'POST request required.');
        if ($userId < 1) return nutshellError(401, 'registration_required', 'Create an account to use Nutshell.');

        $raw = file_get_contents('php://input');
        $json = $raw !== '' ? json_decode($raw, true) : null;
        $isJson = is_array($json);
        $input = $isJson ? $json : $_POST;
        $csrf = $input['csrf_token'] ?? null;
        if (!is_string($csrf) || !verifyCsrfToken($csrf)) return nutshellError(403, 'csrf_invalid', 'Invalid or expired CSRF token. Refresh the page and try again.');
        if (\App\Src\Services\TermsAcceptanceService::currentUserNeedsAcceptance()) return nutshellError(403, 'terms_required', 'Accept the Terms and Conditions before using Nutshell.');

        $db = Database::getInstance()->getConnection();
        $active = $db->prepare('SELECT active FROM users WHERE id = :id');
        $active->execute(['id' => $userId]);
        if ((int)$active->fetchColumn() !== 1) return nutshellError(403, 'account_inactive', 'This account cannot use Nutshell.');

        $rawId = $input['summary_id'] ?? null;
        if ($rawId !== null && $rawId !== '') {
            if (!is_scalar($rawId) || !preg_match('/^[1-9][0-9]{0,9}$/', (string)$rawId)) return nutshellError(400, 'invalid_summary_id', 'The summary ID is invalid.');
            $summaryId = (int)$rawId;
        }
        $requestKey = $input['request_key'] ?? '';
        if (!is_string($requestKey) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/', $requestKey)) return nutshellError(400, 'invalid_request_key', 'Refresh the page and try again.');
        $idempotencyKey = hash('sha256', $userId . ':' . ($summaryId ?? 'standalone') . ':' . $requestKey);
        if ($summaryId === null) {
            $priorStmt = $db->prepare('SELECT id,status,failure_reason,nutshell_text,word_count,source_word_count,primary_summary_word_count,compression_ratio,processing_time_ms,algorithm_version,analysis_mode FROM nutshell_generations WHERE idempotency_key=:key LIMIT 1');
            $priorStmt->execute(['key' => $idempotencyKey]);
            $prior = $priorStmt->fetch(PDO::FETCH_ASSOC);
            if ($prior && $prior['status'] === 'completed' && trim((string)$prior['nutshell_text']) !== '') {
                return [200, nutshellSuccess((string)$prior['nutshell_text'], $prior, (string)$prior['analysis_mode'], null, true)];
            }
            if ($prior) return nutshellPreviousFailure($prior);
        }
        $text = is_string($input['text'] ?? null) ? trim($input['text']) : (is_string($input['original_text'] ?? null) ? trim($input['original_text']) : '');
        $hasFile = !$isJson && isset($_FILES['pdf_file']) && (int)($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($summaryId !== null && ($text !== '' || $hasFile)) return nutshellError(400, 'ambiguous_input', 'Choose either a saved summary or a new source.');
        if ($summaryId === null && $text !== '' && $hasFile) return nutshellError(422, 'invalid_input', '', new ProcessingError('SOURCE_CONFLICT'));
        if ($summaryId === null && $text === '' && !$hasFile) return nutshellError(422, 'invalid_input');
        if (strlen($text) > 200000) return nutshellError(413, 'source_too_large', 'Text must be 200,000 bytes or less.');

        $mode = strtolower(trim((string)($input['analysis_mode'] ?? 'general')));
        $supportedModes = ['general', 'academic', 'executive', 'technical', 'study', 'news'];
        if (!in_array($mode, $supportedModes, true)) $mode = 'general';
        $sourceSummary = '';
        $primaryText = '';
        $primaryWords = 0;
        $inputType = 'text';
        if ($summaryId !== null) {
            $db->beginTransaction(); $transaction = true;
            $query = $db->prepare('SELECT s.*, a.original_text, a.generated_summary FROM summaries s LEFT JOIN summary_artifacts a ON a.summary_id = s.id WHERE s.id = :id AND s.user_id = :user_id FOR UPDATE');
            $query->execute(['id' => $summaryId, 'user_id' => $userId]);
            $summary = $query->fetch(PDO::FETCH_ASSOC);
            if (!$summary) { $db->rollBack(); $transaction = false; return nutshellError(404, 'summary_not_found', 'Summary not found or access denied.'); }
            if (($summary['status'] ?? '') !== 'completed') { $db->rollBack(); $transaction = false; return nutshellError(409, 'summary_ineligible', 'Only a completed summary can have a Nutshell.'); }
            $sourceSummary = trim((string)($summary['original_text'] ?? ''));
            $primaryText = Formatter::toOverallSummaryTextFromStoredSummary((string)($summary['generated_summary'] ?? ''));
            $primaryWords = nutshellWordCount($primaryText);
            if ($sourceSummary === '' || $primaryText === '') { $db->rollBack(); $transaction = false; return nutshellError(409, 'summary_ineligible', 'The saved summary has no readable source or result.'); }
            $mode = strtolower(trim((string)(Formatter::extractProfileDataFromStoredSummary((string)$summary['generated_summary'])['analysis_mode'] ?? $mode)));
            if (!in_array($mode, $supportedModes, true)) $mode = 'general';
            $cachedText = trim((string)($summary['nutshell_text'] ?? ''));
            if ($cachedText !== '') {
                $cachedId = $db->prepare('SELECT id, word_count, source_word_count, primary_summary_word_count, compression_ratio, processing_time_ms, algorithm_version FROM nutshell_generations WHERE summary_id = :id AND status = \'completed\' ORDER BY id DESC LIMIT 1');
                $cachedId->execute(['id' => $summaryId]); $cached = $cachedId->fetch(PDO::FETCH_ASSOC) ?: [];
                $db->commit(); $transaction = false;
                return [200, nutshellSuccess($cachedText, $cached, $mode, $summaryId, true)];
            }
            $existing = $db->prepare('SELECT id,status,failure_reason,nutshell_text,word_count,source_word_count,primary_summary_word_count,compression_ratio,processing_time_ms,algorithm_version FROM nutshell_generations WHERE idempotency_key = :key LIMIT 1');
            $existing->execute(['key' => $idempotencyKey]); $prior = $existing->fetch(PDO::FETCH_ASSOC);
            if ($prior) {
                $db->commit(); $transaction = false;
                if ($prior['status'] === 'completed' && trim((string)$prior['nutshell_text']) !== '') return [200, nutshellSuccess((string)$prior['nutshell_text'], $prior, $mode, $summaryId, true)];
                return nutshellPreviousFailure($prior);
            }
            if ($primaryWords <= 20) { $db->commit(); $transaction = false; return [200, ['success' => true, 'status' => 'not_needed', 'nutshell' => '', 'generation' => null, 'configuration' => ['analysis_mode' => $mode, 'output_format' => 'paragraph', 'source_summary_id' => $summaryId], 'warnings' => ['The primary summary is already concise.']]]; }
            $text = $sourceSummary;
            $inputType = 'summary';
        }

        $limiter = new RateLimiter();
        if ($limiter->isLimited('nutshell', (string)$userId)) {
            if ($transaction) { $db->rollBack(); $transaction = false; }
            [$status, $failure] = nutshellError(429, 'rate_limited');
            $failure['retry_after'] = $limiter->getRetryAfter('nutshell', (string)$userId);
            return [$status, $failure];
        }
        if ($hasFile) {
            $savedFile = (new FileUploadService())->saveUploadedFile($_FILES['pdf_file']);
            $inputType = strtolower(pathinfo((string)($_FILES['pdf_file']['name'] ?? ''), PATHINFO_EXTENSION));
        } elseif (preg_match('~^(?:[a-z][a-z0-9+.-]*://|www\.)~i', $text)) {
            $resolvedSource = (new SourceResolver())->resolve($text);
            $text = $resolvedSource->getText();
            $savedFile = $resolvedSource->getFilePath();
            $inputType = 'url';
        }
        $sourceWords = $savedFile !== '' ? 0 : nutshellWordCount($text);
        if ($savedFile === '' && $sourceWords < 8) { if ($transaction) { $db->rollBack(); $transaction = false; } return nutshellError(422, 'insufficient_content', 'The source does not contain enough readable content.'); }

        $started = microtime(true);
        try {
            $result = (new LocalPythonBridge())->nutshell([
                'text' => $text,
                'file_path' => $savedFile,
                'analysis_mode' => $mode,
                'primary_summary_word_count' => $primaryWords,
            ], 45);
        } catch (Throwable $exception) {
            $failure = ProcessingError::fromThrowable($exception);
            $failure->log();
            $db->prepare('INSERT INTO nutshell_generations (summary_id,user_id,input_type,status,failure_reason,analysis_mode,output_format,primary_summary_word_count,processing_time_ms,idempotency_key) VALUES (:summary_id,:user_id,:input_type,\'failed\',:failure_reason,:mode,\'paragraph\',:primary_words,:duration,:key)')->execute([
                'summary_id'=>$summaryId, 'user_id'=>$userId, 'input_type'=>$inputType, 'failure_reason'=>$failure->errorCode, 'mode'=>$mode, 'primary_words'=>$primaryWords, 'duration'=>(int)((microtime(true)-$started)*1000), 'key'=>$idempotencyKey,
            ]);
            if ($transaction) { $db->commit(); $transaction = false; }
            $limiter->recordAttempt('nutshell', (string)$userId);
            return nutshellError($failure->httpStatus, 'generation_failed', '', $failure);
        }
        $content = trim((string)($result['nutshell'] ?? ''));
        $sourceWords = max($sourceWords, (int)($result['source_word_count'] ?? 0));
        $wordCount = nutshellWordCount($content);
        $duration = max(0, (int)((microtime(true) - $started) * 1000));
        if (($result['status'] ?? '') === 'not_needed') {
            if ($transaction) { $db->rollBack(); $transaction = false; }
            return [200, ['success' => true, 'status' => 'not_needed', 'nutshell' => '', 'generation' => null, 'configuration' => ['analysis_mode'=>$mode,'output_format'=>'paragraph','source_summary_id'=>$summaryId], 'warnings'=>['The primary summary is already concise.']]];
        }
        $plain = preg_replace('/<\s*\/?\s*[a-z][^>]*>/i', '', $content) === $content;
        $uniqueLines = array_unique(array_filter(array_map('trim', preg_split('/\R/', $content) ?: [])));
        if (($result['status'] ?? '') !== 'completed' || $content === '' || !$plain || $wordCount >= $sourceWords || ($primaryWords > 0 && $wordCount >= $primaryWords) || count($uniqueLines) !== count(array_filter(array_map('trim', preg_split('/\R/', $content) ?: [])))) {
            $db->prepare('INSERT INTO nutshell_generations (summary_id,user_id,input_type,status,failure_reason,analysis_mode,output_format,source_word_count,primary_summary_word_count,processing_time_ms,idempotency_key) VALUES (:summary_id,:user_id,:input_type,\'failed\',\'SUMMARY_QUALITY_FAILED\',:mode,\'paragraph\',:source_words,:primary_words,:duration,:key)')->execute([
                'summary_id'=>$summaryId, 'user_id'=>$userId, 'input_type'=>$inputType, 'mode'=>$mode, 'source_words'=>$sourceWords, 'primary_words'=>$primaryWords, 'duration'=>$duration, 'key'=>$idempotencyKey,
            ]);
            if ($transaction) { $db->commit(); $transaction = false; }
            $limiter->recordAttempt('nutshell', (string)$userId);
            error_log('[Nutshell] generation rejected summary_id=' . ($summaryId ?? 'none') . ' category=output_validation duration_ms=' . $duration);
            return nutshellError(422, 'generation_failed', '', new ProcessingError('SUMMARY_QUALITY_FAILED'));
        }
        $ratio = round($wordCount / max(1, $sourceWords), 4);
        $insert = $db->prepare('INSERT INTO nutshell_generations (summary_id,user_id,input_type,nutshell_text,status,analysis_mode,output_format,word_count,source_word_count,primary_summary_word_count,compression_ratio,processing_time_ms,algorithm_version,idempotency_key) VALUES (:summary_id,:user_id,:input_type,:text,\'completed\',:mode,\'paragraph\',:words,:source_words,:primary_words,:ratio,:duration,:version,:key)');
        $insert->execute(['summary_id'=>$summaryId,'user_id'=>$userId,'input_type'=>$inputType,'text'=>$content,'mode'=>$mode,'words'=>$wordCount,'source_words'=>$sourceWords,'primary_words'=>$primaryWords,'ratio'=>$ratio,'duration'=>$duration,'version'=>(string)($result['algorithm_version'] ?? 'nutshell-extractive-v2'),'key'=>$idempotencyKey]);
        $generationId = (int)$db->lastInsertId();
        if ($summaryId !== null) {
            $update = $db->prepare('UPDATE summaries SET nutshell_text=:text,nutshell_word_count=:words,nutshell_generated_at=CURRENT_TIMESTAMP WHERE id=:id AND user_id=:user_id');
            $update->execute(['text'=>$content,'words'=>$wordCount,'id'=>$summaryId,'user_id'=>$userId]);
            if ($update->rowCount() < 1) throw new RuntimeException('Summary snapshot update failed.');
        }
        if ($transaction) { $db->commit(); $transaction = false; }
        $limiter->recordAttempt('nutshell', (string)$userId);
        error_log('[Nutshell] generation_id=' . $generationId . ' summary_id=' . ($summaryId ?? 'none') . ' duration_ms=' . $duration . ' status=completed');
        return [200, nutshellSuccess($content, ['id'=>$generationId,'word_count'=>$wordCount,'source_word_count'=>$sourceWords,'primary_summary_word_count'=>$primaryWords,'compression_ratio'=>$ratio,'processing_time_ms'=>$duration,'algorithm_version'=>$result['algorithm_version'] ?? 'nutshell-extractive-v2'], $mode, $summaryId, false)];
    } catch (Throwable $exception) {
        if ($transaction && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
        if ($db instanceof PDO && $summaryId === null && isset($idempotencyKey)) {
            try {
                $priorStmt = $db->prepare('SELECT id,status,failure_reason,nutshell_text,word_count,source_word_count,primary_summary_word_count,compression_ratio,processing_time_ms,algorithm_version,analysis_mode FROM nutshell_generations WHERE idempotency_key=:key LIMIT 1');
                $priorStmt->execute(['key' => $idempotencyKey]);
                $prior = $priorStmt->fetch(PDO::FETCH_ASSOC);
                if ($prior && $prior['status'] === 'completed' && trim((string)$prior['nutshell_text']) !== '') {
                    return [200, nutshellSuccess((string)$prior['nutshell_text'], $prior, (string)$prior['analysis_mode'], null, true)];
                }
                if ($prior && $prior['status'] === 'failed') return nutshellPreviousFailure($prior);
            } catch (Throwable $ignored) { }
        }
        $failure = ProcessingError::fromThrowable($exception);
        $failure->log();
        return nutshellError($failure->httpStatus, 'internal_error', '', $failure);
    } finally {
        if ($savedFile !== '' && is_file($savedFile)) @unlink($savedFile);
        if ($resolvedSource !== null) $resolvedSource->cleanup();
    }
}

function nutshellSuccess(string $content, array $row, string $mode, ?int $summaryId, bool $cached): array
{
    $words = nutshellWordCount($content);
    return ['success'=>true,'status'=>'completed','nutshell'=>$content,'generation'=>[
        'id'=>isset($row['id']) ? (int)$row['id'] : null,'status'=>'completed','format'=>'paragraph','content'=>$content,
        'word_count'=>(int)($row['word_count'] ?? $words),'source_word_count'=>(int)($row['source_word_count'] ?? 0),
        'primary_summary_word_count'=>(int)($row['primary_summary_word_count'] ?? 0),'compression_ratio'=>(float)($row['compression_ratio'] ?? 0),
        'processing_time_ms'=>(int)($row['processing_time_ms'] ?? 0),'algorithm_version'=>(string)($row['algorithm_version'] ?? 'nutshell-extractive-v2'),'cached'=>$cached,
    ],'configuration'=>['analysis_mode'=>$mode,'output_format'=>'paragraph','source_summary_id'=>$summaryId],'warnings'=>[]];
}

[$status, $response] = nutshellGenerate();
nutshellJson($status, $response);
