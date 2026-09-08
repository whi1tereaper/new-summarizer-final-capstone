<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';

use App\Src\Controllers\AnalyticsController;

// Admin role gate
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? 'user') !== 'admin'
) {
    header('Location: login.php?context=admin');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$username = $_SESSION['username'] ?? 'Admin';

// Graceful degrade if migration not run yet
$analyticsAvailable = false;
$kpis      = [];
$devices   = [];
$funnel    = [];
$trend     = [];
$referrers = [];

try {
    $ac        = new AnalyticsController();
    $kpis      = $ac->getKpis();
    $devices   = $ac->getDeviceBreakdown();
    $funnel    = $ac->getScrollFunnel();
    $trend     = $ac->getDailyTrend();
    $referrers = $ac->getTopReferrers();
    $analyticsAvailable = true;
} catch (Throwable $e) {
    error_log('[admin_analytics] ' . $e->getMessage());
}

// ── Pre-compute display values ────────────────────────────────────────────────
$lcpMs      = $kpis['median_lcp_ms'] ?? null;
$lcpLabel   = $lcpMs === null ? '—' : number_format($lcpMs) . ' ms';
$lcpClass   = 'kpi-neutral';
if ($lcpMs !== null) {
    $lcpClass = $lcpMs <= 2500 ? 'kpi-good' : ($lcpMs <= 4000 ? 'kpi-warn' : 'kpi-bad');
}

$avgDwell = $kpis['avg_dwell_seconds'] ?? 0;
$dwellFmt = $avgDwell > 0 ? gmdate('i:s', (int)$avgDwell) : '0:00';

$convRate  = $kpis['conversion_rate'] ?? 0;
$bounce    = $kpis['bounce_rate']     ?? 0;
$bounceClass = $bounce > 70 ? 'kpi-bad' : ($bounce > 40 ? 'kpi-warn' : 'kpi-good');

// SVG sparkline data
$trendValues = array_column($trend, 'sessions');
$trendMax    = max(array_merge([1], $trendValues));
$svgW = 700; $svgH = 90;
$barW = $svgW / max(1, count($trendValues));
$pts  = [];
foreach ($trendValues as $i => $v) {
    $x    = round($i * $barW + $barW / 2, 2);
    $y    = round($svgH - ($v / $trendMax) * ($svgH - 14) - 4, 2);
    $pts[] = "$x,$y";
}
$polyline = implode(' ', $pts);
$areaPath = '';
if ($pts) {
    $areaPath  = 'M ' . $pts[0];
    foreach (array_slice($pts, 1) as $p) $areaPath .= ' L ' . $p;
    [$lx]      = explode(',', end($pts));
    [$fx]      = explode(',', reset($pts));
    $areaPath .= " L {$lx},{$svgH} L {$fx},{$svgH} Z";
}

$trendStart = $trend ?  date('M j', strtotime($trend[0]['date']))         : '';
$trendEnd   = $trend ?  date('M j', strtotime(end($trend)['date']))       : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Landing Analytics — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@700&family=Inter:wght@400;500;600&family=Outfit:wght@700;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ── Layout ──────────────────────────────────────────────────────── */
        .an-page {
            min-height: 100vh;
            background: var(--gradient-page-background);
        }

        .an-topbar {
            background: rgba(255,255,255,0.72);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid var(--color-border);
            position: sticky;
            top: 0;
            z-index: 30;
        }

        .an-topbar__inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 40px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .an-topbar__brand {
            font-family: 'Outfit', sans-serif;
            font-size: 0.9em;
            font-weight: 900;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--color-primary-dark);
            text-decoration: none;
        }

        .an-topbar__nav {
            display: flex;
            align-items: center;
            gap: 28px;
        }

        .an-topbar__nav a {
            font-family: 'Inter', sans-serif;
            font-size: 0.78em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--color-muted);
            text-decoration: none;
            transition: color 0.15s;
        }

        .an-topbar__nav a:hover,
        .an-topbar__nav a.active {
            color: var(--color-primary-dark);
        }

        .an-topbar__nav a.nav-danger {
            color: var(--color-danger);
        }

        .an-shell {
            max-width: 1280px;
            margin: 0 auto;
            padding: 48px 40px 80px;
        }

        /* ── Page header ─────────────────────────────────────────────────── */
        .an-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--color-primary-dark);
        }

        .an-header__eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 0.7em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-primary-dark);
            margin: 0 0 8px;
        }

        .an-header__title {
            font-family: 'Merriweather', serif;
            font-size: 2em;
            font-weight: 700;
            margin: 0;
            line-height: 1.1;
            color: var(--color-text);
        }

        .an-header__meta {
            font-family: 'Inter', sans-serif;
            font-size: 0.78em;
            color: var(--color-muted);
            text-align: right;
        }

        /* ── Section label ───────────────────────────────────────────────── */
        .an-section-label {
            font-family: 'Inter', sans-serif;
            font-size: 0.68em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-muted);
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .an-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--color-border);
        }

        /* ── KPI cards row ───────────────────────────────────────────────── */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-bottom: 36px;
        }

        @media (max-width: 1100px) { .kpi-row { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 600px)  { .kpi-row { grid-template-columns: repeat(2, 1fr); } }

        .kpi-card {
            background: rgba(255,255,255,0.82);
            border: 1px solid var(--color-border);
            padding: 22px 20px 18px;
            box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 3px; height: 100%;
            background: var(--gradient-button);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .kpi-card:hover::before { opacity: 1; }

        .kpi-card__label {
            font-family: 'Inter', sans-serif;
            font-size: 0.65em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--color-muted);
            margin-bottom: 10px;
        }

        .kpi-card__value {
            font-family: 'Merriweather', serif;
            font-size: 2em;
            font-weight: 700;
            line-height: 1;
            color: var(--color-text);
        }

        .kpi-card__value.kpi-good { color: #166534; }
        .kpi-card__value.kpi-warn { color: #92400e; }
        .kpi-card__value.kpi-bad  { color: var(--color-danger); }
        .kpi-card__value.kpi-neutral { color: var(--color-text); }

        .kpi-card__sublabel {
            font-family: 'Inter', sans-serif;
            font-size: 0.68em;
            color: var(--color-muted);
            margin-top: 6px;
        }

        /* ── Middle row: Device + Funnel + Referrers ─────────────────────── */
        .an-middle-row {
            display: grid;
            grid-template-columns: 1fr 1.4fr 1.4fr;
            gap: 20px;
            margin-bottom: 36px;
        }

        @media (max-width: 900px)  { .an-middle-row { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 580px)  { .an-middle-row { grid-template-columns: 1fr; } }

        .an-panel {
            background: rgba(255,255,255,0.82);
            border: 1px solid var(--color-border);
            padding: 24px;
            box-shadow: var(--shadow-soft);
        }

        .an-panel__title {
            font-family: 'Inter', sans-serif;
            font-size: 0.68em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--color-muted);
            margin: 0 0 20px;
        }

        /* Device bars */
        .device-row {
            display: grid;
            grid-template-columns: 60px 1fr 38px;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .device-row:last-child { margin-bottom: 0; }

        .device-row__icon {
            font-size: 0.65em;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            color: var(--color-text);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .device-row__track {
            background: var(--color-soft-bg);
            height: 7px;
            overflow: hidden;
        }

        .device-row__fill {
            height: 100%;
            background: var(--gradient-button);
            transition: width 0.6s cubic-bezier(.4,0,.2,1);
        }

        .device-row__pct {
            font-family: 'Inter', sans-serif;
            font-size: 0.72em;
            font-weight: 600;
            color: var(--color-primary-dark);
            text-align: right;
        }

        /* Funnel columns */
        .funnel-grid {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            height: 120px;
        }

        .funnel-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
            justify-content: flex-end;
        }

        .funnel-col__track {
            width: 100%;
            background: var(--color-soft-bg);
            height: 78px;
            display: flex;
            align-items: flex-end;
            overflow: hidden;
        }

        .funnel-col__bar {
            width: 100%;
            background: var(--gradient-button);
            min-height: 3px;
            transition: height 0.6s cubic-bezier(.4,0,.2,1);
        }

        .funnel-col__pct {
            font-family: 'Merriweather', serif;
            font-size: 0.82em;
            font-weight: 700;
            color: var(--color-primary-dark);
            margin-top: 8px;
        }

        .funnel-col__label {
            font-family: 'Inter', sans-serif;
            font-size: 0.58em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--color-muted);
            margin-top: 3px;
            text-align: center;
            line-height: 1.3;
        }

        /* Referrers table */
        .ref-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Inter', sans-serif;
            font-size: 0.8em;
        }

        .ref-table th {
            font-size: 0.68em;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--color-muted);
            font-weight: 600;
            border-bottom: 1px solid var(--color-border);
            padding: 0 6px 8px;
            text-align: left;
        }

        .ref-table td {
            padding: 9px 6px;
            border-bottom: 1px solid rgba(151,125,255,0.10);
            color: var(--color-text);
        }

        .ref-table tr:last-child td { border-bottom: none; }
        .ref-table tr:hover td { background: rgba(242,230,238,0.5); }

        .ref-domain { font-family: monospace; font-size: 0.9em; }

        .empty-state {
            font-family: 'Inter', sans-serif;
            font-size: 0.82em;
            color: var(--color-muted);
            padding: 20px 0;
            text-align: center;
        }

        /* ── Sparkline panel ─────────────────────────────────────────────── */
        .sparkline-panel {
            background: rgba(255,255,255,0.82);
            border: 1px solid var(--color-border);
            padding: 24px;
            box-shadow: var(--shadow-soft);
            margin-bottom: 0;
        }

        .sparkline-panel__header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 16px;
        }

        .sparkline-panel__peak {
            font-family: 'Inter', sans-serif;
            font-size: 0.72em;
            color: var(--color-muted);
        }

        .sparkline-svg {
            width: 100%;
            height: 90px;
            display: block;
            overflow: visible;
        }

        .sparkline-axis {
            display: flex;
            justify-content: space-between;
            font-family: 'Inter', sans-serif;
            font-size: 0.68em;
            color: var(--color-muted);
            margin-top: 6px;
        }

        /* ── Not available state ─────────────────────────────────────────── */
        .an-unavailable {
            text-align: center;
            padding: 80px 40px;
            border: 1px dashed var(--color-border);
        }

        .an-unavailable h2 {
            font-family: 'Inter', sans-serif;
            font-size: 1em;
            color: var(--color-muted);
            font-weight: 600;
            margin: 0 0 10px;
        }

        .an-unavailable p {
            font-size: 0.85em;
            color: var(--color-muted);
            margin: 0;
        }

        .an-unavailable code {
            background: var(--color-soft-bg);
            padding: 2px 6px;
            font-size: 0.9em;
        }

        /* ── Responsive topbar ───────────────────────────────────────────── */
        @media (max-width: 640px) {
            .an-topbar__inner { padding: 0 20px; }
            .an-shell         { padding: 32px 20px 60px; }
            .an-header        { flex-direction: column; align-items: flex-start; gap: 8px; }
            .an-header__meta  { text-align: left; }
            .an-topbar__nav   { gap: 16px; }
            .an-topbar__nav a { font-size: 0.7em; }
        }
    </style>
