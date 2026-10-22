document.addEventListener('DOMContentLoaded', () => {
    const appViewer = window.appViewer || {};

    // ── Summary length slider & Form handling ────────────────────────────────
    const form = document.getElementById('summarize-form');
    const capabilities = form ? JSON.parse(form.dataset.capabilities || '{}') : {};
    const fileInput = document.getElementById('pdf');
    const textInput = document.getElementById('text');
    const urlInput = document.getElementById('source_url');
    const sourceType = document.getElementById('source_type');
    const sourceNote = document.getElementById('source-selection-note');
    const sourceError = document.getElementById('source-error');
    const submitStatus = document.getElementById('submit-status');
    const sentenceCount = document.getElementById('sentence_count');
    const errors = window.LightErrors;
    const draftFields = ['document_title', 'original_text', 'source_url', 'source_type', 'analysis_mode', 'output_format', 'summary_depth', 'summary_length', 'sentence_count', 'summary_style', 'use_llm_synthesis', 'request_token'];
    const initialFailure = form ? JSON.parse(form.dataset.initialFailure || 'null') : null;
    const savedDraft = errors?.draft.read();
    let currentFailure = savedDraft && savedDraft.savedAt > Number(capabilities.serverSavedAt || 0) ? savedDraft.failure || null : initialFailure;
    let restoredFileName = savedDraft?.fileName || '';
    let restoredFileIdentity = savedDraft?.fileIdentity || '';
    let uncertainSubmission = savedDraft?.uncertainSubmission === true;
    const fileIdentity = file => file ? `${file.name}|${file.size}|${file.lastModified}` : '';
    let submitting = false;
    let nutshellRequestKey = null;
    const newToken = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
    if (form && savedDraft?.fields) {
        const preferences = new URLSearchParams(location.search);
        for (const name of draftFields) {
            const field = form.elements.namedItem(name);
            if (!field || preferences.has(name) || !(name in savedDraft.fields)) continue;
            if (field.type === 'checkbox') field.checked = savedDraft.fields[name] === '1';
            else if (field.tagName !== 'SELECT' || Array.from(field.options).some(option => option.value === savedDraft.fields[name])) field.value = savedDraft.fields[name];
        }
    }
    function saveDraft() {
        if (!form || !errors) return false;
        const fields = {};
        for (const name of draftFields) {
            const field = form.elements.namedItem(name);
            if (field) fields[name] = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value;
        }
        return errors.draft.write({ fields, fileName: fileInput?.files?.[0]?.name || restoredFileName, fileIdentity: fileIdentity(fileInput?.files?.[0]) || restoredFileIdentity, uncertainSubmission, failure: currentFailure, nutshellRequestKey });
    }
    function sourceChanged(event) {
        if (!form) return;
        const samePendingFile = uncertainSubmission && event?.target === fileInput && restoredFileIdentity && fileIdentity(fileInput.files?.[0]) === restoredFileIdentity;
        if (!samePendingFile) {
            form.elements.namedItem('request_token').value = newToken();
            uncertainSubmission = false;
        }
        nutshellRequestKey = null;
        currentFailure = null;
        if (sourceError) sourceError.hidden = true;
        const titleError = document.getElementById('document-title-error');
        if (titleError) titleError.hidden = true;
        document.getElementById('document_title')?.removeAttribute('aria-invalid');
        document.getElementById('source-recovery-actions')?.setAttribute('hidden', '');
        saveDraft();
    }
    const fileReadyText = file => `${file.name} · ${(file.size / 1048576).toFixed(1)} MB · Ready for summarization.`;

    const setDescription = (selectId, descriptions, targetId) => {
        const select = document.getElementById(selectId);
        const target = document.getElementById(targetId);
        if (!select || !target) return;
        const update = () => { target.textContent = descriptions.find(item => item.value === select.value)?.description || ''; };
        select.addEventListener('change', update);
        update();
    };
    setDescription('output_format', capabilities.formats || [], 'style_description');
    setDescription('analysis_mode', capabilities.analysisModes || [], 'analysis_description');

    const synthesisChoice = document.getElementById('use_llm_synthesis');
    const outputFormat = document.getElementById('output_format');
    if (synthesisChoice && outputFormat) {
        const updateSynthesisAvailability = () => {
            const supported = outputFormat.value !== 'structured';
            synthesisChoice.disabled = !supported;
            if (!supported) synthesisChoice.checked = false;
        };
        outputFormat.addEventListener('change', updateSynthesisAvailability);
        updateSynthesisAvailability();
    }

    const updateSource = (method, focus = false) => {
        if (!sourceType) return;
        sourceType.value = method;
        document.querySelectorAll('[data-source-choice]').forEach(button => {
            const active = button.dataset.sourceChoice === method;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        document.querySelectorAll('[data-source-panel]').forEach(panel => {
            const active = panel.dataset.sourcePanel === method;
            panel.hidden = !active;
            panel.querySelectorAll('input, textarea').forEach(input => { input.disabled = !active; });
        });
        if (sourceNote) sourceNote.textContent = method === 'file' && fileInput?.files?.[0]
            ? fileReadyText(fileInput.files[0])
            : method === 'url' ? 'The page will retrieve the URL and extract readable article content.'
            : method === 'text' ? 'Paste the document text you want summarized.'
            : 'Choose a PDF or DOCX, paste text, or provide a public article URL.';
        if (focus) document.querySelector(`[data-source-panel="${method}"] input:not(:disabled), [data-source-panel="${method}"] textarea:not(:disabled)`)?.focus();
    };
    document.querySelectorAll('[data-source-choice]').forEach(button => button.addEventListener('click', () => { updateSource(button.dataset.sourceChoice, true); sourceChanged(); }));
    updateSource(sourceType?.value || 'file');

    document.querySelectorAll('[data-depth]').forEach(button => button.addEventListener('click', () => {
        const depth = button.dataset.depth;
        const description = (capabilities.depths || []).find(item => item.value === depth);
        document.querySelectorAll('[data-depth]').forEach(option => {
            const active = option === button;
            option.classList.toggle('is-active', active);
            option.setAttribute('aria-pressed', String(active));
        });
        document.getElementById('summary_depth').value = depth;
        document.getElementById('summary_length').value = depth;
        if (sentenceCount) sentenceCount.value = ({ very_brief: 1, brief: 3, short: 5, balanced: 8, detailed: 11, comprehensive: 15 })[depth] || 8;
        document.getElementById('depth_description').textContent = description?.description || '';
        sourceChanged();
    }));
    const restoredDepth = document.getElementById('summary_depth')?.value;
    document.querySelectorAll('[data-depth]').forEach(button => { const active = button.dataset.depth === restoredDepth; button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active)); });
    if (restoredDepth && document.getElementById('depth_description')) document.getElementById('depth_description').textContent = (capabilities.depths || []).find(item => item.value === restoredDepth)?.description || '';

    function validateSource() {
        const titleInput = document.getElementById('document_title');
        if (titleInput && Array.from(titleInput.value.trim()).length > Number(capabilities.maxTitleCharacters)) {
            showSourceFailure({ code: 'TITLE_TOO_LONG' });
            titleInput.focus();
            return false;
        }
        const method = sourceType?.value;
        const text = textInput?.value.trim() || '';
        const url = urlInput?.value.trim() || '';
        const file = fileInput?.files?.[0];
        let code = '';
        if (method === 'file' && !file) code = 'FILE_REQUIRED';
        else if (method === 'file' && file && file.size > Number(capabilities.maxUploadBytes)) code = 'FILE_TOO_LARGE';
        else if (method === 'file' && file && !(capabilities.allowedExtensions || []).includes(file.name.split('.').pop().toLowerCase())) code = 'FILE_UNSUPPORTED';
        else if (method === 'text' && !text) code = 'INPUT_EMPTY';
        else if (method === 'text' && new TextEncoder().encode(textInput.value).length > Number(capabilities.maxTextBytes)) code = 'INPUT_TOO_LARGE';
        else if (method === 'text' && !/[\p{L}\p{N}]/u.test(text)) code = 'CONTENT_UNREADABLE';
        else if (method === 'url') {
            try { const parsed = new URL(url); if (!['http:', 'https:'].includes(parsed.protocol) || !parsed.hostname || parsed.username || parsed.password) code = 'URL_INVALID'; }
            catch { code = 'URL_INVALID'; }
        }
        if (code) {
            showSourceFailure({ code });
            const invalidInput = method === 'file' ? fileInput : method === 'url' ? urlInput : textInput;
            invalidInput?.setAttribute('aria-invalid', 'true');
            invalidInput?.focus();
            return false;
        }
        if (sourceError) sourceError.hidden = true;
        return true;
    }
    function showSourceFailure(value) {
        currentFailure = errors.failure(value);
        const titleError = document.getElementById('document-title-error');
        const isTitleError = currentFailure.code === 'TITLE_TOO_LONG';
        if (sourceError) sourceError.hidden = isTitleError;
        if (titleError) titleError.hidden = !isTitleError;
        if (isTitleError) document.getElementById('document_title')?.setAttribute('aria-invalid', 'true');
        errors.render(isTitleError ? titleError : sourceError, currentFailure);
        const actions = document.getElementById('source-recovery-actions');
        const retry = document.getElementById('source-retry');
        if (retry) retry.hidden = !currentFailure.retryable;
        document.querySelectorAll('[data-recovery-source]').forEach(button => { button.hidden = !(['access', 'extraction', 'format', 'content', 'quality', 'nlp'].includes(currentFailure.category) || /^(URL|FILE)_/.test(currentFailure.code)) || button.dataset.recoverySource === sourceType?.value; });
        const signIn = document.getElementById('source-sign-in');
        if (signIn) signIn.hidden = currentFailure.code !== 'AUTH_REQUIRED';
        if (actions) actions.hidden = !Array.from(actions.children).some(element => !element.hidden);
        saveDraft();
    }
    function setSubmitting(busy) {
        submitting = busy;
        const submitButton = form.querySelector('.summary-submit-button');
        if (submitButton) { submitButton.disabled = busy; submitButton.textContent = busy ? 'Starting summary…' : 'Summarize Document'; }
        const nutshellButton = document.getElementById('btn-nutshell-action');
        if (nutshellButton) nutshellButton.disabled = busy;
        const retry = document.getElementById('source-retry');
        if (retry) retry.disabled = busy;
        form.setAttribute('aria-busy', String(busy));
        if (submitStatus) submitStatus.textContent = busy ? 'Preparing your document for summarization…' : '';
    }
    if (form) form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting || !validateSource()) return;
        currentFailure = null;
        uncertainSubmission = true;
        restoredFileIdentity = fileIdentity(fileInput?.files?.[0]) || restoredFileIdentity;
        saveDraft();
        const body = new FormData(form);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 45000);
        setSubmitting(true);
        try {
            const response = await fetch(form.action, { method: 'POST', body, headers: { Accept: 'application/json' }, signal: controller.signal });
            let data;
            try { data = await response.json(); } catch { showSourceFailure({ code: 'NETWORK_INTERRUPTED' }); return; }
            if (response.ok && data?.success === true && typeof data.redirect === 'string') {
                const target = new URL(data.redirect, location.href);
                const expected = new URL('processing.php', location.href);
                const result = new URL('result.php', location.href);
                if (target.origin === expected.origin && [expected.pathname, result.pathname].includes(target.pathname)) {
                    if (target.pathname === result.pathname) errors.draft.clear();
                    location.assign(target.href);
                    return;
                }
            }
            uncertainSubmission = false;
            showSourceFailure(data?.failure || { code: response.status === 413 ? 'INPUT_TOO_LARGE' : response.status === 401 ? 'AUTH_REQUIRED' : 'PROCESSING_FAILED' });
        } catch { showSourceFailure({ code: 'NETWORK_INTERRUPTED' }); }
        finally { clearTimeout(timeout); setSubmitting(false); }
    });
    document.getElementById('source-retry')?.addEventListener('click', () => form?.requestSubmit());
    document.querySelectorAll('[data-recovery-source]').forEach(button => button.addEventListener('click', () => { updateSource(button.dataset.recoverySource, true); sourceChanged(); }));
    form?.addEventListener('input', sourceChanged);
    form?.addEventListener('change', sourceChanged);
    if (savedDraft) {
        const note = document.getElementById('draft-recovery-note');
        if (note) { note.hidden = false; note.textContent = sourceType?.value === 'file' && restoredFileName ? `Your settings were restored. Select ${restoredFileName} again; browsers cannot restore local file selections.` : 'Your source and settings were restored in this tab. Recovery drafts expire after one hour and are cleared after a successful summary.'; }
    }
    if (currentFailure) showSourceFailure(currentFailure);
    else {
        if (sourceError) sourceError.hidden = true;
        const titleError = document.getElementById('document-title-error');
        if (titleError) titleError.hidden = true;
    }
    [fileInput, textInput, urlInput].forEach(input => input?.addEventListener('input', () => { input.removeAttribute('aria-invalid'); if (sourceError) sourceError.hidden = true; }));
    fileInput?.addEventListener('change', () => {
        restoredFileName = fileInput.files?.[0]?.name || '';
        fileInput.removeAttribute('aria-invalid');
        if (sourceError) sourceError.hidden = true;
        if (fileInput.files?.length && sourceNote) sourceNote.textContent = fileReadyText(fileInput.files[0]);
    });

    const nutshellBtn = document.getElementById('btn-nutshell-action');
    const nutshellPanel = document.getElementById('nutshell-panel');
    const nutshellText = document.getElementById('nutshell-text');
    const nutshellLoading = document.getElementById('nutshell-loading');
    const nutshellCopyBtn = document.getElementById('nutshell-copy-btn');
    const nutshellError = document.getElementById('nutshell-error');
    const nutshellErrorMessage = document.getElementById('nutshell-error-message');
    const nutshellRetryBtn = document.getElementById('nutshell-retry-btn');
    nutshellRequestKey = savedDraft?.nutshellRequestKey || null;

    function setNutshellErrorState(value) {
        if (!nutshellPanel) return;
        nutshellPanel.classList.add('nutshell-panel--error');
        if (nutshellLoading) nutshellLoading.style.display = 'none';
        if (nutshellText) {
            nutshellText.textContent = '';
            nutshellText.style.display = 'none';
        }
        if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'none';
        const failure = errors.failure(value);
        const title = nutshellError?.querySelector('.nutshell-error__title');
        if (title) title.textContent = failure.title;
        if (nutshellErrorMessage) nutshellErrorMessage.textContent = `${failure.explanation} ${failure.recovery}${failure.reference ? ` Reference: ${failure.reference}` : ''}`;
        if (nutshellRetryBtn) nutshellRetryBtn.hidden = !failure.retryable;
        if (nutshellError) nutshellError.style.display = 'flex';
    }

    function resetNutshellState() {
        if (!nutshellPanel) return;
        nutshellPanel.classList.remove('nutshell-panel--error');
        if (nutshellError) {
            nutshellError.style.display = 'none';
            if (nutshellErrorMessage) nutshellErrorMessage.textContent = '';
        }
        if (nutshellLoading) nutshellLoading.style.display = 'none';
        if (nutshellText) {
            nutshellText.textContent = '';
            nutshellText.style.display = 'none';
        }
        if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'none';
    }

    if (nutshellRetryBtn && nutshellBtn) {
        nutshellRetryBtn.addEventListener('click', () => {
            nutshellBtn.click();
        });
    }

    if (nutshellBtn && nutshellPanel) {
        nutshellBtn.addEventListener('click', async (e) => {
            e.preventDefault();

            if (appViewer.requiresRegistrationForNutshell) {
                saveDraft();
                window.location.href = appViewer.registerUrl || 'register.php';
                return;
            }

            const textInput = document.getElementById('text');
            const fileInput = document.getElementById('pdf');
            const csrfInput = document.querySelector('input[name="csrf_token"]');
            const analysisMode = document.getElementById('analysis_mode')?.value || 'general';

            const method = sourceType?.value || 'file';
            const originalText = method === 'url' ? (urlInput?.value.trim() || '') : (textInput?.value.trim() || '');
            const hasFile = method === 'file' && fileInput && fileInput.files && fileInput.files.length > 0;

            if (!validateSource()) return;

            // Reveal nutshell panel & loading state
            nutshellPanel.style.display = 'block';
            nutshellPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            resetNutshellState();
            if (nutshellLoading) nutshellLoading.style.display = 'flex';
            nutshellBtn.disabled = true;

            const formData = new FormData();
            if (csrfInput) formData.append('csrf_token', csrfInput.value);
            nutshellRequestKey ||= (window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`).replace(/[^A-Za-z0-9_-]/g, '');
            saveDraft();
            formData.append('request_key', nutshellRequestKey);
            formData.append('analysis_mode', analysisMode);
            formData.append('source_type', method);
            if (originalText) formData.append('original_text', originalText);
            if (hasFile) formData.append('pdf_file', fileInput.files[0]);

            try {
                const response = await fetch('nutshell_generate.php', {
                    method: 'POST',
                    body: formData,
                });

                const rawText = await response.text();
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (parseErr) {
                    data = null;
                }

                if (data && data.redirect) {
                    const destination = new URL(data.redirect, location.href);
                    if (destination.origin === location.origin && ['login.php', 'register.php'].some(path => destination.pathname === new URL(path, location.href).pathname)) {
                        window.location.href = destination.href;
                        return;
                    }
                }

                if (nutshellLoading) nutshellLoading.style.display = 'none';
                nutshellBtn.disabled = false;

                if (data && data.success && data.nutshell) {
                    nutshellRequestKey = null;
                    errors.draft.clear();
                    resetNutshellState();
                    if (nutshellText) {
                        nutshellText.textContent = data.nutshell;
                        nutshellText.style.display = 'block';
                    }
                    if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'inline-flex';
                } else if (data && data.status === 'not_needed') {
                    nutshellRequestKey = null;
                    resetNutshellState();
                    if (nutshellText) { nutshellText.textContent = 'The primary summary is already concise.'; nutshellText.style.display = 'block'; }
                } else if (data?.failure) {
                    setNutshellErrorState(data.failure);
                } else {
                    setNutshellErrorState({ code: data ? 'PROCESSING_FAILED' : 'NETWORK_INTERRUPTED' });
                }
            } catch (err) {
                if (nutshellLoading) nutshellLoading.style.display = 'none';
                nutshellBtn.disabled = false;
                setNutshellErrorState({ code: 'NETWORK_INTERRUPTED' });
            }
        });
    }

    if (nutshellCopyBtn && nutshellText) {
        nutshellCopyBtn.addEventListener('click', () => {
            const text = nutshellText.textContent.trim();
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                const label = nutshellCopyBtn.querySelector('.copy-label');
                if (label) {
                    const prev = label.textContent;
                    label.textContent = 'Copied!';
                    setTimeout(() => { label.textContent = prev; }, 2000);
                }
            });
        });
    }

});
