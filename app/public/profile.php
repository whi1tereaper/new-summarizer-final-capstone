<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Repositories/UserRepository.php';
require_once __DIR__ . '/../src/Utils/validation.php';

use App\Src\Repositories\UserRepository;

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$userId) {
    header('Location: login.php?redirect=profile.php');
    exit;
}

$profile = (new UserRepository())->findProfileById((int)$userId);
if ($profile === null) {
    http_response_code(404);
}

$username = is_array($profile) ? (string)$profile['username'] : '';
$profileInitial = $username !== '' ? strtoupper(substr($username, 0, 1)) : '';
$createdAt = is_array($profile)
    ? (new DateTimeImmutable((string)$profile['created_at']))->format('F j, Y')
    : '';
$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
$workspaceHref = $isAdmin ? 'admin_dashboard.php' : 'dashboard.php';
$workspaceLabel = $isAdmin ? 'Admin dashboard' : 'Dashboard';
$studentApplication=null;$studentContinued=false;
if(!$isAdmin&&$profile!==null){
    require_once __DIR__.'/../src/Services/StudentVerificationService.php';
    try{$studentApplication=(new \App\Src\Services\StudentVerificationService())->forUser((int)$userId);$studentContinued=(new \App\Src\Services\CampusAccessService())->continuedAccess((int)$userId);}catch(\Throwable $ignored){}
}
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Profile — LIGHT</title>
    <meta name="description" content="View your LIGHT account information.">
    <script>try{var t=localStorage.getItem('light-results-theme');if(t!=='light'&&t!=='dark')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.dataset.theme=t;}catch(e){}</script>
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/site-nav.css">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
    <link rel="stylesheet" href="assets/css/profile.css?v=<?= filemtime(__DIR__ . '/assets/css/profile.css') ?>">
</head>
<body class="profile-page">
    <?php require __DIR__ . '/partials/site-nav.php'; ?>

    <main class="profile-shell">
        <p class="nex-section-label">Your account</p>
        <h1 class="profile-page__title">Profile</h1>

        <div class="profile-layout">
            <aside class="profile-sidebar" aria-label="Account sidebar">
                <p class="profile-sidebar__heading">Account</p>
                <nav class="profile-sidebar__nav" aria-label="Account navigation">
                    <a class="profile-sidebar__link" href="<?= htmlspecialchars($workspaceHref, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($workspaceLabel, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a class="profile-sidebar__link" href="history.php">History</a>
                    <a class="profile-sidebar__link is-active" href="profile.php" aria-current="page">Profile</a>
                </nav>
            </aside>

            <?php if ($profile !== null): ?>
                <div class="profile-main">
                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="profile-message profile-message--error" role="alert">
                            <?= htmlspecialchars((string)$_SESSION['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <section class="profile-card" aria-labelledby="profile-card-title">
                        <div class="profile-card__identity">
                            <span class="profile-initial" aria-hidden="true"><?= htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8') ?></span>
                            <div>
                                <p class="profile-card__eyebrow">Account profile</p>
                                <h2 id="profile-card-title" class="profile-card__username"><?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                            </div>
                        </div>

                        <dl class="profile-details">
                            <div class="profile-details__row">
                                <dt>Email</dt>
                                <dd><?= htmlspecialchars((string)$profile['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                            </div>
                            <div class="profile-details__row">
                                <dt>Username</dt>
                                <dd><?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                            </div>
                            <div class="profile-details__row">
                                <dt>Account created</dt>
                                <dd><time datetime="<?= htmlspecialchars((string)$profile['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></time></dd>
                            </div>
                        </dl>
                    </section>

                    <?php if(!$isAdmin): ?><section class="profile-card" aria-labelledby="student-access-title"><h2 id="student-access-title">Student access</h2><p data-student-status="<?= $studentContinued?'verified':'restricted' ?>"><?= $studentContinued?'Approved — your Student ID has an active database match.':'Restricted — there is no active approved Student ID match for this account. Your account and summaries are preserved.' ?></p><?php if($studentApplication): ?><p>Student ID: <?= htmlspecialchars(\App\Src\Services\StudentVerificationService::masked($studentApplication['student_number']),ENT_QUOTES,'UTF-8') ?></p><?php endif; ?><p>Student ID eligibility is managed by the system administrator. No additional verification form, document, student OTP or manual approval is required.</p></section><?php endif; ?>

                    <section class="profile-card profile-deactivation" aria-labelledby="profile-deactivation-title">
                        <h2 id="profile-deactivation-title" class="profile-deactivation__title">Deactivate account</h2>
                        <p class="profile-deactivation__description">
                            Your account will be signed out and you will not be able to sign in while it is deactivated.
                            Your saved summaries will be retained.
                        </p>
                        <form method="post" action="auth.php?action=deactivateAccount">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="profile-deactivation__field">
                                <label for="deactivation-password">Confirm your current password</label>
                                <div class="profile-deactivation__input-wrap">
                                    <input
                                        class="profile-deactivation__input"
                                        type="password"
                                        id="deactivation-password"
                                        name="password"
                                        autocomplete="current-password"
                                        maxlength="72"
                                        required
                                    >
                                    <button class="pw-toggle" type="button" aria-label="Show password" aria-pressed="false">
                                        <svg class="pw-icon pw-icon--show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="pw-icon pw-icon--hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </div>
                            <button class="profile-deactivation__button" type="submit">Deactivate account</button>
                        </form>
                    </section>
                </div>
            <?php else: ?>
                <section class="profile-card profile-card--error" role="alert">
                    <h2>Profile unavailable</h2>
                    <p>We could not find an active account for this session. Sign out, then sign in again.</p>
                    <a href="auth.php?action=logout" class="js-logout-link">Sign out</a>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <?php require __DIR__ . '/partials/site-footer.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleButtons = document.querySelectorAll('.profile-deactivation__input-wrap .pw-toggle');
            toggleButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const input = button.closest('.profile-deactivation__input-wrap')?.querySelector('input');
                    if (!input) return;

                    const isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';
                    button.classList.toggle('pw-visible', isPassword);
                    button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                    button.setAttribute('aria-pressed', String(isPassword));
                });
            });
        });
    </script>
</body>
</html>
