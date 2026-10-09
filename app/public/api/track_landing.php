<?php
declare(strict_types=1);

// Bootstrap provides session, DB, and security headers.
require_once __DIR__ . '/../../src/whitereaper.php';
require_once __DIR__ . '/../../src/Services/RateLimiter.php';

use App\Src\Database;
use App\Src\Services\RateLimiter;

// Only JSON responses from this endpoint.
header('Content-Type: application/json; charset=utf-8');

// Only POST is valid - sendBeacon always POSTs.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read raw body - sendBeacon sends Content-Type: text/plain or application/json.
$rawInput = file_get_contents('php://input');
if (empty($rawInput) || strlen($rawInput) > 32768) {
    // Ignore empty or suspiciously oversized payloads silently (beacon fire-and-forget).
    http_response_code(204);
    exit;
}

$data = json_decode($rawInput, true);
if (!is_array($data) || !isset($data['session_id'], $data['events']) || !is_array($data['events'])) {
    http_response_code(204);
    exit;
}

// Validate session_id: alphanumeric + _ - only, 16-64 chars.
$sessionId = (string)($data['session_id'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_\-]{16,64}$/', $sessionId)) {
    http_response_code(204);
    exit;
}

$clientIp = RateLimiter::getClientIp();
$rateLimiter = new RateLimiter();
if ($rateLimiter->isLimited('landing_beacon', hash('sha256', $clientIp))) {
    http_response_code(429);
    exit;
}
$rateLimiter->recordAttempt('landing_beacon', hash('sha256', $clientIp));

// Hard cap: ignore batches with absurd event counts (bot guard).
if (count($data['events']) > 25) {
    http_response_code(204);
    exit;
}

// ── Session Metadata ──────────────────────────────────────────────────────────
$userId     = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$guestToken = isset($_SESSION['guest_token']) ? (string)$_SESSION['guest_token'] : null;

// Never store the raw IP - salted SHA-256 gives a stable unique-visitor key
// that cannot be reversed to an identity.
$rawIp  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$ipHash = hash('sha256', $rawIp . 'LP_ANALYTICS_SALT_v1');

$userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$referrer = '';
$rawReferrer = trim((string)($data['referrer'] ?? ''));
if ($rawReferrer !== '') {
    $parsedReferrer = parse_url($rawReferrer);
    $host = is_array($parsedReferrer) ? strtolower((string)($parsedReferrer['host'] ?? '')) : '';
    if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host)) {
        $referrer = mb_substr($host, 0, 255);
    }
}

// Simple device detection - good enough for dashboard segmentation.
$deviceCategory = 'desktop';
if (preg_match('/(tablet|ipad|playbook)|(android(?!.*mobile))/i', $userAgent)) {
    $deviceCategory = 'tablet';
} elseif (preg_match('/(android.*mobile|iphone|ipod|opera mini|iemobile|mobile)/i', $userAgent)) {
    $deviceCategory = 'mobile';
}

// ── Aggregate event data before hitting the DB ────────────────────────────────
$lcpMs       = null;
$fidMs       = null;
$inpMs       = null;
$clsScore    = null;
$maxScroll   = 0;
$dwellSec    = 0;
$converted   = 0;

$validEventTypes = ['pageview', 'cta_click', 'scroll_milestone', 'web_vitals', 'page_exit'];

$cleanEvents = [];
$exitEventSeen = false;
foreach ($data['events'] as $evt) {
    if (!is_array($evt) || !isset($evt['type'])) {
        continue;
    }

    $type  = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$evt['type']));
    $label = isset($evt['label']) ? mb_substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$evt['label']), 0, 100) : null;
    $value = isset($evt['value']) && is_numeric($evt['value']) ? (int)$evt['value'] : null;

    if (!in_array($type, $validEventTypes, true)) {
        continue;
    }
    if ($type === 'page_exit') {
        if ($exitEventSeen) {
            continue;
        }
        $exitEventSeen = true;
    }

    $cleanEvents[] = ['type' => $type, 'label' => $label, 'value' => $value];

    // Accumulate session-level aggregates so we can UPDATE in a single query.
    if ($type === 'web_vitals') {
        if ($label === 'LCP' && $value !== null) $lcpMs = max(0, min(60000, $value));
        if ($label === 'FID' && $value !== null) $fidMs = max(0, min(60000, $value));
        if ($label === 'INP' && $value !== null) $inpMs = max(0, min(60000, $value));
        if ($label === 'CLS' && $value !== null) $clsScore = round(max(0, min(10000, $value)) / 10000.0, 4);
    } elseif ($type === 'scroll_milestone' && $value !== null) {
        $maxScroll = max($maxScroll, min(100, $value));
    } elseif ($type === 'page_exit' && $value !== null) {
        $dwellSec = max($dwellSec, min(65535, $value));
    } elseif ($type === 'cta_click' && in_array($label, ['hero_get_started', 'nav_register'], true)) {
        $converted = 1;
    }
}

