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
$isGuestTermsReviewFlow = empty($_SESSION['user_id']) && $returnTarget === 'summarizer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isGuestTermsReviewFlow) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Unauthorized request.');
    }

    $_SESSION['guest_terms_reviewed'] = true;
    $_SESSION['guest_terms_reviewed_at'] = date('Y-m-d H:i:s');
    rotateCsrfToken();
    header('Location: ' . $returnTarget);
    exit;
}

$backHref = $returnTarget;
$backLabel = $isGuestTermsReviewFlow
    ? 'Back to Summarizer'
    : (!empty($_SESSION['user_id']) ? 'Back' : 'Back to Home');
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
            <?php if ($isGuestTermsReviewFlow): ?>
                <p class="privacy-note">
                    Read every section below, then use the confirmation button at the end of this page to return to the summarizer.
                </p>
            <?php endif; ?>
        </header>

        <section class="terms-card">
            <?php foreach ($sections as $index => $section): ?>
                <article class="terms-section">
                    <h2><?= (int)($index + 1) ?>. <?= htmlspecialchars($section['heading']) ?></h2>
                    <p><?= htmlspecialchars($section['body']) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($isGuestTermsReviewFlow): ?>
            <form method="POST" action="terms.php?return=summarizer.php" class="terms-actions">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="return_to" value="summarizer.php">
                <button type="submit" class="btn-auth">I Have Read All Terms</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
