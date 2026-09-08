<?php
declare(strict_types=1);

namespace App\Src\Controllers;

use App\Src\Database;
use PDO;

/**
 * Reads aggregated landing page analytics from the DB for admin dashboard display.
 * All methods return plain arrays — no view logic here.
 */
class AnalyticsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Top-level KPI summary.
     *
     * @return array{
     *   total_sessions: int,
     *   sessions_7d: int,
     *   sessions_today: int,
     *   conversion_rate: float,
     *   avg_dwell_seconds: float,
     *   median_lcp_ms: int|null,
     *   bounce_rate: float,
     * }
     */
    public function getKpis(): array
    {
        // All-time totals + 7-day window in one pass.
        $row = $this->db->query("
            SELECT
                COUNT(*)                                                        AS total_sessions,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))             AS sessions_7d,
                SUM(DATE(created_at) = CURDATE())                              AS sessions_today,
                ROUND(AVG(converted) * 100, 1)                                 AS conversion_rate,
                ROUND(AVG(NULLIF(dwell_time_seconds, 0)), 1)                   AS avg_dwell_seconds,
                ROUND(
                    SUM(max_scroll_percent < 25 AND dwell_time_seconds < 5) /
                    NULLIF(COUNT(*), 0) * 100
                , 1)                                                            AS bounce_rate
            FROM landing_page_sessions
        ")->fetch();

        // Median LCP: cheap approximation — sort and pick midpoint.
        $lcpRow = $this->db->query("
            SELECT lcp_ms
            FROM landing_page_sessions
            WHERE lcp_ms IS NOT NULL
            ORDER BY lcp_ms
            LIMIT 1
            OFFSET (
                SELECT FLOOR(COUNT(*) / 2)
                FROM landing_page_sessions
                WHERE lcp_ms IS NOT NULL
            )
        ")->fetch();

        return [
            'total_sessions'    => (int)($row['total_sessions']    ?? 0),
            'sessions_7d'       => (int)($row['sessions_7d']       ?? 0),
            'sessions_today'    => (int)($row['sessions_today']    ?? 0),
            'conversion_rate'   => (float)($row['conversion_rate'] ?? 0.0),
            'avg_dwell_seconds' => (float)($row['avg_dwell_seconds'] ?? 0.0),
            'median_lcp_ms'     => $lcpRow ? (int)$lcpRow['lcp_ms'] : null,
            'bounce_rate'       => (float)($row['bounce_rate']     ?? 0.0),
        ];
    }

    /**
     * Device category breakdown as percentages.
     *
     * @return array<string, int>  e.g. ['desktop'=>60,'mobile'=>30,'tablet'=>10]
     */
    public function getDeviceBreakdown(): array
    {
        $rows = $this->db->query("
            SELECT device_category, COUNT(*) AS cnt
            FROM landing_page_sessions
            GROUP BY device_category
        ")->fetchAll();

        $total  = array_sum(array_column($rows, 'cnt'));
        $result = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];

        if ($total === 0) {
            return $result;
        }

        foreach ($rows as $row) {
            $cat = $row['device_category'];
            if (isset($result[$cat])) {
                $result[$cat] = (int)round($row['cnt'] / $total * 100);
            }
        }

        return $result;
    }

    /**
     * Scroll depth funnel — % of sessions reaching each milestone.
     *
     * @return array<int, float>  e.g. [25=>82.0, 50=>55.0, 75=>33.0, 100=>12.0]
     */
    public function getScrollFunnel(): array
    {
        $row = $this->db->query("
            SELECT
                COUNT(*)                                       AS total,
                SUM(max_scroll_percent >= 25)                  AS s25,
                SUM(max_scroll_percent >= 50)                  AS s50,
                SUM(max_scroll_percent >= 75)                  AS s75,
                SUM(max_scroll_percent >= 100)                 AS s100
            FROM landing_page_sessions
        ")->fetch();

        $total = (int)($row['total'] ?? 0);
        if ($total === 0) {
            return [25 => 0.0, 50 => 0.0, 75 => 0.0, 100 => 0.0];
        }

        return [
            25  => round((int)$row['s25']  / $total * 100, 1),
            50  => round((int)$row['s50']  / $total * 100, 1),
            75  => round((int)$row['s75']  / $total * 100, 1),
            100 => round((int)$row['s100'] / $total * 100, 1),
        ];
    }

    /**
     * Daily session counts for the last 14 days (for sparkline rendering).
     *
     * @return array<array{date: string, sessions: int}>
     */
    public function getDailyTrend(): array
    {
        $rows = $this->db->query("
            SELECT
                DATE(created_at) AS date,
                COUNT(*)         AS sessions
            FROM landing_page_sessions
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ")->fetchAll();

        // Fill in missing days with zero so the sparkline is always 14 points.
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['date']] = (int)$row['sessions'];
        }

        $result = [];
        for ($i = 13; $i >= 0; $i--) {
            $dateKey = date('Y-m-d', strtotime("-{$i} days"));
            $result[] = ['date' => $dateKey, 'sessions' => $indexed[$dateKey] ?? 0];
        }

        return $result;
    }

    /**
     * Compact data bundle for the admin sidebar on summarizer.php.
     * One query per distinct data source; never throws — callers must try/catch.
     *
     * @return array{
     *   total_summaries: int,
     *   summaries_today: int,
     *   registered_users: int,
     *   sessions_today: int,
     *   sessions_7d: int,
     *   conversion_rate_7d: float,
     *   bounce_rate_7d: float,
     *   devices: array<string, int>,
     * }
     */
    public function getSidebarSnapshot(): array
    {
        // --- summaries table ---
        $sumRow = $this->db->query("
            SELECT
                COUNT(*)                                    AS total_summaries,
                SUM(DATE(created_at) = CURDATE())           AS summaries_today
            FROM summaries
        ")->fetch();

        // --- users table ---
        $userRow = $this->db->query("
            SELECT COUNT(*) AS registered_users
            FROM users
            WHERE role = 'user'
        ")->fetch();

        // --- landing_page_sessions (may not exist yet) ---
        $sessions7d = [];
        $devices    = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
        try {
            $sessRow = $this->db->query("
                SELECT
                    SUM(DATE(created_at) = CURDATE())                           AS sessions_today,
                    COUNT(*)                                                    AS sessions_7d,
                    ROUND(AVG(converted) * 100, 1)                             AS conversion_rate,
                    ROUND(
                        SUM(max_scroll_percent < 25 AND dwell_time_seconds < 5)
                        / NULLIF(COUNT(*), 0) * 100
                    , 1)                                                        AS bounce_rate
                FROM landing_page_sessions
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetch();
            $sessions7d = $sessRow;

            $devRows = $this->db->query("
                SELECT device_category, COUNT(*) AS cnt
                FROM landing_page_sessions
                GROUP BY device_category
            ")->fetchAll();
            $total = array_sum(array_column($devRows, 'cnt'));
            if ($total > 0) {
                foreach ($devRows as $r) {
                    $cat = $r['device_category'];
                    if (isset($devices[$cat])) {
                        $devices[$cat] = (int)round($r['cnt'] / $total * 100);
                    }
                }
            }
        } catch (\Throwable) {
            // Landing analytics table not yet migrated — use zeroes.
        }

        return [
            'total_summaries'    => (int)($sumRow['total_summaries']  ?? 0),
            'summaries_today'    => (int)($sumRow['summaries_today']  ?? 0),
            'registered_users'   => (int)($userRow['registered_users'] ?? 0),
            'sessions_today'     => (int)($sessions7d['sessions_today']  ?? 0),
            'sessions_7d'        => (int)($sessions7d['sessions_7d']     ?? 0),
            'conversion_rate_7d' => (float)($sessions7d['conversion_rate'] ?? 0.0),
            'bounce_rate_7d'     => (float)($sessions7d['bounce_rate']    ?? 0.0),
            'devices'            => $devices,
        ];
    }

    /**
     * Sidebar data for a logged-in regular user — their own activity only.
     *
     * @param int $userId
     * @return array{
     *   my_summaries: int,
     *   my_today: int,
     *   total_summaries: int,
     * }
     */
    public function getUserSidebarStats(int $userId): array
    {
        $myRow = $this->db->prepare("
            SELECT
                COUNT(*)                              AS my_summaries,
                SUM(DATE(created_at) = CURDATE())     AS my_today
            FROM summaries
            WHERE user_id = :uid
        ");
        $myRow->execute(['uid' => $userId]);
        $my = $myRow->fetch();

        $totalRow = $this->db->query("
            SELECT COUNT(*) AS total_summaries FROM summaries
        ")->fetch();

        return [
            'my_summaries'    => (int)($my['my_summaries']        ?? 0),
            'my_today'        => (int)($my['my_today']            ?? 0),
            'total_summaries' => (int)($totalRow['total_summaries'] ?? 0),
        ];
    }

    /**
     * Top 5 referrer domains by session count.
     *
     * @return array<array{domain: string, sessions: int, conversions: int}>
     */
    public function getTopReferrers(): array
    {
        $rows = $this->db->query("
            SELECT
                referrer,
                COUNT(*)        AS sessions,
                SUM(converted)  AS conversions
            FROM landing_page_sessions
            WHERE referrer IS NOT NULL AND referrer <> ''
            GROUP BY referrer
            ORDER BY sessions DESC
            LIMIT 5
        ")->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            // Extract just the host to keep the table readable.
            $parsed = @parse_url((string)$row['referrer']);
            $domain = $parsed['host'] ?? $row['referrer'];
            $result[] = [
                'domain'      => htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'),
                'sessions'    => (int)$row['sessions'],
                'conversions' => (int)$row['conversions'],
            ];
        }

        return $result;
    }
}
