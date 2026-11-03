<?php
require_once __DIR__ . '/../../src/Services/CampusAccessService.php';

$trialService ??= new \App\Src\Services\CampusAccessService();
$trialStatus ??= null;
$trialError ??= '';
if ($trialStatus === null && $trialError === '') {
    try {
        $trialStatus = $trialService->status(isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, $trialService->visitorToken());
    } catch (\Throwable $e) {
        $trialError = 'Summarization access is unavailable until the access-control setup is complete.';
    }
}

$trialExhausted = $trialStatus !== null && !$trialStatus['continued'] && $trialStatus['remaining'] === 0;
$totalAllowed = \App\Src\Services\CampusAccessService::FREE_LIMIT;
$remainingCount = $trialStatus !== null && isset($trialStatus['remaining']) ? (int)$trialStatus['remaining'] : 0;
$usedCount = max(0, min($totalAllowed, $totalAllowed - $remainingCount));
$percentUsed = $totalAllowed > 0 ? (int)round(($usedCount / $totalAllowed) * 100) : 100;

$enrollmentLink = (string)config('student_access.enrollment_url', 'https://neust.edu.ph/enrollment-procedure-and-qualifications/');
$enrollmentValid = filter_var($enrollmentLink, FILTER_VALIDATE_URL) && parse_url($enrollmentLink, PHP_URL_SCHEME) === 'https';
?>
<link rel="stylesheet" href="assets/css/student-access.css?v=<?= filemtime(__DIR__ . '/../assets/css/student-access.css') ?>">

