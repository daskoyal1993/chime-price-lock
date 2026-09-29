/**
 * QA error log, browser side (lib/ErrorLogger.php). Loaded first in <head> on every page (lib/Page.php → page_open()).
 *   window error + unhandledrejection → JS_ERROR
 *   fetch() wrapper: network failure, HTTP 5xx, a submit.php reply that isn't JSON, 4xx from other URLs → AJAX_ERROR
 *   (submit.php's own 4xx replies are logged by the server — bootstrap.php → funnel_json_response())
 * Reports go to qa-log.php with navigator.sendBeacon (works while the page navigates away); at most 20 per page,
 * each distinct error once. Shows a small badge while a QA test run (?qa_test=start) is active.
 * Never changes what the page scripts do: fetch returns exactly the same response.
 */
(function () {
    'use strict';

    var cfg;
    try { cfg = JSON.parse(document.getElementById('qa-errors-config').textContent); } catch (e) { return; }

    var MAX = 20;
    var sent = 0;
    var seen = {};
    var queue = [];
    var timer = null;

    function page() { return location.pathname.split('/').pop() || 'index.php'; }

    function flush() {
        timer = null;
        if (!queue.length) return;
        var body = JSON.stringify({ csrf: cfg.csrf, errors: queue.splice(0, queue.length), client: { ua: navigator.userAgent, screen: screen.width + 'x' + screen.height } });
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(cfg.endpoint, new Blob([body], { type: 'text/plain' }))) return;
        } catch (e) { /* fall back below */ }
        try { realFetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' }); } catch (e) { /* give up quietly */ }
    }

    function report(err) {
        try {
            var sig = [err.kind, err.message, err.file, err.line, err.url, err.status].join('|');
            if (seen[sig] || sent >= MAX) return;
            seen[sig] = true;
            sent++;
            err.page = page();
            queue.push(err);
            if (!timer) timer = setTimeout(flush, 300);
        } catch (e) { /* never break the page */ }
    }

    window.addEventListener('error', function (ev) {
        if (!ev || (!ev.message && !ev.error)) return;   // e.g. a failed <img>: not a script error
        report({
            kind: 'js',
            message: String(ev.message || (ev.error && ev.error.message) || 'Script error'),
            file: String(ev.filename || '').split('?')[0],
            line: ev.lineno || 0,
            column: ev.colno || 0,
            stack: ev.error && ev.error.stack ? String(ev.error.stack).slice(0, 800) : ''
        });
    });

    window.addEventListener('unhandledrejection', function (ev) {
        var r = ev && ev.reason;
        report({ kind: 'js', message: 'Unhandled promise rejection: ' + String((r && r.message) || r || ''), stack: r && r.stack ? String(r.stack).slice(0, 800) : '' });
    });

    window.addEventListener('pagehide', flush);

    // fetch() wrapper: same promise and response for the page scripts; failures are reported on the side.
    var realFetch = window.fetch ? window.fetch.bind(window) : null;
    if (realFetch) {
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var own = url.indexOf(cfg.endpoint) !== -1;
            var p = realFetch(input, init);
            if (own) return p;
            var short = String(url).split('#')[0].slice(0, 200);
            var ours = /submit\.php/.test(url);   // its 4xx replies are logged by the server already
            p.then(function (res) {
                var notJson = ours && (res.headers.get('Content-Type') || '').indexOf('json') === -1;
                var bad = res.status >= 500 || (!ours && res.status >= 400) || notJson;
                if (!bad) return;
                res.clone().text().then(function (t) {
                    report({ kind: 'ajax', url: short, status: res.status, message: (notJson ? 'Reply is not JSON' : 'HTTP ' + res.status) + ' from ' + short.split('?')[0], response: t.slice(0, 300) });
                }).catch(function () {});
            }, function (e) {
                report({ kind: 'ajax', url: short, status: 0, message: 'Network error: ' + String((e && e.message) || e) });
            });
            return p;
        };
    }

    // Badge while a QA test run is active.
    if (cfg.testId && cfg.testId !== 'no-test') {
        document.addEventListener('DOMContentLoaded', function () {
            var b = document.createElement('div');
            b.setAttribute('style', 'position:fixed;left:8px;bottom:8px;z-index:99999;padding:4px 8px;border-radius:6px;background:#111827;color:#fde68a;font:600 11px/1.4 system-ui,sans-serif;opacity:.85;pointer-events:none');
            b.textContent = 'QA ' + cfg.testId;
            document.body.appendChild(b);
        });
    }
})();
