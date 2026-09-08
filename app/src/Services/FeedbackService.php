<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/HistoryService.php';

use App\Src\Database;
use PDO;

final class FeedbackService
{
    private static bool $schemaVerified = false;

    public function submitFeedback(
        int $summaryId,
        int $rating,
        ?int $userId,
        ?string $guestToken,
        ?string $shareToken = null
    ): array {
        $this->ensureTable();

        if ($rating < 1 || $rating > 5) {
            return ['error' => 'Rating must be between 1 and 5.'];
        }

        if ($userId === null && $guestToken === null) {
            return ['error' => 'No valid identity for feedback.'];
        }

        $summary = $this->getSummaryHandler()->getSummaryById($summaryId, $userId, $guestToken, $shareToken);
        if (!$summary || isset($summary['error'])) {
            return ['error' => 'Summary not found or access denied.'];
        }

        try {
            $db = Database::getInstance()->getConnection();

            if ($userId !== null) {
                $stmt = $db->prepare(
                    "INSERT INTO feedback (summary_id, user_id, rating)
                     VALUES (:sid, :uid, :rating)
                     ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([
                    'sid' => $summaryId,
                    'uid' => $userId,
                    'rating' => $rating,
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO feedback (summary_id, guest_token, rating)
                     VALUES (:sid, :token, :rating)
                     ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([
                    'sid' => $summaryId,
                    'token' => $guestToken,
                    'rating' => $rating,
                ]);
            }

            return ['success' => true];
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] submitFeedback error: ' . $exception->getMessage());
            return ['error' => 'Database error. Please try again.'];
        }
    }

    public function submitFullFeedback(
        int $summaryId,
        array $data,
        ?int $userId,
        ?string $guestToken
    ): array {
        $this->ensureTable();

        if ($userId === null && $guestToken === null) {
            return ['error' => 'No valid identity for feedback.'];
        }

        $summary = $this->getSummaryHandler()->getSummaryById($summaryId, $userId, $guestToken, null);
        if (!$summary || isset($summary['error'])) {
            return ['error' => 'Summary not found or access denied.'];
        }

        $name = substr(trim($data['name'] ?? ''), 0, 100);
        $email = substr(trim($data['email'] ?? ''), 0, 150);
        $impression = $data['impression'] ?? '';
        $comment = substr(trim($data['comment'] ?? ''), 0, 1000);

        $allowedImpressions = [
            'very_satisfied' => 5,
            'satisfied' => 4,
            'unsatisfied' => 2,
            'very_unsatisfied' => 1,
        ];

        if (!array_key_exists($impression, $allowedImpressions)) {
            return ['error' => 'Invalid impression value.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Invalid email format.'];
        }

        $rating = $allowedImpressions[$impression];

        try {
            $db = Database::getInstance()->getConnection();

            if ($userId !== null) {
                $stmt = $db->prepare(
                    "INSERT INTO feedback (summary_id, user_id, name, email, impression, rating, comment)
                     VALUES (:sid, :uid, :name, :email, :impression, :rating, :comment)
                     ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        email = VALUES(email),
                        impression = VALUES(impression),
                        rating = VALUES(rating),
                        comment = VALUES(comment),
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([
                    'sid' => $summaryId,
                    'uid' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'impression' => $impression,
                    'rating' => $rating,
                    'comment' => $comment,
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO feedback (summary_id, guest_token, name, email, impression, rating, comment)
                     VALUES (:sid, :token, :name, :email, :impression, :rating, :comment)
                     ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        email = VALUES(email),
                        impression = VALUES(impression),
                        rating = VALUES(rating),
                        comment = VALUES(comment),
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([
                    'sid' => $summaryId,
                    'token' => $guestToken,
                    'name' => $name,
                    'email' => $email,
                    'impression' => $impression,
                    'rating' => $rating,
                    'comment' => $comment,
                ]);
            }

            return ['success' => true];
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] submitFullFeedback error: ' . $exception->getMessage());
            return ['error' => 'Database error. Please try again.'];
        }
    }

    public function getFullFeedbackForViewer(int $summaryId, ?int $userId, ?string $guestToken): ?array
    {
        $this->ensureTable();
        if ($userId === null && $guestToken === null) {
            return null;
        }

        try {
            $db = Database::getInstance()->getConnection();

            if ($userId !== null) {
                $stmt = $db->prepare(
                    'SELECT * FROM feedback WHERE summary_id = :sid AND user_id = :uid LIMIT 1'
                );
                $stmt->execute(['sid' => $summaryId, 'uid' => $userId]);
            } else {
                $stmt = $db->prepare(
                    'SELECT * FROM feedback WHERE summary_id = :sid AND guest_token = :token LIMIT 1'
                );
                $stmt->execute(['sid' => $summaryId, 'token' => $guestToken]);
            }

            return $stmt->fetch() ?: null;
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getFullFeedbackForViewer error: ' . $exception->getMessage());
            return null;
        }
    }

    public function getFeedbackForViewer(int $summaryId, ?int $userId, ?string $guestToken): ?int
    {
        $feedback = $this->getFullFeedbackForViewer($summaryId, $userId, $guestToken);
        return $feedback ? (int)$feedback['rating'] : null;
    }

    public function getSummaryRatingStats(int $summaryId): array
    {
        $this->ensureTable();
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                'SELECT ROUND(AVG(rating), 1) as avg, COUNT(*) as count
                 FROM feedback WHERE summary_id = :sid AND rating IS NOT NULL'
            );
            $stmt->execute(['sid' => $summaryId]);
            $row = $stmt->fetch();

            return [
                'avg' => $row['avg'] !== null ? (float)$row['avg'] : null,
                'count' => (int)$row['count'],
            ];
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getSummaryRatingStats error: ' . $exception->getMessage());
            return ['avg' => null, 'count' => 0];
        }
    }

    public function getSystemFeedbackStats(): array
    {
        $this->ensureTable();
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query(
                'SELECT COUNT(*) as total, ROUND(AVG(rating), 2) as avg FROM feedback WHERE rating IS NOT NULL'
            );
            $row = $stmt->fetch();

            return [
                'total_ratings' => (int)$row['total'],
                'avg_rating' => $row['avg'] !== null ? (float)$row['avg'] : null,
            ];
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getSystemFeedbackStats error: ' . $exception->getMessage());
            return ['total_ratings' => 0, 'avg_rating' => null];
        }
    }

    public function getAllFeedbackForAdmin(): array
    {
        $this->ensureTable();
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query(
                'SELECT f.*, s.article_title, u.username as account_username, u.email as account_email
                 FROM feedback f
                 LEFT JOIN summaries s ON f.summary_id = s.id
                 LEFT JOIN users u ON f.user_id = u.id
                 ORDER BY f.rating DESC, f.updated_at DESC
                 LIMIT 50'
            );
            return $stmt->fetchAll();
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getAllFeedbackForAdmin error: ' . $exception->getMessage());
            return [];
        }
    }