</head>
<body class="an-page">

    <!-- ── Sticky top bar ──────────────────────────────────────────────────── -->
    <header class="an-topbar">
        <div class="an-topbar__inner">
            <a href="summarizer.php" class="an-topbar__brand">Article Summarizer</a>
            <nav class="an-topbar__nav" aria-label="Admin navigation">
                <a href="summarizer.php">Workspace</a>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="admin_analytics.php" class="active">Analytics</a>
                <a href="admin_audit_logs.php">Audit Logs</a>
                <a href="admin_challenge_change.php">Security</a>
                <a href="auth.php?action=logout" class="nav-danger js-logout-link">Logout</a>
            </nav>
        </div>
    </header>

    <!-- ── Main content ───────────────────────────────────────────────────── -->
    <main class="an-shell">

        <!-- Page header -->
        <div class="an-header">
            <div>
                <p class="an-header__eyebrow">Admin Panel</p>
                <h1 class="an-header__title">Landing Page Analytics</h1>
            </div>
            <div class="an-header__meta">
                Logged in as <strong><?= htmlspecialchars($username) ?></strong><br>
                Data as of <?= date('M j, Y · g:i A') ?>
            </div>
        </div>

        <?php if (!$analyticsAvailable): ?>
        <div class="an-unavailable">
            <h2>Analytics tables not installed</h2>
            <p>Run <code>database/analytics_schema.sql</code> against your database to enable this page.</p>
        </div>
        <?php else: ?>

        <!-- ── KPI Row ─────────────────────────────────────────────────────── -->
        <p class="an-section-label">Key Metrics</p>
        <div class="kpi-row">

            <div class="kpi-card">
                <div class="kpi-card__label">Sessions Today</div>
                <div class="kpi-card__value"><?= $kpis['sessions_today'] ?></div>
                <div class="kpi-card__sublabel"><?= $kpis['sessions_7d'] ?> this week</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-card__label">Total Sessions</div>
                <div class="kpi-card__value"><?= number_format($kpis['total_sessions']) ?></div>
                <div class="kpi-card__sublabel">All time</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-card__label">CTA Conversion</div>
                <div class="kpi-card__value <?= $convRate >= 10 ? 'kpi-good' : ($convRate >= 5 ? 'kpi-warn' : 'kpi-bad') ?>"><?= $convRate ?>%</div>
                <div class="kpi-card__sublabel">Get Started / Register clicks</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-card__label">Avg Dwell Time</div>
                <div class="kpi-card__value"><?= $dwellFmt ?></div>
                <div class="kpi-card__sublabel">Active tab time (m:ss)</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-card__label">Median LCP</div>
                <div class="kpi-card__value <?= $lcpClass ?>"><?= $lcpLabel ?></div>
                <div class="kpi-card__sublabel">Good &lt; 2,500 ms</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-card__label">Bounce Rate</div>
                <div class="kpi-card__value <?= $bounceClass ?>"><?= $bounce ?>%</div>
                <div class="kpi-card__sublabel">&lt;25% scroll &amp; &lt;5s dwell</div>
            </div>

        </div><!-- /.kpi-row -->

        <!-- ── Device + Funnel + Referrers ────────────────────────────────── -->
        <p class="an-section-label">Visitor Behaviour</p>
        <div class="an-middle-row">

            <!-- Device Breakdown -->
            <div class="an-panel">
                <h2 class="an-panel__title">Device Breakdown</h2>
                <?php
                $deviceIcons = ['desktop' => 'Desktop', 'mobile' => 'Mobile', 'tablet' => 'Tablet'];
                foreach ($devices as $dev => $pct):
                ?>
                <div class="device-row">
                    <span class="device-row__icon"><?= $deviceIcons[$dev] ?? ucfirst($dev) ?></span>
                    <div class="device-row__track">
                        <div class="device-row__fill" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="device-row__pct"><?= $pct ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Scroll Depth Funnel -->
            <div class="an-panel">
                <h2 class="an-panel__title">Scroll Depth Funnel</h2>
                <div class="funnel-grid">
                    <?php
                    $funnelMeta = [
                        25  => '25%',
                        50  => '50%',
                        75  => '75%',
                        100 => 'Full',
                    ];
                    foreach ($funnel as $milestone => $pct):
                    ?>
                    <div class="funnel-col">
                        <div class="funnel-col__track">
                            <div class="funnel-col__bar" style="height:<?= max(3, $pct) ?>%"></div>
                        </div>
                        <div class="funnel-col__pct"><?= $pct ?>%</div>
                        <div class="funnel-col__label"><?= $funnelMeta[$milestone] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top Referrers -->
            <div class="an-panel">
                <h2 class="an-panel__title">Top Referrers</h2>
                <?php if (empty($referrers)): ?>
                    <p class="empty-state">No referrer data yet.<br>Most traffic appears to be direct.</p>
                <?php else: ?>
                <table class="ref-table">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Sessions</th>
                            <th>Conv.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referrers as $ref): ?>
                        <tr>
                            <td class="ref-domain"><?= $ref['domain'] ?></td>
                            <td><?= $ref['sessions'] ?></td>
                            <td><?= $ref['conversions'] > 0
                                    ? round($ref['conversions'] / $ref['sessions'] * 100) . '%'
                                    : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </div><!-- /.an-middle-row -->

        <!-- ── 14-day Sparkline ────────────────────────────────────────────── -->
        <p class="an-section-label">Session Trend</p>
        <div class="sparkline-panel">
            <div class="sparkline-panel__header">
                <h2 class="an-panel__title" style="margin:0;">Daily Sessions — Last 14 Days</h2>
                <span class="sparkline-panel__peak">Peak: <?= $trendMax ?> sessions</span>
            </div>

            <?php if ($pts): ?>
            <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>"
                 preserveAspectRatio="none"
                 xmlns="http://www.w3.org/2000/svg"
                 class="sparkline-svg"
                 role="img"
                 aria-label="14-day session trend sparkline">
                <defs>
                    <linearGradient id="anGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%"   stop-color="#977DFF" stop-opacity="0.22"/>
                        <stop offset="100%" stop-color="#977DFF" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <!-- Zero line -->
                <line x1="0" y1="<?= $svgH - 1 ?>" x2="<?= $svgW ?>" y2="<?= $svgH - 1 ?>"
                      stroke="var(--color-border)" stroke-width="1"/>
                <!-- Area fill -->
                <path d="<?= $areaPath ?>" fill="url(#anGrad)"/>
                <!-- Line -->
                <polyline points="<?= $polyline ?>"
                          fill="none" stroke="#7F63F4" stroke-width="2.5"
                          stroke-linejoin="round" stroke-linecap="round"/>
                <!-- Dots for each data point -->
                <?php foreach ($pts as $i => $pt):
                    [$px, $py] = explode(',', $pt);
                    if ($trendValues[$i] > 0):
                ?>
                <circle cx="<?= $px ?>" cy="<?= $py ?>" r="3"
                        fill="#7F63F4" stroke="#fff" stroke-width="1.5"/>
                <?php endif; endforeach; ?>
            </svg>
            <div class="sparkline-axis">
                <span><?= $trendStart ?></span>
                <?php
                // Show a label roughly mid-way
                if (count($trend) >= 7) {
                    $midIdx = (int)floor(count($trend) / 2);
                    echo '<span>' . date('M j', strtotime($trend[$midIdx]['date'])) . '</span>';
                }
                ?>
                <span><?= $trendEnd ?></span>
            </div>
            <?php else: ?>
            <p class="empty-state">No session data in the last 14 days.</p>
            <?php endif; ?>
        </div>

        <?php endif; // $analyticsAvailable ?>

    </main>

    <script src="assets/js/index.js"></script>
</body>
</html>
