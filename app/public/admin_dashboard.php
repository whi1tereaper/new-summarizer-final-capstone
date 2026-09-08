<?php
// admin-only page
require_once __DIR__ . '/../src/whitereaper.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') !== 'admin') {
    header('Location: login.php?context=admin');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

if (($_GET['refresh'] ?? '') === '1') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AdminController.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';

use App\Src\Controllers\AdminController;
use App\Src\Controllers\AnalyticsController;
use App\Src\Controllers\FeedbackHandler;

$admin = new AdminController();
$users = $admin->getAllUsers();
$stats = $admin->getSystemStats();
$orphanStats = $admin->getUploadOrphanStats();
$feedbackStats = $admin->getFeedbackStats();
$allFeedback = FeedbackHandler::getAllFeedbackForAdmin();
require_once __DIR__ . '/../src/Utils/validation.php';
$csrf_token = generateCsrfToken();

// Landing page analytics — gracefully degrade if tables haven't been migrated yet.
try {
    $analytics = new AnalyticsController();
    $analyticsKpis      = $analytics->getKpis();
    $analyticsDevices   = $analytics->getDeviceBreakdown();
    $analyticsFunnel    = $analytics->getScrollFunnel();
    $analyticsTrend     = $analytics->getDailyTrend();
    $analyticsReferrers = $analytics->getTopReferrers();
    $analyticsAvailable = true;
} catch (Throwable $e) {
    $analyticsAvailable = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .admin-section { margin-bottom: 60px; }
        .admin-section h2 {
            font-family: 'Inter', sans-serif;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-muted);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        .admin-section h2::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--color-border);
            margin-left: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.74);
            border: 1px solid var(--color-border);
            padding: 25px;
            box-shadow: var(--shadow-soft);
        }
        .stat-card .stat-value {
            font-family: 'Merriweather', serif;
            font-size: 2.5em;
            font-weight: 700;
            color: var(--color-text);
        }
        .stat-card .stat-label {
            font-family: 'Inter', sans-serif;
            font-size: 0.7em;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--color-muted);
            margin-top: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Inter', sans-serif;
            font-size: 0.85em;
        }
        th {
            text-align: left;
            font-size: 0.75em;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--color-muted);
            border-bottom: 2px solid var(--color-primary-dark);
            padding: 10px 12px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid var(--color-border);
            color: #2A2436;
        }
        tr:hover td { background: rgba(242, 230, 238, 0.66); }

        .role-badge {
            display: inline-block;
            padding: 3px 10px;
            font-size: 0.75em;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .role-admin { background: var(--gradient-button); color: #fff; }
        .role-user { background: rgba(230, 218, 255, 0.88); color: var(--color-primary-dark); }

        .status-active { color: var(--color-primary-dark); }
        .status-inactive { color: var(--color-danger); }

        .admin-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-bottom: 2px solid var(--color-primary-dark);
            margin-bottom: 50px;
            padding-bottom: 15px;
        }
        .admin-toolbar h1 {
            border-bottom: none;
            margin: 0;
            padding: 0;
            color: var(--color-text);
        }
        .hard-refresh-btn {
            background: var(--gradient-button);
            color: #fff;
            border: none;
            padding: 10px 15px;
            text-transform: uppercase;
            font-size: 0.75em;
            letter-spacing: 1px;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
        }
        @media (max-width: 640px) {
            .admin-toolbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        /* ─── Admin Responsive ─── */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 18px;
            }

            .stat-card {
                padding: 20px;
            }

            .stat-card .stat-value {
                font-size: 2em;
            }
        }

        @media (max-width: 768px) {
            .admin-toolbar h1 {
                font-size: 1.5em;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 14px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-card .stat-value {
                font-size: 1.6em;
            }

            table {
                font-size: 0.78em;
            }

            th, td {
                padding: 8px;
            }
        }

        @media (max-width: 576px) {
            .admin-toolbar h1 {
                font-size: 1.25em;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-card .stat-value {
                font-size: 1.4em;
            }

            .stat-card .stat-label {
                font-size: 0.65em;
            }

            table {
                font-size: 0.72em;
                min-width: 600px;
            }

            th, td {
                padding: 6px 8px;
                white-space: nowrap;
            }
        }
    </style>
</head>
<body>
    <div class="editorial-layout">
        <nav style="margin-bottom: 40px; display:flex; gap:20px; flex-wrap:wrap;">
            <a href="index.php" style="text-decoration:none; font-size:0.8em; color:var(--color-muted); font-family:'Inter'; text-transform:uppercase; letter-spacing:1px;">&larr; Dashboard</a>
            <a href="admin_analytics.php" style="text-decoration:none; font-size:0.8em; color:var(--color-primary-dark); font-family:'Inter'; text-transform:uppercase; letter-spacing:1px;">Analytics</a>
            <a href="admin_audit_logs.php" style="text-decoration:none; font-size:0.8em; color:var(--color-primary-dark); font-family:'Inter'; text-transform:uppercase; letter-spacing:1px;">Audit Logs</a>
            <a href="admin_challenge_change.php" style="text-decoration:none; font-size:0.8em; color:var(--color-primary-dark); font-family:'Inter'; text-transform:uppercase; letter-spacing:1px;">Update Security Challenge</a>
            <a href="auth.php?action=logout" class="js-logout-link" style="text-decoration:none; font-size:0.8em; color:var(--color-danger); font-family:'Inter'; text-transform:uppercase; letter-spacing:1px;">Logout</a>
        </nav>

        <div class="admin-toolbar">
            <h1>Administrative Console</h1>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div style="background:rgba(242, 238, 255, 0.88); color:var(--color-primary-dark); padding:15px; margin-bottom:20px; font-family:'Inter'; font-size:0.85em; border-left:4px solid var(--color-primary-dark);">
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div style="background:var(--color-danger-soft); color:var(--color-danger); padding:15px; margin-bottom:20px; font-family:'Inter'; font-size:0.85em; border-left:4px solid var(--color-danger);">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="admin-section">
            <h2>System Overview</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['user_count'] ?></div>
                    <div class="stat-label">Registered Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['summary_count'] ?></div>
                    <div class="stat-label">Total Summaries</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['guest_user_count'] ?></div>
                    <div class="stat-label">Guest Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $feedbackStats['total_ratings'] ?></div>
                    <div class="stat-label">Total Ratings</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $feedbackStats['avg_rating'] !== null ? number_format($feedbackStats['avg_rating'], 1) . ' &#9733;' : '&mdash;' ?></div>
                    <div class="stat-label">Avg Star Rating</div>
                </div>
            </div>
        </div>

        <div class="admin-section">
            <h2>Storage & Maintenance</h2>
            


            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $orphanStats['size_mb'] ?> MB</div>
                    <div class="stat-label">Uploads Folder Size</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $orphanStats['count'] ?></div>
                    <div class="stat-label">Total Uploaded Files</div>
                </div>
            </div>
            

        </div>

        <div class="admin-section">
            <h2>User Management</h2>
            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Accepted Terms</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['id'] ?></td>
                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><span class="role-badge role-<?= htmlspecialchars($user['role']) ?>"><?= htmlspecialchars($user['role']) ?></span></td>
                        <td class="<?= $user['active'] ? 'status-active' : 'status-inactive' ?>"><?= $user['active'] ? 'Active' : 'Inactive' ?></td>
                        <td><?= (int)($user['terms_accepted'] ?? 0) === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                        <td>
                            <?php
                            $isSelf = (int)$user['id'] === (int)$_SESSION['user_id'];
                            $isAdmin = $user['role'] === 'admin';
                            ?>
                            <?php if ($isSelf): ?>
                                <span style="font-size:0.72em; color:var(--color-muted); text-transform:uppercase; letter-spacing:1px;">(You)</span>
                            <?php elseif ($isAdmin): ?>
                                <span style="font-size:0.72em; color:var(--color-muted); text-transform:uppercase; letter-spacing:1px;">Admin</span>
                            <?php else: ?>
                                <?php if ($user['active']): ?>
                                    <form method="POST" action="admin_dashboard.php?action=deactivate_user" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <button type="submit"
                                            onclick="return confirm('Deactivate user <?= htmlspecialchars(addslashes($user['username'])) ?>? They will be locked out but their data is kept.');"
                                            style="background:transparent; color:var(--color-primary-dark); border:none; text-transform:uppercase; font-size:0.72em; letter-spacing:1px; font-weight:600; cursor:pointer; padding:0; margin-right:10px; text-decoration:underline;">
                                            Deactivate
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" action="admin_dashboard.php?action=delete_user" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                    <button type="submit"
                                        onclick="return confirm('WARNING: Permanently delete user <?= htmlspecialchars(addslashes($user['username'])) ?> and ALL their data? This cannot be undone.');"
                                        style="background:transparent; color:var(--color-danger); border:none; text-transform:uppercase; font-size:0.72em; letter-spacing:1px; font-weight:600; cursor:pointer; padding:0; text-decoration:underline;">
                                        Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="admin-section">
            <h2>User Feedback</h2>
            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Summary ID</th>
                        <th>Summary Title</th>
                        <th>Identifier</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Impression</th>
                        <th>Comment</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allFeedback as $fb): 
                        $identifier = $fb['user_id'] ? 'User #' . $fb['user_id'] : 'Guest';
                        $impressionMap = [
                            'very_satisfied' => 'Very Satisfied',
                            'satisfied' => 'Satisfied',
                            'unsatisfied' => 'Unsatisfied',
                            'very_unsatisfied' => 'Very Unsatisfied'
                        ];
                    ?>
                    <tr>
                        <td><?= $fb['summary_id'] ?></td>
                        <td title="<?= htmlspecialchars($fb['article_title'] ?? 'Deleted Summary') ?>">
                            <?php if (!empty($fb['article_title'])): ?>
                                <a href="result.php?id=<?= $fb['summary_id'] ?>" target="_blank" style="color:var(--color-primary-dark); font-weight:600;">
                                    <?= htmlspecialchars(mb_strimwidth($fb['article_title'], 0, 30, "...")) ?>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--color-muted); font-style:italic;">Deleted Summary</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $identifier ?></td>
                        <td><?= htmlspecialchars(($fb['name'] ?? '') ?: ($fb['account_username'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars(($fb['email'] ?? '') ?: ($fb['account_email'] ?? '-')) ?></td>
                        <td>
                            <?php 
                                $fbImpression = $fb['impression'] ?? '';
                                $isUnsatisfied = str_contains($fbImpression, 'unsatisfied');
                                $displayText = $impressionMap[$fbImpression] ?? (($fb['rating'] ?? null) ? $fb['rating'] . ' Stars' : '-');
                            ?>
                            <span style="font-size:0.8em; font-weight:600; text-transform:uppercase; color:<?= $isUnsatisfied ? 'var(--color-danger)' : 'var(--color-primary-dark)' ?>">
                                <?= $displayText ?>
                            </span>
                        </td>
                        <td title="<?= htmlspecialchars($fb['comment'] ?? '') ?>">
                            <?= htmlspecialchars(mb_strimwidth($fb['comment'] ?? '', 0, 50, "...")) ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($fb['created_at'] ?? 'now')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allFeedback)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; color:var(--color-muted);">No feedback submitted yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="admin-section">
            <h2>Feedback Comments Repository</h2>
            <div class="comments-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <?php 
                foreach ($allFeedback as $fb): 
                    $userName = ($fb['name'] ?? '') ?: ($fb['account_username'] ?? 'Anonymous');
                    $commentText = trim($fb['comment'] ?? '');
                ?>
                    <div class="comment-card" style="background: #fff; border: 1px solid var(--color-border); padding: 20px; border-radius: 0; position: relative;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <div>
                                <strong style="display: block; font-size: 1.1em; color: var(--color-text);"><?= htmlspecialchars($userName) ?></strong>
                                <span style="font-size: 0.75em; color: var(--color-muted); text-transform: uppercase;"><?= date('M d, Y', strtotime($fb['created_at'])) ?></span>
                            </div>
                            <span style="font-size: 0.7em; background: rgba(143, 63, 224, 0.1); color: var(--color-primary-dark); padding: 4px 8px; border-radius: 0; font-weight: 600; text-transform: uppercase;">
                                <?= $fb['impression'] ? str_replace('_', ' ', $fb['impression']) : (($fb['rating'] ?? null) ? $fb['rating'] . ' Stars' : 'Rating Only') ?>
                            </span>
                        </div>
                        <p style="font-size: 0.9em; line-height: 1.6; color: <?= $commentText ? '#4b5563' : '#9ca3af' ?>; margin: 0; font-style: italic;">
                            <?= $commentText ? '"' . htmlspecialchars($commentText) . '"' : '(No detailed comment provided)' ?>
                        </p>
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f3f4f6; font-size: 0.8em; color: var(--color-muted);">
                            Refers to Summary: 
                            <?php if (!empty($fb['article_title'])): ?>
                                <a href="result.php?id=<?= $fb['summary_id'] ?>" target="_blank" style="color: var(--color-primary-dark); text-decoration: underline;"><?= htmlspecialchars(mb_strimwidth($fb['article_title'], 0, 40, "...")) ?></a>
                            <?php else: ?>
                                <span style="font-style:italic;">(Deleted Summary)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($allFeedback)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--color-muted); border: 1px dashed var(--color-border); border-radius: 0;">
                        No feedback has been submitted yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div style="border-top: 1px solid var(--color-border); padding-top: 20px; margin-top: 40px;">
            <a href="index.php" style="font-family:'Inter'; font-size:0.8em; color:var(--color-primary-dark); text-decoration:underline; text-transform:uppercase; letter-spacing:1px;">Return to Dashboard</a>
        </div>

        <?php if (!$analyticsAvailable): ?>
        <div class="admin-section">
            <h2>Landing Page Analytics</h2>
            <p style="font-family:'Inter'; font-size:0.85em; color:var(--color-muted); padding:20px; border:1px dashed var(--color-border); text-align:center;">
                Analytics tables not yet installed. Run <code>database/analytics_schema.sql</code> to enable this section.
            </p>
        </div>
        <?php else: ?>
        <div class="admin-section" id="landing-analytics">
            <h2>Landing Page Analytics</h2>

            <?php
            // ── KPI helpers ──────────────────────────────────────────────────
            $lcpMs       = $analyticsKpis['median_lcp_ms'];
            $lcpLabel    = $lcpMs === null ? '—' : number_format($lcpMs) . ' ms';
            $lcpStatus   = 'lcp-good';
            if ($lcpMs !== null) {
                if ($lcpMs > 4000)      $lcpStatus = 'lcp-poor';
                elseif ($lcpMs > 2500)  $lcpStatus = 'lcp-needs-improvement';
            }

            $avgDwell  = $analyticsKpis['avg_dwell_seconds'];
            $dwellFmt  = $avgDwell > 0 ? gmdate('i:s', (int)$avgDwell) : '0:00';
            ?>

            <!-- ── KPI Cards ── -->
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['sessions_7d'] ?></div>
                    <div class="stat-label">Sessions (7 days)</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['sessions_today'] ?></div>
                    <div class="stat-label">Sessions Today</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['conversion_rate'] ?>%</div>
                    <div class="stat-label">CTA Conversion Rate</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $dwellFmt ?></div>
                    <div class="stat-label">Avg Dwell Time</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value <?= $lcpStatus ?>"><?= $lcpLabel ?></div>
                    <div class="stat-label">Median LCP</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value"><?= $analyticsKpis['bounce_rate'] ?>%</div>
                    <div class="stat-label">Bounce Rate</div>
                </div>

            </div><!-- /.stats-grid -->

            <!-- ── Two-column grid: Device + Funnel ── -->
            <div class="analytics-two-col">

                <!-- Device Breakdown -->
                <div class="analytics-panel">
                    <h3 class="analytics-panel__title">Device Breakdown</h3>
                    <?php foreach ($analyticsDevices as $dev => $pct): ?>
                    <div class="device-row">
                        <span class="device-row__label"><?= ucfirst($dev) ?></span>
                        <div class="device-row__bar-wrap">
                            <div class="device-row__bar" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="device-row__pct"><?= $pct ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Scroll Funnel -->
                <div class="analytics-panel">
                    <h3 class="analytics-panel__title">Scroll Depth Funnel</h3>
                    <div class="funnel-steps">
                        <?php
                        $funnelLabels = [25 => 'Scrolled 25%', 50 => 'Scrolled 50%', 75 => 'Scrolled 75%', 100 => 'Full Page'];
                        foreach ($analyticsFunnel as $milestone => $pct):
                        ?>
                        <div class="funnel-step">
                            <div class="funnel-step__bar-wrap">
                                <div class="funnel-step__bar" style="height:<?= $pct ?>%;"></div>
                            </div>
                            <div class="funnel-step__pct"><?= $pct ?>%</div>
                            <div class="funnel-step__label"><?= $funnelLabels[$milestone] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div><!-- /.analytics-two-col -->

            <!-- ── Daily Sessions Sparkline ── -->
            <div class="analytics-panel" style="margin-bottom:30px;">
                <h3 class="analytics-panel__title">Daily Sessions — Last 14 Days</h3>
                <?php
                $trendValues = array_column($analyticsTrend, 'sessions');
                $trendMax    = max(array_merge([1], $trendValues)); // avoid div-by-zero
                $svgW = 600;
                $svgH = 80;
                $pts  = [];
                $barW = floor($svgW / count($trendValues));
                foreach ($trendValues as $i => $v) {
                    $x = $i * $barW + $barW / 2;
                    $y = $svgH - round(($v / $trendMax) * ($svgH - 10)) - 2;
                    $pts[] = "$x,$y";
                }
                $polyline = implode(' ', $pts);
                $areaPath = 'M ' . $pts[0];
                foreach (array_slice($pts, 1) as $p) $areaPath .= ' L ' . $p;
                $lastPt   = end($pts); list($lx) = explode(',', $lastPt);
                $firstPt  = reset($pts); list($fx) = explode(',', $firstPt);
                $areaPath .= " L {$lx},{$svgH} L {$fx},{$svgH} Z";
                ?>
                <div class="sparkline-wrap">
                    <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="none"
                         xmlns="http://www.w3.org/2000/svg" class="sparkline-svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="sparkGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%"   stop-color="#977DFF" stop-opacity="0.25"/>
                                <stop offset="100%" stop-color="#977DFF" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <path d="<?= $areaPath ?>" fill="url(#sparkGrad)"/>
                        <polyline points="<?= $polyline ?>" fill="none"
                                  stroke="#7F63F4" stroke-width="2" stroke-linejoin="round"/>
                    </svg>
                    <div class="sparkline-dates">
                        <span><?= date('M j', strtotime($analyticsTrend[0]['date'])) ?></span>
                        <span><?= date('M j', strtotime(end($analyticsTrend)['date'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- ── Top Referrers ── -->
            <div class="analytics-panel">
                <h3 class="analytics-panel__title">Top Referrers</h3>
                <?php if (empty($analyticsReferrers)): ?>
                    <p style="font-size:0.85em; color:var(--color-muted); margin:0;">No referrer data yet — most traffic is direct.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Sessions</th>
                            <th>Conversions</th>
                            <th>Conv. Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analyticsReferrers as $ref): ?>
                        <tr>
                            <td style="font-family:monospace; font-size:0.88em;"><?= $ref['domain'] ?></td>
                            <td><?= $ref['sessions'] ?></td>
                            <td><?= $ref['conversions'] ?></td>
                            <td><?= $ref['sessions'] > 0 ? round($ref['conversions'] / $ref['sessions'] * 100, 1) . '%' : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /#landing-analytics -->
        <?php endif; ?>

        <style>
            /* ── Analytics-specific styles ──────────────────────────────────── */
            .lcp-good  { color: #1a7f47; }
            .lcp-needs-improvement { color: #b45309; }
            .lcp-poor  { color: var(--color-danger); }

            .analytics-two-col {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                margin-bottom: 30px;
            }
            @media (max-width: 720px) {
                .analytics-two-col { grid-template-columns: 1fr; }
            }

            .analytics-panel {
                background: rgba(255,255,255,0.72);
                border: 1px solid var(--color-border);
                padding: 22px 24px;
                box-shadow: var(--shadow-soft);
            }
            .analytics-panel__title {
                font-family: 'Inter', sans-serif;
                font-size: 0.72em;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 1.5px;
                color: var(--color-muted);
                margin: 0 0 18px;
            }

            /* Device bars */
            .device-row {
                display: grid;
                grid-template-columns: 68px 1fr 40px;
                align-items: center;
                gap: 10px;
                margin-bottom: 12px;
            }
            .device-row__label {
                font-family: 'Inter', sans-serif;
                font-size: 0.78em;
                color: var(--color-text);
                font-weight: 600;
            }
            .device-row__bar-wrap {
                background: var(--color-soft-bg);
                height: 8px;
                border-radius: 0;
                overflow: hidden;
            }
            .device-row__bar {
                height: 100%;
                background: var(--gradient-button);
                border-radius: 0;
                transition: width 0.4s ease;
            }
            .device-row__pct {
                font-family: 'Inter', sans-serif;
                font-size: 0.75em;
                color: var(--color-muted);
                text-align: right;
            }

            /* Scroll funnel columns */
            .funnel-steps {
                display: flex;
                align-items: flex-end;
                gap: 14px;
                height: 110px;
            }
            .funnel-step {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                height: 100%;
                justify-content: flex-end;
            }
            .funnel-step__bar-wrap {
                width: 100%;
                background: var(--color-soft-bg);
                height: 72px;
                display: flex;
                align-items: flex-end;
                overflow: hidden;
            }
            .funnel-step__bar {
                width: 100%;
                background: var(--gradient-button);
                min-height: 2px;
                transition: height 0.4s ease;
            }
            .funnel-step__pct {
                font-family: 'Merriweather', serif;
                font-size: 0.95em;
                font-weight: 700;
                color: var(--color-primary-dark);
                margin-top: 6px;
            }
            .funnel-step__label {
                font-family: 'Inter', sans-serif;
                font-size: 0.62em;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                color: var(--color-muted);
                text-align: center;
                margin-top: 4px;
            }

            /* Sparkline */
            .sparkline-wrap {
                width: 100%;
                overflow: hidden;
            }
            .sparkline-svg {
                width: 100%;
                height: 80px;
                display: block;
            }
            .sparkline-dates {
                display: flex;
                justify-content: space-between;
                font-family: 'Inter', sans-serif;
                font-size: 0.7em;
                color: var(--color-muted);
                margin-top: 4px;
            }
        </style>

        <div style="border-top: 1px solid var(--color-border); padding-top: 20px; margin-top: 40px;">
            <a href="index.php" style="font-family:'Inter'; font-size:0.8em; color:var(--color-primary-dark); text-decoration:underline; text-transform:uppercase; letter-spacing:1px;">Return to Dashboard</a>
        </div>
    </div>
    <script src="assets/js/index.js"></script>
</body>
</html>
