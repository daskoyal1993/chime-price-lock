/**
 * Product page: treatment picker, plan options, price summary, sticky "Buy Now" bar and FAQ accordion.
 * The chosen plan is sent to submit.php?action=product, which stores it in the session and returns the checkout URL.
 * Button/label text comes from product.json → "text" (boot.text).
 *
 * The script only uses the STANDARD classes below — never the page layout (no nth-child, no parent > child paths).
 * A new product page design keeps these classes (and the data-* on them) and this file needs no change.
 * Full list and the data behind each one: docs/DESIGNER.md → "Product page: standard classes".
 */
(function () {
    'use strict';

    var SEL = {
        card:        '.product-card',            // [data-product] [data-name] [data-image]
        option:      '.product-option',          // one plan (gets .is-checked)
        optionInput: '.product-option-input',    // radio: [data-price] [data-months] [data-label] [data-per-day]
        options:     '.product-options',         // scrolled into view when a treatment is picked
        pricing:     '.product-pricing',         // shown once a plan is picked
        price:       '.product-price',
        perDay:      '.product-price-per-day',
        cta:         '.product-cta',             // card button "Continue with …"; inside a .product-option = buy that plan
        error:       '.product-error',
        pickerItem:  '.product-picker-item',     // [data-product] — hero treatment picker (gets .is-selected)
        bar:         '.buy-bar',
        barImage:    '.buy-bar-image',
        barName:     '.buy-bar-name',
        barPlan:     '.buy-bar-plan',
        barCta:      '.buy-bar-cta',
        faqQuestion: '.faq-question'             // aria-controls = the answer's id
    };

    var boot = JSON.parse(document.getElementById('page-data').textContent);
    var text = boot.text || {};
    var money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2, minimumFractionDigits: 0 });
    var money2 = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function all(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
    function one(sel, root) { return (root || document).querySelector(sel); }

    var cards = all(SEL.card);
    var pickers = all(SEL.pickerItem);
    var bar = one(SEL.bar);
    var barCta = bar ? one(SEL.barCta, bar) : null;

    var active = null;   // product card currently focused (treatment picked or plan chosen)
    var busy = false;

    // Text from product.json → text, with {placeholders} filled in.
    function t(key, fallback, vars) {
        var s = text[key] || fallback;
        Object.keys(vars || {}).forEach(function (k) { s = s.split('{' + k + '}').join(vars[k]); });
        return s;
    }

    function cardFor(product) {
        return cards.filter(function (c) { return c.getAttribute('data-product') === product; })[0] || null;
    }

    // Buttons of the card itself (not the ones inside a plan).
    function cardButtons(card) {
        return all(SEL.cta, card).filter(function (b) { return !b.closest(SEL.option); });
    }

    function selectedPlan(card) {
        return card ? one(SEL.optionInput + ':checked', card) : null;
    }

    function planVars(plan) {
        return {
            plan: plan.getAttribute('data-label'),
            price: money.format(+plan.getAttribute('data-price')),
            per_day: money2.format(+plan.getAttribute('data-per-day') || 0)
        };
    }

    function setText(el, value) { if (el) el.textContent = value; }

    function setActive(card) {
        active = card;
        pickers.forEach(function (p) {
            var on = card && p.getAttribute('data-product') === card.getAttribute('data-product');
            p.classList.toggle('is-selected', !!on);
            p.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        cards.forEach(function (c) { c.classList.toggle('is-active', c === card); });
        updateBar();
    }

    function updateBar() {
        if (!bar) return;
        if (!active) { bar.hidden = true; return; }
        var plan = selectedPlan(active);
        var img = one(SEL.barImage, bar);
        if (img) img.src = active.getAttribute('data-image');
        setText(one(SEL.barName, bar), active.getAttribute('data-name'));
        setText(one(SEL.barPlan, bar), plan ? t('bar_plan', '{plan} · {price} today', planVars(plan)) : '');
        setText(barCta, plan ? t('bar_buy', 'Buy Now') : t('bar_choose', 'Choose plan'));
        bar.hidden = false;
        document.body.classList.add('has-buy-bar');
    }

    function updateCard(card) {
        var plan = selectedPlan(card);
        all(SEL.option, card).forEach(function (row) {
            row.classList.toggle('is-checked', !!one(SEL.optionInput + ':checked', row));
        });
        var pricing = one(SEL.pricing, card);
        if (pricing) {
            pricing.hidden = !plan;
            if (plan) {
                var v = planVars(plan);
                setText(one(SEL.price, card), t('price', '{price} due today', v));
                setText(one(SEL.perDay, card), t('per_day', '· {per_day}/day', v));
            }
        }
        cardButtons(card).forEach(function (btn) {
            btn.disabled = !plan;
            btn.textContent = plan ? t('continue', 'Continue with {plan} — {price}', planVars(plan)) : t('choose_plan', 'Choose a plan length');
        });
    }

    function scrollToPlans(card) {
        var target = one(SEL.options, card) || card;
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function showError(card, message) {
        var box = one(SEL.error, card);
        if (!box) return;
        box.textContent = message || '';
        box.hidden = !message;
    }

    function buy(card, clicked) {
        var plan = selectedPlan(card);
        if (!plan) { scrollToPlans(card); return; }
        if (busy) return;
        busy = true;
        all(SEL.cta, card).forEach(function (b) { b.disabled = true; });
        [clicked].concat(cardButtons(card)).forEach(function (b) {
            if (!b) return;
            if (!b.hasAttribute('data-text')) b.setAttribute('data-text', b.textContent);
            b.textContent = t('wait', 'Please wait…');
        });
        if (barCta) barCta.disabled = true;
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
        if (barCta) barCta.disabled = false;
        all(SEL.cta, card).forEach(function (b) {   // plan buttons back to their own text
            b.disabled = false;
            if (b.hasAttribute('data-text') && b.closest(SEL.option)) b.textContent = b.getAttribute('data-text');
        });
        updateCard(card);
        showError(card, message);
    }

    pickers.forEach(function (p) {
        p.addEventListener('click', function () {
            var card = cardFor(p.getAttribute('data-product'));
            if (!card) return;
            setActive(card);
            scrollToPlans(card);
        });
    });

    cards.forEach(function (card) {
        card.addEventListener('change', function (e) {
            if (!e.target.matches || !e.target.matches(SEL.optionInput)) return;
            // Only one plan across all products.
            cards.forEach(function (other) {
                if (other === card) return;
                var r = selectedPlan(other);
                if (r) { r.checked = false; updateCard(other); }
            });
            updateCard(card);
            setActive(card);
        });
        all(SEL.cta, card).forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var row = btn.closest(SEL.option);
                if (row) {   // a plan's own button: choose that plan, then continue
                    e.preventDefault();
                    var input = one(SEL.optionInput, row);
                    if (input && !input.checked) {
                        input.checked = true;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                buy(card, btn);
            });
        });
        updateCard(card);
        if (selectedPlan(card)) setActive(card);
    });

    if (barCta) barCta.addEventListener('click', function () { if (active) buy(active); });

    all(SEL.faqQuestion).forEach(function (btn) {
        btn.addEventListener('click', function () {
            var open = btn.getAttribute('aria-expanded') !== 'true';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            var answer = document.getElementById(btn.getAttribute('aria-controls'));
            if (answer) answer.hidden = !open;
        });
    });
})();
