/* LIGHT theme engine. This file runs synchronously in the shared document head. */
(() => {
    'use strict';
    if (window.LightTheme) return;
    const storageKey = 'light-results-theme';
    const root = document.documentElement;
    const media = typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    const normalize = value => value === 'light' || value === 'dark' ? value : null;
    const storedPreference = () => {
        try { return normalize(window.localStorage.getItem(storageKey)); }
        catch (_) { return null; }
    };
    let preference = storedPreference();
    let currentTheme;

    function synchronizeControls() {
        if (document.body) document.body.dataset.theme = currentTheme;
        document.querySelectorAll('[data-theme-toggle]').forEach(control => {
            const dark = currentTheme === 'dark';
            control.setAttribute('aria-pressed', String(dark));
            control.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            control.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
        });
        document.querySelectorAll('[data-theme-preference]').forEach(control => {
            control.value = preference || 'system';
        });
        document.querySelectorAll('.nex-header').forEach(header => {
            header.dataset.activeTheme = currentTheme;
        });
    }

    function applyTheme(notify = true) {
        const next = preference || (media?.matches ? 'dark' : 'light');
        const previous = currentTheme;
        currentTheme = next;
        root.dataset.theme = next;
        root.dataset.themePreference = preference || 'system';
        root.style.colorScheme = next;
        synchronizeControls();
        if (notify && previous !== next) {
            window.dispatchEvent(new CustomEvent('themechange', {
                detail: { theme: next, preference: preference || 'system' }
            }));
        }
        return next;
    }

    function setPreference(value) {
        preference = normalize(value);
        try {
            if (preference) window.localStorage.setItem(storageKey, preference);
            else window.localStorage.removeItem(storageKey);
        } catch (_) {
            // In-memory preferences still work when browser storage is blocked.
        }
        return applyTheme();
    }
    window.LightTheme = Object.freeze({
        get: () => currentTheme,
        getPreference: () => preference || 'system',
        set: setPreference,
        toggle: () => setPreference(currentTheme === 'dark' ? 'light' : 'dark'),
        reset: () => setPreference('system'),
        refresh: synchronizeControls,
        storageKey
    });
    // Set the theme before any stylesheet can paint the page.
    applyTheme(false);
    document.addEventListener('click', event => {
        const control = event.target instanceof Element ? event.target.closest('[data-theme-toggle]') : null;
        if (!control || control.disabled || control.getAttribute('aria-disabled') === 'true') return;
        event.preventDefault();
        window.LightTheme.toggle();
    });
    document.addEventListener('change', event => {
        if (event.target instanceof Element && event.target.matches('[data-theme-preference]')) {
            setPreference(event.target.value);
        }
    });
    const systemChanged = () => { if (!preference) applyTheme(); };
    if (media?.addEventListener) media.addEventListener('change', systemChanged);
    else if (media?.addListener) media.addListener(systemChanged);
    window.addEventListener('storage', event => {
        if (event.key !== storageKey && event.key !== null) return;
        preference = event.key === null ? null : normalize(event.newValue);
        applyTheme();
    });
    window.addEventListener('pageshow', synchronizeControls);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', synchronizeControls, { once: true });
    } else synchronizeControls();
})();
