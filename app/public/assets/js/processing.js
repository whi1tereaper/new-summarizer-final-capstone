document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('processing-card');
    if (!card) return;

    const title = document.getElementById('processing-title');
    const status = document.getElementById('processing-status');
    const stateLabel = document.getElementById('processing-state-label');
    const elapsed = document.getElementById('processing-elapsed');
    const activityLabel = document.getElementById('processing-activity-label');
    const help = document.getElementById('processing-help');
    const startedAt = Date.now();
    let settled = false;

    const updateElapsed = () => {
        const seconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
        elapsed.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    };
    const clock = window.setInterval(updateElapsed, 1000);
    const slowNotice = window.setTimeout(() => {
        if (settled) return;
        stateLabel.textContent = 'Still working';
        status.textContent = 'This is taking a little longer. Larger documents and more detailed summaries can need extra time.';
        help.textContent = 'Keep this tab open. We’ll take you to the result as soon as it’s ready.';
    }, 30000);

    function finish() {
        settled = true;
        updateElapsed();
        window.clearInterval(clock);
        window.clearTimeout(slowNotice);
    }

    function showError(message) {
        finish();
        card.dataset.state = 'error';
        stateLabel.textContent = 'Summary unavailable';
        title.textContent = 'We couldn’t load your summary';
        status.textContent = message;
        document.title = 'Summary interrupted — LIGHT';
        document.getElementById('processing-track').hidden = true;
        document.getElementById('processing-activity').hidden = true;
        document.getElementById('processing-recovery').hidden = false;
        document.getElementById('processing-recovery-note').textContent = card.dataset.sourceType === 'file'
            ? 'Your settings are kept. Select your file again when you return.'
            : 'Your text or link and summary settings are kept so you can try again.';
        help.textContent = 'Return to your settings to review the document and start a new request.';
    }

    async function runSummarization() {
        try {
            // The existing request returns a finished result, not intermediate progress.
            // Keep waiting: a short browser timeout can discard a valid server result.
            const response = await fetch('summarize.php?action=execute', {headers: {Accept: 'application/json'}});
            let data;
            try {
                data = await response.json();
            } catch {
                showError('We couldn’t read the server’s response. Please return to your settings and try again.');
                return;
            }
            if (!response.ok || !data || data.success !== true || typeof data.redirect !== 'string') {
                showError('The summarizer couldn’t complete this request. Please return to your settings and try again.');
                return;
            }

            const destination = new URL(data.redirect, window.location.href);
            const resultPage = new URL('result.php', window.location.href);
            if (destination.origin !== resultPage.origin || destination.pathname !== resultPage.pathname) {
                showError('We couldn’t open the result. Please return to your settings and try again.');
                return;
            }

            finish();
            card.dataset.state = 'success';
            stateLabel.textContent = 'Ready';
            title.textContent = 'Your summary is ready';
            status.textContent = 'Opening your summary now…';
            activityLabel.textContent = 'Summary complete';
            document.title = 'Summary ready — LIGHT';
            const resultLink = document.getElementById('processing-result-link');
            resultLink.href = destination.href;
            resultLink.hidden = false;
            help.textContent = 'If the page doesn’t open automatically, use the link above.';
            window.location.replace(destination.href);
        } catch {
            showError('The connection was interrupted before we received a result. Please return to your settings and try again.');
        }
    }

    // Let the screen paint before starting the existing summarization request.
    window.requestAnimationFrame(() => window.setTimeout(runSummarization, 100));
});
