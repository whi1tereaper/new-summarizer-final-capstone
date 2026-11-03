(() => {
    'use strict';

    const panel = document.getElementById('student-trial');
    if (!panel) return;

    const form = document.getElementById('summarize-form');
    let blocked = panel.dataset.blocked === '1';

    const reflect = () => {
        panel.dataset.blocked = blocked ? '1' : '0';
        if (form) {
            const result = document.getElementById('nutshell-text');
            const hasResult = Boolean(result?.textContent.trim() && result.style.display !== 'none');
            const pending = document.getElementById('nutshell-panel')?.dataset.pending === '1';
            form.hidden = blocked && !hasResult && !pending;
            form.dataset.trialBlocked = blocked ? '1' : '0';

            // Only generation is disabled. Copy/status controls in this form remain
            // operable, including for assistive technology, after the last trial use.
            form.removeAttribute('aria-disabled');
            form.querySelectorAll('button[type="submit"],#btn-nutshell-action').forEach(button => {
                if (blocked) {
                    if (!button.disabled) button.dataset.trialDisabled = '1';
                    button.disabled = true;
                } else if (button.dataset.trialDisabled === '1') {
                    button.disabled = false;
                    delete button.dataset.trialDisabled;
                }
            });
        }
    };

    reflect();

    const refresh = async () => {
        try {
            const response = await fetch('access_status.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) return;

            const status = await response.json();
            blocked = !status.continued && status.remaining === 0;

            const counterEl = document.getElementById('student-trial-counter');
            if (counterEl) {
                counterEl.textContent = status.continued
                    ? 'Continued account access · fair-use controls apply'
                    : `${status.remaining} free ${status.remaining === 1 ? 'summary' : 'summaries'} remaining`;
            }

            const activeBar = document.getElementById('student-trial-active-bar');
            if (activeBar) {
                activeBar.hidden = blocked;
            }

            const exhaustedEl = document.getElementById('student-trial-exhausted');
            if (exhaustedEl) {
                exhaustedEl.hidden = !blocked;
            }

            const total = 3;
            const remaining = typeof status.remaining === 'number' ? status.remaining : (status.continued ? total : 0);
            const used = Math.max(0, Math.min(total, total - remaining));
            const percentUsed = Math.round((used / total) * 100);

            const countEl = document.getElementById('trial-meter-count');
            if (countEl) {
                countEl.innerHTML = `<strong>${used}</strong> / ${total} FREE SUMMARIES USED`;
            }

            const progressBar = document.getElementById('trial-progress-bar');
            if (progressBar) {
                progressBar.setAttribute('aria-valuenow', String(used));
                progressBar.setAttribute('aria-valuetext', `${used} of ${total} free summaries used`);
            }

            const meterFill = document.getElementById('trial-meter-fill');
            if (meterFill) {
                meterFill.style.width = `${percentUsed}%`;
            }

            const labelEl = document.getElementById('workspace-header-label');
            const headlineEl = document.getElementById('workspace-headline');
            const subtitleEl = document.getElementById('workspace-subtitle');
            if (blocked) {
                if (labelEl) labelEl.textContent = 'WORKSPACE ACCESS';
                if (headlineEl) headlineEl.textContent = 'Your DEPENDED workspace';
                if (subtitleEl) subtitleEl.textContent = "Your free trial is complete. Choose how you'd like to continue using DEPENDED.";
                document.body.classList.add('trial-exhausted-active');
            } else {
                if (labelEl) labelEl.textContent = 'DEPENDED Workspace';
                if (headlineEl) headlineEl.textContent = 'DEPENDED Workspace';
                if (subtitleEl) subtitleEl.textContent = 'Transform documents, articles, and text into clear and meaningful summaries.';
                document.body.classList.remove('trial-exhausted-active');
            }

            reflect();
        } catch (_) {
            /* Backend checks remain authoritative if the network is unavailable. */
        }
    };

    if (form) {
        form.addEventListener('submit', event => {
            if (blocked) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    }

    window.addEventListener('focus', refresh);
    window.addEventListener('pageshow', refresh);
    window.addEventListener('light:access-changed', refresh);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });
})();