<section class="student-access student-trial" id="student-trial" data-blocked="<?= $trialExhausted || $trialError ? '1' : '0' ?>" aria-label="Summary access">
    <!-- Active Trial Status Bar (Visible while trial is active; hidden when exhausted) -->
    <div class="student-trial__active-bar" id="student-trial-active-bar"<?= $trialExhausted ? ' hidden' : '' ?>>
        <p id="student-trial-counter" role="status" aria-live="polite"><?= htmlspecialchars($trialError ?: ($trialStatus['continued'] ? 'Continued account access · fair-use controls apply' : $remainingCount . ' free ' . ($remainingCount === 1 ? 'summary' : 'summaries') . ' remaining'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($trialStatus !== null && !$trialStatus['continued']): ?>
            <small class="student-trial__active-note">Summaries and Nutshells share these three free uses. Only successful generations use an attempt.</small>
        <?php endif; ?>
    </div>

    <!-- Dedicated Trial Completion & Account Access Experience (Visible when exhausted) -->
    <div id="student-trial-exhausted" class="trial-completion-card" <?= $trialExhausted ? '' : 'hidden' ?>>
        <div class="trial-completion__body">
            
            <!-- AREA 2 — Trial Completion Status (Left Column) -->
            <div class="trial-completion__status-col">
                <div class="trial-status-badge">
                    <span class="trial-status-badge__dot" aria-hidden="true"></span>
                    <span class="trial-status-badge__label">FREE TRIAL COMPLETED</span>
                </div>

                <h2 class="trial-status__heading" id="trial-status-heading">
                    You've used all <?= $totalAllowed ?> free <?= $totalAllowed === 1 ? 'summary' : 'summaries' ?>.
                </h2>

                <p class="trial-status__desc">
                    Thank you for exploring DEPENDED. Your guest trial has ended, but you can continue accessing the platform by registering with an approved student number.
                </p>

                <!-- Usage Progress Indicator -->
                <div class="trial-meter" role="region" aria-label="Trial summary usage progress">
                    <div class="trial-meter__header">
                        <span class="trial-meter__label" id="trial-meter-label">Summary usage</span>
                        <span class="trial-meter__count" id="trial-meter-count" aria-live="polite">
                            <strong><?= $usedCount ?></strong> / <?= $totalAllowed ?> FREE SUMMARIES USED
                        </span>
                    </div>
                    <div class="trial-meter__track" role="progressbar" id="trial-progress-bar"
                         aria-valuenow="<?= $usedCount ?>"
                         aria-valuemin="0"
                         aria-valuemax="<?= $totalAllowed ?>"
                         aria-valuetext="<?= $usedCount ?> of <?= $totalAllowed ?> free summaries used">
                        <div class="trial-meter__fill" id="trial-meter-fill" style="width: <?= $percentUsed ?>%;"></div>
                    </div>
                </div>

                <!-- Existing Results Preservation Assurance -->
                <div class="trial-preservation-callout">
                    <div class="trial-preservation-callout__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </div>
                    <div class="trial-preservation-callout__text">
                        <strong class="trial-preservation-callout__title">Existing results preserved</strong>
                        <p class="trial-preservation-callout__msg">Your previously generated summaries remain available and have not been removed.</p>
                    </div>
                </div>
            </div>

            <!-- AREA 3 — Account Access Options (Right Column) -->
            <div class="trial-completion__access-col">
                <header class="trial-access__header">
                    <h3 class="trial-access__heading">Continue with DEPENDED</h3>
                    <p class="trial-access__subheading">Choose the appropriate option to access your workspace.</p>
                </header>

                <div class="trial-access__options">
                    <!-- Option A — Student Registration (Primary & Visually Dominant) -->
                    <div class="trial-access-card trial-access-card--primary">
                        <div class="trial-access-card__header">
                            <div class="trial-access-card__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                </svg>
                            </div>
                            <div class="trial-access-card__content">
                                <span class="trial-access-card__badge">RECOMMENDED FOR STUDENTS</span>
                                <h4 class="trial-access-card__title">NEUST Peñaranda Student</h4>
                                <p class="trial-access-card__desc">Already enrolled? Register using your approved Student ID to access DEPENDED.</p>
                            </div>
                        </div>
                        <div class="trial-access-card__action">
                            <a href="<?= empty($_SESSION['user_id']) ? 'register.php' : 'profile.php' ?>" class="btn btn--primary btn--lg btn--block trial-btn-primary">
                                <svg class="trial-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                </svg>
                                <span><?= empty($_SESSION['user_id']) ? 'REGISTER AS A STUDENT' : 'VIEW MY ACCOUNT' ?></span>
                            </a>
                            <p class="trial-access-card__note">
                                <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="m6 8 1.5 1.5L10 6"/></svg>
                                <span>Instant access with active Student ID &bull; No ID photos or student OTP required.</span>
                            </p>
                        </div>
                    </div>

                    <!-- Option B — Existing Account (Secondary) -->
                    <div class="trial-access-card trial-access-card--secondary">
                        <div class="trial-access-card__header">
                            <div class="trial-access-card__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                    <polyline points="10 17 15 12 10 7"/>
                                    <line x1="15" y1="12" x2="3" y2="12"/>
                                </svg>
                            </div>
                            <div class="trial-access-card__content">
                                <h4 class="trial-access-card__title">Already have an account?</h4>
                                <p class="trial-access-card__desc">Continue where you left off by signing in to your existing account.</p>
                            </div>
                        </div>
                        <div class="trial-access-card__action">
                            <a href="login.php" class="btn btn--outline btn--lg btn--block trial-btn-secondary">
                                <span>LOG IN TO YOUR ACCOUNT</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Option C — University Enrollment Information (Understated Bottom Section) -->
        <div class="trial-completion__enrollment-row">
            <div class="trial-enrollment-panel">
                <div class="trial-enrollment-panel__info">
                    <div class="trial-enrollment-panel__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 21h18M5 21V7l7-4 7 4v14M9 10h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1"/>
                        </svg>
                    </div>
                    <div class="trial-enrollment-panel__text">
                        <strong class="trial-enrollment-panel__title">Not enrolled yet?</strong>
                        <p class="trial-enrollment-panel__desc">Learn more about university enrollment through NEUST's official admissions channels.</p>
                        <span class="trial-enrollment-panel__disclaimer">A DEPENDED account is not university enrollment. Registration requires an active Student ID in the university database.</span>
                    </div>
                </div>
                <?php if ($enrollmentValid): ?>
                <div class="trial-enrollment-panel__action">
                    <a href="<?= htmlspecialchars($enrollmentLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn--ghost trial-btn-enrollment">
                        <span>LEARN ABOUT NEUST ENROLLMENT</span>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>
                        </svg>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<script src="assets/js/student-access.js?v=<?= filemtime(__DIR__ . '/../assets/js/student-access.js') ?>" defer></script>
