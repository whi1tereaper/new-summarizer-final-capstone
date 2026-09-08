/**
 * landing-analytics.js
 * Instruments the landing page with telemetry: pageview, scroll depth,
 * CTA clicks, Core Web Vitals, and active dwell time.
 * Sends data to api/track_landing.php via navigator.sendBeacon.
 * Zero external dependencies.
 */
(function () {
    'use strict';

    // ── Configuration ───────────────────────────────────────────────────────────
    var ENDPOINT = 'api/track_landing.php';
    var FLUSH_INTERVAL_MS = 4000;

    // ── Session ID ───────────────────────────────────────────────────────────────
    // Re-use across same-tab navigations; new tab = new session.
    function getSessionId() {
        var key = 'lp_sid';
        var sid = sessionStorage.getItem(key);
        if (!sid) {
            sid = 'lp_' +
                Math.random().toString(36).slice(2, 11) +
                Date.now().toString(36);
            sessionStorage.setItem(key, sid);
        }
        return sid;
    }

    var SESSION_ID = getSessionId();
    var queue      = [];
    var pageStart  = Date.now();
    var scrollMilestonesHit = {};

    // ── Event queue helpers ──────────────────────────────────────────────────────
    function enqueue(type, label, value) {
        queue.push({ type: type, label: label || null, value: value != null ? value : null });
    }

    function flush() {
        if (queue.length === 0) return;
        var payload = JSON.stringify({
            session_id: SESSION_ID,
            referrer:   document.referrer || '',
            events:     queue.splice(0, queue.length)
        });
        var sent = false;
        if (navigator.sendBeacon) {
            sent = navigator.sendBeacon(ENDPOINT, new Blob([payload], { type: 'application/json' }));
        }
        if (!sent && window.fetch) {
            // Fallback: keepalive fetch guarantees delivery during page unload.
            fetch(ENDPOINT, {
                method:    'POST',
                headers:   { 'Content-Type': 'application/json' },
                body:      payload,
                keepalive: true
            }).catch(function () {});
        }
    }

    // ── 1. Initial Pageview ──────────────────────────────────────────────────────
    enqueue('pageview', 'landing_init', null);

    // ── 2. Core Web Vitals ───────────────────────────────────────────────────────
    // LCP — Largest Contentful Paint (target: < 2500 ms = Good)
    if ('PerformanceObserver' in window) {
        try {
            var lcpObs = new PerformanceObserver(function (list) {
                var entries = list.getEntries();
                if (entries.length) {
                    var lcp = entries[entries.length - 1];
                    enqueue('web_vitals', 'LCP', Math.round(lcp.startTime));
                }
            });
            lcpObs.observe({ type: 'largest-contentful-paint', buffered: true });
        } catch (e) {}

        // FID — First Input Delay (target: < 100 ms = Good)
        try {
            var fidObs = new PerformanceObserver(function (list) {
                var entries = list.getEntries();
                if (entries.length) {
                    var fi = entries[0];
                    enqueue('web_vitals', 'FID', Math.round(fi.processingStart - fi.startTime));
                }
            });
            fidObs.observe({ type: 'first-input', buffered: true });
        } catch (e) {}
    }

    // ── 3. Scroll Depth Milestones ───────────────────────────────────────────────
    function getScrollPercent() {
        var el  = document.documentElement;
        var scrollable = el.scrollHeight - el.clientHeight;
        if (scrollable <= 0) return 100;
        return Math.min(100, Math.round((window.scrollY / scrollable) * 100));
    }

    var scrollTimer = null;
    function onScroll() {
        if (scrollTimer) return;
        scrollTimer = setTimeout(function () {
            scrollTimer = null;
            var pct = getScrollPercent();
            [25, 50, 75, 100].forEach(function (milestone) {
                if (pct >= milestone && !scrollMilestonesHit[milestone]) {
                    scrollMilestonesHit[milestone] = true;
                    enqueue('scroll_milestone', 'scroll_' + milestone, milestone);
                }
            });
        }, 250);
    }
    window.addEventListener('scroll', onScroll, { passive: true });

    // ── 4. CTA Click Tracking ────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var target = e.target && e.target.closest ? e.target.closest('a, button') : null;
        if (!target) return;

        var href = target.getAttribute('href') || '';
        var cls  = target.className || '';

        // "Get Started" button (btn-glitch-fill links to summarizer.php)
        if (cls.indexOf('btn-glitch-fill') !== -1 || href.indexOf('summarizer.php') !== -1) {
            enqueue('cta_click', 'hero_get_started', 1);
            flush();
        }

        // Nav "Register" button
        if (cls.indexOf('site-actions__button--register') !== -1 || href.indexOf('register.php') !== -1) {
            enqueue('cta_click', 'nav_register', 1);
            flush();
        }

        // Nav "Login" link
        if (cls.indexOf('site-actions__link') !== -1 && href.indexOf('login.php') !== -1) {
            enqueue('cta_click', 'nav_login', 1);
            flush();
        }
    });

    // ── 5. Active Dwell Time & Page Exit ────────────────────────────────────────
    // visibilitychange fires when the tab is backgrounded or closed.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            enqueue('page_exit', 'dwell_time', Math.round((Date.now() - pageStart) / 1000));
            flush();
        }
    });

    // pagehide covers browsers that don't fire visibilitychange reliably on close.
    window.addEventListener('pagehide', function () {
        enqueue('page_exit', 'dwell_time', Math.round((Date.now() - pageStart) / 1000));
        flush();
    });

    // ── 6. Periodic Flush ────────────────────────────────────────────────────────
    setInterval(flush, FLUSH_INTERVAL_MS);

}());
