(() => {
    const storageKey = 'light-results-theme';

    function getPreferredTheme() {
        try {
            const savedTheme = localStorage.getItem(storageKey);
            if (savedTheme === 'light' || savedTheme === 'dark') {
                return savedTheme;
            }
        } catch (error) {
            // The current page can still switch themes when storage is unavailable.
        }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        const normalizedTheme = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.dataset.theme = normalizedTheme;
        if (document.body) {
            document.body.dataset.theme = normalizedTheme;
        }

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            const isDark = normalizedTheme === 'dark';
            button.setAttribute('aria-pressed', String(isDark));
            button.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        });

        // Dispatch a custom event so charts and reactive components can re-render immediately
        window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: normalizedTheme } }));
    }

    function bindThemeButton(button) {
        if (!button || button.dataset.themeBound === 'true') {
            return;
        }

        button.dataset.themeBound = 'true';
        button.addEventListener('click', () => {
            const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem(storageKey, nextTheme);
            } catch (error) {
                // Apply the selection for this page even if it cannot be persisted.
            }
            applyTheme(nextTheme);
        });
    }

    // Apply preferred theme IMMEDIATELY (synchronously) so DOM has the correct data-theme attribute
    // before any downstream scripts or Chart renderers initialize
    const initialTheme = getPreferredTheme();
    applyTheme(initialTheme);

    function initializeThemeControls() {
        applyTheme(getPreferredTheme());

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            bindThemeButton(button);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeThemeControls, { once: true });
    } else {
        initializeThemeControls();
    }
})();
