/* Presentation and input feedback for the Summarize workspace.
 * Request contracts, drafts, access checks and results remain in index.js.
 */
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('.light-workspace #summarize-form');
        if (!form) return;

        const capabilities = JSON.parse(form.dataset.capabilities || '{}');
        const sourceType = document.getElementById('source_type');
        const fileInput = document.getElementById('pdf');
        const textInput = document.getElementById('text');
        const urlInput = document.getElementById('source_url');
        const sourceError = document.getElementById('source-error');
        const sourceNote = document.getElementById('source-selection-note');
        const dropZone = document.getElementById('drop-zone');
        const fileName = dropZone?.querySelector('.nex-drop-zone__filename');
        const fileMeta = dropZone?.querySelector('.nex-drop-zone__file-meta');
        const fileRow = dropZone?.querySelector('.nex-drop-zone__file-row');
        const uploadHint = dropZone?.querySelector('.nex-drop-zone__hint');
        const initialHint = uploadHint?.innerHTML || '';
        const titleInput = document.getElementById('document_title');
        const textCount = document.getElementById('text-count');
        const encoder = new TextEncoder();
        const buttons = Array.from(form.querySelectorAll('.summary-submit-button, #btn-nutshell-action'));
        let dragDepth = 0;

        function fileError(file) {
            if (!file) return '';
            if (file.size > Number(capabilities.maxUploadBytes)) return 'FILE_TOO_LARGE';
            const extension = file.name.split('.').pop().toLowerCase();
            return (capabilities.allowedExtensions || []).includes(extension) ? '' : 'FILE_UNSUPPORTED';
        }

        function textError() {
            const text = textInput?.value || '';
            if (!text.trim()) return '';
            if (encoder.encode(text).length > Number(capabilities.maxTextBytes)) return 'INPUT_TOO_LARGE';
            return /[\p{L}\p{N}]/u.test(text) ? '' : 'CONTENT_UNREADABLE';
        }

        function validUrl() {
            try {
                const url = new URL(urlInput.value.trim());
                return ['http:', 'https:'].includes(url.protocol) && Boolean(url.hostname) && !url.username && !url.password;
            } catch { return false; }
        }

        function renderValidation(code, input, target = sourceError) {
            if (!code || !target) return;
            input?.setAttribute('aria-invalid', 'true');
            if (!target.hidden && target.dataset.errorCode === code) return;
            window.LightErrors?.render(target, { code });
        }

        function updateSourceValidation() {
            if (sourceType?.value === 'file') renderValidation(fileError(fileInput?.files?.[0]), fileInput);
            else if (sourceType?.value === 'text') renderValidation(textError(), textInput);
        }

        function updateActions() {
            const method = sourceType?.value || 'file';
            const file = fileInput?.files?.[0];
            const sourceReady = method === 'file' ? Boolean(file) && !fileError(file)
                : method === 'text' ? Boolean(textInput?.value.trim()) && !textError()
                : method === 'url' && validUrl();
            const titleValid = !titleInput || Array.from(titleInput.value.trim()).length <= Number(capabilities.maxTitleCharacters);
            const blocked = form.dataset.trialBlocked === '1' || document.getElementById('student-trial')?.dataset.blocked === '1';
            const busy = Boolean(form.dataset.workspaceOperation) || form.getAttribute('aria-busy') === 'true';
            buttons.forEach(button => { button.disabled = !sourceReady || !titleValid || blocked || busy; });
        }

        function updateFile() {
            const file = fileInput?.files?.[0];
            if (!dropZone) return;
            dropZone.classList.toggle('has-file', Boolean(file));
            dropZone.classList.toggle('has-invalid-file', Boolean(fileError(file)));
            if (fileRow) fileRow.hidden = !file;
            if (fileName) fileName.textContent = file?.name || '';
            if (fileMeta) {
                const extension = file?.name.split('.').pop().toUpperCase();
                const size = file && (file.size < 1048576
                    ? `${Math.max(1, Math.ceil(file.size / 1024)).toLocaleString()} KB`
                    : `${(file.size / 1048576).toFixed(1)} MB`);
                fileMeta.textContent = file ? `${extension} · ${size}` : '';
            }
            if (uploadHint) {
                if (file) uploadHint.textContent = fileError(file) ? 'Choose a supported document' : 'Your document is ready';
                else uploadHint.innerHTML = initialHint;
            }
            if (sourceNote && sourceType?.value === 'file') {
                sourceNote.textContent = file
                    ? fileError(file) ? 'Replace or remove this file to continue.' : `${file.name} is selected. Replace or remove it below.`
                    : 'Choose a document to enable summarization.';
            }
            if (fileError(file)) renderValidation(fileError(file), fileInput);
            updateActions();
        }

        function updateText() {
            if (!textInput) return;
            const text = textInput.value;
            const words = text.trim() ? text.trim().split(/\s+/u).length : 0;
            const bytes = encoder.encode(text).length;
            if (textCount) textCount.textContent = `${words.toLocaleString()} ${words === 1 ? 'word' : 'words'} · ${Array.from(text).length.toLocaleString()} characters`;
            if (!textInput.hidden && sourceType?.value === 'text') {
                textInput.style.height = 'auto';
                textInput.style.height = `${Math.min(420, Math.max(240, textInput.scrollHeight))}px`;
                textInput.style.overflowY = textInput.scrollHeight > 420 ? 'auto' : 'hidden';
                if (bytes > Number(capabilities.maxTextBytes)) renderValidation('INPUT_TOO_LARGE', textInput);
            }
        }

        form.addEventListener('light:workspace-state', updateActions);
        form.addEventListener('input', event => {
            if (event.target === textInput) updateText();
            updateSourceValidation();
            updateActions();
        });
        form.addEventListener('change', event => {
            if (event.target === fileInput) updateFile();
            updateSourceValidation();
            updateActions();
        });
        form.addEventListener('click', event => {
            if (!event.target.closest('[data-source-choice], [data-recovery-source], [data-depth]')) return;
            if (sourceType?.value === 'text') updateText();
            if (sourceType?.value === 'file') updateFile();
            updateSourceValidation();
            updateActions();
        });
        urlInput?.addEventListener('blur', () => {
            if (urlInput.value.trim() && !validUrl()) renderValidation('URL_INVALID', urlInput);
        });
        textInput?.addEventListener('blur', () => renderValidation(textError(), textInput));
        titleInput?.addEventListener('blur', () => {
            if (Array.from(titleInput.value.trim()).length > Number(capabilities.maxTitleCharacters)) {
                renderValidation('TITLE_TOO_LONG', titleInput, document.getElementById('document-title-error'));
            }
        });

        new MutationObserver(updateActions).observe(form, {
            attributes: true,
            attributeFilter: ['data-trial-blocked', 'aria-busy', 'data-workspace-operation'],
        });

        dropZone?.querySelector('.nex-drop-zone__replace')?.addEventListener('click', event => {
            event.preventDefault();
            fileInput?.click();
        });
        dropZone?.querySelector('.nex-drop-zone__remove')?.addEventListener('click', event => {
            event.preventDefault();
            if (!fileInput) return;
            fileInput.value = '';
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            fileInput.focus();
        });

        if (dropZone && fileInput) {
            ['dragenter', 'dragover'].forEach(type => dropZone.addEventListener(type, event => {
                event.preventDefault();
                if (!event.dataTransfer?.types.includes('Files')) return;
                if (type === 'dragenter') dragDepth += 1;
                dropZone.classList.add('is-drag-over');
                event.dataTransfer.dropEffect = 'copy';
            }));
            dropZone.addEventListener('dragleave', event => {
                event.preventDefault();
                dragDepth = Math.max(0, dragDepth - 1);
                if (!dragDepth) dropZone.classList.remove('is-drag-over');
            });
            dropZone.addEventListener('drop', event => {
                event.preventDefault();
                dragDepth = 0;
                dropZone.classList.remove('is-drag-over');
                const file = event.dataTransfer?.files?.[0];
                if (!file || fileInput.disabled) return;
                const selection = new DataTransfer();
                selection.items.add(file);
                fileInput.files = selection.files;
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        document.querySelector('[data-preferences-toggle]')?.addEventListener('click', () => {
            const preferences = document.getElementById('summary-preferences');
            if (!preferences) return;
            const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
            preferences.focus({ preventScroll: true });
            preferences.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'nearest' });
        });

        const morePreferences = form.querySelector('.workspace-more-preferences');
        if (morePreferences && (document.getElementById('focus_query')?.value.trim() || document.getElementById('use_llm_synthesis')?.checked)) {
            morePreferences.open = true;
        }

        updateFile();
        updateText();
        updateSourceValidation();
        updateActions();
    });
})();