if (empty($cleanEvents)) {
    http_response_code(204);
    exit;
}

// ── Persist to DB ─────────────────────────────────────────────────────────────
try {
    $pdo = Database::getInstance()->getConnection();
    $eventCountStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM landing_page_events WHERE session_id = :session_id'
    );
    $eventCountStatement->execute([':session_id' => $sessionId]);
    if ((int)$eventCountStatement->fetchColumn() >= 500) {
        http_response_code(429);
        exit;
    }
    $pdo->beginTransaction();

    // Upsert the session record. Ignore fields already set on duplicate.
    $upsert = $pdo->prepare("
        INSERT INTO landing_page_sessions
            (session_id, user_id, guest_token, ip_hash, user_agent, device_category, referrer)
        VALUES
            (:session_id, :user_id, :guest_token, :ip_hash, :user_agent, :device_category, :referrer)
        ON DUPLICATE KEY UPDATE
            user_id    = COALESCE(user_id, VALUES(user_id)),
            updated_at = CURRENT_TIMESTAMP()
    ");
    $upsert->execute([
        ':session_id'      => $sessionId,
        ':user_id'         => $userId,
        ':guest_token'     => $guestToken,
        ':ip_hash'         => $ipHash,
        ':user_agent'      => $userAgent,
        ':device_category' => $deviceCategory,
        ':referrer'        => $referrer ?: null,
    ]);

    // Append individual event rows.
    $evtStmt = $pdo->prepare("
        INSERT INTO landing_page_events (session_id, event_type, event_label, event_value)
        VALUES (:session_id, :type, :label, :value)
    ");
    foreach ($cleanEvents as $evt) {
        $evtStmt->execute([
            ':session_id' => $sessionId,
            ':type'       => $evt['type'],
            ':label'      => $evt['label'],
            ':value'      => $evt['value'],
        ]);
    }

    // Update aggregated session metrics - always take the max/latest values.
    $updateStmt = $pdo->prepare("
        UPDATE landing_page_sessions SET
            lcp_ms             = CASE WHEN :lcp IS NOT NULL    THEN COALESCE(lcp_ms,  :lcp2)  ELSE lcp_ms  END,
            fid_ms             = CASE WHEN :fid IS NOT NULL    THEN COALESCE(fid_ms,  :fid2)  ELSE fid_ms  END,
            cls_score          = CASE WHEN :cls IS NOT NULL    THEN COALESCE(cls_score,:cls2)  ELSE cls_score END,
            inp_ms             = CASE WHEN :inp IS NOT NULL    THEN COALESCE(inp_ms, :inp2) ELSE inp_ms END,
            max_scroll_percent = GREATEST(max_scroll_percent, :scroll),
            dwell_time_seconds = GREATEST(dwell_time_seconds, :dwell),
            converted          = CASE WHEN :conv = 1 THEN 1 ELSE converted END
        WHERE session_id = :session_id
    ");
    $updateStmt->execute([
        ':lcp'        => $lcpMs,
        ':lcp2'       => $lcpMs,
        ':fid'        => $fidMs,
        ':fid2'       => $fidMs,
        ':cls'        => $clsScore,
        ':cls2'       => $clsScore,
        ':inp'        => $inpMs ?? null,
        ':inp2'       => $inpMs ?? null,
        ':scroll'     => $maxScroll,
        ':dwell'      => $dwellSec,
        ':conv'       => $converted,
        ':session_id' => $sessionId,
    ]);

    $pdo->commit();
    http_response_code(204); // 204: accepted, no body needed
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[track_landing.php] ' . $e->getMessage());
    http_response_code(204); // Still 204 - client should not retry analytics beacons
}
