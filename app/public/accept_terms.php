<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';
require_once __DIR__ . '/../src/Utils/TermsContent.php';
require_once __DIR__ . '/../src/Services/TermsAcceptanceService.php';

use App\Src\Services\TermsAcceptanceService;
use App\Src\Utils\TermsContent;

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$csrfToken = generateCsrfToken();
$sections = TermsContent::getSections();
$termsService = new TermsAcceptanceService();
$errorMessage = '';

if (!$userId) {
    http_response_code(403);
    $errorMessage = 'Unauthorized request.';
} elseif (!TermsAcceptanceService::currentUserNeedsAcceptance()) {
    header('Location: ' . TermsAcceptanceService::redirectAfterTermsAcceptance());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage === '') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Unauthorized request.';
        http_response_code(403);
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
        $errorMessage = 'You must accept the Terms and Conditions before using the summarizer.';
    } else {
        try {
            if ($termsService->acceptCurrentUserTerms()) {
                rotateCsrfToken();
                header('Location: ' . TermsAcceptanceService::redirectAfterTermsAcceptance());
                exit;
            }

            $errorMessage = 'Unable to save your acceptance. Please try again.';
        } catch (\Throwable $throwable) {
            error_log('[TermsAcceptance] Failed to save acceptance: ' . $throwable->getMessage());
            $errorMessage = 'Unable to save your acceptance. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Terms and Conditions — LIGHT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/design-tokens.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/site-footer.css?v=nex-8">
</head>
<body>
    <?php require __DIR__ . '/partials/site-nav.php'; ?>
    <main class="editorial-layout terms-shell">
        <nav class="terms-nav">
            <a href="auth.php?action=logout" class="js-logout-link">Logout</a>
        </nav>

        <header class="terms-header">
            <h1>Terms and Conditions</h1>
            <p>
                Your account needs a one-time confirmation before you can continue using the software.
                Review the terms below, then confirm your acceptance.
            </p>
        </header>

        <?php if ($errorMessage !== ''): ?>
            <div class="message message-error">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="terms-card terms-scroll-container" aria-label="Terms and Conditions">
            <?php foreach ($sections as $index => $section): ?>
                <article class="terms-section">
                    <h2><?= (int)($index + 1) ?>. <?= htmlspecialchars($section['heading']) ?></h2>
                    <p><?= htmlspecialchars($section['body']) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($errorMessage !== 'Unauthorized request.'): ?>
            <form method="POST" action="accept_terms.php" class="terms-actions">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
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
    <script src="assets/js/index.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const termsCheckbox = document.querySelector('[data-terms-checkbox]');
            const acceptButton = document.querySelector('[data-terms-accept]');
            if (termsCheckbox && acceptButton) {
                acceptButton.disabled = !termsCheckbox.checked;
                termsCheckbox.addEventListener('change', (event) => {
                    acceptButton.disabled = !event.target.checked;
                    acceptButton.classList.toggle('opacity-50', !event.target.checked);
                    acceptButton.classList.toggle('cursor-not-allowed', !event.target.checked);
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
