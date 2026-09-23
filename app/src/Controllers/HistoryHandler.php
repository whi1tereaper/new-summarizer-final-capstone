<?php
namespace App\Src\Controllers;

require_once __DIR__ . '/../Services/HistoryService.php';

use App\Src\Services\HistoryService;

class HistoryHandler
{
    private static ?HistoryService $service = null;

    public static function getSummaryHistory(int|string|null $userId = null, ?string $guestToken = null, int $page = 1, int $limit = 10)
    {
        return self::service()->getSummaryHistory($userId, $guestToken, $page, $limit);
    }

    public static function getSummaryById(int|string $id, $userId = null, $guestToken = null, ?string $shareToken = null)
    {
        return self::service()->getSummaryById($id, $userId, $guestToken, $shareToken);
    }

    public static function getNutshellHistory($userId = null, ?string $guestToken = null, int $limit = 20): array
    {
        return self::service()->getNutshellHistory($userId, $guestToken, $limit);
    }

    private static function service(): HistoryService
    {
        return self::$service ??= new HistoryService();
    }
}