    public function getFeedbackHistoryForViewer(?int $userId, ?string $guestToken, int $limit = 5): array
    {
        $this->ensureTable();
        if ($userId === null && $guestToken === null) {
            return [];
        }

        try {
            $db = Database::getInstance()->getConnection();

            if ($userId !== null) {
                $stmt = $db->prepare(
                    'SELECT f.*, s.article_title
                     FROM feedback f
                     JOIN summaries s ON f.summary_id = s.id
                     WHERE f.user_id = :uid
                     ORDER BY f.created_at DESC
                     LIMIT :limit'
                );
                $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            } else {
                $stmt = $db->prepare(
                    'SELECT f.*, s.article_title
                     FROM feedback f
                     JOIN summaries s ON f.summary_id = s.id
                     WHERE f.guest_token = :token
                     ORDER BY f.created_at DESC
                     LIMIT :limit'
                );
                $stmt->bindValue(':token', $guestToken, PDO::PARAM_STR);
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getFeedbackHistoryForViewer error: ' . $exception->getMessage());
            return [];
        }
    }

    private function ensureTable(): void
    {
        if (self::$schemaVerified) {
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $db->exec(
                "CREATE TABLE IF NOT EXISTS feedback (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    summary_id INT NOT NULL,
                    user_id INT NULL,
                    guest_token VARCHAR(64) NULL,
                    rating TINYINT NULL,
                    name VARCHAR(100) NULL,
                    email VARCHAR(150) NULL,
                    impression VARCHAR(50) NULL,
                    comment TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_summary_user (summary_id, user_id),
                    UNIQUE KEY uk_summary_guest (summary_id, guest_token),
                    INDEX idx_summary (summary_id),
                    INDEX idx_user (user_id),
                    INDEX idx_guest (guest_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $cols = $db->query('SHOW COLUMNS FROM feedback')->fetchAll(PDO::FETCH_COLUMN);
            $required = [
                'name' => 'VARCHAR(100) NULL AFTER rating',
                'email' => 'VARCHAR(150) NULL AFTER name',
                'impression' => 'VARCHAR(50) NULL AFTER email',
                'comment' => 'TEXT NULL AFTER impression',
                'updated_at' => 'DATETIME NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
            ];

            foreach ($required as $col => $definition) {
                if (!in_array($col, $cols, true)) {
                    $db->exec("ALTER TABLE feedback ADD COLUMN $col $definition");
                }
            }

            self::$schemaVerified = true;
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] ensureTable error: ' . $exception->getMessage());
        }
    }

    private function getSummaryHandler(): HistoryService
    {
        return new HistoryService();
    }
}
