/**
 * Design Manager (lib/Design.php): adds the active design's classes to elements with a data-design-id.
 * PHP already adds them while rendering; this covers HTML the browser creates later (e.g. quiz screens from funnel.js).
 * Only ADDS classes (classList.add): never removes, moves or rebuilds elements, and never touches behaviour.
 *
 *   <script id="design-config" type="application/json">{"classes": {"product-hero": "hero-v2 rounded-section"}}</script>
 *   <section class="pp-hero" data-design-id="product-hero">  →  class="pp-hero hero-v2 rounded-section"
 */
(function () {
    'use strict';

    var map;
    try { map = JSON.parse(document.getElementById('design-config').textContent).classes || {}; } catch (e) { return; }
    var ids = Object.keys(map);
    if (!ids.length) return;

    function apply(el) {
        var list = map[el.getAttribute('data-design-id')];
        if (!list) return;
        list.split(/\s+/).forEach(function (c) { if (c) el.classList.add(c); });
    }

    function scan(root) {
        if (root.nodeType !== 1) return;
        if (root.hasAttribute('data-design-id')) apply(root);
        [].forEach.call(root.querySelectorAll('[data-design-id]'), apply);
    }

    scan(document.documentElement);

    // Elements added later (quiz screens, messages): style them as they appear.
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (r) { [].forEach.call(r.addedNodes, scan); });
        }).observe(document.body || document.documentElement, { childList: true, subtree: true });
    }

    // Public, for scripts that build HTML: DesignManager.apply(element)
    window.DesignManager = { apply: scan, classes: function (id) { return map[id] || ''; } };
})();
