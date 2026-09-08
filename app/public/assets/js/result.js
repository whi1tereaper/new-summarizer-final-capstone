document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('summary-page');
    if (!page) {
        return;
    }

    const summaryText = page.dataset.summaryText || '';
    const summaryId = Number(page.dataset.summaryId || 0);
    const csrfToken = page.dataset.csrfToken || '';
    const shareToken = page.dataset.shareToken || '';
    let currentRating = page.dataset.currentRating ? Number(page.dataset.currentRating) : null;
    let translatedText = '';
    let translatedLanguage = 'en';
    let translationWarning = '';
    let translationActive = false;

    function setTextState(element, message, tone = 'neutral') {
        if (!element) {
            return;
        }

        element.classList.remove('is-error', 'is-success');
        if (tone === 'error') {
            element.classList.add('is-error');
        } else if (tone === 'success') {
            element.classList.add('is-success');
        }

        element.textContent = message;
    }

    function renderStars(hovered) {
        const active = hovered ?? currentRating ?? 0;
        document.querySelectorAll('.star-btn').forEach((button) => {
            const value = Number(button.dataset.value || 0);
            button.classList.toggle('is-active', value <= active);
        });
    }

    function setSummaryStatus(message, tone = 'neutral') {
        setTextState(document.getElementById('summary-status'), message, tone);
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
        if (!audioPlayer) {
            return;
        }

        audioPlayer.pause();
        audioPlayer.removeAttribute('src');
        audioPlayer.dataset.requestKey = '';
        audioPlayer.load();
    }

    function renderTranslatedText(target) {
        target.textContent = '';

        const lines = translatedText.split('\n');
        lines.forEach((line, index) => {
            target.appendChild(document.createTextNode(line));
            if (index < lines.length - 1) {
                target.appendChild(document.createElement('br'));
            }
        });
    }

    async function parseJsonResponse(response, fallbackMessage = 'The server returned an invalid response.') {
        const rawText = await response.text();

        try {
            return JSON.parse(rawText);
        } catch (error) {
            const normalizedText = rawText.trim();
            const looksLikeHtml = normalizedText.startsWith('<');
            const message = looksLikeHtml
                ? fallbackMessage
                : (normalizedText || fallbackMessage);

            throw new Error(message);
        }
    }

    function submitRating(value) {
        const message = document.getElementById('feedback-msg');
        setTextState(message, 'Saving...');

        fetch('feedback_submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                summary_id: summaryId,
                rating: value,
                share_token: shareToken,
            }),
        })
            .then((response) => parseJsonResponse(response, 'The rating service returned an invalid response.'))
            .then((data) => {
                if (data.error) {
                    setTextState(message, data.error, 'error');
                    return;
                }

                currentRating = value;
                renderStars(null);
                setTextState(message, 'Rating saved - thank you.', 'success');
            })
            .catch(() => {
                setTextState(message, 'Could not reach server. Please try again.', 'error');
            });
    }

    function startStatusPolling() {
        const processingState = document.querySelector('[data-poll-status="1"]');
        if (!processingState || !summaryId) {
            return;
        }

        window.setInterval(() => {
            const statusUrl = shareToken
                ? `check_status.php?id=${encodeURIComponent(summaryId)}&share=${encodeURIComponent(shareToken)}`
                : `check_status.php?id=${encodeURIComponent(summaryId)}`;
            fetch(statusUrl, {
                headers: { Accept: 'application/json' },
            })
                .then((response) => parseJsonResponse(response, 'Status polling is temporarily unavailable.'))
                .then((data) => {
                    if (data.status === 'completed' || data.status === 'failed') {
                        window.location.reload();
                    }
                })
                .catch(() => {
                    // polling can fail briefly without breaking the page
                });
        }, 3000);
    }

    function playSummaryAudio() {
        const button = document.getElementById('play-audio-btn');
        const audioPanel = document.getElementById('audio-panel');
        const audioPlayer = getAudioPlayer();

        if (!button || !audioPanel || !audioPlayer) {
            return;
        }

        const audioText = getPreferredAudioText();
        const audioLanguage = getPreferredAudioLanguage();
        const requestKey = getAudioRequestKey();

        if (!audioText) {
            setSummaryStatus('No summary text is available for audio playback.', 'error');
            return;
        }

        if (audioPlayer.src && audioPlayer.dataset.requestKey === requestKey) {
            audioPanel.classList.add('is-visible');
            audioPlayer.play().catch(() => {
                setSummaryStatus('Audio is ready. Press play on the player if autoplay is blocked.', 'success');
            });
            return;
        }

        button.disabled = true;
        button.textContent = 'Generating audio...';
        setSummaryStatus('Generating audio...');

        fetch('tts_generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                summary_id: summaryId,
                share_token: shareToken,
                text: audioText,
                language: audioLanguage,
            }),
        })
            .then(async (response) => {
                const data = await parseJsonResponse(response, 'Audio generation returned an invalid response.');
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Audio generation failed.');
                }

                return data;
            })
            .then((data) => {
                audioPlayer.src = data.audio_url;
                audioPlayer.dataset.requestKey = requestKey;
                audioPanel.classList.add('is-visible');
                setSummaryStatus(data.message || 'Audio ready.', 'success');
                audioPlayer.play().catch(() => {
                    setSummaryStatus('Audio is ready. Press play on the player if autoplay is blocked.', 'success');
                });
            })
            .catch((error) => {
                setSummaryStatus(error.message || 'Unable to generate audio right now.', 'error');
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = 'Play Audio';
            });
    }

    function translateToFilipino() {
        const button = document.getElementById('translate-btn');
        const section = document.getElementById('translation-section');
        const target = document.getElementById('translated-text');

        if (!button || !section || !target) {
            return;
        }

        if (!summaryText) {
            setSummaryStatus('No summary text is available for translation.', 'error');
            return;
        }

        if (!section.classList.contains('is-hidden')) {
            section.classList.add('is-hidden');
            translationActive = false;
            button.textContent = 'Translate to Filipino';
            setSummaryStatus('');
            return;
        }

        if (translatedText) {
            renderTranslatedText(target);
            section.classList.remove('is-hidden');
            button.textContent = 'Hide Translation';
            translationActive = translatedLanguage === 'tl';
            setSummaryStatus(
                translationWarning || 'Translation ready.',
                translationWarning ? 'error' : 'success'
            );
            return;
        }

        button.textContent = 'Translating...';
        button.disabled = true;
        setSummaryStatus('Translating summary...');

        fetch('translate_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                text: summaryText,
                target_lang: 'tl',
            }),
        })
            .then((response) => parseJsonResponse(response, 'Translation returned an invalid response.'))
            .then((data) => {
                const nextText = String(data.translated || '').trim();
                if (!nextText) {
                    throw new Error('Translation returned an empty response.');
                }

                translatedText = nextText;
                translatedLanguage = data.target_lang === 'tl' ? 'tl' : 'en';
                translationWarning = typeof data.warning === 'string' ? data.warning : '';
                translationActive = translatedLanguage === 'tl';
                renderTranslatedText(target);
                resetAudioSource();

                section.classList.remove('is-hidden');
                button.textContent = 'Hide Translation';
                setSummaryStatus(
                    translationWarning || 'Translation ready.',
                    translationWarning ? 'error' : 'success'
                );
            })
            .catch((error) => {
                target.textContent = '';
                translatedText = '';
                translatedLanguage = 'en';
                translationWarning = '';
                translationActive = false;
                const failure = document.createElement('em');
                failure.className = 'summary-status is-error';
                failure.textContent = error.message || 'Translation service unavailable.';
                target.appendChild(failure);
                section.classList.remove('is-hidden');
                button.textContent = 'Translate to Filipino';
                setSummaryStatus(error.message || 'Translation service unavailable.', 'error');
            })
            .finally(() => {
                button.disabled = false;
            });
    }

    document.querySelectorAll('.star-btn').forEach((button) => {
        button.addEventListener('mouseenter', () => renderStars(Number(button.dataset.value || 0)));
        button.addEventListener('mouseleave', () => renderStars(null));
        button.addEventListener('click', () => submitRating(Number(button.dataset.value || 0)));
    });

    const playAudioButton = document.getElementById('play-audio-btn');
    if (playAudioButton) {
        playAudioButton.addEventListener('click', playSummaryAudio);
    }

    const translateButton = document.getElementById('translate-btn');
    if (translateButton) {
        translateButton.addEventListener('click', translateToFilipino);
    }

    const audioPlayer = document.getElementById('summary-audio-player');
    if (audioPlayer) {
        audioPlayer.addEventListener('error', () => {
            setSummaryStatus('Audio player could not load the generated file.', 'error');
        });
    }

    renderStars(null);
    startStatusPolling();

    const feedbackForm = document.querySelector('.feedback-form');
    if (feedbackForm) {
        feedbackForm.addEventListener('submit', () => {
            const submitBtn = feedbackForm.querySelector('.feedback-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
            }
        });
    }

});

