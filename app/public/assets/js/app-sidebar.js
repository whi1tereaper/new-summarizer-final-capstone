/* Shared application navigation. Account data stays on the server; only the
 * non-sensitive desktop width preference is persisted in this browser. */
(() => {
    'use strict';

    const initialize = () => {
        const sidebar = document.getElementById('app-sidebar');
        if (!sidebar || sidebar.dataset.navigationBound === 'true') return;
        sidebar.dataset.navigationBound = 'true';

        const root = document.documentElement;
        const mobile = matchMedia('(max-width: 900px)');
        const openButton = document.getElementById('sidebar-open');
        const closeButton = document.getElementById('sidebar-close');
        const backdrop = document.getElementById('sidebar-backdrop');
        const accountButton = document.getElementById('sidebar-account-toggle');
        const accountMenu = document.getElementById('sidebar-account-menu');
        const collapseButtons = Array.from(document.querySelectorAll('[data-sidebar-collapse]'));
        const storageKey = 'depended-sidebar-collapsed';
        const legacyStorageKeys = ['dee-sidebar-collapsed', 'light-sidebar-collapsed'];
        const originalRole = sidebar.getAttribute('role');
        const inertStates = new Map();
        let drawerOpen = false;
        let returnFocus = null;
        let savedBodyOverflow = '';
        let savedRootOverflow = '';

        const focusable = container => Array.from(container.querySelectorAll(
            'a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'
        )).filter(element => element.getClientRects().length && !element.closest('[inert]')
            && getComputedStyle(element).visibility !== 'hidden' && element.tabIndex >= 0);

        const accountIsOpen = () => Boolean(accountMenu && !accountMenu.hidden);

        function closeAccount(restoreFocus = false) {
            if (!accountMenu || !accountButton) return;
            accountMenu.hidden = true;
            accountButton.setAttribute('aria-expanded', 'false');
            if (restoreFocus) accountButton.focus({ preventScroll: true });
        }

        function openAccount(focusPosition = null) {
            if (!accountMenu || !accountButton) return;
            accountMenu.hidden = false;
            accountButton.setAttribute('aria-expanded', 'true');
            if (focusPosition !== null) {
                const items = focusable(accountMenu);
                (focusPosition === 'last' ? items.at(-1) : items[0])?.focus();
            }
        }

        // Walk ancestor siblings as well as body children: some retained pages
        // render the shared navigation inside their existing page wrapper.
        function inertBackground() {
            let branch = sidebar;
            while (branch.parentElement) {
                for (const sibling of branch.parentElement.children) {
                    if (sibling === branch || sibling === backdrop || sibling.contains(backdrop)
                        || ['SCRIPT', 'STYLE', 'LINK'].includes(sibling.tagName)) continue;
                    if (!inertStates.has(sibling)) inertStates.set(sibling, sibling.inert);
                    sibling.inert = true;
                }
                if (branch.parentElement === document.body) break;
                branch = branch.parentElement;
            }
        }

        const backgroundObserver = new MutationObserver(() => {
            if (drawerOpen) inertBackground();
        });

        function restoreBackground() {
            backgroundObserver.disconnect();
            for (const [element, wasInert] of inertStates) element.inert = wasInert;
            inertStates.clear();
        }

        function reflectDrawer() {
            root.dataset.sidebarOpen = String(drawerOpen);
            openButton?.setAttribute('aria-expanded', String(drawerOpen));
            if (backdrop) backdrop.hidden = !drawerOpen;
            sidebar.inert = mobile.matches && !drawerOpen;
            if (mobile.matches) {
                sidebar.setAttribute('aria-hidden', String(!drawerOpen));
            } else sidebar.removeAttribute('aria-hidden');

            if (drawerOpen) {
                sidebar.setAttribute('role', 'dialog');
                sidebar.setAttribute('aria-modal', 'true');
            } else {
                sidebar.removeAttribute('aria-modal');
                if (originalRole) sidebar.setAttribute('role', originalRole);
                else sidebar.removeAttribute('role');
            }
        }

        function openDrawer() {
            if (!mobile.matches || drawerOpen) return;
            returnFocus = document.activeElement;
            closeAccount();
            drawerOpen = true;
            savedBodyOverflow = document.body.style.overflow;
            savedRootOverflow = root.style.overflow;
            document.body.style.overflow = 'hidden';
            root.style.overflow = 'hidden';
            reflectDrawer();
            inertBackground();
            backgroundObserver.observe(document.body, { childList: true });
            (closeButton || focusable(sidebar)[0])?.focus({ preventScroll: true });
        }

        function closeDrawer(restoreFocus = true) {
            const wasOpen = drawerOpen;
            closeAccount();
            drawerOpen = false;
            restoreBackground();
            if (wasOpen) {
                document.body.style.overflow = savedBodyOverflow;
                root.style.overflow = savedRootOverflow;
            }
            reflectDrawer();
            if (wasOpen && restoreFocus) {
                const candidate = returnFocus instanceof HTMLElement && returnFocus.isConnected
                    && returnFocus.getClientRects().length && !returnFocus.closest('[inert]')
                    ? returnFocus : openButton;
                candidate?.focus({ preventScroll: true });
            }
        }

        function reflectCollapse() {
            const collapsed = root.dataset.sidebarCollapsed === 'true';
            for (const button of collapseButtons) {
                const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
                button.setAttribute('aria-expanded', String(!collapsed));
                const labelNode = button.querySelector('[data-sidebar-collapse-label]');
                if (labelNode) labelNode.textContent = label;
            }
        }

        // Fragments are deliberately unavailable to PHP. Synchronize only
        // visual active state for same-page deep links; access remains server-side.
        function syncActiveNavigation() {
            const currentUrl = new URL(window.location.href);
            const links = Array.from(sidebar.querySelectorAll('[data-sidebar-link]'));
            const candidates = links.filter(link => {
                const destination = new URL(link.href, currentUrl);
                return destination.origin === currentUrl.origin && destination.pathname === currentUrl.pathname;
            });
            const selected = (currentUrl.hash
                ? candidates.find(link => new URL(link.href, currentUrl).hash === currentUrl.hash)
                : null) || candidates.find(link => !new URL(link.href, currentUrl).hash);

            for (const link of links) {
                const active = link === selected;
                link.classList.toggle('is-active', active);
                if (active) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            }
        }

        accountButton?.addEventListener('click', () => {
            if (accountIsOpen()) closeAccount();
            else openAccount();
        });
        accountButton?.addEventListener('keydown', event => {
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault();
            openAccount(event.key === 'ArrowUp' ? 'last' : 'first');
        });
        accountMenu?.addEventListener('keydown', event => {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            const items = focusable(accountMenu);
            if (!items.length) return;
            event.preventDefault();
            const index = items.indexOf(document.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
                : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            items[next].focus();
        });

        document.addEventListener('pointerdown', event => {
            if (accountIsOpen() && !accountButton?.contains(event.target) && !accountMenu?.contains(event.target)) {
                closeAccount();
            }
        });
        document.addEventListener('focusin', event => {
            if (accountIsOpen() && !accountButton?.contains(event.target) && !accountMenu?.contains(event.target)) {
                closeAccount();
            }
            if (drawerOpen && !sidebar.contains(event.target)) {
                (closeButton || focusable(sidebar)[0])?.focus({ preventScroll: true });
            }
        });

        for (const button of collapseButtons) {
            button.addEventListener('click', () => {
                if (mobile.matches) return;
                closeAccount();
                root.dataset.sidebarCollapsed = String(root.dataset.sidebarCollapsed !== 'true');
                try { localStorage.setItem(storageKey, root.dataset.sidebarCollapsed); }
                catch { /* Collapsing remains available when browser storage is blocked. */ }
                reflectCollapse();
            });
        }
        openButton?.addEventListener('click', openDrawer);
        closeButton?.addEventListener('click', () => closeDrawer());
        backdrop?.addEventListener('click', () => closeDrawer());

        // Run before the existing logout-confirmation link handler. Its saved
        // focus target must remain visible after the account menu/drawer closes.
        sidebar.addEventListener('click', event => {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!link) return;
            const loggingOut = link.matches('.js-logout-link, a[href*="auth.php?action=logout"]');
            if (loggingOut) {
                if (drawerOpen) closeDrawer();
                else closeAccount(true);
            } else {
                closeAccount();
                if (drawerOpen) closeDrawer(false);
            }
        }, true);

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && accountIsOpen()) {
                event.preventDefault();
                event.stopPropagation();
                closeAccount(true);
                return;
            }
            if (!drawerOpen) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closeDrawer();
                return;
            }
            if (event.key !== 'Tab') return;
            const items = focusable(sidebar);
            const first = items[0];
            const last = items.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }, true);

        mobile.addEventListener('change', () => {
            const focusWasInside = sidebar.contains(document.activeElement);
            closeDrawer(false);
            if (mobile.matches && focusWasInside) openButton?.focus({ preventScroll: true });
        });
        window.addEventListener('pageshow', () => {
            closeDrawer(false);
            reflectCollapse();
        });
        window.addEventListener('storage', event => {
            if (event.key !== storageKey && !legacyStorageKeys.includes(event.key)) return;
            root.dataset.sidebarCollapsed = String(event.newValue === 'true' || event.newValue === '1');
            closeAccount();
            reflectCollapse();
        });
        window.addEventListener('hashchange', syncActiveNavigation);
        window.addEventListener('popstate', syncActiveNavigation);

        // The PHP partial applies this preference synchronously before page
        // content. Initialize control labels without changing the layout width.
        reflectCollapse();
        reflectDrawer();
        syncActiveNavigation();
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
