<?php
namespace App\Src\Controllers;

require_once __DIR__ . '/../Services/FeedbackService.php';

use App\Src\Services\FeedbackService;

class FeedbackHandler
{
    private static ?FeedbackService $service = null;

    public static function submitFeedback(
        int $summaryId,
        int $rating,
        ?int $userId,
        ?string $guestToken,
        ?string $shareToken = null
    ): array {
        return self::service()->submitFeedback($summaryId, $rating, $userId, $guestToken, $shareToken);
    }

    public static function submitFullFeedback(
        int $summaryId,
        array $data,
        ?int $userId,
        ?string $guestToken
    ): array {
        return self::service()->submitFullFeedback($summaryId, $data, $userId, $guestToken);
    }

    public static function getFullFeedbackForViewer(
        int $summaryId,
        ?int $userId,
        ?string $guestToken
    ): ?array {
        return self::service()->getFullFeedbackForViewer($summaryId, $userId, $guestToken);
    }

    public static function getFeedbackForViewer(
        int $summaryId,
        ?int $userId,
        ?string $guestToken
    ): ?int {
        return self::service()->getFeedbackForViewer($summaryId, $userId, $guestToken);
    }

    public static function getSummaryRatingStats(int $summaryId): array
    {
        return self::service()->getSummaryRatingStats($summaryId);
    }

    public static function getSystemFeedbackStats(): array
    {
        return self::service()->getSystemFeedbackStats();
    }

    public static function getAllFeedbackForAdmin(): array
    {
        return self::service()->getAllFeedbackForAdmin();
    }

    public static function getFeedbackHistoryForViewer(
        ?int $userId,
        ?string $guestToken,
        int $limit = 5
    ): array {
        return self::service()->getFeedbackHistoryForViewer($userId, $guestToken, $limit);
    }

    private static function service(): FeedbackService
    {
        return self::$service ??= new FeedbackService();
    }
}
