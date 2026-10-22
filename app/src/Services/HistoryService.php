<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Repositories/HistoryRepository.php';

use App\Src\Database;
use App\Src\Repositories\HistoryRepository;
use PDO;
use PDOStatement;

final class HistoryService
{
    private const MAX_PAGE_SIZE = 50;
    private const GUEST_TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

    private HistoryRepository $historyRepository;

    public function __construct(?HistoryRepository $historyRepository = null)
    {
        $this->historyRepository = $historyRepository ?? new HistoryRepository();
    }

    public function getSummaryHistory(?int $userId = null, ?string $guestToken = null, int $page = 1, int $limit = 10): array
    {
        try {
            [, $pageSize, $offset] = $this->normalizePagination($page, $limit);
            return $this->historyRepository->findSummaryHistory($userId, $guestToken, $pageSize, $offset);
        } catch (\Throwable $exception) {
            error_log('[HistoryService] getSummaryHistory error: ' . $exception->getMessage());
            return ['error' => 'Database error.'];
        }
    }

    public function getSummaryById(int $id, ?int $userId = null, ?string $guestToken = null, ?string $shareToken = null): array
    {
        try {
            $summary = $this->historyRepository->findSummaryById($id);
            if (!$summary) {
                return ['error' => 'Summary not found.'];
            }

            if ($this->viewerOwnsSummary($summary, $userId, $guestToken)
                || $this->viewerHasSharedAccess($summary, $userId, $shareToken)
            ) {
                return $summary;
            }

            return ['error' => 'Unauthorized access.'];
        } catch (\Throwable $exception) {
            error_log('[HistoryService] getSummaryById error: ' . $exception->getMessage());
            return ['error' => 'Database error.'];
        }
    }

    public function getNutshellHistory(?int $userId = null, ?string $guestToken = null, int $limit = 20): array
    {
        try {
            return $this->historyRepository->findNutshellHistory($userId, $guestToken, $limit);
        } catch (\Throwable $exception) {
            error_log('[HistoryService] getNutshellHistory error: ' . $exception->getMessage());
            return ['error' => 'Database error.'];
        }
    }

    private function normalizePagination(int $page, int $limit): array
    {
        $pageNumber = max(1, $page);
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = ($pageNumber - 1) * $pageSize;

        return [$pageNumber, $pageSize, $offset];
    }

    private function viewerOwnsSummary(array $summary, ?int $userId, ?string $guestToken): bool
    {
        if ($userId !== null && $summary['user_id'] !== null && (int)$summary['user_id'] === $userId) {
            return true;
        }

        return $summary['user_id'] === null
            && $guestToken !== null
            && is_string($summary['guest_token'] ?? null)
            && hash_equals($summary['guest_token'], $guestToken);
    }

    private function viewerHasSharedAccess(array $summary, ?int $userId, ?string $shareToken): bool
    {
        return is_string($shareToken)
            && $shareToken !== ''
            && is_string($summary['share_token'] ?? null)
            && hash_equals($summary['share_token'], $shareToken);
    }
}
