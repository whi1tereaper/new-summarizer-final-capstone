<?php

declare(strict_types=1);

namespace App\Src\Repositories;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use PDO;

final class HistoryRepository
{
    private const GUEST_TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function findSummaryById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT s.*, a.original_text, a.generated_summary
             FROM summaries s
             LEFT JOIN summary_artifacts a ON s.id = a.summary_id AND a.original_text IS NOT NULL AND a.generated_summary IS NOT NULL
             WHERE s.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $summary = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($summary) ? $summary : null;
    }

    public function findSummaryHistory(?int $userId, ?string $guestToken, int $limit, int $offset): array
    {
        $query = 'SELECT s.*, a.original_text, a.generated_summary
                  FROM summaries s
                  LEFT JOIN summary_artifacts a ON s.id = a.summary_id AND a.original_text IS NOT NULL AND a.generated_summary IS NOT NULL
                  WHERE 1=1';
        $params = [];

        if ($userId !== null) {
            $query .= ' AND s.user_id = :userId';
            $params['userId'] = $userId;
        } elseif ($this->isValidGuestToken($guestToken)) {
            $query .= ' AND s.user_id IS NULL AND s.guest_token = :guestToken';
            $params['guestToken'] = $guestToken;
        } else {
            return [];
        }

        $query .= ' ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findNutshellHistory(?int $userId, ?string $guestToken, int $limit): array
    {
        $query = 'SELECT n.*, s.article_title, s.share_token
                  FROM nutshell_generations n
                  LEFT JOIN summaries s ON s.id = n.summary_id
                  WHERE n.status = \'completed\' AND ';
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

        $statement = $this->db->prepare($query);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isValidGuestToken(?string $guestToken): bool
    {
        return is_string($guestToken)
            && $guestToken !== ''
            && preg_match(self::GUEST_TOKEN_PATTERN, $guestToken) === 1;
    }
}
