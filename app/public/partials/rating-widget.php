<?php
/**
 * Shared Star Rating & Feedback Component
 *
 * Expected variables:
 * - $ratingStats: array with 'count' (int) and 'avg' (float|string|null)
 * - $existingRating: ?int
 */
$hasExistingRating = isset($existingRating) && $existingRating !== null;
$statsCount = (int)($ratingStats['count'] ?? 0);
$statsAvg = $ratingStats['avg'] ?? null;
?>
<section class="result-section result-section--feedback" id="feedback-widget" aria-labelledby="section-feedback-heading">
    <h2 class="result-section__heading" id="section-feedback-heading">Was this summary useful?</h2>
    
    <div id="feedback-form-container" class="<?= $hasExistingRating ? 'hidden' : '' ?>">
        <p class="result-section__desc">
            Choose a rating to leave feedback.
            <?php if ($statsCount > 0 && $statsAvg !== null): ?>
            <span class="result-rating-avg"><?= htmlspecialchars((string)$statsAvg) ?> avg · <?= $statsCount ?> rating<?= $statsCount !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </p>

        <div id="star-row" class="result-stars" role="radiogroup" aria-label="Rate this summary">
            <?php for ($s = 1; $s <= 5; $s++): ?>
            <button type="button"
                    class="star-btn"
                    data-value="<?= $s ?>"
                    aria-label="Rate <?= $s ?> star<?= $s > 1 ? 's' : '' ?>"
                    role="radio"
                    aria-checked="false">&#9733;</button>
            <?php endfor; ?>
        </div>

        <!-- Feedback comment form block -->
        <div id="feedback-comment-block" class="feedback-comment-block hidden">
            <!-- Deterministic reason tags for low/neutral ratings -->
            <div id="feedback-reasons-block" class="feedback-reasons-block hidden">
                <span class="feedback-reasons-title" id="feedback-reasons-label">What could be improved? <span class="feedback-reasons-opt">(optional)</span></span>
                <div class="feedback-reasons-grid" role="group" aria-labelledby="feedback-reasons-label">
                    <?php 
                    $allowedReasons = \App\Src\Services\FeedbackService::ALLOWED_REASONS;
                    foreach ($allowedReasons as $rKey => $rLabel): 
                    ?>
                    <label class="feedback-reason-chip">
                        <input type="checkbox" name="feedback_reason" value="<?= htmlspecialchars($rKey) ?>" class="feedback-reason-cb">
                        <span class="feedback-reason-text"><?= htmlspecialchars($rLabel) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="feedback-comment-header">
                <label for="feedback-comment" class="feedback-comment-label">Anything you'd like to add? (optional)</label>
                <span class="feedback-anon-badge">
                    <svg class="feedback-anon-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1a3 3 0 0 0-3 3v2H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-1V4a3 3 0 0 0-3-3zm1 5V4a1 1 0 1 0-2 0v2h2z"/></svg>
                    Anonymous
                </span>
            </div>
            <p class="feedback-anon-hint">Your comment is displayed anonymously to administrators.</p>
            <div class="feedback-comment-wrapper">
                <textarea id="feedback-comment" class="feedback-comment-input" rows="3" maxlength="500" placeholder="Share anonymous feedback (optional)..."></textarea>
                <div class="feedback-counter-row">
                    <span id="feedback-counter" class="feedback-counter hidden" aria-live="polite"></span>
                </div>
            </div>
            <button type="button" id="feedback-submit-btn" class="feedback-submit-btn">Submit feedback</button>
            <div id="feedback-msg" class="result-feedback-msg hidden" role="status" aria-live="polite"></div>
        </div>
    </div>

    <!-- Quiet confirmation state -->
    <div id="feedback-confirmed" class="result-feedback-confirmed <?= $hasExistingRating ? '' : 'hidden' ?>" role="status">
        <span class="feedback-confirmed-check" aria-hidden="true">&#10003;</span> Thanks for your feedback
        <?php if ($hasExistingRating && !empty($existingFeedback['comment'])): ?>
            <p class="feedback-confirmed-comment">&ldquo;<?= htmlspecialchars((string)$existingFeedback['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>&rdquo;</p>
        <?php endif; ?>
        <button type="button" id="feedback-edit-btn" class="feedback-edit-toggle">Update feedback</button>
    </div>

    <!-- Anonymous Feedback Comments on this summary -->
    <div class="summary-comments-block" id="summary-comments-block">
        <div class="summary-comments-header">
            <h3 class="summary-comments-title">
                Reader Feedback &amp; Comments
                <?php if (!empty($summaryComments)): ?>
                <span class="summary-comments-count" id="summary-comments-count">(<?= count($summaryComments) ?>)</span>
                <?php endif; ?>
            </h3>
            <span class="feedback-anon-badge">
                <svg class="feedback-anon-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1a3 3 0 0 0-3 3v2H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-1V4a3 3 0 0 0-3-3zm1 5V4a1 1 0 1 0-2 0v2h2z"/></svg>
                Anonymous
            </span>
        </div>

        <div class="summary-comments-list" id="summary-comments-list">
            <?php if (empty($summaryComments)): ?>
                <p class="summary-comments-empty" id="summary-comments-empty">No comments yet. Rate this summary above to leave anonymous feedback.</p>
            <?php else: ?>
                <?php foreach ($summaryComments as $c): ?>
                    <article class="summary-comment-card">
                        <div class="summary-comment-top">
                            <div class="summary-comment-meta">
                                <span class="summary-comment-author">Anonymous Reader</span>
                                <time class="summary-comment-date" datetime="<?= htmlspecialchars((string)$c['created_at']) ?>">
                                    <?= date('M j, Y', strtotime((string)$c['created_at'])) ?>
                                </time>
                            </div>
                            <?php if (!empty($c['rating'])): ?>
                            <span class="summary-comment-stars" aria-label="Rated <?= (int)$c['rating'] ?> of 5 stars">
                                <?= str_repeat('★', max(1, min(5, (int)$c['rating']))) ?><?= str_repeat('☆', 5 - max(1, min(5, (int)$c['rating']))) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php 
                        $reasonsArr = json_decode((string)($c['reasons'] ?? '[]'), true);
                        if (!empty($reasonsArr) && is_array($reasonsArr)): 
                        ?>
                        <div class="summary-comment-reasons">
                            <?php foreach ($reasonsArr as $rKey): 
                                $rLabel = \App\Src\Services\FeedbackService::ALLOWED_REASONS[$rKey] ?? $rKey;
                            ?>
                                <span class="summary-comment-reason-chip"><?= htmlspecialchars((string)$rLabel) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <p class="summary-comment-body"><?= htmlspecialchars((string)$c['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
