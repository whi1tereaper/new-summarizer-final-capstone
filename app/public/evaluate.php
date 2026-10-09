<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/Utils/validation.php';
require_once __DIR__ . '/../src/Services/EvaluationAccess.php';
require_once __DIR__ . '/../src/Services/EvaluationService.php';
require_once __DIR__ . '/partials/evaluation-ui.php';

use App\Src\Services\EvaluationAccess;
use App\Src\Services\EvaluationService;

try { $evaluatorId = EvaluationAccess::requireEvaluator(); }
catch (Throwable $e) {
    http_response_code(403);
    ev_header('Sign in to review', false);
    echo '<p class="analytics-alert">An active account is required to access assigned reviews.</p><p><a href="login.php">Sign in</a></p>';
    ev_footer(); exit;
}
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$token = is_string($_GET['assignment'] ?? null) ? $_GET['assignment'] : '';
$assignment = null;
$assignments = [];
$error = null;
$notice = $_SESSION['evaluation_notice'] ?? null;
unset($_SESSION['evaluation_notice']);
try {
    $service = new EvaluationService();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        EvaluationAccess::requirePost();
        $postedToken = is_string($_POST['assignment'] ?? null) ? $_POST['assignment'] : '';
        $service->submitRating($postedToken, $evaluatorId, is_array($_POST['ratings'] ?? null) ? $_POST['ratings'] : [], (string) ($_POST['comment'] ?? ''));
        rotateCsrfToken();
        $_SESSION['evaluation_notice'] = 'Your assessment has been submitted and locked. Thank you for your independent review.';
        header('Location: evaluate.php', true, 303);
        exit;
    }
    if ($token !== '') {
        $assignment = $service->getBlindAssignment($token, $evaluatorId);
        if (!$assignment) { http_response_code(404); $error = 'This assignment is unavailable.'; }
    } else { $assignments = $service->listAssignments($evaluatorId); }
} catch (InvalidArgumentException | DomainException $e) { $error = $e->getMessage(); }
catch (Throwable $e) { error_log('[evaluation rating] ' . $e->getMessage()); $error = 'Your assessment could not be loaded or saved. Please try again.'; }

ev_header('Summary review', false);
if ($notice): ?><p class="evaluation-notice" role="status"><?= ev_h($notice) ?></p><?php endif;
if ($error): ?><p class="analytics-alert" role="alert"><?= ev_h($error) ?></p><?php endif;
if ($assignment): ?>
    <p><a href="evaluate.php">Back to my assignments</a></p>
    <div class="evaluation-reading"><section><h2>Source document</h2><p><?= ev_h($assignment['title'] ?? '') ?></p><div class="evaluation-prose"><?= ev_h($assignment['source_text'] ?? '') ?></div></section><section><h2><?= ev_h($assignment['blind_label'] ?? $assignment['label'] ?? $assignment['presentation_label'] ?? 'Assigned summary') ?></h2><div class="evaluation-prose"><?= ev_h($assignment['summary_text'] ?? $assignment['summary'] ?? $assignment['generated_summary'] ?? '') ?></div></section></div>
    <?php if (!empty($assignment['submitted_at']) || ($assignment['status'] ?? '') === 'submitted'): ?><p class="evaluation-notice">This assessment has been submitted and is locked.</p>
    <?php else: ?><section class="evaluation-section"><h2>Assessment rubric</h2><div class="evaluation-rubric evaluation-note"><p><strong>1 — Very poor:</strong> serious errors or unusable output.</p><p><strong>2 — Poor:</strong> major weaknesses affecting usefulness.</p><p><strong>3 — Acceptable:</strong> understandable, with noticeable issues.</p><p><strong>4 — Good:</strong> strong output with minor weaknesses.</p><p><strong>5 — Excellent:</strong> satisfies the criterion with no meaningful weaknesses.</p></div><p>Judge each criterion separately, using only the source and summary. Consider the amount of information that can reasonably fit in the displayed summary. Do not discuss your scores with other evaluators before submitting.</p>
    <form method="post" class="evaluation-form"><input type="hidden" name="csrf_token" value="<?= ev_h(generateCsrfToken()) ?>"><input type="hidden" name="assignment" value="<?= ev_h($token) ?>"><?php ev_rating_fields(); ev_textarea('comment', 'Comments and specific examples (optional)', false); ?><p class="evaluation-note">Submission locks this assessment. If a correction is necessary, contact the research administrator.</p><div class="evaluation-actions"><button type="submit" class="btn-auth">Submit assessment</button></div></form></section><?php endif;
elseif ($token === ''): ?>
    <?php if (!$assignments): ?><section class="empty-state"><h2>No assigned reviews</h2><p>Your research administrator will assign documents and summaries here.</p></section>
    <?php else: $completed = count(array_filter($assignments, static fn(array $item): bool => !empty($item['submitted_at']) || ($item['status'] ?? '') === 'submitted')); ?><p><?= $completed ?> of <?= count($assignments) ?> assessments submitted.</p><div class="activity-list"><?php foreach ($assignments as $item): ?><article class="activity-item"><div><strong><?= ev_h($item['blind_label'] ?? $item['label'] ?? $item['presentation_label'] ?? 'Assigned summary') ?></strong><span><?= ev_h($item['title'] ?? 'Assigned document') ?></span></div><?php if (!empty($item['submitted_at']) || ($item['status'] ?? '') === 'submitted'): ?><span>Submitted · locked</span><?php else: ?><a href="evaluate.php?assignment=<?= ev_h($item['token'] ?? $item['assignment_token'] ?? '') ?>">Review summary</a><?php endif; ?></article><?php endforeach; ?></div><?php endif;
endif;
ev_footer();
