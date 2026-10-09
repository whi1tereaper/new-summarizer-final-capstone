document.addEventListener('DOMContentLoaded', () => {
    const appViewer = window.appViewer || {};
    const sidebarData = window.sidebarAnalyticsData;
    const sidebar = document.querySelector('.analytics-sidebar');
    if (sidebarData && sidebar) {
        const chartInstances = [];
        const _style = getComputedStyle(document.documentElement);
        const _accent  = (_style.getPropertyValue('--color-accent')         || '#a43d2f').trim();
        const _inkSec  = (_style.getPropertyValue('--color-text-secondary') || '#4f554d').trim();
        const _muted   = (_style.getPropertyValue('--color-text-muted')     || '#6e756b').trim();
        const _success = (_style.getPropertyValue('--color-success')        || '#3d7a5a').trim();
        const _border  = (_style.getPropertyValue('--color-border')         || '#c8c5bb').trim();
        const chartColors = [_accent, '#3B82F6', '#10B981', '#F59E0B', '#8B5CF6'];
        function _alpha(hex, a) { const r=parseInt(hex.slice(1,3),16),g=parseInt(hex.slice(3,5),16),b=parseInt(hex.slice(5,7),16); return `rgba(${r},${g},${b},${a})`; }
        const chartLabels = rows => rows.map(row => row.label || 'Other');
        const chartValues = rows => rows.map(row => Number(row.total));
        const formatMetric = value => value === null || value === undefined ? 'N/A' : Number(value).toLocaleString();
        const formatMethod = m => {
            if (!m || m === 'N/A') return 'N/A';
            const map = {
                'standard_paragraph': 'Paragraph',
                'bullet_points': 'Bullets',
                'hybrid': 'Hybrid',
                'executive_summary': 'Executive',
                'academic_summary': 'Academic',
                'simple_summary': 'Simple',
                'technical_summary': 'Technical',
                'news_summary': 'News'
            };
            return map[m] || m.replace(/_/g, ' ');
        };
        const renderSidebar = data => {
            const empty = Number(data.kpis?.summaries || 0) === 0;
            sidebar.querySelector('.analytics-sidebar__empty').hidden = !empty;
            sidebar.querySelectorAll('.analytics-sidebar__chart').forEach(node => { node.hidden = empty; });
            sidebar.querySelectorAll('[data-analytics]').forEach(node => {
                const key = node.dataset.analytics;
                node.textContent = key === 'reduction' && data.kpis[key] !== null ? `${data.kpis[key]}%` : key === 'method' ? formatMethod(data.kpis[key]) : formatMetric(data.kpis[key]);
            });
            if (typeof Chart === 'undefined') return;
            chartInstances.splice(0).forEach(chart => chart.destroy());
            if (empty) return;
            const base = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#080808',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        padding: 8,
                        cornerRadius: 4,
                        titleFont: { size: 10, family: 'Inter, sans-serif', weight: '600' },
                        bodyFont: { size: 10, family: 'Inter, sans-serif' }
                    }
                }
            };
            chartInstances.push(new Chart(document.getElementById('sidebarActivityChart'), {
                type: 'line',
                data: {
                    labels: data.trend.map(row => row.bucket),
                    datasets: [{
                        data: chartValues(data.trend),
                        borderColor: _accent,
                        backgroundColor: _alpha(_accent, .1),
                        fill: true,
                        tension: .3,
                        borderWidth: 2,
                        pointRadius: 2,
                        pointHoverRadius: 4,
                        pointBackgroundColor: _accent
                    }]
                },
                options: {
                    ...base,
                    scales: {
                        x: {
                            ticks: { maxTicksLimit: 5, maxRotation: 0, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } },
                            grid: { display: false }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } },
                            grid: { color: 'rgba(8, 8, 8, 0.05)' }
                        }
                    }
                }
            }));
            chartInstances.push(new Chart(document.getElementById('sidebarWordsChart'), {
                type: 'bar',
                data: {
                    labels: ['Words'],
                    datasets: [
                        { label: 'Original', data: [Number(data.words_comparison.original_words || 0)], backgroundColor: 'rgba(8, 8, 8, 0.25)', borderRadius: 3, borderSkipped: false },
                        { label: 'Summary', data: [Number(data.words_comparison.summary_words || 0)], backgroundColor: _accent, borderRadius: 3, borderSkipped: false }
                    ]
                },
                options: {
                    ...base,
                    plugins: {
                        ...base.plugins,
                        legend: { display: true, labels: { boxWidth: 8, boxHeight: 8, color: _muted, font: { size: 10, family: 'Inter, sans-serif' } } }
                    },
                    scales: {
                        x: { ticks: { color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { color: 'rgba(8, 8, 8, 0.05)' } }
                    }
                }
            }));
            chartInstances.push(new Chart(document.getElementById('sidebarCategoryChart'), {
                type: 'doughnut',
                data: {
                    labels: chartLabels(data.categories),
                    datasets: [{ data: chartValues(data.categories), backgroundColor: chartColors, borderWidth: 0 }]
                },
                options: {
                    ...base,
                    plugins: {
                        ...base.plugins,
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } } }
                    },
                    cutout: '68%'
                }
            }));
            chartInstances.push(new Chart(document.getElementById('sidebarMethodChart'), {
                type: 'bar',
                data: {
                    labels: chartLabels(data.styles),
                    datasets: [{ data: chartValues(data.styles), backgroundColor: _alpha(_accent, .65), borderRadius: 3, borderSkipped: false }]
                },
                options: {
                    ...base,
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0, color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { color: 'rgba(8, 8, 8, 0.05)' } },
                        y: { ticks: { color: _muted, font: { size: 9, family: 'Inter, sans-serif' } }, grid: { display: false } }
                    }
                }
            }));
        };
        renderSidebar(sidebarData);
        sidebar.querySelectorAll('.analytics-range').forEach(button => button.addEventListener('click', async () => {
            sidebar.querySelectorAll('.analytics-range').forEach(item => item.classList.remove('is-active'));
            button.classList.add('is-active');
            sidebar.classList.add('is-loading');
            try {
                const response = await fetch(`analytics.php?action=data&range=${encodeURIComponent(button.dataset.range)}`, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (data && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }
                if (!response.ok || data.error) throw new Error(data.error || 'Analytics unavailable');
                renderSidebar(data);
            } catch (error) {
                sidebar.querySelector('.analytics-sidebar__empty').textContent = 'Analytics data could not be loaded. Please try again.';
                sidebar.querySelector('.analytics-sidebar__empty').hidden = false;
            } finally { sidebar.classList.remove('is-loading'); }
        }));
    }

    // ── Summary length slider ────────────────────────────────────────────────
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
    document.querySelectorAll('[data-source-choice]').forEach(button => button.addEventListener('click', () => updateSource(button.dataset.sourceChoice, true)));
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
        if (sentenceCount) sentenceCount.value = ({ brief: 3, short: 5, balanced: 8, detailed: 11, comprehensive: 15 })[depth] || 8;
        document.getElementById('depth_description').textContent = description?.description || '';
    }));

    if (form) form.addEventListener('submit', event => {
        const method = sourceType?.value;
        const text = textInput?.value.trim() || '';
        const url = urlInput?.value.trim() || '';
        const file = fileInput?.files?.[0];
        let error = '';
        if (method === 'file' && !file) error = 'Choose a PDF or DOCX file to continue.';
        else if (method === 'file' && file && file.size > Number(capabilities.maxUploadBytes || 0)) error = 'This file is larger than the 30 MB limit.';
        else if (method === 'file' && file && !/\.(pdf|docx)$/i.test(file.name)) error = 'Choose a PDF or DOCX file.';
        else if (method === 'text' && !text) error = 'Paste document text to continue.';
        else if (method === 'text' && new TextEncoder().encode(text).length > Number(capabilities.maxTextBytes || 200000)) error = 'Text input is too long. Limit: 195 KB.';
        else if (method === 'url' && (!url || !urlInput.checkValidity() || !/^https?:\/\//i.test(url))) error = 'Enter a valid HTTP or HTTPS article URL.';
        if (error) {
            event.preventDefault();
            if (sourceError) { sourceError.textContent = error; sourceError.hidden = false; }
            const invalidInput = method === 'file' ? fileInput : method === 'url' ? urlInput : textInput;
            invalidInput?.setAttribute('aria-invalid', 'true');
            invalidInput?.focus();
            return;
        }
        if (sourceError) sourceError.hidden = true;
        const submitButton = form.querySelector('.summary-submit-button');
        if (submitButton) { submitButton.disabled = true; submitButton.textContent = 'Starting summary…'; }
        document.getElementById('btn-nutshell-action')?.setAttribute('disabled', 'disabled');
        if (submitStatus) submitStatus.textContent = 'Preparing your document for summarization…';
    });
    [fileInput, textInput, urlInput].forEach(input => input?.addEventListener('input', () => { input.removeAttribute('aria-invalid'); if (sourceError) sourceError.hidden = true; }));
    fileInput?.addEventListener('change', () => {
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
    let nutshellRequestKey = null;

    function setNutshellErrorState(errorMessage) {
        if (!nutshellPanel) return;
        nutshellPanel.classList.add('nutshell-panel--error');
        if (nutshellLoading) nutshellLoading.style.display = 'none';
        if (nutshellText) {
            nutshellText.textContent = '';
            nutshellText.style.display = 'none';
        }
        if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'none';
        if (nutshellErrorMessage) nutshellErrorMessage.textContent = errorMessage || 'An unexpected error occurred while distilling the text.';
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

            if (!originalText && !hasFile) {
                nutshellPanel.style.display = 'block';
                nutshellPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                setNutshellErrorState('Please paste article text, provide a valid URL, or select a document first.');
                if (textInput) textInput.focus();
                return;
            }

            // Reveal nutshell panel & loading state
            nutshellPanel.style.display = 'block';
            nutshellPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            resetNutshellState();
            if (nutshellLoading) nutshellLoading.style.display = 'flex';
            nutshellBtn.disabled = true;

            const formData = new FormData();
            if (csrfInput) formData.append('csrf_token', csrfInput.value);
            nutshellRequestKey ||= (window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`).replace(/[^A-Za-z0-9_-]/g, '');
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
                    window.location.href = data.redirect;
                    return;
                }

                if (nutshellLoading) nutshellLoading.style.display = 'none';
                nutshellBtn.disabled = false;

                if (data && data.success && data.nutshell) {
                    nutshellRequestKey = null;
                    resetNutshellState();
                    if (nutshellText) {
                        nutshellText.textContent = data.nutshell;
                        nutshellText.style.display = 'block';
                    }
                    if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'inline-flex';
                } else if (data && data.status === 'not_needed') {
                    nutshellRequestKey = null;
                    setNutshellErrorState('The primary summary is already concise.');
                } else if (data && data.message) {
                    nutshellRequestKey = null;
                    setNutshellErrorState(data.message);
                } else if (!response.ok) {
                    setNutshellErrorState(`Server responded with status ${response.status}. Please check your input and try again.`);
                } else {
                    setNutshellErrorState('Failed to extract a valid summary statement from the provided document.');
                }
            } catch (err) {
                if (nutshellLoading) nutshellLoading.style.display = 'none';
                nutshellBtn.disabled = false;
                setNutshellErrorState('Unable to reach the server. Please check your network connection and try again.');
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

    const logoutLinks = document.querySelectorAll('.js-logout-link');
    if (logoutLinks.length === 0) {
        return;
    }

    const logoutDialog = createLogoutDialog();
    let pendingHref = '';
    let lastActiveElement = null;

    const openLogoutDialog = (href) => {
        pendingHref = href;
        lastActiveElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        logoutDialog.overlay.hidden = false;
        logoutDialog.overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        window.setTimeout(() => logoutDialog.confirmButton.focus(), 0);
    };

    const closeLogoutDialog = () => {
        pendingHref = '';
        logoutDialog.overlay.hidden = true;
        logoutDialog.overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');

        if (lastActiveElement instanceof HTMLElement) {
            lastActiveElement.focus();
        }
    };

    logoutLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            openLogoutDialog(link.href);
        });
    });

    logoutDialog.cancelButton.addEventListener('click', closeLogoutDialog);
    logoutDialog.overlay.addEventListener('click', (event) => {
        if (event.target === logoutDialog.overlay) {
            closeLogoutDialog();
        }
    });
    logoutDialog.confirmButton.addEventListener('click', () => {
        if (pendingHref !== '') {
            window.location.href = pendingHref;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (logoutDialog.overlay.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeLogoutDialog();
            return;
        }

        if (event.key === 'Tab') {
            const focusableElements = [
                logoutDialog.cancelButton,
                logoutDialog.confirmButton,
            ];
            const currentIndex = focusableElements.indexOf(document.activeElement);
            const direction = event.shiftKey ? -1 : 1;
            const fallbackIndex = event.shiftKey ? focusableElements.length - 1 : 0;
            const nextIndex = currentIndex === -1
                ? fallbackIndex
                : (currentIndex + direction + focusableElements.length) % focusableElements.length;

            event.preventDefault();
            focusableElements[nextIndex].focus();
        }
    });
});

function createLogoutDialog() {
    const overlay = document.createElement('div');
    overlay.className = 'confirmation-modal';
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');

    overlay.innerHTML = `
        <div class="confirmation-modal__backdrop"></div>
        <div class="confirmation-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="logout-modal-title" aria-describedby="logout-modal-message">
            <p class="confirmation-modal__eyebrow">Session Confirmation</p>
            <h2 id="logout-modal-title" class="confirmation-modal__title">Log out?</h2>
            <p id="logout-modal-message" class="confirmation-modal__message">
                Are you sure you want to logout from your current session?
            </p>
            <div class="confirmation-modal__actions">
                <button type="button" class="confirmation-modal__button confirmation-modal__button-secondary">Cancel</button>
                <button type="button" class="confirmation-modal__button confirmation-modal__button-primary">Logout</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    return {
        overlay,
        cancelButton: overlay.querySelector('.confirmation-modal__button-secondary'),
        confirmButton: overlay.querySelector('.confirmation-modal__button-primary'),
    };
}
