document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('processing-card');
    if (!card) return;
    const errors = window.LightErrors;
    const liveStatus = document.getElementById('processing-live');
    const activity = document.getElementById('processing-activity');
    const recovery = document.getElementById('processing-recovery');
    const recoveryNote = document.getElementById('processing-recovery-note');
    const retryButton = document.getElementById('processing-retry');
    const cancelButton = document.getElementById('processing-cancel-button');
    let settled = false;
    let pollTimer = null;
    let activeRequest = null;
    let connectionFailures = 0;
    let retryAction = 'status';

    function stop() {
        settled = true;
        clearTimeout(pollTimer);
        pollTimer = null;
    }
    function working() {
        settled = false;
        connectionFailures = 0;
        card.dataset.state = 'working';
        recovery.hidden = true;
        activity.hidden = false;
        retryButton.disabled = false;
        if (cancelButton) cancelButton.disabled = false;
        if (liveStatus) liveStatus.textContent = 'Checking your summary request.';
    }
    function showFailure(value, canRetry = false, action = 'retry') {
        stop();
        const failure = errors.render(document.getElementById('processing-error'), value);
        card.dataset.state = 'error';
        document.title = `${failure.title} — LIGHT`;
        const draft = errors.draft.read();
        if (draft) {
            // Only definitive failures may receive a new submission token on return.
            if (action !== 'status' && draft.fields) delete draft.fields.request_token;
            errors.draft.write({ ...draft, failure });
        }
        if (recoveryNote) recoveryNote.textContent = card.dataset.sourceType === 'file'
            ? 'Return to your settings to choose a file. Your browser cannot restore the previous file selection.'
            : draft ? 'Your source and settings are saved in this tab for recovery for up to one hour.' : 'Return to summary settings to review your source.';
        if (liveStatus) liveStatus.textContent = failure.title;
        activity.hidden = true;
        recovery.hidden = false;
        retryAction = action;
        retryButton.hidden = !canRetry;
        retryButton.disabled = false;
        retryButton.textContent = action === 'status' ? 'Check connection and status' : 'Retry summary';
        document.getElementById('processing-error')?.focus();
    }
    function schedulePoll(delay = 2000) {
        if (settled) return;
        clearTimeout(pollTimer);
        pollTimer = setTimeout(() => request('status'), delay);
    }
    function complete(redirect) {
        let destination;
        try { destination = new URL(redirect, location.href); } catch { return false; }
        const resultPage = new URL('result.php', location.href);
        if (destination.origin !== resultPage.origin || destination.pathname !== resultPage.pathname) return false;
        stop();
        card.dataset.state = 'success';
        errors.draft.clear();
        if (liveStatus) liveStatus.textContent = 'Your summary is ready. Opening it now.';
        location.replace(destination.href);
        return true;
    }
    async function request(action) {
        if (settled) return;
        const controller = new AbortController();
        activeRequest = controller;
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const url = new URL('summarize.php', location.href);
            url.searchParams.set('action', action);
            url.searchParams.set('job_id', card.dataset.jobId || '');
            const options = { headers: { Accept: 'application/json' }, signal: controller.signal };
            if (action !== 'status') {
                options.method = 'POST';
                options.body = new URLSearchParams({ csrf_token: card.dataset.csrfToken || '', job_id: card.dataset.jobId || '' });
            }
            const response = await fetch(url, options);
            let data;
            try { data = await response.json(); } catch { throw new Error('response-unconfirmed'); }
            if (settled) return;
            connectionFailures = 0;
            if (data?.status === 'completed') {
                if (typeof data.redirect === 'string' && complete(data.redirect)) return;
                showFailure({ code: 'RESULT_UNAVAILABLE' }, true, 'status');
                return;
            }
            if (data?.failure || ['failed', 'timed_out', 'cancelled'].includes(data?.status)) {
                showFailure(data.failure || { code: 'PROCESSING_FAILED' }, data.can_retry === true && data.failure?.retryable === true);
                return;
            }
            if (!response.ok || data?.success !== true) {
                showFailure({ code: response.status === 401 ? 'AUTH_REQUIRED' : response.status === 404 ? 'JOB_NOT_FOUND' : response.status === 403 ? 'ACCESS_DENIED' : 'PROCESSING_FAILED' });
                return;
            }
            if (data.job_id) card.dataset.jobId = data.job_id;
            if (!card.dataset.jobId) { showFailure({ code: 'REQUEST_INVALID' }); return; }
            schedulePoll();
        } catch {
            if (settled) return;
            connectionFailures++;
            if (connectionFailures >= 3) showFailure({ code: 'NETWORK_INTERRUPTED' }, true, 'status');
            else {
                if (liveStatus) liveStatus.textContent = 'The connection was interrupted. Checking the existing request again.';
                schedulePoll(2500);
            }
        } finally { clearTimeout(timeout); if (activeRequest === controller) activeRequest = null; }
    }
    retryButton?.addEventListener('click', () => { const action = retryAction; working(); retryButton.disabled = true; request(action); });
    cancelButton?.addEventListener('click', async () => {
        if (settled) return;
        cancelButton.disabled = true;
        stop();
        activeRequest?.abort();
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch('summarize.php?action=cancel', {
                method: 'POST', headers: { Accept: 'application/json' },
                body: new URLSearchParams({ csrf_token: card.dataset.csrfToken || '', job_id: card.dataset.jobId || '' }),
                signal: controller.signal,
            });
            const data = await response.json();
            if (data?.status === 'completed' && typeof data.redirect === 'string' && complete(data.redirect)) return;
            if (!response.ok || data?.failure) { showFailure(data.failure || { code: 'PROCESSING_FAILED' }); return; }
            location.replace('summarizer.php');
        } catch { showFailure({ code: 'NETWORK_INTERRUPTED' }, true, 'status'); }
        finally { clearTimeout(timeout); }
    });
    window.addEventListener('pagehide', () => { stop(); activeRequest?.abort(); });
    window.requestAnimationFrame(() => setTimeout(() => request('execute'), 100));
});
