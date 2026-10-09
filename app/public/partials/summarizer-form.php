<?php
use App\Src\Services\ArticleService;
use App\Src\Services\FileUploadService;
use App\Src\Services\SummarizerPromptTemplate;

$formState = is_array($_SESSION['summarizer_form_state'] ?? null) ? $_SESSION['summarizer_form_state'] : $_POST;
unset($_SESSION['summarizer_form_state']);
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
];
$formats = [
    'paragraph' => ['Paragraph', 'A continuous narrative of the document’s key ideas.'],
    'bullets' => ['Bullet Points', 'Key takeaways presented as concise standalone points.'],
    'hybrid' => ['Hybrid (Overview + Bullets)', 'A short overview followed by scannable key points.'],
    'structured' => ['Structured Sections', 'Selected evidence organized into clearly labeled sections.'],
];
$depths = [
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
    'maxUploadBytes' => FileUploadService::MAX_UPLOAD_BYTES,
    'maxTextBytes' => ArticleService::MAX_TEXT_BYTES,
    'llmSynthesisAvailable' => $llmSynthesisAvailable,
];
?>
<form id="summarize-form" action="summarize.php" method="POST" enctype="multipart/form-data" class="summary-workspace workspace-page__form" data-capabilities="<?= htmlspecialchars(json_encode($capabilities, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (!empty($embeddedSummarizer)): ?>
    <h2 class="workspace-title visually-hidden" id="workspace-title">Summarizer</h2>
    <?php else: ?>
    <h1 class="workspace-title visually-hidden" id="workspace-title">Summarizer</h1>
    <?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

    <?php $errorMessage = $_SESSION['flash_error'] ?? $_GET['error'] ?? null; if ($errorMessage): ?>
        <div class="flash-error" role="alert"><?= htmlspecialchars($errorMessage) ?><?php unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <div class="summary-panel summary-panel--page">
        <div class="summary-grid">
            <div class="nex-form-row" id="document-title-card">
                <label class="nex-form-row__label" for="document_title">Document Title <span class="label-hint">(Optional)</span></label>
                <div><input type="text" id="document_title" name="document_title" placeholder="Add a title or leave blank to use the document heading" value="<?= htmlspecialchars($formState['document_title'] ?? '') ?>" autocomplete="off"></div>
            </div>

            <fieldset class="nex-form-row source-method" id="source-method">
                <legend class="nex-form-row__label">Source</legend>
                <div>
                    <div class="source-method__options" role="group" aria-label="Choose one source method">
                        <?php foreach ($sourceLabels as $value => $label): ?>
                            <button type="button" class="source-method__option<?= $sourceType === $value ? ' is-active' : '' ?>" data-source-choice="<?= $value ?>" aria-pressed="<?= $sourceType === $value ? 'true' : 'false' ?>"><?= $label ?></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="source_type" name="source_type" value="<?= htmlspecialchars($sourceType) ?>">
                    <div class="source-panel" data-source-panel="file"<?= $sourceType === 'file' ? '' : ' hidden' ?>>
                        <label class="visually-hidden" for="pdf">Upload PDF or DOCX file</label>
                        <div class="nex-drop-zone" id="drop-zone">
                            <div class="nex-drop-zone__icon" aria-hidden="true">↑</div>
                            <p class="nex-drop-zone__hint">UPLOAD YOUR DOCUMENT<br><span>PDF / DOCX · drag and drop or click to browse</span></p>
                            <p class="privacy-note">Maximum size: 30 MB</p>
                            <div class="nex-drop-zone__file-row" hidden>
                                <span class="nex-drop-zone__filename"></span>
                                <button type="button" class="nex-drop-zone__replace">Replace</button>
                                <button type="button" class="nex-drop-zone__remove">Remove</button>
                            </div>
                            <input type="file" id="pdf" name="pdf_file" accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" aria-describedby="source-selection-note">
                        </div>
                    </div>
                    <div class="source-panel" data-source-panel="text"<?= $sourceType === 'text' ? '' : ' hidden' ?>>
                        <label class="visually-hidden" for="text">Paste document text</label>
                        <textarea id="text" name="original_text" maxlength="200000" placeholder="Paste document text here..." aria-describedby="source-selection-note"><?= htmlspecialchars((($formState['source_type'] ?? '') === 'text') ? ($formState['original_text'] ?? '') : '') ?></textarea>
                        <p class="privacy-note">Up to 200 KB of text.</p>
                    </div>
                    <div class="source-panel" data-source-panel="url"<?= $sourceType === 'url' ? '' : ' hidden' ?>>
                        <label class="visually-hidden" for="source_url">Article URL</label>
                        <input type="url" id="source_url" name="source_url" placeholder="https://example.com/article" value="<?= htmlspecialchars((($formState['source_type'] ?? '') === 'url') ? ($formState['source_url'] ?? '') : '') ?>" autocomplete="url">
                        <p class="privacy-note">Public web pages and direct PDF links are retrieved and checked for readable content.</p>
                    </div>
                    <p class="privacy-note" id="source-selection-note" aria-live="polite">Choose a PDF or DOCX, paste text, or provide a public article URL.</p>
                    <p class="privacy-note">Please do not submit private, sensitive, or confidential information unless you are allowed to do so.</p>
                    <p class="source-error" id="source-error" role="alert" hidden></p>
                </div>
            </fieldset>

            <div class="settings-divider" aria-hidden="true"></div>
            <h2 class="settings-heading">Summary Settings</h2>

            <div class="summary-controls-grid" aria-label="Summary organization settings">
                <div class="nex-form-row" id="output-style">
                    <label class="nex-form-row__label" for="output_format">Output Format</label>
                    <div class="summary-control-field">
                        <div class="summary-select-wrap">
                            <select id="output_format" name="output_format" aria-describedby="output-format-help style_description"><?php foreach ($formats as $value => [$label]): ?><option value="<?= $value ?>"<?= $activeFormat === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select>
                        </div>
                        <p class="summary-control-hint" id="output-format-help">Choose how the generated summary should be organized.</p>
                        <p class="style-description" id="style_description" aria-live="polite"><?= htmlspecialchars($formats[$activeFormat][1]) ?></p>
                    </div>
                </div>

                <div class="nex-form-row" id="analysis-mode">
                    <label class="nex-form-row__label" for="analysis_mode">Analysis Mode</label>
                    <div class="summary-control-field">
                        <div class="summary-select-wrap">
                            <select id="analysis_mode" name="analysis_mode" aria-describedby="analysis-mode-help analysis_description"><?php foreach ($analysisModes as $value => [$label]): ?><option value="<?= $value ?>"<?= $activeMode === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select>
                        </div>
                        <p class="summary-control-hint" id="analysis-mode-help">Choose how LIGHT should interpret and prioritize the document.</p>
                        <p class="style-description" id="analysis_description" aria-live="polite"><?= htmlspecialchars($analysisModes[$activeMode][1]) ?></p>
                    </div>
                </div>
            </div>

            <fieldset class="nex-form-row depth-field" id="summary-length">
                <legend class="nex-form-row__label">Summary Depth</legend>
                <div><div class="depth-options" role="group" aria-label="Summary depth">
                    <?php foreach ($depths as $value => [$label]): ?><button type="button" class="depth-option<?= $activeDepth === $value ? ' is-active' : '' ?>" data-depth="<?= $value ?>" aria-pressed="<?= $activeDepth === $value ? 'true' : 'false' ?>"><?= htmlspecialchars($label) ?></button><?php endforeach; ?>
                </div>
                <p class="style-description" id="depth_description" aria-live="polite"><?= htmlspecialchars($depths[$activeDepth][1]) ?></p>
                <input type="hidden" id="summary_depth" name="summary_depth" value="<?= htmlspecialchars($activeDepth) ?>">
                <input type="hidden" id="summary_length" name="summary_length" value="<?= htmlspecialchars($activeDepth) ?>">
                <input type="hidden" id="sentence_count" name="sentence_count" value="8">
                <input type="hidden" id="summary_style" name="summary_style" value="standard_paragraph">
                </div>
            </fieldset>

            <?php if ($llmSynthesisAvailable): ?>
            <fieldset class="nex-form-row synthesis-opt-in" id="llm-synthesis-setting">
                <legend class="nex-form-row__label">Generation Method</legend>
                <div>
                    <label class="synthesis-opt-in__choice" for="use_llm_synthesis">
                        <input type="checkbox" id="use_llm_synthesis" name="use_llm_synthesis" value="1"<?= ($formState['use_llm_synthesis'] ?? '') === '1' ? ' checked' : '' ?> aria-describedby="llm-synthesis-description">
                        <span>Use evidence-controlled AI synthesis</span>
                    </label>
                    <p class="style-description" id="llm-synthesis-description">Only selected source passages are sent to the configured language-model endpoint. Opt in only when you may process this content there; an external endpoint can receive it off-device. Automated checks are heuristic, not proof of factual accuracy. Structured Sections remain extractive.</p>
                </div>
            </fieldset>
            <?php endif; ?>
        </div>

        <div class="summary-panel__footer">
            <div class="summary-actions-group">
                <button type="submit" class="summary-submit-button global-button-effect">Summarize Document</button>
                <button type="button" id="btn-nutshell-action" class="summary-nutshell-button global-button-effect">Generate Nutshell</button>
            </div>
            <p class="submit-status" id="submit-status" role="status" aria-live="polite"></p>
        </div>

        <section class="nutshell-panel" id="nutshell-panel" aria-label="Nutshell summary" style="display:none">
            <div class="nutshell-panel__header">
                <span class="nutshell-badge">
                    <svg class="nutshell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.64 5.64l2.12 2.12m8.48 8.48 2.12 2.12m0-12.72-2.12 2.12m-8.48 8.48-2.12 2.12"/><circle cx="12" cy="12" r="5"/></svg>
                    Nutshell
                </span>
                <button type="button" class="nutshell-copy-btn" id="nutshell-copy-btn" style="display:none" aria-label="Copy Nutshell summary">
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
                    <button type="button" class="nutshell-error__retry-btn" id="nutshell-retry-btn">Try again</button>
                </div>
            </div>
        </section>
    </div>
</form>

