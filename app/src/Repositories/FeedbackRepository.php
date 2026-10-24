<?php

declare(strict_types=1);

namespace App\Src\Repositories;

require_once __DIR__ . '/../Database.php';

use App\Src\Database;
use PDO;

final class FeedbackRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function getSummaryRatingStats(int $summaryId): array
    {
        $statement = $this->db->prepare(
            'SELECT ROUND(AVG(rating), 1) AS avg, COUNT(*) AS count
             FROM feedback
             WHERE summary_id = :summary_id AND rating IS NOT NULL'
        );
        $statement->execute(['summary_id' => $summaryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'avg' => $row !== false && $row['avg'] !== null ? (float)$row['avg'] : null,
            'count' => $row !== false ? (int)($row['count'] ?? 0) : 0,
        ];
    }

    public function getFullFeedbackForViewer(int $summaryId, ?int $userId, ?string $guestToken): ?array
    {
        if ($userId === null && $guestToken === null) {
            return null;
        }

        if ($userId !== null) {
            $statement = $this->db->prepare(
                'SELECT * FROM feedback WHERE summary_id = :summary_id AND user_id = :user_id LIMIT 1'
            );
            $statement->execute([
                'summary_id' => $summaryId,
                'user_id' => $userId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM feedback WHERE summary_id = :summary_id AND guest_token = :guest_token LIMIT 1'
        );
        $statement->execute([
            'summary_id' => $summaryId,
            'guest_token' => $guestToken,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function getCommentsForSummary(int $summaryId, int $limit = 20): array
    {
        $statement = $this->db->prepare(
            'SELECT id, summary_id, rating, reasons, comment, created_at
             FROM feedback_comments
             WHERE summary_id = :summary_id AND comment IS NOT NULL AND TRIM(comment) != \'\'
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':summary_id', $summaryId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSystemFeedbackStats(?string $from = null, ?string $to = null): array
    {
        if (($from === null) !== ($to === null)) {
            throw new \InvalidArgumentException('Both feedback statistics range dates are required.');
        }

        $sql = 'SELECT COUNT(*) AS total, ROUND(AVG(rating), 2) AS avg
                FROM feedback WHERE rating IS NOT NULL';
        $params = [];
        if ($from !== null && $to !== null) {
            foreach ([$from, $to] as $date) {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                    throw new \InvalidArgumentException('Invalid feedback statistics date range.');
                }
            }
            if ($from > $to) {
                $toNext = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
                $sql .= ' AND created_at >= :from AND created_at < :to_next';
                $params = ['from' => $from, 'to_next' => $toNext];
            }
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'total_ratings' => $row !== false ? (int)($row['total'] ?? 0) : 0,
            'avg_rating' => $row !== false && $row['avg'] !== null ? (float)$row['avg'] : null,
        ];
    }

    public function getAllFeedbackForAdmin(array $filters = []): array
    {
        $rating = isset($filters['rating']) && is_numeric($filters['rating']) ? (int)$filters['rating'] : null;
        $hasComment = isset($filters['has_comment']) && $filters['has_comment'] !== null ? (bool)$filters['has_comment'] : null;
        $sort = $filters['sort'] ?? 'newest';

        $where = [];
        $params = [];

        if ($rating !== null && $rating >= 1 && $rating <= 5) {
            $where[] = 'f.rating = :rating';
            $params['rating'] = $rating;
        }

        if ($hasComment === true) {
            $where[] = '(f.comment IS NOT NULL AND TRIM(f.comment) != \'\')';
        } elseif ($hasComment === false) {
            $where[] = '(f.comment IS NULL OR TRIM(f.comment) = \'\')';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        switch ($sort) {
            case 'lowest_rated':
                $orderSql = 'ORDER BY f.rating ASC, f.updated_at DESC';
                break;
            case 'highest_rated':
                $orderSql = 'ORDER BY f.rating DESC, f.updated_at DESC';
                break;
            case 'newest':
            default:
                $orderSql = 'ORDER BY f.updated_at DESC, f.created_at DESC';
                break;
        }

        $sql = "SELECT
                    f.id,
                    f.summary_id,
                    f.rating,
                    'Anonymous' AS name,
                    NULL AS email,
                    f.impression,
                    f.reasons,
                    f.comment,
                    f.created_at,
                    f.updated_at,
                    s.article_title,
                    s.input_type AS source_type,
                    s.summary_style AS analysis_mode,
                    s.summary_length AS summary_depth,
                    s.original_word_count,
                    s.summary_word_count,
                    ROUND(1.0 * s.summary_word_count / NULLIF(s.original_word_count, 0), 2) AS compression_ratio,
                    s.processing_time,
                    NULL AS account_username,
                    NULL AS account_email
                FROM feedback f
                LEFT JOIN summaries s ON f.summary_id = s.id
                {$whereSql}
                {$orderSql}
                LIMIT 100";

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFeedbackTelemetryBreakdown(): array
    {
        $styleStatement = $this->db->query(
            "SELECT
                COALESCE(s.summary_style, 'unspecified') AS analysis_mode,
                COUNT(*) AS count,
                ROUND(AVG(f.rating), 2) AS avg_rating,
                SUM(CASE WHEN f.rating <= 2 THEN 1 ELSE 0 END) AS low_rating_count
             FROM feedback f
             LEFT JOIN summaries s ON f.summary_id = s.id
             WHERE f.rating IS NOT NULL
             GROUP BY s.summary_style
             ORDER BY count DESC"
        );
        $byStyle = $styleStatement->fetchAll(PDO::FETCH_ASSOC);

        $lengthStatement = $this->db->query(
            "SELECT
                COALESCE(s.summary_length, 'unspecified') AS summary_depth,
                COUNT(*) AS count,
                ROUND(AVG(f.rating), 2) AS avg_rating,
                SUM(CASE WHEN f.rating <= 2 THEN 1 ELSE 0 END) AS low_rating_count
             FROM feedback f
             LEFT JOIN summaries s ON f.summary_id = s.id
             WHERE f.rating IS NOT NULL
             GROUP BY s.summary_length
             ORDER BY count DESC"
        );
        $byLength = $lengthStatement->fetchAll(PDO::FETCH_ASSOC);

        $sourceStatement = $this->db->query(
            "SELECT
                COALESCE(s.input_type, 'unspecified') AS source_type,
                COUNT(*) AS count,
                ROUND(AVG(f.rating), 2) AS avg_rating
             FROM feedback f
             LEFT JOIN summaries s ON f.summary_id = s.id
             WHERE f.rating IS NOT NULL
             GROUP BY s.input_type
             ORDER BY count DESC"
        );
        $bySource = $sourceStatement->fetchAll(PDO::FETCH_ASSOC);

        $reasonsStatement = $this->db->query(
            "SELECT reasons FROM feedback WHERE reasons IS NOT NULL AND reasons != ''"
        );
        $reasonCounts = [];
        while ($row = $reasonsStatement->fetch(PDO::FETCH_ASSOC)) {
            $decoded = json_decode((string)($row['reasons'] ?? '[]'), true);
            if (is_array($decoded)) {
                foreach ($decoded as $tag) {
                    if (is_string($tag)) {
                        $reasonCounts[$tag] = ($reasonCounts[$tag] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($reasonCounts);

        return [
            'by_style' => $byStyle,
            'by_length' => $byLength,
            'by_source' => $bySource,
            'reasons_counts' => $reasonCounts,
        ];
    }

    public function getFeedbackHistoryForViewer(?int $userId, ?string $guestToken, int $limit = 5): array
    {
        if ($userId === null && $guestToken === null) {
            return [];
        }

        if ($userId !== null) {
            $statement = $this->db->prepare(
                'SELECT f.*, s.article_title
                 FROM feedback f
                 JOIN summaries s ON f.summary_id = s.id
                 WHERE f.user_id = :user_id
                 ORDER BY f.created_at DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        } else {
            $statement = $this->db->prepare(
                'SELECT f.*, s.article_title
                 FROM feedback f
                 JOIN summaries s ON f.summary_id = s.id
                 WHERE f.guest_token = :guest_token
                 ORDER BY f.created_at DESC
                 LIMIT :limit'
            );
            $statement->bindValue(':guest_token', $guestToken, PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
