/**
 * Checkout: field masks, client-side checks, billing toggle and submit to submit.php?action=checkout.
 * The server validates everything again and runs the payment driver (lib/Payment.php).
 * Button text comes from checkout.json → "text" (boot.text).
 *
 * The script only uses STANDARD names — never the page layout: form fields by their name attribute
 * (shipping_first_name … card_cvv, billing_same) and the classes in SEL. A new checkout design (components/checkout/form.php
 * or a template, docs/TEMPLATES.md) keeps these and this file needs no change.
 * Expiry: two selects card_month + card_year, or one "MM / YY" field card_expiry — both work.
 */
(function () {
    'use strict';

    var SEL = {
        form:     '.checkout-form',
        field:    '.field',            // wrapper of one input (gets .fq-invalid); holds the .field-error
        error:    '.field-error',
        billing:  '.billing-fields',   // shown when billing_same is "no"
        cta:      '.checkout-cta',     // submit button
        ctaLabel: '.checkout-cta-label',
        message:  '.checkout-error'    // message above the button
    };

    var boot = JSON.parse(document.getElementById('page-data').textContent);
    var form = document.querySelector(SEL.form);
    if (!form) return;
    var btn = form.querySelector(SEL.cta) || document.querySelector(SEL.cta);
    var billing = form.querySelector(SEL.billing);
    var busy = false;
    var text = boot.text || {};

    function t(key, fallback) { return text[key] || fallback; }

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var ADDRESS = ['first_name', 'last_name', 'address1', 'city', 'state', 'zip'];

    // A field by its name (the first element when there are several, e.g. radios).
    function field(name) {
        var el = form.elements[name];
        return el && el.length !== undefined && !el.tagName ? el[0] : el || null;
    }
    function val(name) { var n = field(name); return n ? String(n.value).trim() : ''; }
    function digits(s) { return String(s).replace(/\D/g, ''); }

    function luhn(num) {
        var sum = 0, alt = false;
        for (var i = num.length - 1; i >= 0; i--) {
            var n = +num.charAt(i);
            if (alt) { n *= 2; if (n > 9) n -= 9; }
            sum += n;
            alt = !alt;
        }
        return num.length > 0 && sum % 10 === 0;
    }

    // billing_same: radios (value 1 / 0) or a checkbox; no control at all = billing is the shipping address.
    function billingSame() {
        var el = form.elements.billing_same;
        if (!el) return true;
        if (el.type === 'checkbox') return el.checked;
        var r = form.querySelector('input[name="billing_same"]:checked');
        return !r || r.value === '1';
    }

    // Expiry as [month, year] from card_month + card_year, or from one "MM / YY" (or MM/YYYY) field card_expiry.
    function expiry() {
        if (field('card_expiry')) {
            var d = digits(val('card_expiry'));
            var m = +d.slice(0, 2), y = +d.slice(2);
            if (y && y < 100) y += 2000;
            return [m || 0, y || 0];
        }
        return [+val('card_month'), +val('card_year')];
    }
    function expiryField(which) { return field('card_expiry') ? 'card_expiry' : which; }

    function wrapOf(name) {
        var el = field(name);
        return el ? el.closest(SEL.field) : null;
    }

    function setError(name, message) {
        if (field('card_expiry') && (name === 'card_month' || name === 'card_year')) name = 'card_expiry';   // server errors
        var wrap = wrapOf(name);
        var input = field(name);
        if (wrap) {
            var err = wrap.querySelector(SEL.error);
            if (err) { err.textContent = message || ''; err.hidden = !message; }
            wrap.classList.toggle('fq-invalid', !!message);
        }
        if (input && input.setAttribute) {
            if (message) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
        }
    }

    function formError(message) {
        var box = form.querySelector(SEL.message) || document.querySelector(SEL.message);
        if (!box) return;
        box.textContent = message || '';
        box.hidden = !message;
    }

    function validate() {
        var errors = {};
        var groups = billingSame() ? ['shipping'] : ['shipping', 'billing'];
        groups.forEach(function (g) {
            ADDRESS.forEach(function (f) {
                if (field(g + '_' + f) && !val(g + '_' + f)) errors[g + '_' + f] = t('required', 'This field is required.');
            });
            if (val(g + '_zip') && !/^\d{5}$/.test(val(g + '_zip'))) errors[g + '_zip'] = 'Enter a 5-digit ZIP code.';
        });
        var phone = digits(val('shipping_phone'));
        if (phone.length === 11 && phone.charAt(0) === '1') phone = phone.slice(1);
        if (phone.length !== 10) errors.shipping_phone = 'Enter a valid 10-digit US phone number.';
        if (!EMAIL.test(val('shipping_email'))) errors.shipping_email = 'Enter a valid email address.';

        var card = digits(val('card_number'));
        var testCard = boot.testCard && card === boot.testCard;   // only set when payment_driver = test or vrio
        if (card.length < 13 || card.length > 19 || (!testCard && !luhn(card))) errors.card_number = 'Enter a valid card number.';
        var ex = expiry(), month = ex[0], year = ex[1], now = new Date();
        if (field('card_expiry')) {
            if (!month || month > 12 || !year) errors.card_expiry = 'Enter the expiry date as MM / YY.';
        } else {
            if (!month) errors.card_month = 'Select the expiry month.';
            if (!year) errors.card_year = 'Select the expiry year.';
        }
        if (month && (year < now.getFullYear() || (year === now.getFullYear() && month < now.getMonth() + 1))) errors[expiryField('card_month')] = 'This card has expired.';
        var cvv = digits(val('card_cvv'));
        if (cvv.length < 3 || cvv.length > 4) errors.card_cvv = 'Enter the 3 or 4 digit security code.';
        return errors;
    }

    function address(g) {
        var out = {};
        ADDRESS.concat(['address2']).forEach(function (f) { out[f] = val(g + '_' + f); });
        return out;
    }

    function showErrors(errors) {
        var keys = Object.keys(errors);
        keys.forEach(function (k) { setError(k, errors[k]); });
        var first = keys.length && field(field('card_expiry') && /^card_(month|year)$/.test(keys[0]) ? 'card_expiry' : keys[0]);
        if (first && first.focus) {
            first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            first.focus({ preventScroll: true });
        }
    }

    function tracking() {
        var out = {};
        try {
            var params = new URLSearchParams(location.search);
            ['affid', 'sub1', 'c1', 'click_id'].forEach(function (k) { if (params.get(k)) out[k] = params.get(k); });
        } catch (e) { /* old browser */ }
        return out;
    }

    // Button text: its .checkout-cta-label if it has one, else the whole button (its own HTML comes back afterwards).
    var btnLabel = btn ? (btn.querySelector(SEL.ctaLabel) || btn) : null;
    var btnHtml = btnLabel ? btnLabel.innerHTML : '';
    function setBusy(on) {
        busy = on;
        if (!btn) return;
        btn.disabled = on;
        if (on) btnLabel.textContent = t('processing', 'Processing…');
        else btnLabel.innerHTML = btnHtml;
    }

    // Masks (only for the fields the page has)
    function mask(name, fn) {
        var el = field(name);
        if (el && el.addEventListener) el.addEventListener('input', function (e) { e.target.value = fn(e.target.value); });
    }
    mask('card_number', function (v) { return digits(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 '); });
    mask('card_cvv', function (v) { return digits(v).slice(0, 4); });
    mask('shipping_zip', function (v) { return digits(v).slice(0, 5); });
    mask('billing_zip', function (v) { return digits(v).slice(0, 5); });
    mask('card_expiry', function (v) {
        var d = digits(v).slice(0, 4);
        return d.length > 2 ? d.slice(0, 2) + ' / ' + d.slice(2) : d;
    });
    mask('shipping_phone', function (v) {
        var d = digits(v);
        if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
        d = d.slice(0, 10);
        return d.length > 6 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : d.length > 3 ? '(' + d.slice(0, 3) + ') ' + d.slice(3) : d;
    });

    function clearFor(target) {
        if (target && target.name) setError(target.name, null);
    }
    form.addEventListener('input', function (e) { clearFor(e.target); formError(null); });
    form.addEventListener('change', function (e) {
        if (e.target.name === 'billing_same' && billing) billing.hidden = billingSame();
        clearFor(e.target);
    });
    if (billing) billing.hidden = billingSame();

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;
        formError(null);
        var errors = validate();
        if (Object.keys(errors).length) { showErrors(errors); return; }

        setBusy(true);
        var shipping = address('shipping');
        shipping.phone = val('shipping_phone');
        shipping.email = val('shipping_email');
        var ex = expiry();

        fetch(boot.submitUrl + '?action=checkout', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': boot.csrf },
            body: JSON.stringify({
                shipping: shipping,
                billing_same: billingSame(),
                billing: billingSame() ? null : address('billing'),
                card: { number: digits(val('card_number')), month: String(ex[0]), year: String(ex[1]), cvv: digits(val('card_cvv')) },
                prospect_id: boot.prospectId,
                tracking: tracking()   // Vrio tracking1-3 (affid, sub1/c1, click_id from the landing URL)
            })
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) { return { status: res.status, data: data }; });
        }).then(function (r) {
            if (r.data.success && r.data.redirect) {
                location.href = r.data.redirect + location.search;
                return;
            }
            setBusy(false);
            if (r.status === 422 && r.data.errors) { showErrors(r.data.errors); return; }
            if (r.status === 409) { location.href = 'product.php' + location.search; return; }
            formError(r.data.message || t('error', 'Something went wrong. Please try again.'));
        }).catch(function () {
            setBusy(false);
            formError(t('offline', 'We could not reach the server. Check your connection and try again.'));
        });
    });

    /* ------------------------------------------------------------------ address autocomplete */

    // Google Places suggestions on the shipping / billing address. Picking one fills address, city, state and ZIP.
    // Key and country come from config.php → google_maps (boot.maps). Without a key the fields work as before.
    function setField(name, value) {
        var input = field(name);
        if (!input || value == null) return;
        input.value = value;
        // Same events as typing: masks run, old errors clear.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function fillAddress(group, place) {
        var c = {};
        (place.address_components || []).forEach(function (part) {
            part.types.forEach(function (type) { if (!c[type]) c[type] = part; });
        });
        var long = function (type) { return c[type] ? c[type].long_name : ''; };
        var street = [long('street_number'), long('route')].filter(Boolean).join(' ');
        if (street && field(group + '_address1')) {
            // No "input" event on the address field itself: Google would take it as typing and open the suggestions again.
            field(group + '_address1').value = street;
            setError(group + '_address1', null);
            formError(null);
        }
        if (long('subpremise') && field(group + '_address2')) setField(group + '_address2', long('subpremise'));
        setField(group + '_city', long('locality') || long('postal_town') || long('sublocality_level_1') || long('administrative_area_level_3'));
        setField(group + '_zip', long('postal_code'));

        // State: the select only has the states we ship to (from the funnel); anything else stays empty.
        var state = field(group + '_state');
        var code = c.administrative_area_level_1 ? c.administrative_area_level_1.short_name : '';
        if (state && state.options) {
            var known = [].some.call(state.options, function (o) { return o.value === code; });
            setField(group + '_state', known ? code : '');
            if (code && !known) setError(group + '_state', t('state_not_served', 'We don’t ship to this state yet.'));
        }
    }

    function attachAutocomplete(group) {
        var input = field(group + '_address1');
        if (!input || input.getAttribute('data-autocomplete')) return;
        input.setAttribute('data-autocomplete', '1');
        var ac = new google.maps.places.Autocomplete(input, {
            types: ['address'],
            componentRestrictions: { country: (boot.maps && boot.maps.country) || 'us' },
            fields: ['address_components']
        });
        ac.addListener('place_changed', function () {
            var place = ac.getPlace();
            if (place && place.address_components) fillAddress(group, place);
        });
        // Enter picks a suggestion instead of submitting the form.
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var open = [].some.call(document.querySelectorAll('.pac-container'), function (el) {
                return el.offsetParent !== null && el.querySelector('.pac-item');
            });
            if (open) e.preventDefault();
        });
    }

    window.initAddressAutocomplete = function () {
        if (!window.google || !google.maps || !google.maps.places) return;
        attachAutocomplete('shipping');
        attachAutocomplete('billing');
    };

    if (boot.maps && boot.maps.key) {
        var script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(boot.maps.key) +
            '&libraries=places&callback=initAddressAutocomplete&loading=async';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }
})();
