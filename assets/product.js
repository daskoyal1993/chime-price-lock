/**
 * Product page: treatment picker, plan rows, sticky "Buy Now" bar and FAQ accordion.
 * The chosen plan is sent to submit.php?action=product, which stores it in the session
 * and returns the checkout URL. Button/label text comes from product.json → "text" (boot.text).
 */
(function () {
    'use strict';

    var boot = JSON.parse(document.getElementById('page-data').textContent);
    var cards = [].slice.call(document.querySelectorAll('.product-card'));
    var treats = [].slice.call(document.querySelectorAll('.treat-card'));
    var bar = document.getElementById('buy-bar');
    var barBtn = document.getElementById('buy-bar-btn');
    var text = boot.text || {};
    var money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2, minimumFractionDigits: 0 });

    var active = null;   // product card currently focused (treatment picked or plan chosen)
    var busy = false;

    // Text from product.json → text, with {placeholders} filled in.
    function t(key, fallback, vars) {
        var s = text[key] || fallback;
        Object.keys(vars || {}).forEach(function (k) { s = s.split('{' + k + '}').join(vars[k]); });
        return s;
    }

    function cardFor(keyword) {
        return cards.filter(function (c) { return c.getAttribute('data-product') === keyword; })[0] || null;
    }

    function selectedPlan(card) {
        return card ? card.querySelector('input[type="radio"]:checked') : null;
    }

    function setActive(card) {
        active = card;
        treats.forEach(function (t) {
            var on = card && t.getAttribute('data-treatment') === card.getAttribute('data-product');
            t.classList.toggle('is-selected', !!on);
            t.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        cards.forEach(function (c) { c.classList.toggle('is-active', c === card); });
        updateBar();
    }

    function planVars(plan) {
        return { plan: plan.getAttribute('data-label'), price: money.format(+plan.getAttribute('data-price')) };
    }

    function updateBar() {
        if (!active) { bar.hidden = true; return; }
        var plan = selectedPlan(active);
        document.getElementById('buy-bar-img').src = active.getAttribute('data-image');
        document.getElementById('buy-bar-name').textContent = active.getAttribute('data-name');
        document.getElementById('buy-bar-plan').textContent = plan
            ? t('bar_plan', '{plan} · {price} today', planVars(plan))
            : '';
        barBtn.textContent = plan ? t('bar_buy', 'Buy Now') : t('bar_choose', 'Choose plan');
        bar.hidden = false;
        document.body.classList.add('has-buy-bar');
    }

    function updateCard(card) {
        var plan = selectedPlan(card);
        var btn = card.querySelector('[data-buy]');
        card.querySelectorAll('.plan').forEach(function (row) {
            row.classList.toggle('is-checked', !!row.querySelector('input:checked'));
        });
        btn.disabled = !plan;
        btn.textContent = plan
            ? t('continue', 'Continue with {plan} — {price}', planVars(plan))
            : t('choose_plan', 'Choose a plan length');
    }

    function scrollToPlans(card) {
        var target = card.querySelector('.plans') || card;
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function showError(card, message) {
        var box = card.querySelector('.form-error');
        box.textContent = message || '';
        box.hidden = !message;
    }

    function buy(card) {
        var plan = selectedPlan(card);
        if (!plan) { scrollToPlans(card); return; }
        if (busy) return;
        busy = true;
        var btn = card.querySelector('[data-buy]');
        btn.disabled = true;
        btn.textContent = t('wait', 'Please wait…');
        barBtn.disabled = true;
        showError(card, null);

        fetch(boot.submitUrl + '?action=product', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': boot.csrf },
            body: JSON.stringify({ variant_id: +plan.value })
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) { return { status: res.status, data: data }; });
        }).then(function (r) {
            if (r.data.success && r.data.redirect) {
                location.href = r.data.redirect + location.search;
                return;
            }
            if (r.status === 409) { location.href = 'index.php' + location.search; return; }
            fail(card, r.data.message || t('error', 'Something went wrong. Please try again.'));
        }).catch(function () {
            fail(card, t('offline', 'We could not reach the server. Check your connection and try again.'));
        });
    }

    function fail(card, message) {
        busy = false;
        barBtn.disabled = false;
        updateCard(card);
        showError(card, message);
    }

    treats.forEach(function (t) {
        t.addEventListener('click', function () {
            var card = cardFor(t.getAttribute('data-treatment'));
            if (!card) return;
            setActive(card);
            scrollToPlans(card);
        });
    });

    cards.forEach(function (card) {
        card.addEventListener('change', function (e) {
            if (e.target.type !== 'radio') return;
            // Only one plan across all products.
            cards.forEach(function (other) {
                if (other === card) return;
                var r = selectedPlan(other);
                if (r) { r.checked = false; updateCard(other); }
            });
            updateCard(card);
            setActive(card);
        });
        card.querySelector('[data-buy]').addEventListener('click', function () { buy(card); });
        updateCard(card);
        if (selectedPlan(card)) setActive(card);
    });

    barBtn.addEventListener('click', function () { if (active) buy(active); });

    document.querySelectorAll('.faq-item button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var open = btn.getAttribute('aria-expanded') !== 'true';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.getElementById(btn.getAttribute('aria-controls')).hidden = !open;
        });
    });
})();
