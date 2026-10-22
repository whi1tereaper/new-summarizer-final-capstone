<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Support/ProcessingError.php';

use App\Src\Database;
use PDO;
use App\Src\Support\ProcessingError;

class SummaryJobService
{
    private const STALE_PROCESSING_SECONDS = 600;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function ensureJobForPending(array $pending, ?int $userId, ?string $guestToken): array
    {
        $jobId = (string)($pending['job_id'] ?? bin2hex(random_bytes(16)));
        $existing = $this->getJob($jobId);
        if ($existing !== null) {
            return $existing;
        }

        $idempotency = $this->buildIdempotencyKey($pending, $userId, $guestToken);
        $existingByKey = $this->findByIdempotencyKey($idempotency);
        if ($existingByKey !== null) {
            return $existingByKey;
        }

        $payload = json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Could not encode the pending summary payload.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO summary_jobs (job_id, user_id, guest_token, state, payload, idempotency_key, created_at, updated_at) VALUES (:job_id, :user_id, :guest_token, :state, :payload, :idempotency_key, NOW(), NOW())'
        );
        try {
        $statement->execute([
            'job_id' => $jobId,
            'user_id' => $userId,
            'guest_token' => $guestToken,
            'state' => 'queued',
            'payload' => $payload,
            'idempotency_key' => $idempotency,
        ]);
        } catch (\PDOException $error) {
            // The unique key is authoritative if two identical submissions race.
            if ($error->getCode() !== '23000') throw $error;
            $existing = $this->findByIdempotencyKey($idempotency);
            if ($existing !== null) return $existing;
            throw $error;
        }

        $stored = $this->getJob($jobId);
        if ($stored === null) {
            throw new \RuntimeException('Unable to create a durable summary job.');
        }

