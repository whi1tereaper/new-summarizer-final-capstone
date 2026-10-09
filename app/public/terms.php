<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';
require_once __DIR__ . '/../src/Utils/TermsContent.php';

use App\Src\Utils\TermsContent;

$sections = TermsContent::getSections();
$csrfToken = generateCsrfToken();
$allowedReturnTargets = ['index.php', 'summarizer.php'];
$requestedReturnTarget = (string)($_POST['return_to'] ?? $_GET['return'] ?? 'index.php');
$returnTarget = in_array($requestedReturnTarget, $allowedReturnTargets, true) ? $requestedReturnTarget : 'index.php';
$needsTermsReview = \App\Src\Services\TermsAcceptanceService::currentUserNeedsAcceptance();
$showTermsActions = true;
$errorMessage = '';
$noticeMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showTermsActions) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errorMessage = 'Unauthorized request.';
    } elseif (isset($_POST['decline_terms'])) {
        require_once __DIR__ . '/../src/Services/RememberMeService.php';
        require_once __DIR__ . '/../src/Services/GuestSessionService.php';
        \App\Src\Services\RememberMeService::clearCookie();
        (new \App\Src\Services\GuestSessionService())->clearGuestIdentity();
        \App\Src\Utils\SessionManager::destroy();
        \App\Src\Utils\SessionManager::start();
        $_SESSION['error'] = 'You must accept the Terms and Conditions before using the summarizer.';
        header('Location: login.php');
        exit;
    } elseif (!isAcceptedCheckboxValue($_POST['accept_terms'] ?? null)) {
        $errorMessage = 'You must accept the Terms and Conditions before continuing.';
    } elseif (!empty($_SESSION['user_id'])) {
        $termsService = new \App\Src\Services\TermsAcceptanceService();
        try {
            if ($termsService->acceptCurrentUserTerms()) {
                rotateCsrfToken();
                header('Location: ' . \App\Src\Services\TermsAcceptanceService::redirectAfterTermsAcceptance());
                exit;
            }

            $errorMessage = 'Unable to save your acceptance. Please try again.';
        } catch (\Throwable $throwable) {
            error_log('[TermsAcceptance] Failed to save acceptance: ' . $throwable->getMessage());
            $errorMessage = 'Unable to save your acceptance. Please try again.';
        }
    } else {
        $_SESSION['guest_terms_reviewed'] = true;
        $_SESSION['guest_terms_reviewed_at'] = date('Y-m-d H:i:s');
        $_SESSION['guest_terms_accepted'] = true;
        $_SESSION['guest_terms_accepted_at'] = date('Y-m-d H:i:s');
        rotateCsrfToken();
        header('Location: ' . \App\Src\Services\TermsAcceptanceService::redirectAfterTermsAcceptance());
        exit;
    }
}

$backHref = $returnTarget;
$backLabel = empty($_SESSION['user_id'])
    ? 'Back to Summarizer'
    : 'Back';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms and Conditions — LIGHT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css?v=terms-3">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
</head>
<body>
    <?php require __DIR__ . '/partials/site-nav.php'; ?>
    <main class="editorial-layout terms-shell">
        <nav class="terms-nav">
            <a href="<?= htmlspecialchars($backHref) ?>"><?= htmlspecialchars($backLabel) ?></a>
        </nav>

        <header class="terms-header">
            <h1>Terms and Conditions</h1>
            <p>
                Please read these terms carefully before using the Article Summarization Software.
                The language below is intentionally simple so it is easy to understand.
            </p>
            <?php if ($showTermsActions): ?>
                <p class="privacy-note">
                    Review every section below. You can confirm your agreement here when you are ready to continue.
                </p>
            <?php endif; ?>
        </header>

        <?php if ($errorMessage !== ''): ?>
            <div class="message message-error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>
        <?php if ($noticeMessage !== ''): ?>
            <div class="message message-error" role="status"><?= htmlspecialchars($noticeMessage) ?></div>
        <?php endif; ?>

        <section class="terms-card terms-scroll-container" aria-label="Terms and Conditions">
            <?php foreach ($sections as $index => $section): ?>
                <article class="terms-section">
                    <h2><?= (int)($index + 1) ?>. <?= htmlspecialchars($section['heading']) ?></h2>
                    <p><?= htmlspecialchars($section['body']) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($showTermsActions): ?>
            <form method="POST" action="terms.php?return=<?= htmlspecialchars(urlencode($returnTarget)) ?>" class="terms-actions" id="terms-actions">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTarget) ?>">
                <div class="consent-box consent-box-standalone">
                    <input type="checkbox" id="accept_terms_checkbox" name="accept_terms" value="1" required data-terms-checkbox>
                    <span class="consent-copy">
                        <label for="accept_terms_checkbox">I have read, understood, and agree to the Terms and Conditions.</label>
                    </span>
                </div>
                <div class="terms-action-buttons">
                    <button id="btn_accept_terms" type="submit" class="btn-auth" disabled data-terms-accept>Accept &amp; Continue</button>
                    <button id="btn_decline_terms" type="button" class="btn-auth btn-auth-secondary">No, I Don't Accept</button>
                </div>
                <div id="decline_terms_notice" class="message message-error" role="alert" hidden>
                    You must accept the terms to use the summarization feature.
                </div>
            </form>
        <?php endif; ?>

    </main>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const termsCheckbox = document.querySelector('[data-terms-checkbox]');
            const acceptButton = document.querySelector('[data-terms-accept]');

            if (termsCheckbox && acceptButton) {
                acceptButton.disabled = !termsCheckbox.checked;
                termsCheckbox.addEventListener('change', () => {
                    acceptButton.disabled = !termsCheckbox.checked;
                    acceptButton.classList.toggle('opacity-50', !termsCheckbox.checked);
                    acceptButton.classList.toggle('cursor-not-allowed', !termsCheckbox.checked);
                });
            }

            const declineButton = document.getElementById('btn_decline_terms');
            const declineNotice = document.getElementById('decline_terms_notice');
            if (declineButton && declineNotice) {
                declineButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    declineNotice.hidden = false;
                    declineButton.disabled = true;
                    window.setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 1200);
                });
            }
        });
    </script>
<?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
