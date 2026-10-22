(() => {
    'use strict';
    const catalog = window.lightErrorCatalog || {};
    const storagePrefix = `light-summary-draft:${location.pathname.replace(/[^/]*$/, '')}`;
    const storageKey = `${storagePrefix}:${window.lightDraftScope || 'session'}`;
    try { for (const key of Object.keys(sessionStorage)) if (key.startsWith(storagePrefix) && key !== storageKey) sessionStorage.removeItem(key); } catch {}
    const lifetime = 60 * 60 * 1000;
    let expiryTimer;
    const expire = savedAt => { clearTimeout(expiryTimer); expiryTimer = setTimeout(() => draft.clear(), Math.max(0, lifetime - (Date.now() - savedAt))); };
    function failure(value, fallback = 'PROCESSING_FAILED') {
        const code = value && typeof value === 'object' && catalog[value.code] ? value.code : fallback;
        const entry = catalog[code] || catalog.PROCESSING_FAILED || {
            title: 'We could not complete this request.',
            explanation: 'LIGHT could not confirm why this request failed.',
            recovery: 'Return to your source and settings to review the request.',
            retryable: false,
        };
        return { ...entry, code, reference: /^[A-Za-z0-9_-]{6,80}$/.test(value?.reference || '') ? value.reference : '' };
    }
    function render(container, value) {
        if (!container) return;
        const item = failure(value);
        container.replaceChildren();
        container.dataset.errorCode = item.code;
        const title = document.createElement('strong');
        title.className = 'light-error__title';
        title.textContent = item.title;
        container.append(title);
        for (const [name, text] of [['explanation', item.explanation], ['recovery', item.recovery]]) {
            const paragraph = document.createElement('p');
            paragraph.className = `light-error__${name}`;
            paragraph.textContent = text;
            container.append(paragraph);
        }
        if (item.reference) {
            const reference = document.createElement('p');
            reference.className = 'light-error__reference';
            reference.textContent = `Reference: ${item.reference}`;
            container.append(reference);
        }
        container.hidden = false;
        return item;
    }
    const draft = {
        read() {
            try {
                const stored = JSON.parse(sessionStorage.getItem(storageKey));
                if (!stored || Date.now() - stored.savedAt > lifetime) { this.clear(); return null; }
                expire(stored.savedAt);
                return stored;
            } catch { return null; }
        },
        write(value) {
            try { const savedAt = Date.now(); sessionStorage.setItem(storageKey, JSON.stringify({ ...value, savedAt })); expire(savedAt); return true; }
            catch { return false; }
        },
        clear() { clearTimeout(expiryTimer); try { sessionStorage.removeItem(storageKey); } catch {} },
    };
    window.LightErrors = { failure, render, draft };
})();
