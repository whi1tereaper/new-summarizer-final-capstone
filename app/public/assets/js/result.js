document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('summary-page');
    if (!page) {
        return;
    }

    // ── Page data ────────────────────────────────────────────────────
    const summaryText = page.dataset.summaryText || '';
    const summaryId   = Number(page.dataset.summaryId || 0);
    const csrfToken   = page.dataset.csrfToken || '';
    const shareToken  = page.dataset.shareToken || '';
    let currentRating     = page.dataset.currentRating ? Number(page.dataset.currentRating) : null;
    let selectedRating    = null;
    let translatedText    = '';
    let translatedLanguage = 'en';
    let translationWarning = '';
    let translationActive  = false;

    // ── Helpers ──────────────────────────────────────────────────────
    function setTextState(element, message, tone = 'neutral') {
        if (!element) return;
        element.classList.remove('is-error', 'is-success');
        if (tone === 'error')   element.classList.add('is-error');
        if (tone === 'success') element.classList.add('is-success');
        element.textContent = message;
        element.classList.toggle('hidden', message === '');
    }

    function renderStars(hovered) {
        const active = hovered ?? selectedRating ?? 0;
        document.querySelectorAll('.star-btn').forEach((button) => {
            const value = Number(button.dataset.value || 0);
            button.classList.toggle('is-active', value <= active);
        });
    }

    function setSummaryStatus(message, tone = 'neutral') {
        setTextState(document.getElementById('summary-status'), message, tone);
    }

    function setActionLabel(button, text) {
        if (!button) return;
        const label = button.querySelector('.result-tool-btn__text') || button.querySelector('span');
        (label || button).textContent = text;
    }

    function setAudioPanelVisible(visible) {
        const panel = document.getElementById('audio-panel');
        if (panel) {
            panel.classList.toggle('hidden', !visible);
            panel.hidden = !visible;
        }
        const button = document.getElementById('play-audio-btn');
        if (button) button.setAttribute('aria-expanded', String(visible));
    }

    function setTranslationVisible(visible) {
        const section = document.getElementById('translation-section');
        if (section) {
            section.classList.toggle('is-hidden', !visible);
            section.hidden = !visible;
        }
        const button = document.getElementById('translate-btn');
        if (button) button.setAttribute('aria-expanded', String(visible));
    }

    function getAudioPlayer() {
        return document.getElementById('summary-audio-player');
    }

    function getPreferredAudioText() {
        return translationActive && translatedText ? translatedText : summaryText;
    }

    function getPreferredAudioLanguage() {
        return translationActive && translatedLanguage === 'tl' ? 'tl' : 'en';
    }

    function getAudioRequestKey() {
        return `${getPreferredAudioLanguage()}:${getPreferredAudioText()}`;
    }

    function resetAudioSource() {
        const audioPlayer = getAudioPlayer();
        if (!audioPlayer) return;
        audioPlayer.pause();
        audioPlayer.removeAttribute('src');
        audioPlayer.dataset.requestKey = '';
        audioPlayer.load();
        setAudioPanelVisible(false);
    }

    function renderTranslatedText(target) {
        target.textContent = '';
        translatedText.split('\n').forEach((line, index, arr) => {
            target.appendChild(document.createTextNode(line));
            if (index < arr.length - 1) {
                target.appendChild(document.createElement('br'));
            }
        });
    }

    async function parseJsonResponse(response, fallbackMessage = 'The server returned an invalid response.') {
        const rawText = await response.text();
        try {
            return JSON.parse(rawText);
        } catch {
            const normalized = rawText.trim();
            const message = normalized.startsWith('<') ? fallbackMessage : (normalized || fallbackMessage);
            throw new Error(message);
        }
    }

    // ── Rating & Feedback ─────────────────────────────────────────────
    function selectRating(value) {
        selectedRating = value;
        renderStars(null);

        document.querySelectorAll('.star-btn').forEach((button) => {
            const val = Number(button.dataset.value || 0);
            button.setAttribute('aria-checked', val === selectedRating ? 'true' : 'false');
        });

        const commentBlock = document.getElementById('feedback-comment-block');
        if (commentBlock) {
            commentBlock.classList.remove('hidden');
            commentBlock.hidden = false;
        }

        const reasonsBlock = document.getElementById('feedback-reasons-block');
        if (reasonsBlock) {
            if (selectedRating <= 3) {
                reasonsBlock.classList.remove('hidden');
                reasonsBlock.hidden = false;
            } else {
                reasonsBlock.classList.add('hidden');
                reasonsBlock.hidden = true;
            }
        }

        const message = document.getElementById('feedback-msg');
        if (message && message.classList.contains('is-error')) {
            setTextState(message, '');
        }

        const submitBtn = document.getElementById('feedback-submit-btn');
        if (submitBtn) {
            submitBtn.disabled = false;
        }
    }

    function submitFeedback() {
        const message = document.getElementById('feedback-msg');
        const submitBtn = document.getElementById('feedback-submit-btn');
        const commentInput = document.getElementById('feedback-comment');
        const commentText = commentInput ? commentInput.value.trim() : '';

        const selectedReasons = [];
        document.querySelectorAll('input[name="feedback_reason"]:checked').forEach((cb) => {
            if (cb.value) {
                selectedReasons.push(cb.value);
            }
        });

        if (!selectedRating || selectedRating < 1) {
            setTextState(message, 'Please select a star rating (1 to 5) above before submitting.', 'error');
            const firstStar = document.querySelector('.star-btn');
            if (firstStar) {
                firstStar.focus();
            }
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting…';
        }
        setTextState(message, '');

        fetch('feedback_submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                summary_id: summaryId,
                rating: selectedRating,
                comment: commentText,
                reasons: selectedReasons,
                share_token: shareToken,
            }),
        })
            .then((r) => parseJsonResponse(r, 'The feedback service returned an invalid response.'))
            .then((data) => {
                if (data.error) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit feedback';
                    }
                    setTextState(message, data.error, 'error');
                    return;
                }

                currentRating = selectedRating;
                const formContainer = document.getElementById('feedback-form-container');
                const confirmed = document.getElementById('feedback-confirmed');
                if (formContainer) {
                    formContainer.classList.add('hidden');
                }
                if (confirmed) {
                    confirmed.classList.remove('hidden');
                }
            })
            .catch(() => {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit feedback';
                }
                setTextState(message, 'Could not reach server. Please try again.', 'error');
            });
    }

    // ── Status polling (pending/processing summaries) ─────────────────
    function startStatusPolling() {
        const processingState = document.querySelector('[data-poll-status="1"]');
        if (!processingState || !summaryId) return;

        window.setInterval(() => {
            const url = shareToken
                ? `check_status.php?id=${encodeURIComponent(summaryId)}&share=${encodeURIComponent(shareToken)}`
                : `check_status.php?id=${encodeURIComponent(summaryId)}`;

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((r) => parseJsonResponse(r, 'Status polling is temporarily unavailable.'))
                .then((data) => {
                    if (data.status === 'completed' || data.status === 'failed') {
                        window.location.reload();
                    }
                })
                .catch(() => { /* polling can fail briefly */ });
        }, 3000);
    }

    // ── Audio ─────────────────────────────────────────────────────────
    function playSummaryAudio() {
        const button      = document.getElementById('play-audio-btn');
        const audioPanel  = document.getElementById('audio-panel');
        const audioPlayer = getAudioPlayer();

        if (!audioPanel || !audioPlayer) return;

        const audioText    = getPreferredAudioText();
        const audioLang    = getPreferredAudioLanguage();
        const requestKey   = getAudioRequestKey();

        if (!audioText) {
            setSummaryStatus('No summary text is available for audio playback.', 'error');
            return;
        }

        // Already generated - show panel and play
        if (audioPlayer.src && audioPlayer.dataset.requestKey === requestKey) {
            setAudioPanelVisible(true);
            audioPlayer.play().catch(() => {
                setSummaryStatus('Audio is ready. Press play on the player if autoplay is blocked.', 'success');
            });
            return;
        }

        if (button) {
            button.disabled = true;
            setActionLabel(button, 'Generating audio…');
        }
        setSummaryStatus('Generating audio…');

        fetch('tts_generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                summary_id: summaryId,
                share_token: shareToken,
                text: audioText,
                language: audioLang,
            }),
        })
            .then(async (r) => {
                const data = await parseJsonResponse(r, 'Audio generation returned an invalid response.');
                if (!r.ok || !data.success) throw new Error(data.message || 'Audio generation failed.');
                return data;
            })
            .then((data) => {
                // The reader may switch languages while audio is generated.
                if (requestKey !== getAudioRequestKey()) return;
                audioPlayer.src = data.audio_url;
                audioPlayer.dataset.requestKey = requestKey;
                setAudioPanelVisible(true);
                setSummaryStatus(data.message || 'Audio ready.', 'success');
                audioPlayer.play().catch(() => {
                    setSummaryStatus('Audio is ready. Press play on the player if autoplay is blocked.', 'success');
                });
            })
            .catch((error) => {
                if (requestKey !== getAudioRequestKey()) return;
                setSummaryStatus(error.message || 'Unable to generate audio right now.', 'error');
            })
            .finally(() => {
                if (button) {
                    button.disabled = false;
                    setActionLabel(button, 'Listen');
                }
            });
    }

    // ── Translation ───────────────────────────────────────────────────
    function translateToFilipino() {
        const button  = document.getElementById('translate-btn');
        const section = document.getElementById('translation-section');
        const target  = document.getElementById('translated-text');

        if (!section || !target) return;

        if (!summaryText) {
            setSummaryStatus('No summary text is available for translation.', 'error');
            return;
        }

        // Only completed translations toggle off; failed requests retry immediately.
        if (translationActive && !section.classList.contains('is-hidden')) {
            setTranslationVisible(false);
            translationActive = false;
            resetAudioSource();
            setActionLabel(button, 'Translate to Filipino');
            setSummaryStatus('');
            return;
        }

        // Already translated - just reveal
        if (translatedText) {
            renderTranslatedText(target);
            setTranslationVisible(true);
            setActionLabel(button, 'Hide translation');
            translationActive = translatedLanguage === 'tl';
            resetAudioSource();
            setSummaryStatus(
                translationWarning || 'Translation ready.',
                translationWarning ? 'error' : 'success'
            );
            return;
        }

        if (button) {
            setActionLabel(button, 'Translating…');
            button.disabled = true;
        }
        target.textContent = '';
        setTranslationVisible(false);
        setSummaryStatus('Translating summary…');

        fetch('translate_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                text: summaryText,
                target_lang: 'tl',
            }),
        })
            .then((r) => parseJsonResponse(r, 'Translation returned an invalid response.'))
            .then((data) => {
                if (data.error) {
                    throw new Error(data.error);
                }

                const nextText = String(data.translated || '').trim();
                if (!nextText) throw new Error('Translation returned an empty response.');
                if (data.target_lang !== 'tl' || data.used_fallback === true) {
                    throw new Error('The translation service did not return Filipino text. Please try again.');
                }

                translatedText     = nextText;
                translatedLanguage = data.target_lang === 'tl' ? 'tl' : 'en';
                translationWarning = typeof data.warning === 'string' ? data.warning : '';
                translationActive  = translatedLanguage === 'tl';
                renderTranslatedText(target);
                resetAudioSource();

                setTranslationVisible(true);
                setActionLabel(button, 'Hide translation');
                setSummaryStatus(
                    translationWarning || 'Translation ready.',
                    translationWarning ? 'error' : 'success'
                );
            })
            .catch((error) => {
                target.textContent = '';
                translatedText     = '';
                translatedLanguage = 'en';
                translationWarning = '';
                translationActive  = false;

                const failure = document.createElement('em');
                failure.className = 'result-status-line is-error';
                failure.textContent = error.message || 'Translation service unavailable.';
                target.appendChild(failure);
                setTranslationVisible(true);
                setActionLabel(button, 'Retry translation');
                setSummaryStatus(error.message || 'Translation service unavailable.', 'error');
            })
            .finally(() => {
                if (button) button.disabled = false;
            });
    }

    // ── Event listeners ───────────────────────────────────────────────
    document.querySelectorAll('.star-btn').forEach((button) => {
        button.addEventListener('mouseenter', () => renderStars(Number(button.dataset.value || 0)));
        button.addEventListener('mouseleave', () => renderStars(null));
        button.addEventListener('click', () => selectRating(Number(button.dataset.value || 0)));
    });

    const commentInput = document.getElementById('feedback-comment');
    const commentCounter = document.getElementById('feedback-counter');
    if (commentInput && commentCounter) {
        commentInput.addEventListener('input', () => {
            const remaining = 500 - commentInput.value.length;
            if (remaining <= 50) {
                commentCounter.classList.remove('hidden');
                commentCounter.textContent = `${remaining} character${remaining === 1 ? '' : 's'} left`;
            } else {
                commentCounter.classList.add('hidden');
            }
        });
    }

    const feedbackSubmitBtn = document.getElementById('feedback-submit-btn');
    if (feedbackSubmitBtn) {
        feedbackSubmitBtn.addEventListener('click', submitFeedback);
    }

    // Summary toolbar audio button.
    const playAudioBtn = document.getElementById('play-audio-btn');
    if (playAudioBtn) {
        playAudioBtn.setAttribute('aria-controls', 'audio-panel');
        playAudioBtn.addEventListener('click', playSummaryAudio);
    }

    const copySummaryBtn = document.getElementById('copy-summary-btn');
    if (copySummaryBtn) {
        let copyResetTimer = null;
        const copyBtnText = copySummaryBtn.querySelector('.result-tool-btn__text');

        copySummaryBtn.addEventListener('click', async () => {
            if (!summaryText) {
                setSummaryStatus('No summary text is available to copy.', 'error');
                return;
            }

            let copiedSuccessfully = false;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(summaryText);
                    copiedSuccessfully = true;
                } else {
                    const tempArea = document.createElement('textarea');
                    tempArea.value = summaryText;
                    tempArea.style.position = 'fixed';
                    tempArea.style.left = '-9999px';
                    tempArea.style.top = '0';
                    document.body.appendChild(tempArea);
                    tempArea.focus();
                    tempArea.select();
                    copiedSuccessfully = document.execCommand('copy');
                    document.body.removeChild(tempArea);
                }
            } catch {
                copiedSuccessfully = false;
            }

            if (copiedSuccessfully) {
                setSummaryStatus('Summary copied to clipboard.', 'success');

                // Trigger smooth copied animation sequence
                if (copyResetTimer) {
                    clearTimeout(copyResetTimer);
                }

                copySummaryBtn.classList.remove('is-copied');
                void copySummaryBtn.offsetWidth; // Force reflow to re-trigger keyframe
                copySummaryBtn.classList.add('is-copied');

                if (copyBtnText) {
                    copyBtnText.textContent = 'Copied!';
                }

                copyResetTimer = setTimeout(() => {
                    copySummaryBtn.classList.remove('is-copied');
                    if (copyBtnText) {
                        copyBtnText.textContent = 'Copy Summary';
                    }
                    copyResetTimer = null;
                }, 2200);
            } else {
                setSummaryStatus('Could not copy the summary. Please select and copy it manually.', 'error');
            }
        });
    }

    const exportPdfBtn = document.getElementById('export-pdf-btn');
    if (exportPdfBtn) {
        exportPdfBtn.addEventListener('click', () => window.print());
    }

    const translateBtn = document.getElementById('translate-btn');
    if (translateBtn) {
        translateBtn.setAttribute('aria-controls', 'translation-section');
        translateBtn.addEventListener('click', translateToFilipino);
    }

    const audioPlayer = document.getElementById('summary-audio-player');
    if (audioPlayer) {
        audioPlayer.addEventListener('error', () => {
            setSummaryStatus('Audio player could not load the generated file.', 'error');
        });
    }

    const sourceDisclosure = document.querySelector('.result-source-disclosure');
    if (sourceDisclosure) {
        const toggleText = sourceDisclosure.querySelector('.result-source-toggle__text');
        sourceDisclosure.addEventListener('toggle', () => {
            if (toggleText) {
                toggleText.textContent = sourceDisclosure.open ? 'Hide full source text' : 'Show full source text';
            }
        });
    }

    // Reveal linked sections, including headings inside disclosures.
    function findHashTarget(hash) {
        if (!hash || hash === '#') return null;
        try {
            return document.getElementById(decodeURIComponent(hash.slice(1)));
        } catch {
            return null;
        }
    }

    function revealHashTarget(hash, smooth = false) {
        const target = findHashTarget(hash);
        if (!target) return;

        // Older links may point to headings now inside a collapsed disclosure.
        for (let element = target; element; element = element.parentElement) {
            if (element.tagName === 'DETAILS') element.open = true;
        }

        window.requestAnimationFrame(() => {
            const focusTarget = target.tagName === 'DETAILS'
                ? target.querySelector('summary') || target
                : target;
            if (!focusTarget.matches('a[href], button, input, select, textarea, summary, [tabindex]')) {
                focusTarget.setAttribute('tabindex', '-1');
                focusTarget.addEventListener('blur', () => focusTarget.removeAttribute('tabindex'), { once: true });
            }
            focusTarget.focus({ preventScroll: true });
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            target.scrollIntoView({ behavior: smooth && !reducedMotion ? 'smooth' : 'instant', block: 'start' });
        });
    }

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin
            || destination.pathname !== window.location.pathname
            || destination.search !== window.location.search
            || !findHashTarget(destination.hash)) return;

        event.preventDefault();
        if (window.location.hash !== destination.hash) {
            window.history.pushState(null, '', destination.hash);
        }
        revealHashTarget(destination.hash, true);
    });

    window.addEventListener('hashchange', () => revealHashTarget(window.location.hash));
    revealHashTarget(window.location.hash);

    // ── Scroll Progress Bar ───────────────────────────────────────────
    const progressBar = document.querySelector('#result-scroll-progress .result-scroll-progress__bar');
    function updateScrollProgress() {
        if (!progressBar) return;
        const totalHeight = document.documentElement.scrollHeight - window.innerHeight;
        if (totalHeight <= 0) {
            progressBar.style.width = '100%';
            return;
        }
        const progress = Math.min(100, Math.max(0, (window.scrollY / totalHeight) * 100));
        progressBar.style.width = `${progress}%`;
    }

    if (progressBar) {
        window.addEventListener('scroll', updateScrollProgress, { passive: true });
        window.addEventListener('resize', updateScrollProgress);
        document.querySelectorAll('details').forEach((disclosure) => {
            disclosure.addEventListener('toggle', updateScrollProgress);
        });
        updateScrollProgress();
    }

    // ── Scroll Reveal Motion Effects ──────────────────────────────────
    function initScrollReveal() {
        const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const revealItems = document.querySelectorAll('.reveal-on-scroll');
        if (!revealItems.length) return;

        if (prefersReduced || !('IntersectionObserver' in window)) {
            revealItems.forEach((el) => el.classList.add('is-revealed'));
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-revealed');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.08, rootMargin: '0px 0px -40px 0px' }
        );

        revealItems.forEach((el) => observer.observe(el));
    }
    initScrollReveal();

    // ── Init ──────────────────────────────────────────────────────────
    renderStars(null);
    startStatusPolling();
});
