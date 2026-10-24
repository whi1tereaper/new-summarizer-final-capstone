<?php
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Repositories/FeedbackRepository.php';
require_once __DIR__ . '/HistoryService.php';

use App\Src\Database;
use App\Src\Repositories\FeedbackRepository;
use PDO;

final class FeedbackService
{
    private FeedbackRepository $feedbackRepository;

    public function __construct(?FeedbackRepository $feedbackRepository = null)
    {
        $this->feedbackRepository = $feedbackRepository ?? new FeedbackRepository();
    }

    public const ALLOWED_REASONS = [
        'missing_info'       => 'Missing important information',
        'too_long'           => 'Too long',
        'too_short'          => 'Too short',
        'incorrect_info'     => 'Incorrect information',
        'repetitive'         => 'Repetitive',
        'hard_to_understand' => 'Hard to understand',
        'format_mismatch'    => "Format didn't match selection",
        'mode_mismatch'      => "Analysis mode didn't behave as expected",
        'other'              => 'Other',
    ];

    public function submitFeedback(
        int $summaryId,
        int $rating,
        ?int $userId,
        ?string $guestToken,
        ?string $shareToken = null,
        ?string $comment = null,
        ?array $reasons = null
    ): array {
        $this->ensureTable();

        if ($rating < 1 || $rating > 5) {
            return ['error' => 'Rating must be between 1 and 5.'];
        }

        if ($userId === null && $guestToken === null) {
            return ['error' => 'No valid identity for feedback.'];
        }

        if ($comment !== null) {
            $comment = trim($comment);
            if (mb_strlen($comment, 'UTF-8') > 500) {
                return ['error' => 'Comment cannot exceed 500 characters.'];
            }
            if ($comment === '') {
                $comment = null;
            }
        }

        $reasonsJson = null;
        if ($reasons !== null) {
            $cleanReasons = [];
            foreach ($reasons as $r) {
                if (is_string($r) && isset(self::ALLOWED_REASONS[$r])) {
                    $cleanReasons[] = $r;
                }
            }
            if (!empty($cleanReasons)) {
                if ($rating > 3) {
                    return ['error' => 'Improvement reason tags are only permitted for ratings of 3 stars or below.'];
                }
                $reasonsJson = json_encode(array_values(array_unique($cleanReasons)), JSON_UNESCAPED_UNICODE);
            }
        }

        $summary = $this->getSummaryHandler()->getSummaryById($summaryId, $userId, $guestToken, $shareToken);
        if (!$summary || isset($summary['error'])) {
            return ['error' => 'Summary not found or access denied.'];
        }

        try {
            $db = Database::getInstance()->getConnection();
            $db->beginTransaction();

            if ($userId !== null) {
                $sql = "INSERT INTO feedback (summary_id, user_id, rating, reasons, comment)
                       VALUES (:sid, :uid, :rating, :reasons, :comment)
                       ON DUPLICATE KEY UPDATE 
                          rating = VALUES(rating), 
                          reasons = VALUES(reasons), 
                          comment = VALUES(comment), 
                          updated_at = CURRENT_TIMESTAMP";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    'sid'     => $summaryId,
                    'uid'     => $userId,
                    'rating'  => $rating,
                    'reasons' => $reasonsJson,
                    'comment' => $comment,
                ]);
            } else {
                $sql = "INSERT INTO feedback (summary_id, guest_token, rating, reasons, comment)
                       VALUES (:sid, :token, :rating, :reasons, :comment)
                       ON DUPLICATE KEY UPDATE 
                          rating = VALUES(rating), 
                          reasons = VALUES(reasons), 
                          comment = VALUES(comment), 
                          updated_at = CURRENT_TIMESTAMP";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    'sid'     => $summaryId,
                    'token'   => $guestToken,
                    'rating'  => $rating,
                    'reasons' => $reasonsJson,
                    'comment' => $comment,
                ]);
            }

            // Record feedback comment event in feedback_comments ledger (identity-hidden presentation; no user/guest/ip identifiers, keyed by summary_id for evaluation telemetry)
            if ($comment !== null || $reasonsJson !== null) {
                $fcStmt = $db->prepare(
                    "INSERT INTO feedback_comments (summary_id, rating, reasons, comment)
                     VALUES (:sid, :rating, :reasons, :comment)"
                );
                $fcStmt->execute([
                    'sid'     => $summaryId,
                    'rating'  => $rating,
                    'reasons' => $reasonsJson,
                    'comment' => $comment ?? '',
                ]);
            }

            $db->commit();
            return ['success' => true];
        } catch (\Throwable $exception) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
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
                $sql = "INSERT INTO feedback (summary_id, user_id, name, email, impression, rating, comment)
                       VALUES (:sid, :uid, :name, :email, :impression, :rating, :comment)
                       ON DUPLICATE KEY UPDATE
                          name = VALUES(name),
                          email = VALUES(email),
                          impression = VALUES(impression),
                          rating = VALUES(rating),
                          comment = VALUES(comment),
                          updated_at = CURRENT_TIMESTAMP";
                $stmt = $db->prepare($sql);
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
                $sql = "INSERT INTO feedback (summary_id, guest_token, name, email, impression, rating, comment)
                       VALUES (:sid, :token, :name, :email, :impression, :rating, :comment)
                       ON DUPLICATE KEY UPDATE
                          name = VALUES(name),
                          email = VALUES(email),
                          impression = VALUES(impression),
                          rating = VALUES(rating),
                          comment = VALUES(comment),
                          updated_at = CURRENT_TIMESTAMP";
                $stmt = $db->prepare($sql);
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
        try {
            return $this->feedbackRepository->getFullFeedbackForViewer($summaryId, $userId, $guestToken);
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
            return $this->feedbackRepository->getSummaryRatingStats($summaryId);
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getSummaryRatingStats error: ' . $exception->getMessage());
            return ['avg' => null, 'count' => 0];
        }
    }

    /**
     * Retrieve anonymous feedback comments for a specific summary.
     *
     * @param int $summaryId
     * @param int $limit
     * @return array
     */
    public function getCommentsForSummary(int $summaryId, int $limit = 20): array
    {
        $this->ensureTable();
        try {
            return $this->feedbackRepository->getCommentsForSummary($summaryId, $limit);
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getCommentsForSummary error: ' . $exception->getMessage());
            return [];
        }
    }

    public function getSystemFeedbackStats(?string $from = null, ?string $to = null): array
    {
        $this->ensureTable();
        try {
            return $this->feedbackRepository->getSystemFeedbackStats($from, $to);
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getSystemFeedbackStats error: ' . $exception->getMessage());
            return ['total_ratings' => 0, 'avg_rating' => null];
        }
    }

    public function getAllFeedbackForAdmin(array $filters = []): array
    {
        $this->ensureTable();
        try {
            return $this->feedbackRepository->getAllFeedbackForAdmin($filters);
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getAllFeedbackForAdmin error: ' . $exception->getMessage());
            return [];
        }
    }

    public function getFeedbackTelemetryBreakdown(): array
    {
        $this->ensureTable();
        try {
            return $this->feedbackRepository->getFeedbackTelemetryBreakdown();
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getFeedbackTelemetryBreakdown error: ' . $exception->getMessage());
            return [
                'by_style' => [],
                'by_length' => [],
                'by_source' => [],
                'reasons_counts' => [],
            ];
        }
    }

    public function getFeedbackHistoryForViewer(?int $userId, ?string $guestToken, int $limit = 5): array
    {
        $this->ensureTable();
        try {
            return $this->feedbackRepository->getFeedbackHistoryForViewer($userId, $guestToken, $limit);
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] getFeedbackHistoryForViewer error: ' . $exception->getMessage());
            return [];
        }
    }
    /**
     * Prunes historical feedback comment events older than a given retention threshold.
     * feedback_comments behaves as an append-only historical feedback event ledger.
     *
     * @param int $days Retention threshold in days (must be > 0).
     * @return int Number of pruned event rows.
     */
    public function pruneFeedbackEvents(int $days = 90): int
    {
        if ($days <= 0) {
            throw new \InvalidArgumentException('Retention days must be greater than zero.');
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                'DELETE FROM feedback_comments WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
            );
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\Throwable $exception) {
            error_log('[FeedbackService] pruneFeedbackEvents error: ' . $exception->getMessage());
            return 0;
        }
    }

    private function ensureTable(): void
    {
        // Schema is managed via database/schema.sql
    }

    private function getSummaryHandler(): HistoryService
    {
        return new HistoryService();
    }
}
