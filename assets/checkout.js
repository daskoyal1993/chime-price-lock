/**
 * Checkout: field masks, client-side checks, billing toggle and submit to submit.php?action=checkout.
 * The server validates everything again and runs the payment driver (lib/Payment.php).
 * Button text comes from checkout.json → "text" (boot.text).
 */
(function () {
    'use strict';

    var boot = JSON.parse(document.getElementById('page-data').textContent);
    var form = document.getElementById('checkout-form');
    var btn = document.getElementById('complete-btn');
    var billing = document.getElementById('billing-fields');
    var busy = false;
    var text = boot.text || {};

    function t(key, fallback) { return text[key] || fallback; }

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var ADDRESS = ['first_name', 'last_name', 'address1', 'city', 'state', 'zip'];

    function $(id) { return document.getElementById(id); }
    function val(id) { var n = $(id); return n ? n.value.trim() : ''; }
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

    function billingSame() {
        var r = form.querySelector('input[name="billing_same"]:checked');
        return !r || r.value === '1';
    }

    function setError(field, message) {
        var wrap = form.querySelector('[data-field="' + field + '"]');
        if (!wrap) return;
        var err = wrap.querySelector('.fq-error');
        var input = wrap.querySelector('input, select');
        err.textContent = message || '';
        err.hidden = !message;
        wrap.classList.toggle('fq-invalid', !!message);
        if (input) {
            if (message) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
        }
    }

    function formError(message) {
        var box = form.querySelector('.form-error');
        box.textContent = message || '';
        box.hidden = !message;
    }

    function validate() {
        var errors = {};
        var groups = billingSame() ? ['shipping'] : ['shipping', 'billing'];
        groups.forEach(function (g) {
            ADDRESS.forEach(function (f) {
                if (!val(g + '_' + f)) errors[g + '_' + f] = t('required', 'This field is required.');
            });
            if (val(g + '_zip') && !/^\d{5}$/.test(val(g + '_zip'))) errors[g + '_zip'] = 'Enter a 5-digit ZIP code.';
        });
        var phone = digits(val('shipping_phone'));
        if (phone.length === 11 && phone.charAt(0) === '1') phone = phone.slice(1);
        if (phone.length !== 10) errors.shipping_phone = 'Enter a valid 10-digit US phone number.';
        if (!EMAIL.test(val('shipping_email'))) errors.shipping_email = 'Enter a valid email address.';

        var card = digits(val('card_number'));
        var testCard = boot.testCard && card === boot.testCard;   // only set when payment_driver = test
        if (card.length < 13 || card.length > 19 || (!testCard && !luhn(card))) errors.card_number = 'Enter a valid card number.';
        var month = +val('card_month'), year = +val('card_year'), now = new Date();
        if (!month) errors.card_month = 'Select the expiry month.';
        if (!year) errors.card_year = 'Select the expiry year.';
        if (month && year === now.getFullYear() && month < now.getMonth() + 1) errors.card_month = 'This card has expired.';
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
        var first = keys.length && form.querySelector('[data-field="' + keys[0] + '"] input, [data-field="' + keys[0] + '"] select');
        if (first) {
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

    function setBusy(on) {
        busy = on;
        btn.disabled = on;
        btn.querySelector('span').textContent = on ? t('processing', 'Processing…') : t('button', 'Complete Purchase');
    }

    // Masks
    $('card_number').addEventListener('input', function (e) {
        e.target.value = digits(e.target.value).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 ');
    });
    ['card_cvv', 'shipping_zip', 'billing_zip'].forEach(function (id) {
        $(id).addEventListener('input', function (e) { e.target.value = digits(e.target.value).slice(0, id === 'card_cvv' ? 4 : 5); });
    });
    $('shipping_phone').addEventListener('input', function (e) {
        var d = digits(e.target.value);
        if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
        d = d.slice(0, 10);
        e.target.value = d.length > 6 ? '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6) : d.length > 3 ? '(' + d.slice(0, 3) + ') ' + d.slice(3) : d;
    });

    form.addEventListener('input', function (e) {
        var wrap = e.target.closest('[data-field]');
        if (wrap) setError(wrap.getAttribute('data-field'), null);
        formError(null);
    });
    form.addEventListener('change', function (e) {
        if (e.target.name === 'billing_same') billing.hidden = billingSame();
        var wrap = e.target.closest('[data-field]');
        if (wrap) setError(wrap.getAttribute('data-field'), null);
    });

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

        fetch(boot.submitUrl + '?action=checkout', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': boot.csrf },
            body: JSON.stringify({
                shipping: shipping,
                billing_same: billingSame(),
                billing: billingSame() ? null : address('billing'),
                card: { number: digits(val('card_number')), month: val('card_month'), year: val('card_year'), cvv: digits(val('card_cvv')) },
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
    function setField(id, value) {
        var input = $(id);
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
        if (street) {
            // No "input" event on the address field itself: Google would take it as typing and open the suggestions again.
            $(group + '_address1').value = street;
            setError(group + '_address1', null);
            formError(null);
        }
        if (long('subpremise') && $(group + '_address2')) setField(group + '_address2', long('subpremise'));
        setField(group + '_city', long('locality') || long('postal_town') || long('sublocality_level_1') || long('administrative_area_level_3'));
        setField(group + '_zip', long('postal_code'));

        // State: the select only has the states we ship to (from the funnel); anything else stays empty.
        var state = $(group + '_state');
        var code = c.administrative_area_level_1 ? c.administrative_area_level_1.short_name : '';
        if (state) {
            var known = [].some.call(state.options, function (o) { return o.value === code; });
            setField(group + '_state', known ? code : '');
            if (code && !known) setError(group + '_state', t('state_not_served', 'We don’t ship to this state yet.'));
        }
    }

    function attachAutocomplete(group) {
        var input = $(group + '_address1');
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
