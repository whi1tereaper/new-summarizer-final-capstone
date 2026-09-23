<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use PDO;
use PDOStatement;

final class HistoryService
{
    private const MAX_PAGE_SIZE = 50;
    private const GUEST_TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

    public function getSummaryHistory(?int $userId = null, ?string $guestToken = null, int $page = 1, int $limit = 10): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            [, $pageSize, $offset] = $this->normalizePagination($page, $limit);
            [$query, $params] = $this->buildHistoryQuery($userId, $guestToken);

            if ($query === null) {
                return [];
            }

            $query .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
            $statement = $db->prepare($query);
            $this->bindQueryValues($statement, $params, $pageSize, $offset);
            $statement->execute();

            return $statement->fetchAll();
        } catch (\Throwable $exception) {
            error_log('[HistoryService] getSummaryHistory error: ' . $exception->getMessage());
            return ['error' => 'Database error.'];
        }
    }

    public function getSummaryById(int $id, ?int $userId = null, ?string $guestToken = null, ?string $shareToken = null): array
    {
        try {
            $summary = $this->findSummaryById($id);
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
            $db = Database::getInstance()->getConnection();
            $query = 'SELECT n.*, s.article_title, s.share_token
                      FROM nutshell_generations n
                      LEFT JOIN summaries s ON s.id = n.summary_id
                      WHERE ';
            $params = [];

            if ($userId !== null) {
                $query .= 'n.user_id = :userId';
                $params['userId'] = $userId;
            } elseif ($this->isValidGuestToken($guestToken)) {
                $query .= 'n.user_id IS NULL AND n.guest_token = :guestToken';
                $params['guestToken'] = $guestToken;
            } else {
                return [];
            }

            $query .= ' ORDER BY n.created_at DESC LIMIT :limit';
            $statement = $db->prepare($query);
            foreach ($params as $key => $value) {
                $statement->bindValue(':' . $key, $value);
            }
            $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll();
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

    private function buildHistoryQuery(?int $userId, ?string $guestToken): array
    {
        $query = 'SELECT s.*, a.original_text, a.generated_summary 
                  FROM summaries s 
                  LEFT JOIN summary_artifacts a ON s.id = a.summary_id 
                  WHERE 1=1';
        $params = [];

        if ($userId !== null) {
            $query .= ' AND user_id = :userId';
            $params['userId'] = $userId;
            return [$query, $params];
        }

        if ($this->isValidGuestToken($guestToken)) {
            $query .= ' AND user_id IS NULL AND guest_token = :guestToken';
            $params['guestToken'] = $guestToken;
            return [$query, $params];
        }

        return [null, []];
    }

    private function bindQueryValues(PDOStatement $statement, array $params, int $limit, int $offset): void
    {
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
    }

    private function findSummaryById(int $id)
    {
        $db = Database::getInstance()->getConnection();
        $statement = $db->prepare(
            'SELECT s.*, a.original_text, a.generated_summary 
             FROM summaries s 
             LEFT JOIN summary_artifacts a ON s.id = a.summary_id 
             WHERE s.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch();
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

    private function isValidGuestToken(?string $guestToken): bool
    {
        return is_string($guestToken)
            && $guestToken !== ''
            && preg_match(self::GUEST_TOKEN_PATTERN, $guestToken) === 1;
    }
}
