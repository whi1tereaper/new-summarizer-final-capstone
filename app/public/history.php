<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once '../src/Controllers/HistoryHandler.php';
require_once '../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';

use App\Src\Controllers\HistoryHandler;
use App\Src\Utils\Formatter;
use App\Src\Controllers\AnalyticsController;

$userId       = $_SESSION['user_id'] ?? null;
$guestToken   = $_SESSION['guest_token'] ?? null;
$isGuestViewer = $userId === null;
$isAdmin      = ($_SESSION['role'] ?? 'user') === 'admin';

if ($userId === null && $guestToken === null) {
    header('Location: login.php');
    exit;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$history = HistoryHandler::getSummaryHistory($userId, $guestToken, $page);
$nutshellHistory = HistoryHandler::getNutshellHistory($userId, $guestToken);

// Analytics — only loaded for admins, silently skipped otherwise.
$analyticsAvailable = false;
$anKpis = $anDevices = $anFunnel = $anTrend = $anReferrers = [];
if ($isAdmin) {
    try {
        $ac = new AnalyticsController();
        $anKpis      = $ac->getKpis();
        $anDevices   = $ac->getDeviceBreakdown();
        $anFunnel    = $ac->getScrollFunnel();
        $anTrend     = $ac->getDailyTrend();
        $anReferrers = $ac->getTopReferrers();
        $analyticsAvailable = true;
    } catch (Throwable $e) {
        // Tables not yet migrated — fail silently
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isGuestViewer ? 'Guest Summary History' : 'Article Insights History' ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=Outfit:wght@600;700;800&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        nav { margin-bottom: 50px; display:flex; gap: 30px; border-bottom: 1px solid var(--color-border); padding-bottom: 20px;}
        nav a { color: var(--color-muted); font-size: 0.8em; text-decoration:none; font-family:'Inter'; text-transform:uppercase;}
        nav a:hover { color: var(--color-primary-dark); }

        h1 { 
            font-size: 2.5em; 
            margin: 0 0 50px 0; 
            border-bottom: 2px solid var(--color-primary-dark);
            padding-bottom: 15px;
            font-family: 'Outfit', 'Inter', sans-serif;
            font-weight: 800;
            letter-spacing: -0.04em;
            line-height: 0.98;
        }

        .history-list { display: flex; flex-direction: column; gap: 60px; }
        .history-item { border-bottom: 1px solid var(--color-border-strong); padding-bottom: 40px; }
        .history-item:last-child { border-bottom: none; }

        .article-title { 
            font-family: 'Merriweather', serif; 
            font-size: 1.8em; 
            margin: 0 0 10px 0; 
            line-height: 1.2;
        }
        .article-title a { text-decoration: none; color: inherit; }
        .article-title a:hover { color: var(--color-primary-dark); text-decoration: underline; }

        .meta-line { 
            font-size: 0.75em; 
            color: var(--color-muted); 
            text-transform: uppercase; 
            letter-spacing: 1.2px; 
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-family: 'Inter', sans-serif;
        }
        .meta-line strong { color: var(--color-text); }

        .summary-preview { 
            font-family: 'Merriweather', serif;
            font-size: 1.1em; 
            color: #2A2436; 
            margin-top: 20px;
        }

        .btn-view {
            display: inline-block;
            margin-top: 20px;
            font-size: 0.8em;
            font-weight: 600;
            color: var(--color-primary-dark);
            text-decoration: underline;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: 'Inter';
        }

        .btn-view:hover { color: var(--color-primary); }

        .empty-state { text-align: left; margin: 60px 0; color: var(--color-muted); font-style: italic; }

        /* ─── History Responsive ─── */
        @media (max-width: 768px) {
            nav { gap: 16px; padding-bottom: 14px; }

            h1 {
                font-size: 1.8em;
                margin-bottom: 30px;
            }

            .article-title {
                font-size: 1.4em;
            }

            .history-list {
                gap: 40px;
            }

            .history-item {
                padding-bottom: 28px;
            }

            .summary-preview {
                font-size: 1em;
            }
        }

        @media (max-width: 576px) {
            nav { gap: 12px; padding-bottom: 12px; margin-bottom: 30px; }

            h1 {
                font-size: 1.4em;
                margin-bottom: 24px;
                padding-bottom: 10px;
            }

            .article-title {
                font-size: 1.2em;
            }

            .meta-line {
                gap: 10px;
                font-size: 0.7em;
            }

            .history-list {
                gap: 30px;
            }

            .history-item {
                padding-bottom: 20px;
            }

            .summary-preview {
                font-size: 0.92em;
            }
        }
        /* ── Analytics section (admin-only) ───────────────────────────── */
        .an-divider {
            margin: 70px 0 0;
            border: none;
            border-top: 2px solid var(--color-primary-dark);
        }

        .an-section-eyebrow {
            font-family: 'Inter', sans-serif;
            font-size: 0.68em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-primary-dark);
            margin: 28px 0 6px;
        }

        .an-section-title {
            font-family: 'Outfit', 'Inter', sans-serif;
            font-size: 1.6em;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1;
            color: var(--color-text);
            margin: 0 0 32px;
            border-bottom: none;
            padding-bottom: 0;
        }

        .an-section-label {
            font-family: 'Inter', sans-serif;
            font-size: 0.65em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-muted);
            margin: 36px 0 14px;
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

        /* KPI cards */
        .an-kpi-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
        }

        @media (max-width: 900px) { .an-kpi-row { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 540px) { .an-kpi-row { grid-template-columns: repeat(2, 1fr); } }

        .an-kpi {
            background: rgba(255,255,255,0.82);
            border: 1px solid var(--color-border);
            padding: 18px 16px 14px;
            box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
        }

        .an-kpi::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 3px; height: 100%;
            background: var(--gradient-button);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .an-kpi:hover::before { opacity: 1; }

        .an-kpi__label {
            font-family: 'Inter', sans-serif;
            font-size: 0.6em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--color-muted);
            margin-bottom: 8px;
        }

        .an-kpi__val {
            font-family: 'Merriweather', serif;
            font-size: 1.8em;
            font-weight: 700;
            line-height: 1;
            color: var(--color-text);
        }

        .an-kpi__val.kpi-good { color: #166534; }
        .an-kpi__val.kpi-warn { color: #92400e; }
        .an-kpi__val.kpi-bad  { color: var(--color-danger); }

        .an-kpi__sub {
            font-family: 'Inter', sans-serif;
            font-size: 0.65em;
            color: var(--color-muted);
            margin-top: 5px;
        }

        /* Two-col panels */
        .an-two-col {
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 18px;
            margin-bottom: 18px;
        }

        @media (max-width: 680px) { .an-two-col { grid-template-columns: 1fr; } }

        .an-panel {
            background: rgba(255,255,255,0.82);
            border: 1px solid var(--color-border);
            padding: 20px 22px;
            box-shadow: var(--shadow-soft);
        }

        .an-panel__title {
            font-family: 'Inter', sans-serif;
            font-size: 0.62em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--color-muted);
            margin: 0 0 16px;
        }

        /* Device bars */
        .dv-row { display: grid; grid-template-columns: 58px 1fr 36px; align-items: center; gap: 8px; margin-bottom: 12px; }
        .dv-row:last-child { margin-bottom: 0; }
        .dv-label { font-family: 'Inter', sans-serif; font-size: 0.7em; font-weight: 600; color: var(--color-text); text-transform: uppercase; letter-spacing: 0.5px; }
        .dv-track { background: var(--color-soft-bg); height: 7px; overflow: hidden; }
        .dv-fill  { height: 100%; background: var(--gradient-button); transition: width 0.5s ease; }
        .dv-pct   { font-family: 'Inter', sans-serif; font-size: 0.7em; font-weight: 600; color: var(--color-primary-dark); text-align: right; }

        /* Funnel */
        .fn-grid { display: flex; align-items: flex-end; gap: 10px; height: 110px; }
        .fn-col   { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end; }
        .fn-track { width: 100%; background: var(--color-soft-bg); height: 70px; display: flex; align-items: flex-end; overflow: hidden; }
        .fn-bar   { width: 100%; background: var(--gradient-button); min-height: 3px; transition: height 0.5s ease; }
        .fn-pct   { font-family: 'Merriweather', serif; font-size: 0.78em; font-weight: 700; color: var(--color-primary-dark); margin-top: 6px; }
        .fn-lbl   { font-family: 'Inter', sans-serif; font-size: 0.56em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-muted); margin-top: 3px; text-align: center; }

        /* Sparkline */
        .sp-wrap  { background: rgba(255,255,255,0.82); border: 1px solid var(--color-border); padding: 20px 22px; box-shadow: var(--shadow-soft); margin-bottom: 18px; }
        .sp-hdr   { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; }
        .sp-peak  { font-family: 'Inter', sans-serif; font-size: 0.7em; color: var(--color-muted); }
        .sp-svg   { width: 100%; height: 80px; display: block; overflow: visible; }
        .sp-axis  { display: flex; justify-content: space-between; font-family: 'Inter', sans-serif; font-size: 0.65em; color: var(--color-muted); margin-top: 5px; }

        /* Referrer table */
        .ref-tbl  { width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; font-size: 0.78em; }
        .ref-tbl th { font-size: 0.65em; text-transform: uppercase; letter-spacing: 1px; color: var(--color-muted); font-weight: 600; border-bottom: 1px solid var(--color-border); padding: 0 6px 7px; text-align: left; }
        .ref-tbl td { padding: 8px 6px; border-bottom: 1px solid rgba(151,125,255,0.10); color: var(--color-text); }
        .ref-tbl tr:last-child td { border-bottom: none; }
        .ref-tbl tr:hover td { background: rgba(242,230,238,0.5); }
        .ref-domain { font-family: monospace; font-size: 0.88em; }
        .an-empty { font-family: 'Inter', sans-serif; font-size: 0.8em; color: var(--color-muted); text-align: center; padding: 16px 0; }
    </style>
</head>
<body>

<main class="editorial-layout">
    <nav>
        <a href="index.php">← BACK</a>
    </nav>

    <h1><?= $isGuestViewer ? 'Guest Summary History' : 'History' ?></h1>

    <?php if (isset($history['error'])): ?>
        <p style="color: var(--color-danger); border-left: 4px solid var(--color-danger); margin: 30px 0; font-size: 0.9em; padding-left: 20px;">
            <strong>NOTIFICATION:</strong> <?php echo htmlspecialchars($history['error']); ?>
        </p>
    <?php elseif (empty($history)): ?>
        <div class="empty-state">
            <p>History remains vacant. Initiate a <a href="index.php" style="color: var(--color-primary-dark); font-weight: bold;">new document analysis</a> to begin.</p>
        </div>
    <?php else: ?>
        <div class="history-list">
            <?php foreach ($history as $item): ?>
                <div class="history-item">
                    <h2 class="article-title">
                        <a href="result.php?id=<?php echo $item['id']; ?>&amp;share=<?php echo urlencode((string)($item['share_token'] ?? '')); ?>">
                            <?php echo htmlspecialchars($item['article_title']); ?>
                        </a>
                    </h2>
                    <div class="meta-line">
                        <span>#<?php echo $item['id']; ?></span>
                        <span><?php echo strtoupper(htmlspecialchars($item['input_type'])); ?></span>
                        <span><?php echo date('F d, Y', strtotime($item['created_at'])); ?></span>
                    </div>
                    <div class="summary-preview">
                    <?php
                        $previewText = Formatter::toPlainTextFromStoredSummary((string)($item['generated_summary'] ?? ''));
                        if (mb_strlen($previewText, 'UTF-8') > 280) {
                            $previewText = mb_substr($previewText, 0, 280, 'UTF-8') . '...';
                        }
                        echo htmlspecialchars($previewText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    ?>
                    </div>
                    <a href="result.php?id=<?php echo $item['id']; ?>&amp;share=<?php echo urlencode((string)($item['share_token'] ?? '')); ?>" class="btn-view">Access Full Report</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!isset($nutshellHistory['error']) && !empty($nutshellHistory)): ?>
        <h2 style="margin-top: 70px; border-bottom: 2px solid var(--color-primary-dark); padding-bottom: 15px;">Nutshell History</h2>
        <div class="history-list">
            <?php foreach ($nutshellHistory as $nutshell): ?>
                <div class="history-item">
                    <h2 class="article-title">
                        <?php if (!empty($nutshell['summary_id'])): ?>
                            <a href="result.php?id=<?php echo (int)$nutshell['summary_id']; ?>&amp;share=<?php echo urlencode((string)($nutshell['share_token'] ?? '')); ?>">
                                <?php echo htmlspecialchars((string)($nutshell['article_title'] ?? 'Document')); ?>
                            </a>
                        <?php else: ?>
                            <?php echo htmlspecialchars((string)($nutshell['input_type'] ?? 'Document')); ?>
                        <?php endif; ?>
                    </h2>
                    <div class="meta-line">
                        <span>NUTSHELL</span>
                        <span><?php echo date('F d, Y', strtotime($nutshell['created_at'])); ?></span>
                        <span><?php echo (int)$nutshell['word_count']; ?> WORDS</span>
                    </div>
                    <?php if (!empty($nutshell['nutshell_text'])): ?>
                        <div class="summary-preview"><?php echo htmlspecialchars((string)$nutshell['nutshell_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php if ($isAdmin && $analyticsAvailable):
    // ── Pre-compute display values ─────────────────────────────────────
    $lcpMs   = $anKpis['median_lcp_ms'] ?? null;
    $lcpLbl  = $lcpMs === null ? '—' : number_format($lcpMs) . ' ms';
    $lcpCls  = $lcpMs === null ? '' : ($lcpMs <= 2500 ? 'kpi-good' : ($lcpMs <= 4000 ? 'kpi-warn' : 'kpi-bad'));
    $conv    = $anKpis['conversion_rate'] ?? 0;
    $bounce  = $anKpis['bounce_rate']     ?? 0;
    $dwell   = $anKpis['avg_dwell_seconds'] ?? 0;
    $dwFmt   = $dwell > 0 ? gmdate('i:s', (int)$dwell) : '0:00';

    // Sparkline geometry
    $vals  = array_column($anTrend, 'sessions');
    $vmax  = max(array_merge([1], $vals));
    $svgW  = 700; $svgH = 80;
    $bw    = $svgW / max(1, count($vals));
    $pts   = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i * $bw + $bw / 2, 1) . ',' . round($svgH - ($v / $vmax) * ($svgH - 12) - 3, 1);
    }
    $poly  = implode(' ', $pts);
    $area  = '';
    if ($pts) {
        $area  = 'M ' . $pts[0];
        foreach (array_slice($pts, 1) as $p) $area .= ' L ' . $p;
        [$lx]  = explode(',', end($pts));
        [$fx]  = explode(',', reset($pts));
        $area .= " L {$lx},{$svgH} L {$fx},{$svgH} Z";
    }
    $tStart = $anTrend ? date('M j', strtotime($anTrend[0]['date']))          : '';
    $tEnd   = $anTrend ? date('M j', strtotime(end($anTrend)['date']))        : '';
?>
<section class="editorial-layout" id="landing-analytics" aria-label="Landing Page Analytics">
    <hr class="an-divider">
    <p class="an-section-eyebrow">Admin Only</p>
    <h2 class="an-section-title">Landing Page Analytics</h2>

    <!-- KPIs -->
    <p class="an-section-label">Key Metrics</p>
    <div class="an-kpi-row">
        <div class="an-kpi">
            <div class="an-kpi__label">Sessions Today</div>
            <div class="an-kpi__val"><?= $anKpis['sessions_today'] ?></div>
            <div class="an-kpi__sub"><?= $anKpis['sessions_7d'] ?> this week</div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi__label">Total Sessions</div>
            <div class="an-kpi__val"><?= number_format($anKpis['total_sessions']) ?></div>
            <div class="an-kpi__sub">All time</div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi__label">CTA Conversion</div>
            <div class="an-kpi__val <?= $conv >= 10 ? 'kpi-good' : ($conv >= 5 ? 'kpi-warn' : 'kpi-bad') ?>"><?= $conv ?>%</div>
            <div class="an-kpi__sub">Get Started / Register</div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi__label">Avg Dwell</div>
            <div class="an-kpi__val"><?= $dwFmt ?></div>
            <div class="an-kpi__sub">Active time (m:ss)</div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi__label">Median LCP</div>
            <div class="an-kpi__val <?= $lcpCls ?>"><?= $lcpLbl ?></div>
            <div class="an-kpi__sub">Good &lt; 2,500 ms</div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi__label">Bounce Rate</div>
            <div class="an-kpi__val <?= $bounce > 70 ? 'kpi-bad' : ($bounce > 40 ? 'kpi-warn' : 'kpi-good') ?>"><?= $bounce ?>%</div>
            <div class="an-kpi__sub">&lt;25% scroll &amp; &lt;5s</div>
        </div>
    </div>

    <!-- Device + Funnel -->
    <p class="an-section-label">Visitor Behaviour</p>
    <div class="an-two-col">
        <div class="an-panel">
            <h3 class="an-panel__title">Device Breakdown</h3>
            <?php foreach ($anDevices as $dev => $pct): ?>
            <div class="dv-row">
                <span class="dv-label"><?= ucfirst($dev) ?></span>
                <div class="dv-track"><div class="dv-fill" style="width:<?= $pct ?>%"></div></div>
                <span class="dv-pct"><?= $pct ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="an-panel">
            <h3 class="an-panel__title">Scroll Depth Funnel</h3>
            <div class="fn-grid">
                <?php $fnLabels = [25=>'25%',50=>'50%',75=>'75%',100=>'Full'];
                foreach ($anFunnel as $ms => $pct): ?>
                <div class="fn-col">
                    <div class="fn-track"><div class="fn-bar" style="height:<?= max(3,$pct) ?>%"></div></div>
                    <div class="fn-pct"><?= $pct ?>%</div>
                    <div class="fn-lbl"><?= $fnLabels[$ms] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Sparkline -->
    <p class="an-section-label">Session Trend</p>
    <div class="sp-wrap">
        <div class="sp-hdr">
            <span class="an-panel__title" style="margin:0;">Daily Sessions — Last 14 Days</span>
            <span class="sp-peak">Peak: <?= $vmax ?> sessions</span>
        </div>
        <?php if ($pts): ?>
        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="none"
             xmlns="http://www.w3.org/2000/svg" class="sp-svg" aria-hidden="true">
            <defs>
                <linearGradient id="hGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%"   stop-color="#977DFF" stop-opacity="0.22"/>
                    <stop offset="100%" stop-color="#977DFF" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <line x1="0" y1="<?= $svgH-1 ?>" x2="<?= $svgW ?>" y2="<?= $svgH-1 ?>"
                  stroke="var(--color-border)" stroke-width="1"/>
            <path d="<?= $area ?>" fill="url(#hGrad)"/>
            <polyline points="<?= $poly ?>" fill="none"
                      stroke="#7F63F4" stroke-width="2.5"
                      stroke-linejoin="round" stroke-linecap="round"/>
            <?php foreach ($pts as $i => $pt): [$px,$py] = explode(',',$pt); if ($vals[$i] > 0): ?>
            <circle cx="<?= $px ?>" cy="<?= $py ?>" r="3"
                    fill="#7F63F4" stroke="#fff" stroke-width="1.5"/>
            <?php endif; endforeach; ?>
        </svg>
        <div class="sp-axis">
            <span><?= $tStart ?></span>
            <?php if (count($anTrend) >= 7) echo '<span>' . date('M j', strtotime($anTrend[(int)floor(count($anTrend)/2)]['date'])) . '</span>'; ?>
            <span><?= $tEnd ?></span>
        </div>
        <?php else: ?>
        <p class="an-empty">No session data in the last 14 days.</p>
        <?php endif; ?>
    </div>

    <!-- Referrers -->
    <p class="an-section-label">Top Referrers</p>
    <div class="an-panel">
        <h3 class="an-panel__title" style="margin-bottom:14px;">Traffic Sources</h3>
        <?php if (empty($anReferrers)): ?>
        <p class="an-empty">No referrer data yet — most traffic is direct.</p>
        <?php else: ?>
        <table class="ref-tbl">
            <thead><tr><th>Domain</th><th>Sessions</th><th>Conv. Rate</th></tr></thead>
            <tbody>
                <?php foreach ($anReferrers as $ref): ?>
                <tr>
                    <td class="ref-domain"><?= $ref['domain'] ?></td>
                    <td><?= $ref['sessions'] ?></td>
                    <td><?= $ref['sessions'] > 0 ? round($ref['conversions']/$ref['sessions']*100).'%' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</section>
<?php endif; ?>

</body>
</html>