        return $stored;
    }

    public function getJob(string $jobId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *, (updated_at < DATE_SUB(NOW(), INTERVAL ' . self::STALE_PROCESSING_SECONDS . ' SECOND)) AS is_stale
             FROM summary_jobs WHERE job_id = :job_id LIMIT 1'
        );
        $statement->execute(['job_id' => $jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)
            && ($row['state'] ?? null) === 'processing'
            && (int)($row['is_stale'] ?? 0) === 1) {
            $failure = new ProcessingError('PROCESSING_TIMEOUT');
            $message = $failure->getMessage();
            $recovery = $this->db->prepare(
                'UPDATE summary_jobs
                 SET state = :state, error_message = :error_message, error_details = :error_details, completed_at = NOW(), updated_at = NOW()
                 WHERE job_id = :job_id AND state = :processing_state AND updated_at = :observed_updated_at
                   AND updated_at < DATE_SUB(NOW(), INTERVAL ' . self::STALE_PROCESSING_SECONDS . ' SECOND)'
            );
            $recovery->execute([
                'state' => 'timed_out',
                'error_message' => $message,
                'error_details' => json_encode($failure->toArray()),
                'job_id' => $jobId,
                'processing_state' => 'processing',
                'observed_updated_at' => $row['updated_at'],
            ]);
            if ($recovery->rowCount() === 1) {
                $row['state'] = 'timed_out';
                $row['error_message'] = $message;
                $row['error_details'] = json_encode($failure->toArray());
            } else {
                $statement->execute(['job_id' => $jobId]);
                $freshRow = $statement->fetch(PDO::FETCH_ASSOC);
                $row = is_array($freshRow) ? $freshRow : null;
            }
        }
        return is_array($row) ? $row : null;
    }

    public function getJobForOwner(string $jobId, ?int $userId, ?string $guestToken): ?array
    {
        // Check ownership without invoking stale-job recovery on foreign records.
        $query = $this->db->prepare('SELECT user_id, guest_token FROM summary_jobs WHERE job_id = ? LIMIT 1');
        $query->execute([$jobId]);
        $job = $query->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($job === null) {
            return null;
        }
        if ($userId !== null && isset($job['user_id']) && (int)$job['user_id'] === $userId) {
            return $this->getJob($jobId);
        }
        $storedGuest = is_string($job['guest_token'] ?? null) ? $job['guest_token'] : '';
        if ($userId === null && $job['user_id'] === null && $guestToken !== null && $storedGuest !== '' && hash_equals($storedGuest, $guestToken)) {
            return $this->getJob($jobId);
        }
        return null;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM summary_jobs WHERE idempotency_key = :idempotency_key LIMIT 1');
        $statement->execute(['idempotency_key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function markProcessing(string $jobId): bool
    {
        $statement = $this->db->prepare("UPDATE summary_jobs SET state = 'processing', worker_pid = :pid, started_at = COALESCE(started_at, NOW()), updated_at = NOW() WHERE job_id = :job_id AND state IN ('queued','processing') AND worker_pid IS NULL");
        $statement->execute(['pid' => getmypid(), 'job_id' => $jobId]);
        return $statement->rowCount() === 1;
    }

    public function completeJob(string $jobId, int $resultId): void
    {
        $statement = $this->db->prepare("UPDATE summary_jobs SET state = :state, result_id = :result_id, completed_at = NOW(), updated_at = NOW(), error_message = NULL, error_details = NULL, payload = '{}' WHERE job_id = :job_id AND state = 'processing'");
        $statement->execute(['state' => 'completed', 'result_id' => $resultId, 'job_id' => $jobId]);
    }

    public function failJob(string $jobId, \Throwable|string $message, string $state = 'failed', ?int $expectedPid = null): void
    {
        $failure = $message instanceof \Throwable ? ProcessingError::fromThrowable($message) : new ProcessingError('PROCESSING_FAILED');
        $failure->log();
        $finalState = $failure->errorCode === 'PROCESSING_TIMEOUT' ? 'timed_out' : ($state === 'cancelled' ? 'cancelled' : 'failed');
        $job = $this->getJob($jobId);
        $pending = json_decode((string)($job['payload'] ?? '{}'), true) ?: [];
        $retain = $failure->toArray()['retryable'] && ($pending['source_type'] ?? '') !== 'file' && (int)($job['retry_count'] ?? 0) < 2;
        $sql = "UPDATE summary_jobs SET state = :state, error_message = :error_message, error_details = :details, payload = :payload, completed_at = NOW(), updated_at = NOW() WHERE job_id = :job_id AND state IN ('queued','processing')";
        $params = ['state' => $finalState, 'error_message' => $failure->getMessage(), 'details' => json_encode($failure->toArray()), 'payload' => $retain ? $job['payload'] : '{}', 'job_id' => $jobId];
        if ($expectedPid !== null) { $sql .= ' AND worker_pid = :pid'; $params['pid'] = $expectedPid; }
        $this->db->prepare($sql)->execute($params);
    }

    public function cancelJob(string $jobId): void
    {
        $statement = $this->db->prepare("UPDATE summary_jobs SET state = :state, payload = '{}', completed_at = NOW(), updated_at = NOW() WHERE job_id = :job_id AND state IN ('queued','processing')");
        $statement->execute(['state' => 'cancelled', 'job_id' => $jobId]);
    }

    public function startBackgroundWorker(string $jobId): bool
    {
        $existing = $this->getJob($jobId);
        if ($existing === null) {
            return false;
        }

        if ((string)($existing['state'] ?? '') !== 'queued') {
            return false;
        }

        try {
            $cliPhpBinary = DIRECTORY_SEPARATOR === '\\' ? $this->resolveCliPhpBinary() : PHP_BINARY;
        } catch (\Throwable $error) {
            $this->failJob($jobId, new ProcessingError('WORKER_UNAVAILABLE', $error));
            return false;
        }

        $claim = $this->db->prepare('UPDATE summary_jobs SET state = :state, started_at = COALESCE(started_at, NOW()), updated_at = NOW() WHERE job_id = :job_id AND state = :queued_state');
        $claim->execute([
            'state' => 'processing',
            'job_id' => $jobId,
            'queued_state' => 'queued',
        ]);

        if ($claim->rowCount() !== 1) {
            return false;
        }

        $scriptPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'summary_worker.php';

        if (DIRECTORY_SEPARATOR === '\\') {
            $command = $this->buildDetachedWindowsCommand($cliPhpBinary, $scriptPath, $jobId);
            $handle = @popen($command, 'r');
            if (!is_resource($handle)) {
                $this->failJob($jobId, new ProcessingError('WORKER_UNAVAILABLE'));
                return false;
            }
            pclose($handle);
            return true;
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($jobId) . ' >/dev/null 2>&1 &';
        @exec($command);
        return true;
    }

    private function resolveCliPhpBinary(): string
    {
        $configured = trim((string)(getenv('LOCAL_PHP_BIN') ?: ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        $candidates = [
            rtrim((string)PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . 'php.exe',
            dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
            dirname(PHP_BINARY, 3) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        if (strtolower(pathinfo(PHP_BINARY, PATHINFO_EXTENSION)) === 'exe'
            && strtolower(pathinfo(PHP_BINARY, PATHINFO_FILENAME)) === 'php'
            && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }
        throw new \RuntimeException('PHP CLI was not found for the background summary worker.');
    }

    private function returnClaimToQueue(string $jobId): void
    {
        $statement = $this->db->prepare(
            'UPDATE summary_jobs SET state = :queued, started_at = NULL, updated_at = NOW() WHERE job_id = :job_id AND state = :processing'
        );
        $statement->execute(['queued' => 'queued', 'processing' => 'processing', 'job_id' => $jobId]);
    }

    private function buildDetachedWindowsCommand(string $phpBinary, string $scriptPath, string $jobId): string
    {
        return sprintf(
            'start "" /B "%s" "%s" "%s" > NUL 2>&1',
            $phpBinary,
            $scriptPath,
            $jobId
        );
    }

    private function buildIdempotencyKey(array $pending, ?int $userId, ?string $guestToken): string
    {
        $signature = [
            'user' => $userId ?? 0,
            'guest' => $guestToken ?? '',
            'source_type' => (string)($pending['source_type'] ?? ''),
            'original_text' => (string)($pending['original_text'] ?? ''),
            'source_url' => (string)($pending['source_url'] ?? ''),
            'source_hash' => (string)($pending['source_hash'] ?? $pending['file_path'] ?? ''),
            'analysis_mode' => (string)($pending['analysis_mode'] ?? ''),
            'summary_depth' => (string)($pending['summary_depth'] ?? ''),
            'output_format' => (string)($pending['output_format'] ?? ''),
            'summary_style' => (string)($pending['summary_style'] ?? ''),
            'document_title' => (string)($pending['document_title'] ?? ''),
            'focus_query' => (string)($pending['focus_query'] ?? ''),
            'use_llm_synthesis' => !empty($pending['use_llm_synthesis']),
            'request_token' => (string)($pending['request_token'] ?? ''),
        ];
        return 'summary:' . hash('sha256', json_encode($signature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function failureForJob(array $job): ProcessingError
    {
        $stored = json_decode((string)($job['error_details'] ?? ''), true);
        if (is_array($stored)) return ProcessingError::fromWorker($stored);
        // Historical free-form messages are untrusted and cannot prove a specific cause.
        return new ProcessingError(match ($job['state'] ?? '') {
            'cancelled' => 'PROCESSING_CANCELLED', 'timed_out' => 'PROCESSING_TIMEOUT',
            default => 'PROCESSING_FAILED',
        }, null, substr(hash('sha256', (string)($job['job_id'] ?? '')), 0, 24));
    }

    public function canRetry(array $job): bool
    {
        $payload = json_decode((string)($job['payload'] ?? ''), true);
        return in_array($job['state'], ['failed', 'timed_out'], true)
            && $this->failureForJob($job)->toArray()['retryable']
            && (int)($job['retry_count'] ?? 0) < 2
            && strtotime((string)$job['created_at']) > time() - 3600
            && is_array($payload) && !empty($payload['original_text'])
            && ($payload['source_type'] ?? '') !== 'file';
    }

    public function retryJob(array $job): array
    {
        if (!$this->canRetry($job)) return $job;
        $statement = $this->db->prepare("UPDATE summary_jobs SET state = 'queued', retry_count = retry_count + 1, worker_pid = NULL, started_at = NULL, completed_at = NULL, error_message = NULL, error_details = NULL, updated_at = NOW() WHERE job_id = :id AND state = :state AND retry_count = :retries");
        $statement->execute(['id' => $job['job_id'], 'state' => $job['state'], 'retries' => (int)$job['retry_count']]);
        return $this->getJob($job['job_id']) ?? $job;
    }

    public function purgeExpiredPayloads(): void
    {
        $expired = new ProcessingError('REQUEST_EXPIRED');
        $this->db->prepare("UPDATE summary_jobs SET state = 'cancelled', error_message = ?, error_details = ?, completed_at = NOW(), updated_at = NOW() WHERE state = 'queued' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)")
            ->execute([$expired->getMessage(), json_encode($expired->toArray())]);
        $staleIds = $this->db->query("SELECT job_id FROM summary_jobs WHERE state = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_PROCESSING_SECONDS . " SECOND)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($staleIds as $staleId) $this->getJob($staleId);
        $rows = $this->db->query("SELECT payload FROM summary_jobs WHERE state IN ('completed','failed','timed_out','cancelled') AND payload <> '{}' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchAll(PDO::FETCH_ASSOC);
        require_once __DIR__ . '/../Support/RuntimePaths.php';
        $uploadRoot = realpath(\App\Src\Support\RuntimePaths::uploadsDirectory());
        foreach ($rows as $row) {
            $payload = json_decode((string)$row['payload'], true) ?: [];
            $path = is_string($payload['file_path'] ?? null) ? realpath($payload['file_path']) : false;
            if ($uploadRoot !== false && $path !== false && str_starts_with(strtolower($path), strtolower($uploadRoot . DIRECTORY_SEPARATOR))
                && preg_match('/\A\d+_[a-f0-9]{16}\.(pdf|docx)\z/', basename($path))) {
                @unlink($path);
            }
        }
        $this->db->exec("UPDATE summary_jobs SET payload = '{}', updated_at = updated_at WHERE state IN ('completed','failed','timed_out','cancelled') AND payload <> '{}' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
}
