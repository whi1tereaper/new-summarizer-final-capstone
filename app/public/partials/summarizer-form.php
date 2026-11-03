<?php
use App\Src\Services\ArticleService;
use App\Src\Services\FileUploadService;

if (!isset($_SESSION['summarizer_form_saved_at']) || time() - (int)$_SESSION['summarizer_form_saved_at'] > 3600) {
    unset($_SESSION['summarizer_form_state'], $_SESSION['flash_failure']);
}
$formState = is_array($_SESSION['summarizer_form_state'] ?? null) ? $_SESSION['summarizer_form_state'] : $_POST;
// Dashboard preference links may preselect only these validated form fields.
foreach (['analysis_mode', 'output_format', 'summary_depth'] as $preferredField) {
    if (isset($_GET[$preferredField]) && is_string($_GET[$preferredField])) {
        $formState[$preferredField] = $_GET[$preferredField];
    }
}
$csrf_token = $csrf_token ?? (function_exists('generateCsrfToken') ? generateCsrfToken() : (string)($_SESSION['csrf_token'] ?? ''));
$llmSynthesisAvailable = (bool)config('summarizer.llm_available', false);
$sourceType = (string)($formState['source_type'] ?? 'file');
$sourceLabels = ['file' => 'Upload File', 'text' => 'Paste Text', 'url' => 'URL'];
$sourceLabels = array_intersect_key($sourceLabels, array_fill_keys(ArticleService::ALLOWED_SOURCE_TYPES, true));
$sourceType = isset($sourceLabels[$sourceType]) ? $sourceType : 'file';
$analysisModes = [
    'general' => ['General', 'Balances the document’s main ideas, arguments, and conclusions.'],
    'academic' => ['Academic', 'Emphasizes research questions, methods, findings, and scholarly implications.'],
    'executive' => ['Executive', 'Prioritizes strategic impact, decisions, operational outcomes, and actions.'],
    'technical' => ['Technical', 'Highlights architecture, implementation, mechanisms, constraints, and trade-offs.'],
    'study' => ['Study', 'Focuses on core concepts, explanations, definitions, and learning takeaways.'],
    'news' => ['News', 'Leads with the most newsworthy facts, then adds context and implications.'],
    'legal' => ['Legal & Policy', 'Prioritizes operative terms, obligations, liabilities, compliance, and conditions.'],
    'scientific' => ['Scientific', 'Prioritizes hypotheses, experimental methods, empirical findings, and limitations.'],
];
$formats = [
    'paragraph' => ['Paragraph', 'A continuous narrative of the document’s key ideas.'],
    'bullets' => ['Bullet Points', 'Key takeaways presented as concise standalone points.'],
    'hybrid' => ['Hybrid (Overview + Bullets)', 'A short overview followed by scannable key points.'],
    'structured' => ['Structured Sections', 'Selected evidence organized into clearly labeled sections.'],
    'key_takeaways' => ['Key Takeaways', 'High-impact key takeaways prioritized for instant comprehension.'],
];
$depths = [
    'very_brief' => ['Very Brief', 'Ultra-condensed distillation of the single most vital premise.'],
    'brief' => ['Brief', 'Distills the single most important premise or conclusion.'],
    'short' => ['Short', 'Keeps core points with minimal supporting context.'],
    'balanced' => ['Balanced', 'Preserves primary ideas, supporting points, and the main conclusion.'],
    'detailed' => ['Detailed', 'Covers major arguments, evidence, examples, and qualifications.'],
    'comprehensive' => ['Comprehensive', 'Provides a high coverage synthesis with structural and nuanced detail.'],
];
$analysisModes = array_intersect_key($analysisModes, array_fill_keys(ArticleService::ALLOWED_ANALYSIS_MODES, true));
$formats = array_intersect_key($formats, array_fill_keys(ArticleService::ALLOWED_OUTPUT_FORMATS, true));
$depths = array_intersect_key($depths, array_fill_keys(ArticleService::ALLOWED_SUMMARY_DEPTHS, true));
$activeMode = strtolower(trim((string)($formState['analysis_mode'] ?? 'general')));
$activeMode = isset($analysisModes[$activeMode]) ? $activeMode : 'general';
$activeFormat = strtolower(trim((string)($formState['output_format'] ?? 'paragraph')));
$activeFormat = isset($formats[$activeFormat]) ? $activeFormat : 'paragraph';
$activeDepth = strtolower(trim((string)($formState['summary_depth'] ?? ($formState['summary_length'] ?? 'balanced'))));
$activeDepth = isset($depths[$activeDepth]) ? $activeDepth : 'balanced';
$capabilities = [
    'analysisModes' => array_map(static fn($key, $item) => ['value' => $key, 'label' => $item[0], 'description' => $item[1]], array_keys($analysisModes), array_values($analysisModes)),
    'formats' => array_map(static fn($key, $item) => ['value' => $key, 'label' => $item[0], 'description' => $item[1]], array_keys($formats), array_values($formats)),
    'depths' => array_map(static fn($key, $item) => ['value' => $key, 'label' => $item[0], 'description' => $item[1]], array_keys($depths), array_values($depths)),
    'maxUploadBytes' => FileUploadService::effectiveUploadLimit(),
    'maxTextBytes' => ArticleService::MAX_TEXT_BYTES,
    'maxTitleCharacters' => ArticleService::MAX_TITLE_CHARACTERS,
    'allowedExtensions' => FileUploadService::ALLOWED_EXTENSIONS,
    'llmSynthesisAvailable' => $llmSynthesisAvailable,
    'serverSavedAt' => (int)($_SESSION['summarizer_form_saved_at'] ?? 0) * 1000,
];
$initialFailure = is_array($_SESSION['flash_failure'] ?? null) ? $_SESSION['flash_failure'] : null;
unset($_SESSION['flash_error']);
require __DIR__ . '/error-support.php';
require __DIR__ . '/student-trial.php';
// Inline workspace icons share one consistent, lightweight style.
$workspaceIcon = static function (string $name): string {
    $paths = [
        'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M4 15v5a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/>',
        'document' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5Z"/><path d="M14 3v5h5M9 12h6m-6 4h6"/>',
        'text' => '<path d="M5 4h14M12 4v16m-4 0h8M5 8V4m14 4V4"/>',
        'link' => '<path d="m10 13 4-4m-6 7-1 1a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m2 1 1-1a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0" transform="translate(1 0)"/>',
        'preferences' => '<path d="M4 7h6m4 0h6M4 17h10m4 0h2"/><circle cx="12" cy="7" r="2"/><circle cx="16" cy="17" r="2"/>',
        'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
    ];
    return '<svg class="workspace-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['document']) . '</svg>';
};
?>
<form id="summarize-form" <?= $trialExhausted||$trialError?'hidden':'' ?> action="summarize.php" method="POST" enctype="multipart/form-data" novalidate class="summary-workspace workspace-page__form" aria-label="Document summarization workspace" data-initial-failure="<?= htmlspecialchars(json_encode($initialFailure, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" data-capabilities="<?= htmlspecialchars(json_encode($capabilities, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="request_token" value="<?= bin2hex(random_bytes(16)) ?>">

    <div class="workspace-toolbar">
                    <div class="source-method__options" role="group" aria-label="Choose one source method">
                        <?php foreach ($sourceLabels as $value => $label): ?>
                            <button type="button" class="btn btn--ghost btn--sm source-method__option<?= $sourceType === $value ? ' is-active' : '' ?>" data-source-choice="<?= $value ?>" aria-controls="source-panel-<?= $value ?>" aria-pressed="<?= $sourceType === $value ? 'true' : 'false' ?>"><?= $workspaceIcon(['file' => 'upload', 'text' => 'text', 'url' => 'link'][$value]) ?><span><?= $label ?></span></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="source_type" name="source_type" value="<?= htmlspecialchars($sourceType) ?>">
        <button type="button" class="btn btn--ghost workspace-preferences-button" data-preferences-toggle aria-controls="summary-preferences"><?= $workspaceIcon('preferences') ?><span>Preferences</span></button>
    </div>
    <div class="summary-panel summary-panel--page">
        <div class="summary-grid">
            <section class="workspace-document" aria-labelledby="workspace-document-heading">
                <h2 class="workspace-section-heading" id="workspace-document-heading"><?= $workspaceIcon('document') ?>Your document</h2>
            <div class="source-method" id="source-method">
                <div>
                    <div class="source-panel" id="source-panel-file" data-source-panel="file"<?= $sourceType === 'file' ? '' : ' hidden' ?>>
                        <label class="visually-hidden" for="pdf">Upload PDF or DOCX file</label>
                        <div class="nex-drop-zone" id="drop-zone">
                            <div class="nex-drop-zone__icon"><?= $workspaceIcon('upload') ?></div>
                            <p class="nex-drop-zone__hint">Drop your document here<span>Drag and drop your PDF / DOCX, or <span class="nex-drop-zone__action">browse files</span></span></p>
                            <p class="workspace-upload-limit" id="upload-limit">Maximum file size: <?= number_format(FileUploadService::effectiveUploadLimit() / 1048576, 1) ?> MiB</p>
                            <div class="nex-drop-zone__file-row" hidden>
                                <span class="nex-drop-zone__filename"></span><span class="nex-drop-zone__file-meta"></span>
                                <button type="button" class="btn btn--secondary btn--sm nex-drop-zone__replace">Replace</button>
                                <button type="button" class="btn btn--destructive btn--sm nex-drop-zone__remove">Remove</button>
                            </div>
                            <input type="file" id="pdf" name="pdf_file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" aria-describedby="upload-limit source-selection-note source-error">
                        </div>
                    </div>
                    <div class="source-panel" id="source-panel-text" data-source-panel="text"<?= $sourceType === 'text' ? '' : ' hidden' ?>>
                        <label class="visually-hidden" for="text">Paste document text</label>
                        <textarea id="text" name="original_text" rows="10" placeholder="Paste the document or article you want to summarize here..." aria-describedby="text-count text-limit source-error"><?= htmlspecialchars((($formState['source_type'] ?? '') === 'text') ? ($formState['original_text'] ?? '') : '') ?></textarea>
                        <p class="workspace-editor-meta"><span id="text-count" role="status" aria-live="polite" aria-atomic="true">0 words &middot; 0 characters</span><span id="text-limit">Up to <?= number_format(ArticleService::MAX_TEXT_BYTES / 1000, 0) ?> KB of UTF-8 text.</span></p>
                    </div>
                    <div class="source-panel workspace-url-panel" id="source-panel-url" data-source-panel="url"<?= $sourceType === 'url' ? '' : ' hidden' ?>>
                        <div class="workspace-url-icon"><?= $workspaceIcon('link') ?></div><label for="source_url">Article URL</label>
                        <input type="url" id="source_url" name="source_url" placeholder="https://example.com/article" value="<?= htmlspecialchars((($formState['source_type'] ?? '') === 'url') ? ($formState['source_url'] ?? '') : '') ?>" autocomplete="url" aria-describedby="url-help source-error">
                        <p class="privacy-note" id="url-help">Use a public article or direct PDF link. The page is checked for readable content when you submit.</p>
                    </div>
                    <p class="visually-hidden" id="source-selection-note" aria-live="polite">Choose a PDF or DOCX, paste text, or provide a public article URL.</p>
                    <div class="light-error" id="source-error" role="alert" tabindex="-1" data-error-code="<?= htmlspecialchars((string)($initialFailure['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= $initialFailure && $initialFailure['code'] !== 'TITLE_TOO_LONG' ? '' : ' hidden' ?>>
                        <?php if ($initialFailure): ?>
                        <strong class="light-error__title"><?= htmlspecialchars((string)($initialFailure['title'] ?? 'We could not complete this request.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <p><?= htmlspecialchars((string)($initialFailure['explanation'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <p><?= htmlspecialchars((string)($initialFailure['recovery'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <div id="source-recovery-actions" class="light-recovery-actions" hidden>
                        <button class="btn btn--secondary" type="button" id="source-retry" hidden>Retry request</button>
                        <button class="btn btn--secondary" type="button" data-recovery-source="text" hidden>Paste readable text</button>
                        <button class="btn btn--secondary" type="button" data-recovery-source="file" hidden>Choose another document</button>
                        <a href="login.php" id="source-sign-in" hidden>Sign in</a>
                    </div>
                    <p class="light-draft-note" id="draft-recovery-note" role="status" hidden></p>
                </div>
            </div>

            <div class="workspace-title-field" id="document-title-card">
                <label class="nex-form-row__label" for="document_title">Document Title <span class="label-hint">(Optional)</span></label>
                <div>
                    <input type="text" id="document_title" name="document_title" placeholder="Add a title or leave blank to use the document heading" value="<?= htmlspecialchars($formState['document_title'] ?? '') ?>" autocomplete="off" aria-describedby="document-title-error">
                    <div class="light-error" id="document-title-error" role="alert" tabindex="-1"<?= ($initialFailure['code'] ?? '') === 'TITLE_TOO_LONG' ? '' : ' hidden' ?>>
                        <?php if (($initialFailure['code'] ?? '') === 'TITLE_TOO_LONG'): ?>
                        <strong class="light-error__title"><?= htmlspecialchars($initialFailure['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <p><?= htmlspecialchars($initialFailure['explanation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <p><?= htmlspecialchars($initialFailure['recovery'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            </section>
            <section class="workspace-preferences" id="summary-preferences" aria-labelledby="workspace-preferences-heading" tabindex="-1">
                <h2 class="workspace-section-heading" id="workspace-preferences-heading"><?= $workspaceIcon('preferences') ?>Summary preferences</h2>
            <div class="summary-controls-grid" aria-label="Summary organization settings">
                <div class="workspace-control" id="output-style">
                    <label class="nex-form-row__label" for="output_format">Output Format</label>
                    <div class="summary-control-field">
                        <div class="summary-select-wrap">
                            <select id="output_format" name="output_format" aria-describedby="output-format-help style_description"><?php foreach ($formats as $value => [$label]): ?><option value="<?= $value ?>"<?= $activeFormat === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select>
                        </div>
                        <p class="visually-hidden" id="output-format-help">Choose how the generated summary should be organized.</p>
                        <p class="visually-hidden" id="style_description" aria-live="polite"><?= htmlspecialchars($formats[$activeFormat][1]) ?></p>
                    </div>
                </div>

                <div class="workspace-control" id="analysis-mode">
                    <label class="nex-form-row__label" for="analysis_mode">Analysis Mode</label>
                    <div class="summary-control-field">
                        <div class="summary-select-wrap">
                            <select id="analysis_mode" name="analysis_mode" aria-describedby="analysis-mode-help analysis_description"><?php foreach ($analysisModes as $value => [$label]): ?><option value="<?= $value ?>"<?= $activeMode === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select>
                        </div>
                        <p class="visually-hidden" id="analysis-mode-help">Choose how DEPENDED should interpret and prioritize the document.</p>
                        <p class="visually-hidden" id="analysis_description" aria-live="polite"><?= htmlspecialchars($analysisModes[$activeMode][1]) ?></p>
                    </div>
                </div>
            </div>

            <fieldset class="depth-field" id="summary-length">
                <legend class="nex-form-row__label">Summary Depth</legend>
                <div><div class="depth-options" role="group" aria-label="Summary depth">
                    <?php foreach ($depths as $value => [$label]): ?><button type="button" class="btn btn--ghost btn--sm depth-option<?= $activeDepth === $value ? ' is-active' : '' ?>" data-depth="<?= $value ?>" aria-pressed="<?= $activeDepth === $value ? 'true' : 'false' ?>"><?= htmlspecialchars($label) ?></button><?php endforeach; ?>
                </div>
                <p class="workspace-help" id="depth_description" aria-live="polite"><?= htmlspecialchars($depths[$activeDepth][1]) ?></p>
                <input type="hidden" id="summary_depth" name="summary_depth" value="<?= htmlspecialchars($activeDepth) ?>">
                <input type="hidden" id="summary_length" name="summary_length" value="<?= htmlspecialchars($activeDepth) ?>">
                <input type="hidden" id="sentence_count" name="sentence_count" value="<?= ['very_brief' => 1, 'brief' => 3, 'short' => 5, 'balanced' => 8, 'detailed' => 11, 'comprehensive' => 15][$activeDepth] ?>">
                <input type="hidden" id="summary_style" name="summary_style" value="standard_paragraph">
                </div>
            </fieldset>

                <details class="workspace-more-preferences"<?= !empty($formState['focus_query']) || !empty($formState['use_llm_synthesis']) ? ' open' : '' ?>>
                    <summary>More preferences<span>Focus<?= $llmSynthesisAvailable ? ' &amp; generation' : '' ?></span></summary>
                    <div class="workspace-control workspace-focus">
                        <label for="focus_query">Focus <span class="label-hint">(Optional)</span></label>
                        <input type="text" id="focus_query" name="focus_query" maxlength="300" value="<?= htmlspecialchars($formState['focus_query'] ?? '') ?>" placeholder="e.g. Main findings and recommendations" aria-describedby="focus-help">
                        <p class="workspace-help" id="focus-help">Prioritize a topic or question in the standard summary. Nutshell distills the central message.</p>
                    </div>
            <?php if ($llmSynthesisAvailable): ?>
            <fieldset class="synthesis-opt-in" id="llm-synthesis-setting">
                <legend class="nex-form-row__label">Generation Method</legend>
                <div>
                    <label class="synthesis-opt-in__choice" for="use_llm_synthesis">
                        <input type="checkbox" id="use_llm_synthesis" name="use_llm_synthesis" value="1"<?= ($formState['use_llm_synthesis'] ?? '') === '1' ? ' checked' : '' ?> aria-describedby="llm-synthesis-description">
                        <span>Use evidence-controlled AI synthesis</span>
                    </label>
                    <p class="workspace-help" id="llm-synthesis-description">Only selected source passages are sent to the configured language-model endpoint. Opt in only when you may process this content there; an external endpoint can receive it off-device. Automated checks are heuristic, not proof of factual accuracy. Structured Sections remain extractive.</p>
                </div>
            </fieldset>
            <?php endif; ?>
                </details>
            </section>
        </div>

        <div class="summary-panel__footer">
            <p class="workspace-usage-note"><?= $workspaceIcon('shield') ?><span>Subject to account access and fair-use controls.</span></p>
            <div class="summary-actions-group">
                <button type="submit" class="btn btn--primary summary-submit-button" disabled><?= $workspaceIcon('document') ?><span class="summary-action-label">Summarize Document</span><?= $workspaceIcon('arrow') ?></button>
                <button type="button" id="btn-nutshell-action" class="btn btn--secondary summary-nutshell-button" disabled><?= $workspaceIcon('document') ?><span>Generate Nutshell</span></button>
            </div>
            <p class="submit-status" id="submit-status" role="status" aria-live="polite"></p>
        </div>

        <section class="nutshell-panel" id="nutshell-panel" aria-label="Nutshell summary" style="display:none">
            <div class="nutshell-panel__header">
                <span class="nutshell-badge">
                    <?= $workspaceIcon('document') ?>
                    Nutshell
                </span>
                <button type="button" class="btn btn--secondary nutshell-copy-btn" id="nutshell-copy-btn" style="display:none" aria-label="Copy Nutshell summary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    <span class="copy-label">Copy</span>
                </button>
            </div>
            <div class="nutshell-body">
                <div class="nutshell-loading" id="nutshell-loading" style="display:none" role="status">
                    <span class="nutshell-spinner" aria-hidden="true"></span>
                    <span>Distilling the document into its central message…</span>
                </div>
                <p class="nutshell-text" id="nutshell-text" style="display:none" aria-live="polite"></p>
                <div class="nutshell-error" id="nutshell-error" style="display:none" role="alert">
                    <span class="nutshell-error__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 7v6m0 4h.01"/></svg>
                    </span>
                    <div class="nutshell-error__content">
                        <strong class="nutshell-error__title">Nutshell could not be generated</strong>
                        <p class="nutshell-error__message" id="nutshell-error-message"></p>
                    </div>
                    <button type="button" class="btn btn--secondary nutshell-error__retry-btn" id="nutshell-retry-btn">Try again</button>
                </div>
            </div>
        </section>
    </div>
</form>
<p class="workspace-privacy-note">Please do not submit private, sensitive, or confidential information unless you are allowed to do so.</p>

