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
$errorMessage = '';
$noticeMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $needsTermsReview) {
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
        $termsService->acceptCurrentUserTerms();
        rotateCsrfToken();
        header('Location: ' . \App\Src\Services\TermsAcceptanceService::redirectAfterTermsAcceptance());
        exit;
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms and Conditions</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
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
            <?php if ($needsTermsReview): ?>
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

        <section class="terms-card">
            <?php foreach ($sections as $index => $section): ?>
                <article class="terms-section">
                    <h2><?= (int)($index + 1) ?>. <?= htmlspecialchars($section['heading']) ?></h2>
                    <p><?= htmlspecialchars($section['body']) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($needsTermsReview): ?>
            <form method="POST" action="terms.php?return=<?= htmlspecialchars(urlencode($returnTarget)) ?>" class="terms-actions" id="terms-actions">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTarget) ?>">
                <label class="consent-box consent-box-standalone">
                    <input type="checkbox" name="accept_terms" value="1" required data-terms-checkbox>
                    <span class="consent-copy">
                        I have read and agree to the Terms and Conditions.
                    </span>
                </label>
                <button type="submit" class="btn-auth" disabled data-terms-accept>Accept</button>
                <button type="submit" name="decline_terms" value="1" class="btn-auth btn-auth-secondary" formnovalidate>
                    No, I Don't Accept
                </button>
            </form>
        <?php endif; ?>

    </main>
    <?php if ($needsTermsReview): ?>
        <script>
            const termsCheckbox = document.querySelector('[data-terms-checkbox]');
            const acceptButton = document.querySelector('[data-terms-accept]');

            if (termsCheckbox && acceptButton) {
                termsCheckbox.addEventListener('change', () => {
                    acceptButton.disabled = !termsCheckbox.checked;
                });
            }
        </script>
    <?php endif; ?>
</body>
</html>
