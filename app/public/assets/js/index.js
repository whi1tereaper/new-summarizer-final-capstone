document.addEventListener('DOMContentLoaded', () => {
    const sidebarData = window.sidebarAnalyticsData;
    const sidebar = document.querySelector('.analytics-sidebar');
    if (sidebarData && sidebar && typeof Chart !== 'undefined') {
        const chartInstances = [];
        const chartColors = ['#6250c8', '#8b7cf0', '#3b82f6', '#14b8a6', '#f59e0b', '#64748b'];
        const chartLabels = rows => rows.map(row => row.label || 'Other');
        const chartValues = rows => rows.map(row => Number(row.total));
        const formatMetric = value => value === null || value === undefined ? 'N/A' : Number(value).toLocaleString();
        const renderSidebar = data => {
            const empty = Number(data.kpis?.summaries || 0) === 0;
            sidebar.querySelector('.analytics-sidebar__empty').hidden = !empty;
            sidebar.querySelectorAll('.analytics-sidebar__chart').forEach(node => { node.hidden = empty; });
            sidebar.querySelectorAll('[data-analytics]').forEach(node => {
                const key = node.dataset.analytics;
                node.textContent = key === 'reduction' && data.kpis[key] !== null ? `${data.kpis[key]}%` : key === 'method' ? (data.kpis[key] || 'N/A') : formatMetric(data.kpis[key]);
            });
            chartInstances.splice(0).forEach(chart => chart.destroy());
            if (empty) return;
            const base = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { enabled: true } } };
            chartInstances.push(new Chart(document.getElementById('sidebarActivityChart'), { type: 'line', data: { labels: data.trend.map(row => row.bucket), datasets: [{ data: chartValues(data.trend), borderColor: '#6250c8', backgroundColor: 'rgba(98,80,200,.12)', fill: true, tension: .3 }] }, options: { ...base, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } }));
            chartInstances.push(new Chart(document.getElementById('sidebarWordsChart'), { type: 'bar', data: { labels: ['Words'], datasets: [{ label: 'Original', data: [Number(data.words_comparison.original_words || 0)], backgroundColor: '#3b82f6' }, { label: 'Summary', data: [Number(data.words_comparison.summary_words || 0)], backgroundColor: '#6250c8' }] }, options: { ...base, plugins: { legend: { display: true, labels: { boxWidth: 8 } } }, scales: { y: { beginAtZero: true } } } }));
            chartInstances.push(new Chart(document.getElementById('sidebarCategoryChart'), { type: 'doughnut', data: { labels: chartLabels(data.categories), datasets: [{ data: chartValues(data.categories), backgroundColor: chartColors }] }, options: { ...base, cutout: '60%' } }));
            chartInstances.push(new Chart(document.getElementById('sidebarMethodChart'), { type: 'bar', data: { labels: chartLabels(data.styles), datasets: [{ data: chartValues(data.styles), backgroundColor: '#8b7cf0', borderRadius: 3 }] }, options: { ...base, indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } } }));
        };
        renderSidebar(sidebarData);
        sidebar.querySelectorAll('.analytics-range').forEach(button => button.addEventListener('click', async () => {
            sidebar.querySelectorAll('.analytics-range').forEach(item => item.classList.remove('is-active'));
            button.classList.add('is-active');
            sidebar.classList.add('is-loading');
            try {
                const response = await fetch(`analytics.php?action=data&range=${encodeURIComponent(button.dataset.range)}`, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || data.error) throw new Error(data.error || 'Analytics unavailable');
                renderSidebar(data);
            } catch (error) {
                sidebar.querySelector('.analytics-sidebar__empty').textContent = 'Analytics data could not be loaded. Please try again.';
                sidebar.querySelector('.analytics-sidebar__empty').hidden = false;
            } finally { sidebar.classList.remove('is-loading'); }
        }));
    }

    // ── Segmented length picker ──────────────────────────────────────────────
    const segContainer = document.querySelector('.length-segmented');
    const hiddenCount  = document.getElementById('sentence_count');
    const hiddenLength = document.getElementById('summary_length');

    if (segContainer && (hiddenCount || hiddenLength)) {
        segContainer.addEventListener('click', (event) => {
            const btn = event.target.closest('.length-seg-btn');
            if (!btn) return;

            // Deactivate all, then activate the clicked one.
            segContainer.querySelectorAll('.length-seg-btn').forEach((b) => {
                b.classList.remove('length-seg-btn--active');
                b.setAttribute('aria-pressed', 'false');
            });
            btn.classList.add('length-seg-btn--active');
            btn.setAttribute('aria-pressed', 'true');
            if (hiddenCount && btn.dataset.count) {
                hiddenCount.value = btn.dataset.count;
            }
            if (hiddenLength && btn.dataset.length) {
                hiddenLength.value = btn.dataset.length;
            }
        });
    }

    // Keep the two source inputs mutually exclusive. This prevents a file from
    // silently taking precedence over pasted text, while preserving the pasted
    // value if the user changes their mind and removes the selected file.
    const sourceFileInput = document.getElementById('pdf');
    const sourceTextInput = document.getElementById('text');
    const sourceSelectionNote = document.getElementById('source-selection-note');
    let savedSourceText = '';

    if (sourceFileInput && sourceTextInput) {
        sourceFileInput.addEventListener('change', () => {
            const hasFile = sourceFileInput.files && sourceFileInput.files.length > 0;
            if (hasFile) {
                savedSourceText = sourceTextInput.value;
                sourceTextInput.value = '';
                sourceTextInput.disabled = true;
                sourceTextInput.placeholder = 'Remove the selected file to paste text or a URL.';
                if (sourceSelectionNote) sourceSelectionNote.textContent = `Using ${sourceFileInput.files[0].name} as the source.`;
                return;
            }

            sourceTextInput.disabled = false;
            sourceTextInput.value = savedSourceText;
            sourceTextInput.placeholder = 'Paste article text/url here...';
            if (sourceSelectionNote) sourceSelectionNote.textContent = 'Choose one source: a file or pasted text/URL.';
        });
    }

    // ── Nutshell Generator ──────────────────────────────────────────────────
    const nutshellBtn = document.getElementById('btn-nutshell-action');
    const nutshellPanel = document.getElementById('nutshell-panel');
    const nutshellText = document.getElementById('nutshell-text');
    const nutshellLoading = document.getElementById('nutshell-loading');
    const nutshellCopyBtn = document.getElementById('nutshell-copy-btn');
    const nutshellError = document.getElementById('nutshell-error');
    const nutshellErrorMessage = document.getElementById('nutshell-error-message');
    const nutshellRetryBtn = document.getElementById('nutshell-retry-btn');

    function setNutshellErrorState(errorMessage) {
        if (!nutshellPanel) return;
        nutshellPanel.classList.add('nutshell-panel--error');
        if (nutshellLoading) nutshellLoading.style.display = 'none';
        if (nutshellText) nutshellText.style.display = 'none';
        if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'none';
        if (nutshellErrorMessage) nutshellErrorMessage.textContent = errorMessage || 'An unexpected error occurred while distilling the text.';
        if (nutshellError) nutshellError.style.display = 'flex';
    }

    function resetNutshellState() {
        if (!nutshellPanel) return;
        nutshellPanel.classList.remove('nutshell-panel--error');
        if (nutshellError) nutshellError.style.display = 'none';
        if (nutshellText) {
            nutshellText.textContent = '';
            nutshellText.style.display = 'none';
        }
        if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'inline-flex';
    }

    if (nutshellRetryBtn && nutshellBtn) {
        nutshellRetryBtn.addEventListener('click', () => {
            nutshellBtn.click();
        });
    }

    if (nutshellBtn && nutshellPanel) {
        nutshellBtn.addEventListener('click', async (e) => {
            e.preventDefault();

            const textInput = document.getElementById('text');
            const fileInput = document.getElementById('pdf');
            const csrfInput = document.querySelector('input[name="csrf_token"]');

            const originalText = textInput ? textInput.value.trim() : '';
            const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

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

                if (nutshellLoading) nutshellLoading.style.display = 'none';
                nutshellBtn.disabled = false;

                if (data && data.success && data.nutshell) {
                    resetNutshellState();
                    if (nutshellText) {
                        nutshellText.textContent = data.nutshell;
                        nutshellText.style.display = 'block';
                    }
                    if (nutshellCopyBtn) nutshellCopyBtn.style.display = 'inline-flex';
                } else if (data && data.message) {
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
