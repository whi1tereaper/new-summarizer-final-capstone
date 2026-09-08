<?php
require_once __DIR__ . '/../src/whitereaper.php';
use App\Src\Services\TermsAcceptanceService;

// Build the page state first so the template can branch cleanly for guests, users, and admins.
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'GUEST';
$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
$guestTermsAccepted = !empty($_SESSION['guest_terms_accepted']);
$guestTermsReviewed = !empty($_SESSION['guest_terms_reviewed']);
$guestNeedsTermsReview = $userId === null && !$guestTermsReviewed;
$termsLink = 'terms.php?return=summarizer.php';

require_once '../src/Utils/validation.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Controllers/AnalyticsController.php';

use App\Src\Controllers\AnalyticsController;

$csrf_token = generateCsrfToken();

// ── Sidebar data ──────────────────────────────────────────────────────────────
// Load analytics data based on role. Always graceful: any DB failure → null.
$sidebarData   = null;
$sidebarMode   = null; // 'admin' | 'user'

if ($userId) {
    try {
        $ac = new AnalyticsController();
        if ($isAdmin) {
            $sidebarData = $ac->getSidebarSnapshot();
            $sidebarMode = 'admin';
        } else {
            $sidebarData = $ac->getUserSidebarStats((int)$userId);
            $sidebarMode = 'user';
        }
    } catch (\Throwable $e) {
        error_log('[summarizer sidebar] ' . $e->getMessage());
        // Non-fatal: sidebar stays null, layout stays single-column.
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARTICLE SUMMARIZER</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=Outfit:wght@600;700&family=VT323&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/index.css?v=<?= time() ?>">
</head>

<body class="page-summary-layout<?= $isAdmin ? ' page-summary-layout--admin' : '' ?><?= $sidebarData ? ' page-summary-layout--has-sidebar' : '' ?>">
    <header class="site-header">
        <div class="site-header__inner">



            <div class="site-actions">
                <span class="site-actions__welcome">Welcome, <strong><?= htmlspecialchars($username) ?></strong></span>
                <?php if ($userId): ?>
                    <?php if ($isAdmin): ?>
                        <a href="admin_dashboard.php" class="site-actions__link">Admin</a>
                        <a href="admin_analytics.php" class="site-actions__link">Analytics</a>
                    <?php endif; ?>
                    <a href="history.php" class="site-actions__link">History</a>
                    <a href="auth.php?action=logout" class="site-actions__button js-logout-link">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="site-actions__link">Login</a>
                    <a href="register.php" class="site-actions__button site-actions__button--register">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="workspace-page">
        <section class="workspace-page__content" aria-labelledby="workspace-title">


            <form id="summarize-form" action="summarize.php" method="POST" enctype="multipart/form-data" class="summary-workspace workspace-page__form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <?php 
                    // Show the latest validation or processing failure once, then clear it from the session.
                    $errorMessage = $_SESSION['flash_error'] ?? $_GET['error'] ?? null;
                    if ($errorMessage): 
                ?>
                    <div class="flash-error">
                        <?php
                            echo htmlspecialchars($errorMessage);
                            unset($_SESSION['flash_error']);
                        ?>
                    </div>
                <?php endif; ?>

                <div class="summary-panel summary-panel--page">
                    <div class="summary-panel__header">
                        <div>
                            <p class="summary-panel__eyebrow"></p>
                            <h2>Generate Article Summary</h2>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <section class="summary-card" id="source-file">
                            <label for="pdf">Source File</label>
                            <input type="file" id="pdf" name="pdf_file" accept=".pdf,.docx">
                        </section>

                        <section class="summary-card" id="output-style">
                            <label for="summary_style">Output Style</label>
                            <select id="summary_style" name="summary_style">
                                <option value="standard_paragraph" <?= ($_POST['summary_style'] ?? '') === 'standard_paragraph' ? 'selected' : '' ?>>Paragraph Summary</option>
                                <option value="bullet_points" <?= ($_POST['summary_style'] ?? '') === 'bullet_points' ? 'selected' : '' ?>>Bullet Points</option>
                                <option value="hybrid" <?= ($_POST['summary_style'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                                <option value="executive_summary" <?= ($_POST['summary_style'] ?? '') === 'executive_summary' ? 'selected' : '' ?>>Executive Summary</option>

                            </select>
                        </section>

                        <section class="summary-card summary-card--full" id="summary-length">
                            <label>Summary Length</label>
                            <?php
                                $lengthMap = [
                                    'brief'         => 3,
                                    'short'         => 5,
                                    'balanced'      => 8,
                                    'detailed'      => 11,
                                    'comprehensive' => 15,
                                ];
                                // Support both direct summary_length posted or reverse-mapping sentence_count
                                $postedLength  = strtolower(trim((string)($_POST['summary_length'] ?? '')));
                                if (array_key_exists($postedLength, $lengthMap)) {
                                    $activeLabel = $postedLength;
                                } else {
                                    $postedCount   = (int)($_POST['sentence_count'] ?? 8);
                                    $activeLabel   = 'balanced';
                                    $minDiff       = PHP_INT_MAX;
                                    foreach ($lengthMap as $lbl => $cnt) {
                                        $diff = abs($cnt - $postedCount);
                                        if ($diff < $minDiff) { $minDiff = $diff; $activeLabel = $lbl; }
                                    }
                                }
                            ?>
                            <div class="length-segmented" role="group" aria-label="Summary length">
                                <?php foreach ($lengthMap as $label => $count): ?>
                                <button
                                    type="button"
                                    class="length-seg-btn<?= $label === $activeLabel ? ' length-seg-btn--active' : '' ?>"
                                    data-length="<?= $label ?>"
                                    data-count="<?= $count ?>"
                                    aria-pressed="<?= $label === $activeLabel ? 'true' : 'false' ?>"
                                ><?= ucfirst($label) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="summary_length" name="summary_length" value="<?= htmlspecialchars($activeLabel) ?>">
                            <input type="hidden" id="sentence_count" name="sentence_count" value="<?= $lengthMap[$activeLabel] ?>">
                        </section>

                        <section class="summary-card summary-card--full" id="source-text">
                            <label for="text">Source</label>
                            <textarea id="text" name="original_text" placeholder="Paste article text/url here..."></textarea>
                            <p class="privacy-note">
                                Please do not submit private, sensitive, or confidential information unless you are allowed to do so.
                                Your submitted content will be processed to generate a summary.
                            </p>
                        </section>
                    </div>

                    <div class="summary-panel__footer">
                        <?php 
                        // Guests and users with pending terms must clear consent before the submit button becomes useful.
                        $needsAcceptance = !$userId || TermsAcceptanceService::currentUserNeedsAcceptance();
                        if ($needsAcceptance): 
                        ?>
                            <?php if ($guestNeedsTermsReview && !$userId): ?>
                                <div class="summary-gate">
                                    <p class="summary-gate__note">
                                        You must read the full Terms and Conditions before you can continue to the consent step.
                                    </p>
                                    <a href="<?= htmlspecialchars($termsLink) ?>" class="btn-submit summary-gate__button">Read Terms and Conditions</a>
                                </div>
                            <?php else: ?>
                                <label class="consent-box consent-box-inline">
                                    <input
                                        type="checkbox"
                                        id="guest_terms_accept"
                                        name="guest_terms_accept"
                                        value="1"
                                        <?= !$userId && $guestTermsAccepted ? 'checked' : '' ?>
                                        required
                                    >
                                    <span class="consent-copy">
                                        I agree to the <a href="<?= htmlspecialchars($termsLink) ?>">Terms and Conditions</a>
                                        and understand that my submitted text will be processed to generate a summary.
                                    </span>
                                </label>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="summary-actions-group">
                            <button type="submit" class="summary-submit-button" <?= (!$userId && $guestNeedsTermsReview) ? 'disabled' : '' ?>>
                                SUMMARIZE
                            </button>
                            <button type="button" id="btn-nutshell-action" class="summary-nutshell-button" <?= (!$userId && $guestNeedsTermsReview) ? 'disabled' : '' ?>>
                                NUTSHELL
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Dedicated Nutshell Result Card -->
            <section class="nutshell-panel" id="nutshell-panel" style="display: none;" aria-live="polite">
                <div class="nutshell-panel__header">
                    <div class="nutshell-badge">
                        <svg class="nutshell-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        <span>IN A NUTSHELL</span>
                    </div>
                    <button type="button" class="nutshell-copy-btn" id="nutshell-copy-btn" title="Copy to clipboard">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        <span class="copy-label">Copy</span>
                    </button>
                </div>
                <div class="nutshell-body" id="nutshell-body">
                    <div class="nutshell-loading" id="nutshell-loading" style="display: none;">
                        <div class="nutshell-spinner"></div>
                        <span>Distilling central message...</span>
                    </div>
                    <div class="nutshell-error" id="nutshell-error" style="display: none;" role="alert">
                        <div class="nutshell-error__icon">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </div>
                        <div class="nutshell-error__content">
                            <strong class="nutshell-error__title">Unable to Generate Nutshell</strong>
                            <p class="nutshell-error__message" id="nutshell-error-message"></p>
                        </div>
                        <button type="button" class="nutshell-error__retry-btn" id="nutshell-retry-btn">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 4v6h6"></path>
                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                            </svg>
                            <span>Retry</span>
                        </button>
                    </div>
                    <p class="nutshell-text" id="nutshell-text"></p>
                </div>
            </section>

        <?php if ($sidebarData): ?>
        <aside class="workspace-sidebar" aria-label="Analytics Overview">

            <?php if ($sidebarMode === 'admin'): ?>
            <!-- ── ADMIN SIDEBAR ─────────────────────────────────────────── -->
            <div class="sidebar-header">
                <span class="sidebar-header__eyebrow">Live Overview</span>
                <h2 class="sidebar-header__title">System Metrics</h2>
            </div>

            <div class="sidebar-section">
                <span class="sidebar-section__label">Summarizer Activity</span>
                <div class="sidebar-kpi-grid">
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= number_format($sidebarData['total_summaries']) ?></span>
                        <span class="sidebar-kpi__label">Total Summaries</span>
                    </div>
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= $sidebarData['summaries_today'] ?></span>
                        <span class="sidebar-kpi__label">Today</span>
                    </div>
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= number_format($sidebarData['registered_users']) ?></span>
                        <span class="sidebar-kpi__label">Registered Users</span>
                    </div>
                </div>
            </div>

            <div class="sidebar-section">
                <span class="sidebar-section__label">Traffic (7 Days)</span>
                <div class="sidebar-kpi-grid">
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= $sidebarData['sessions_today'] ?></span>
                        <span class="sidebar-kpi__label">Sessions Today</span>
                    </div>
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= $sidebarData['sessions_7d'] ?></span>
                        <span class="sidebar-kpi__label">7-Day Sessions</span>
                    </div>
                    <div class="sidebar-kpi sidebar-kpi--<?= $sidebarData['conversion_rate_7d'] >= 10 ? 'good' : ($sidebarData['conversion_rate_7d'] >= 5 ? 'warn' : 'neutral') ?>">
                        <span class="sidebar-kpi__value"><?= $sidebarData['conversion_rate_7d'] ?>%</span>
                        <span class="sidebar-kpi__label">Conversion</span>
                    </div>
                    <div class="sidebar-kpi sidebar-kpi--<?= $sidebarData['bounce_rate_7d'] > 70 ? 'bad' : ($sidebarData['bounce_rate_7d'] > 40 ? 'warn' : 'good') ?>">
                        <span class="sidebar-kpi__value"><?= $sidebarData['bounce_rate_7d'] ?>%</span>
                        <span class="sidebar-kpi__label">Bounce Rate</span>
                    </div>
                </div>
            </div>

            <div class="sidebar-section">
                <span class="sidebar-section__label">Device Split</span>
                <?php
                $deviceLabels = ['desktop' => 'Desktop', 'mobile' => 'Mobile', 'tablet' => 'Tablet'];
                foreach ($sidebarData['devices'] as $dev => $pct):
                    if ($pct === 0) continue;
                ?>
                <div class="sidebar-device-bar">
                    <span class="sidebar-device-bar__label"><?= $deviceLabels[$dev] ?? ucfirst($dev) ?></span>
                    <div class="sidebar-device-bar__track">
                        <div class="sidebar-device-bar__fill" style="width:<?= max(2, $pct) ?>%"></div>
                    </div>
                    <span class="sidebar-device-bar__pct"><?= $pct ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>

            <a href="admin_analytics.php" class="sidebar-link">Full Analytics &rarr;</a>

            <?php else: ?>
            <!-- ── USER SIDEBAR ──────────────────────────────────────────── -->
            <div class="sidebar-header">
                <span class="sidebar-header__eyebrow">Your Activity</span>
                <h2 class="sidebar-header__title">Summary Stats</h2>
            </div>

            <div class="sidebar-section">
                <span class="sidebar-section__label">Your Summaries</span>
                <div class="sidebar-kpi-grid">
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= $sidebarData['my_summaries'] ?></span>
                        <span class="sidebar-kpi__label">Total Generated</span>
                    </div>
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= $sidebarData['my_today'] ?></span>
                        <span class="sidebar-kpi__label">Today</span>
                    </div>
                </div>
            </div>

            <div class="sidebar-section">
                <span class="sidebar-section__label">Platform</span>
                <div class="sidebar-kpi-grid">
                    <div class="sidebar-kpi">
                        <span class="sidebar-kpi__value"><?= number_format($sidebarData['total_summaries']) ?></span>
                        <span class="sidebar-kpi__label">Total Summaries</span>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </aside>
        <?php endif; ?>

        </section>
    </main>

<script src="assets/js/index.js"></script>

</body>
</html>
