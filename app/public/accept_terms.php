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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Terms and Conditions</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/poc-neust-logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
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

        <section class="terms-card">
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
                <label class="consent-box consent-box-standalone">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <span class="consent-copy">
                        I have read and agree to the Terms and Conditions.
                    </span>
                </label>
                <button type="submit" class="btn-auth">Accept and Continue</button>
            </form>
        <?php endif; ?>
    </main>
    <script src="assets/js/index.js"></script>
</body>
</html>
