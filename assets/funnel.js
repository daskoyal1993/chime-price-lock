/**
 * Dynamic step funnel (quiz).
 *
 * index.php embeds #funnel-data:
 *   funnel  - normalized API definition (lib/FunnelRepository.php)
 *   screens - pages to show, one at a time (lib/Screens.php + index.json → "screens")
 *   config  - endpoints and validation limits; config.text = on-screen text from index.json → "text"
 *
 * Handles every API question type, show/hide conditions, "None of these" options,
 * age/BMI derivation, validation and disqualification. The same rules run again on the
 * server (lib/FunnelRules.php). When the intake is accepted the visitor goes to product.php.
 */
(function () {
    'use strict';

    var bootEl = document.getElementById('funnel-data');
    var root = document.getElementById('funnel');
    if (!bootEl || !root) return;

    var boot = JSON.parse(bootEl.textContent);
    var funnel = boot.funnel;
    var screens = boot.screens;
    var cfg = boot.config;
    var backBtn = document.getElementById('funnel-back');
    var progressEl = document.getElementById('funnel-progress');
    var text = cfg.text || {};

    // On-screen text from index.json → text (falls back to the built-in wording).
    function T(key, fallback) { return text[key] || fallback; }

    var STORE_KEY = 'funnel:' + funnel.slug + ':' + funnel.form.slug;
    var EXCLUSIVE = /^(none\b|no$|i am not\b)/i;
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var CARD_SELECT_MAX = 6; // selects with this many options or fewer render as option cards

    var questions = {};
    funnel.steps.forEach(function (step) {
        step.questions.forEach(function (q) { questions[q.key] = q; });
    });

    var state = restore() || { view: 'steps', screen: 0, answers: {}, dq: null };

    // Per-question display settings (index.json → questions): prechecked, text, compact, required_message.
    var SETTINGS = cfg.questions || {};
    function setting(key, name) { return SETTINGS[key] ? SETTINGS[key][name] : undefined; }

    // "prechecked" checkboxes start ticked until the visitor unticks them (an unticked box is kept as []).
    Object.keys(SETTINGS).forEach(function (key) {
        var q = questions[key];
        if (q && q.type === 'checkbox' && setting(key, 'prechecked') && !(key in state.answers)) {
            state.answers[key] = q.options.map(function (o) { return o.value; });
        }
    });
    var busy = false;

    /* ------------------------------------------------------------------ storage */

    function restore() {
        try {
            var saved = JSON.parse(localStorage.getItem(STORE_KEY));
            if (saved && typeof saved === 'object' && saved.answers && typeof saved.screen === 'number') return saved;
        } catch (e) { /* storage blocked or corrupt */ }
        return null;
    }

    function persist() {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) { /* ignore */ }
    }

    function forget() {
        try { localStorage.removeItem(STORE_KEY); } catch (e) { /* ignore */ }
    }

    /* ------------------------------------------------------------------ rules */

    function ageFromDob(dob) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dob || '');
        if (!m) return null;
        var y = +m[1], mo = +m[2], d = +m[3];
        var birth = new Date(y, mo - 1, d);
        if (birth.getFullYear() !== y || birth.getMonth() !== mo - 1 || birth.getDate() !== d) return null;
        var today = new Date();
        if (birth > today) return null;
        var age = today.getFullYear() - y;
        if (today.getMonth() < mo - 1 || (today.getMonth() === mo - 1 && today.getDate() < d)) age--;
        return age;
    }

    function calcBmi(height, weight) {
        if (!height || height.feet === '' || height.feet == null || !isNumeric(weight)) return null;
        var inches = (+height.feet) * 12 + (+height.inches || 0);
        var lbs = +weight;
        if (inches <= 0 || lbs <= 0) return null;
        return Math.round((703 * lbs / (inches * inches)) * 10) / 10;
    }

    function bmiRating(bmi) {
        if (bmi < 18.5) return 'Underweight';
        if (bmi < 25) return 'Normal';
        if (bmi < 30) return 'Overweight';
        return 'Obese';
    }

    /** Recompute age, bmi and bmi_rating in place. */
    function derive(answers) {
        delete answers.age;
        delete answers.bmi;
        delete answers.bmi_rating;
        var age = ageFromDob(answers.dob);
        if (age !== null) answers.age = age;
        var bmi = calcBmi(answers.height, answers.weight);
        if (bmi !== null) {
            answers.bmi = bmi;
            answers.bmi_rating = bmiRating(bmi);
        }
        return answers;
    }

    function isNumeric(v) {
        return v !== '' && v !== null && v !== undefined && !Array.isArray(v) && isFinite(v);
    }

    function isEmpty(v) {
        if (Array.isArray(v)) return v.length === 0;
        if (v && typeof v === 'object') return v.feet === '' || v.feet == null;
        return v === undefined || v === null || String(v).trim() === '';
    }

    function matches(c, answers) {
        var actual = answers[c.key];
        var expected = c.value;

        if (isEmpty(actual)) return c.operator === 'not_equal_to';

        if (Array.isArray(actual)) { // checkbox: "equal_to" means "includes"
            var has = actual.indexOf(expected) !== -1;
            if (c.operator === 'equal_to' || c.operator === 'contains') return has;
            if (c.operator === 'not_equal_to') return !has;
            return false;
        }

        var numeric = isNumeric(actual) && isNumeric(expected);
        var a = numeric ? +actual : String(actual);
        var b = numeric ? +expected : String(expected);

        switch (c.operator) {
            case 'equal_to': return a === b;
            case 'not_equal_to': return a !== b;
            case 'contains': return String(actual).toLowerCase().indexOf(String(expected).toLowerCase()) !== -1;
            case 'greater_than': return numeric && a > b;
            case 'equal_to_greater_than':
            case 'greater_than_or_equal_to': return numeric && a >= b;
            case 'less_than': return numeric && a < b;
            case 'equal_to_less_than':
            case 'less_than_or_equal_to': return numeric && a <= b;
            default:
                if (window.console) console.warn('Funnel: unknown condition operator', c.operator);
                return false;
        }
    }

    /** Visible when every "show" condition matches and not every "hide" condition matches. */
    function isVisible(q, answers) {
        if (q.type === 'hidden') return false;
        var show = [], hide = [];
        q.conditions.forEach(function (c) { (c.visibility === 'hide' ? hide : show).push(c); });
        for (var i = 0; i < show.length; i++) {
            if (!matches(show[i], answers)) return false;
        }
        if (hide.length && hide.every(function (c) { return matches(c, answers); })) return false;
        return true;
    }

    function screenQuestions(index) {
        return screens[index].keys.map(function (k) { return questions[k]; }).filter(Boolean);
    }

    function activeScreens() {
        var list = [];
        for (var i = 0; i < screens.length; i++) {
            if (screenQuestions(i).some(function (q) { return q.type !== 'bmi' && isVisible(q, state.answers); })) list.push(i);
        }
        return list;
    }

    function validateValue(q, value) {
        if (isEmpty(value)) return q.required ? requiredMessage(q) : null;

        switch (q.type) {
            case 'email':
                return EMAIL.test(value) ? null : 'Enter a valid email address.';
            case 'phone':
                var digits = String(value).replace(/\D/g, '');
                if (digits.length === 11 && digits.charAt(0) === '1') digits = digits.slice(1);
                return digits.length === 10 ? null : 'Enter a valid 10-digit US phone number.';
            case 'number':
                if (q.key === 'zip') return /^\d{5}$/.test(value) ? null : 'Enter a 5-digit ZIP code.';
                return isNumeric(value) ? null : 'Enter a number.';
            case 'date':
                var age = ageFromDob(value);
                if (age === null) return 'Enter a valid date (MM/DD/YYYY).';
                if (q.key === 'dob' && age < cfg.minAge) return 'You must be at least ' + cfg.minAge + ' years old.';
                return null;
            case 'height':
                return value.inches === '' || value.inches == null ? 'Select your height.' : null;
            case 'weight':
                if (!isNumeric(value) || +value < cfg.weightMin || +value > cfg.weightMax) {
                    return 'Enter a weight between ' + cfg.weightMin + ' and ' + cfg.weightMax + ' lbs.';
                }
                return null;
            default:
                return null;
        }
    }

    function requiredMessage(q) {
        if (q.type === 'checkbox' && q.options.length === 1) return setting(q.key, 'required_message') || 'Please check this box to continue.';
        if (q.type === 'checkbox' || q.type === 'radio' || q.type === 'select') return 'Please choose an option.';
        if (q.type === 'height') return 'Select your height.';
        return 'This field is required.';
    }

    function validateScreen(index) {
        var errors = {};
        screenQuestions(index).forEach(function (q) {
            if (!isVisible(q, state.answers)) return;
            var msg = q.type === 'bmi'
                ? (q.required && state.answers.bmi == null ? 'Enter your height and weight to calculate your BMI.' : null)
                : validateValue(q, state.answers[q.key]);
            if (msg) errors[q.key] = msg;
        });
        return errors;
    }

    function findDisqualifier(index) {
        var list = screenQuestions(index);
        for (var i = 0; i < list.length; i++) {
            var q = list[i];
            if (!isVisible(q, state.answers)) continue;
            var value = state.answers[q.key];
            for (var j = 0; j < q.options.length; j++) {
                var opt = q.options[j];
                var picked = Array.isArray(value) ? value.indexOf(opt.value) !== -1 : value === opt.value;
                if (picked && opt.disqualify) return { key: q.key, label: q.label, value: opt.value };
            }
        }
        return null;
    }

    /** Answers the server needs: visible, non-computed questions only. */
    function collectAnswers() {
        var out = {};
        Object.keys(questions).forEach(function (key) {
            var q = questions[key];
            if (q.type === 'bmi' || !isVisible(q, state.answers)) return;
            if (!isEmpty(state.answers[key])) out[key] = state.answers[key];
        });
        return out;
    }

    function screenOfKey(key) {
        for (var i = 0; i < screens.length; i++) {
            if (screens[i].keys.indexOf(key) !== -1) return i;
        }
        return -1;
    }

    /* ------------------------------------------------------------------ DOM helpers */

    var SVG = {
        check: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14.293 9.293a1 1 0 0 1 1.414 1.414l-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 1 1 1.414-1.414L11 12.586l3.293-3.293z"/></svg>',
        arrow: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>',
        chevron: '<svg class="select-arrow" width="12" height="7" viewBox="0 0 12 7" aria-hidden="true"><path fill="currentColor" d="M.24.24a.83.83 0 0 1 1.18 0l4.41 4.41L10.24.24a.83.83 0 1 1 1.18 1.18l-5 5a.83.83 0 0 1-1.18 0l-5-5a.83.83 0 0 1 0-1.18Z"/></svg>',
        info: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
        lock: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        tick: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
        alert: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 22 20H2L12 3Z"/><path d="M12 10v4"/><path d="M12 17h.01"/></svg>'
    };

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === false || v === null || v === undefined) return;
                if (k === 'text') node.textContent = v;
                else if (k === 'html') node.innerHTML = v; // only used with the static SVG strings above
                else if (k === 'className') node.className = v;
                else node.setAttribute(k, v === true ? '' : v);
            });
        }
        [].concat(children || []).forEach(function (child) {
            if (child === null || child === undefined || child === false) return;
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    function formatPhone(value) {
        var d = value.replace(/\D/g, '');
        if (d.length === 11 && d.charAt(0) === '1') d = d.slice(1);
        d = d.slice(0, 10);
        if (d.length > 6) return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
        if (d.length > 3) return '(' + d.slice(0, 3) + ') ' + d.slice(3);
        return d;
    }

    /** MM/DD/YYYY typing mask; the stored answer is ISO (YYYY-MM-DD) once complete. */
    function formatDob(value) {
        var d = value.replace(/\D/g, '').slice(0, 8);
        if (d.length > 4) return d.slice(0, 2) + '/' + d.slice(2, 4) + '/' + d.slice(4);
        if (d.length > 2) return d.slice(0, 2) + '/' + d.slice(2);
        return d;
    }

    function dobToIso(masked) {
        var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(masked);
        return m ? m[3] + '-' + m[1] + '-' + m[2] : masked;
    }

    function isoToDob(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? m[2] + '/' + m[3] + '/' + m[1] : (iso || '');
    }

    function cssEscape(s) {
        return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&');
    }

    function nextUrl(base) {
        if (!location.search) return base;
        return base + (base.indexOf('?') === -1 ? location.search : '&' + location.search.slice(1));
    }

    function post(action, body) {
        return fetch(cfg.submitUrl + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf },
            body: JSON.stringify(body)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { status: res.status, data: data };
            });
        });
    }

    // prospect_id / affid / c1 from the landing URL, stored with the lead.
    function tracking() {
        var out = {};
        try {
            var params = new URLSearchParams(location.search);
            ['prospect_id', 'affid', 'c1'].forEach(function (k) { if (params.get(k)) out[k] = params.get(k); });
        } catch (e) { /* old browser: no tracking */ }
        return out;
    }

    // From the contact screen on, save the answers so far in the background (submit.php?action=lead).
    // The quiz never waits for it or shows its errors.
    function saveLead() {
        var a = state.answers;
        if (isEmpty(a.first_name) || isEmpty(a.last_name) || !EMAIL.test(String(a.email || '')) || isEmpty(a.phone)) return;
        post('lead', { answers: collectAnswers(), tracking: tracking() }).catch(function () { /* ignore */ });
    }

    function wrapOf(key) {
        return root.querySelector('.fq[data-key="' + cssEscape(key) + '"]');
    }

    /* ------------------------------------------------------------------ question controls */

    function isCardSelect(q) {
        return q.type === 'select' && q.options.length <= CARD_SELECT_MAX;
    }

    function renderQuestion(q, isOnly) {
        var id = 'fq-' + q.key;
        var errId = id + '-error';
        var value = state.answers[q.key];
        var wrap;

        // Label: hidden visually when the question is the screen heading.
        function label(tag, attrs) {
            return el(tag, Object.assign({ className: 'fq-label' + (isOnly ? ' sr-only' : '') , text: q.label }, attrs || {}));
        }
        function desc() {
            return q.description && !(isOnly && q.type !== 'checkbox') ? el('p', { className: 'fq-desc', text: q.description }) : null;
        }

        if (q.type === 'checkbox' && q.options.length === 1) {
            // Consent: read-and-acknowledge block with a small checkbox.
            var opt = q.options[0];
            // "compact": just the checkbox line (the title is kept for screen readers); "text": shown instead of the option text.
            var compact = !!setting(q.key, 'compact');
            wrap = el('fieldset', { className: 'fq fq-consent' + (compact ? ' is-compact' : ''), 'data-key': q.key }, [
                el('legend', { className: compact ? 'sr-only' : 'fq-label', text: q.label }),
                q.description && !compact ? el('p', { className: 'consent-text', text: q.description }) : null,
                el('label', { className: 'consent-check' }, [
                    el('input', { type: 'checkbox', value: opt.value, 'data-key': q.key, 'aria-describedby': errId, checked: Array.isArray(value) && value.indexOf(opt.value) !== -1 }),
                    el('span', { text: setting(q.key, 'text') || opt.value })
                ])
            ]);
        } else if (q.type === 'checkbox' || (q.type === 'radio' && q.options.length > 2) || isCardSelect(q)) {
            var multi = q.type === 'checkbox';
            wrap = el('fieldset', { className: 'fq fq-cards', 'data-key': q.key }, [label('legend'), desc()]);
            var list = el('div', { className: 'opt-list', role: multi ? 'group' : 'radiogroup' });
            q.options.forEach(function (opt, i) {
                var checked = Array.isArray(value) ? value.indexOf(opt.value) !== -1 : value === opt.value;
                var exclusive = multi && q.options.length > 1 && EXCLUSIVE.test(opt.value);
                list.appendChild(el('label', { className: 'opt' + (exclusive ? ' opt-none' : '') }, [
                    el('span', { className: 'opt-body' }, [
                        el('span', { className: 'opt-text', text: opt.value }),
                        opt.subtext ? el('span', { className: 'opt-sub', text: opt.subtext }) : null
                    ]),
                    el('input', {
                        type: multi ? 'checkbox' : 'radio', name: q.key, value: opt.value, id: id + '-' + i,
                        className: 'sr-only', checked: checked, 'data-key': q.key, 'aria-describedby': errId,
                        'data-exclusive': exclusive ? '1' : null
                    }),
                    el('span', { className: 'opt-mark' + (multi ? '' : ' opt-mark-round'), html: SVG.check })
                ]));
            });
            wrap.appendChild(list);
        } else if (q.type === 'radio') {
            // Two options: segmented control (e.g. Female / Male).
            wrap = el('fieldset', { className: 'fq fq-seg', 'data-key': q.key }, [label('legend')]);
            var seg = el('div', { className: 'seg', role: 'radiogroup' });
            q.options.forEach(function (opt, i) {
                seg.appendChild(el('input', { type: 'radio', name: q.key, id: id + '-' + i, value: opt.value, className: 'sr-only', checked: value === opt.value, 'data-key': q.key, 'aria-describedby': errId }));
                seg.appendChild(el('label', { 'for': id + '-' + i, text: opt.value }));
            });
            wrap.appendChild(seg);
            if (q.description) wrap.appendChild(el('p', { className: 'fq-hint', html: SVG.info }, [q.description]));
        } else {
            wrap = el('div', { className: 'fq fq-' + q.type, 'data-key': q.key });
            var labelTag = q.type === 'height' || q.type === 'bmi' ? 'p' : 'label';
            if (q.type !== 'bmi') wrap.appendChild(label(labelTag, labelTag === 'label' ? { 'for': id } : { id: id + '-label' }));
            wrap.appendChild(control(q, id, errId, value));
            if (q.description && q.type !== 'textarea') wrap.appendChild(el('p', { className: 'fq-hint', html: SVG.info }, [q.description]));
        }

        wrap.appendChild(el('p', { className: 'fq-error', id: errId, hidden: true }));
        wrap.hidden = !isVisible(q, state.answers);
        return wrap;
    }

    function control(q, id, errId, value) {
        var input;
        switch (q.type) {
            case 'select':
                input = el('select', { id: id, className: 'input', 'data-key': q.key, 'aria-describedby': errId });
                input.appendChild(el('option', { value: '', text: q.key === 'shipping_state' ? T('select_state', 'Select your state') : T('select', 'Select…') }));
                q.options.forEach(function (opt) {
                    input.appendChild(el('option', { value: opt.value, text: opt.value, selected: value === opt.value }));
                });
                return el('div', { className: 'select-wrap', html: SVG.chevron }, [input]);

            case 'textarea':
                input = el('textarea', { id: id, className: 'input', rows: 4, maxlength: 2000, 'data-key': q.key, 'aria-describedby': errId, placeholder: q.description ? 'e.g. ' + q.description : null });
                input.value = value || '';
                return input;

            case 'height':
                var h = value || {};
                var feet = el('select', { className: 'input', 'data-key': q.key, 'data-part': 'feet', 'aria-label': 'Feet', 'aria-describedby': errId });
                var inches = el('select', { className: 'input', 'data-key': q.key, 'data-part': 'inches', 'aria-label': 'Inches', 'aria-describedby': errId });
                feet.appendChild(el('option', { value: '', text: T('feet', 'Feet') }));
                inches.appendChild(el('option', { value: '', text: T('inches', 'Inches') }));
                for (var f = 4; f <= 7; f++) feet.appendChild(el('option', { value: String(f), text: String(f), selected: String(h.feet) === String(f) }));
                for (var n = 0; n <= 11; n++) inches.appendChild(el('option', { value: String(n), text: String(n), selected: String(h.inches) === String(n) }));
                return el('div', { className: 'grid-2', role: 'group', 'aria-labelledby': id + '-label' }, [
                    el('div', { className: 'select-wrap', html: SVG.chevron }, [feet]),
                    el('div', { className: 'select-wrap', html: SVG.chevron }, [inches])
                ]);

            case 'bmi':
                return el('div', { className: 'bmi-box' }, [
                    el('aside', { className: 'info-card' }, [
                        el('span', { html: SVG.info, className: 'info-icon' }),
                        el('div', {}, [
                            el('h3', { text: T('bmi_title', 'Why BMI?') }),
                            el('p', { text: T('bmi_text', 'Your height & weight calculate BMI — one of several factors your clinician uses to personalize your plan.') })
                        ])
                    ]),
                    el('aside', { className: 'info-card info-card-warn', 'data-bmi': '1', hidden: true, 'aria-live': 'polite' }, [
                        el('span', { html: SVG.alert, className: 'info-icon' }),
                        el('div', {}, [
                            el('h3', { 'data-bmi-text': '1' }),
                            el('p', { text: T('bmi_warn_text', 'Even modest weight loss can improve health. Your plan is personalized beyond BMI.') })
                        ])
                    ])
                ]);

            default: // input, email, phone, number, date, weight
                var attrs = { id: id, className: 'input', 'data-key': q.key, 'aria-describedby': errId, readonly: q.readonly, placeholder: q.label.replace(/:$/, '') };
                if (q.type === 'email') { attrs.type = 'email'; attrs.autocomplete = 'email'; attrs.placeholder = T('placeholder_email', 'Email'); }
                else if (q.type === 'phone') { attrs.type = 'tel'; attrs.autocomplete = 'tel-national'; attrs.placeholder = T('placeholder_phone', 'Phone number'); }
                else if (q.type === 'date') { attrs.type = 'text'; attrs.inputmode = 'numeric'; attrs.placeholder = 'MM/DD/YYYY'; attrs.maxlength = 10; attrs.autocomplete = q.key === 'dob' ? 'bday' : null; }
                else if (q.type === 'weight') { attrs.type = 'text'; attrs.inputmode = 'numeric'; attrs.maxlength = 3; attrs.placeholder = T('placeholder_weight', 'Weight (lbs)'); }
                else if (q.type === 'number') { attrs.type = 'text'; attrs.inputmode = 'numeric'; if (q.key === 'zip') { attrs.maxlength = 5; attrs.autocomplete = 'postal-code'; attrs.placeholder = T('placeholder_zip', 'ZIP code'); } }
                else { attrs.type = 'text'; attrs.autocomplete = autocompleteFor(q.key); }
                input = el('input', attrs);
                input.value = q.type === 'date' ? isoToDob(value) : (value == null ? '' : value);
                return input;
        }
    }

    function autocompleteFor(key) {
        return {
            first_name: 'given-name', last_name: 'family-name',
            address_line1: 'address-line1', address_line2: 'address-line2', city: 'address-level2'
        }[key] || null;
    }

    function readValue(q, wrap) {
        if (q.type === 'checkbox') {
            return [].slice.call(wrap.querySelectorAll('input:checked')).map(function (i) { return i.value; });
        }
        if (q.type === 'radio' || isCardSelect(q)) {
            var picked = wrap.querySelector('input:checked');
            return picked ? picked.value : '';
        }
        if (q.type === 'height') {
            return { feet: wrap.querySelector('[data-part="feet"]').value, inches: wrap.querySelector('[data-part="inches"]').value };
        }
        var c = wrap.querySelector('[data-key]');
        if (!c) return '';
        return q.type === 'date' ? dobToIso(c.value) : c.value;
    }

    function showError(key, message) {
        var wrap = wrapOf(key);
        if (!wrap) return;
        var err = wrap.querySelector('.fq-error');
        err.textContent = message || '';
        err.hidden = !message;
        wrap.classList.toggle('fq-invalid', !!message);
        wrap.querySelectorAll('input, select, textarea').forEach(function (c) {
            if (message) c.setAttribute('aria-invalid', 'true'); else c.removeAttribute('aria-invalid');
        });
    }

    function refreshScreen(index) {
        derive(state.answers);
        screenQuestions(index).forEach(function (q) {
            var wrap = wrapOf(q.key);
            if (!wrap) return;
            var visible = isVisible(q, state.answers);
            if (wrap.hidden === visible) {
                wrap.hidden = !visible;
                if (!visible) showError(q.key, null);
            }
            if (q.type === 'bmi') {
                var card = wrap.querySelector('[data-bmi]');
                var bmi = state.answers.bmi;
                card.hidden = bmi == null;
                if (bmi != null) wrap.querySelector('[data-bmi-text]').textContent = 'Your BMI is ' + bmi.toFixed(1) + ' (' + state.answers.bmi_rating + ')';
            }
        });
        updateChrome();
    }

    /** Progress bar, header back arrow and button label. */
    function updateChrome() {
        var list = activeScreens();
        var pos = Math.max(0, list.indexOf(state.screen));
        var pct = state.view === 'steps' ? Math.max(4, Math.round((pos / Math.max(1, list.length)) * 100)) : 100;
        if (progressEl) {
            progressEl.firstElementChild.style.width = pct + '%';
            progressEl.setAttribute('aria-valuenow', String(pct));
        }
        if (backBtn) backBtn.hidden = !(state.view === 'steps' ? pos > 0 : state.view === 'dq');
        var btn = root.querySelector('[data-next]');
        if (btn && !busy && state.view === 'steps') {
            var isLast = list[list.length - 1] === state.screen;
            btn.firstChild.textContent = screens[state.screen].button || (isLast ? T('last_button', 'See my plan') : T('continue', 'Continue'));
        }
    }

    /* ------------------------------------------------------------------ views */

    function renderScreen() {
        derive(state.answers);
        var list = activeScreens();
        if (list.indexOf(state.screen) === -1) {
            // Current screen no longer applies: go to the nearest active one before it.
            var prev = list.filter(function (i) { return i < state.screen; });
            state.screen = prev.length ? prev[prev.length - 1] : (list[0] || 0);
        }

        var screen = screens[state.screen];
        var qs = screenQuestions(state.screen);
        // The main question of the screen: one that isn't a follow-up of another question on it.
        var primaries = qs.filter(function (q) {
            return q.type !== 'bmi' && !q.conditions.some(function (c) { return screen.keys.indexOf(c.key) !== -1; });
        });
        var isOnly = primaries.length === 1 && !screen.heading;
        var main = primaries[0];
        var heading = screen.heading || (isOnly ? main.label : screen.badge || funnel.form.name);
        var helper = screen.text || (isOnly && main.type !== 'checkbox' ? main.description : null)
            || (isOnly && main.type === 'checkbox' && main.options.length > 1 ? T('select_all', '*Select all that apply & click "Continue" below') : null);
        var hasFields = qs.some(function (q) { return ['input', 'email', 'phone', 'height', 'weight', 'date', 'number'].indexOf(q.type) !== -1; });

        var form = el('form', { className: 'quiz-form', novalidate: true });
        if (screen.badge) {
            form.appendChild(el('span', { className: 'quiz-badge' + (screen.badge_style === 'success' ? ' quiz-badge-success' : ''), html: screen.badge_style === 'success' ? SVG.tick : '' }, [screen.badge]));
        }
        var titleRow = el('div', { className: 'quiz-title-row' }, [el('h1', { className: 'quiz-title', tabindex: '-1', text: heading })]);
        if (screen.doctor && cfg.doctorImage) titleRow.appendChild(el('img', { className: 'quiz-doctor', src: cfg.doctorImage, alt: T('doctor_alt', 'Clinician who will review your intake') }));
        form.appendChild(titleRow);
        if (helper) form.appendChild(el('p', { className: 'quiz-helper' + (helper.charAt(0) === '*' ? ' is-note' : ''), text: helper }));

        var fields = el('div', { className: 'quiz-fields' + (qs.some(function (q) { return q.key === 'first_name'; }) && qs.some(function (q) { return q.key === 'last_name'; }) ? ' has-name-row' : '') });
        qs.forEach(function (q) { fields.appendChild(renderQuestion(q, isOnly && q === main)); });
        form.appendChild(fields);

        if (hasFields) form.appendChild(el('p', { className: 'quiz-private', html: SVG.lock }, [T('private', 'Private & secure.')]));
        form.appendChild(el('p', { className: 'form-error', role: 'alert', hidden: true }));
        var btn = el('button', { type: 'submit', className: 'btn-pill', 'data-next': '1' }, [T('continue', 'Continue')]);
        btn.insertAdjacentHTML('beforeend', SVG.arrow);
        form.appendChild(el('div', { className: 'quiz-actions' }, [btn]));

        root.replaceChildren(form);

        form.addEventListener('input', onChange);
        form.addEventListener('change', onChange);
        form.addEventListener('submit', function (e) { e.preventDefault(); onNext(); });

        refreshScreen(state.screen);
    }

    function onChange(e) {
        var target = e.target;
        var key = target.getAttribute('data-key');
        var q = key && questions[key];
        if (!q) return;
        var wrap = target.closest('.fq');
        clearTimeout(autoTimer);   // any newer answer cancels a pending auto-continue

        if (q.type === 'phone' && e.type === 'input') {
            target.value = formatPhone(target.value);
        } else if (q.type === 'date' && e.type === 'input') {
            target.value = formatDob(target.value);
        } else if ((q.type === 'number' && q.key === 'zip') || q.type === 'weight') {
            target.value = target.value.replace(/\D/g, '').slice(0, q.type === 'weight' ? 3 : 5);
        } else if (q.type === 'checkbox' && e.type === 'change' && target.checked) {
            // "None of these" clears the other choices, and picking a condition clears "None".
            var exclusive = target.getAttribute('data-exclusive') === '1';
            wrap.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                if (box === target) return;
                if (exclusive || box.getAttribute('data-exclusive') === '1') box.checked = false;
            });
        }

        state.answers[key] = readValue(q, wrap);
        showError(key, null);
        formError(null);
        refreshScreen(state.screen);
        persist();

        if (e.type === 'change' && isAutoContinue(q, target)) autoContinue(key, target.value);
    }

    /* ------------------------------------------------------------------ auto-continue */

    // Choices that move on by themselves: index.json → auto_continue_options (exact, e.g. "None of these", "No")
    // and auto_continue_patterns (e.g. ^no\b matches "No - I affirm…", ^i am not\b matches "I am NOT allergic…").
    var autoTimer = null;
    var AUTO_CONTINUE = (cfg.autoContinue || []).map(function (v) { return String(v).trim().toLowerCase(); });
    var AUTO_PATTERNS = (cfg.autoPatterns || []).map(function (p) {
        try { return new RegExp(p, 'i'); } catch (e) { return null; }   // a bad pattern in index.json is ignored
    }).filter(Boolean);

    function isAutoContinue(q, target) {
        if (['checkbox', 'radio', 'select'].indexOf(q.type) === -1) return false;
        if (q.type === 'checkbox' && !target.checked) return false;   // unticking never moves on
        var value = String(target.value).trim();
        return AUTO_CONTINUE.indexOf(value.toLowerCase()) !== -1 || AUTO_PATTERNS.some(function (re) { return re.test(value); });
    }

    // Press the screen's own Continue button (form submit → onNext: validation, DQ, lead save, tracking, next screen),
    // but only when everything on the screen is answered — otherwise wait for the visitor as usual.
    function autoContinue(key, value) {
        var screen = state.screen;
        autoTimer = setTimeout(function () {   // short pause so the selected card is visible before the screen changes
            var picked = state.answers[key];
            var still = Array.isArray(picked) ? picked.indexOf(value) !== -1 : picked === value;
            if (busy || state.view !== 'steps' || state.screen !== screen || !still) return;   // visitor changed it meanwhile
            derive(state.answers);
            if (Object.keys(validateScreen(screen)).length) return;
            var btn = root.querySelector('[data-next]');
            if (btn && !btn.disabled) btn.click();
        }, 300);
    }

    function go(index) {
        state.screen = index;
        state.view = 'steps';
        persist();
        renderScreen();
        window.scrollTo(0, 0);
        var h = root.querySelector('h1');
        if (h) h.focus({ preventScroll: true });
    }

    function onBack() {
        if (state.view === 'dq') {
            var index = screenOfKey(state.dq && state.dq.key);
            state.dq = null;
            go(index === -1 ? state.screen : index);
            return;
        }
        var list = activeScreens();
        var pos = list.indexOf(state.screen);
        if (pos > 0) go(list[pos - 1]);
    }

    function onNext() {
        if (busy) return;
        derive(state.answers);

        var errors = validateScreen(state.screen);
        var keys = Object.keys(errors);
        if (keys.length) {
            keys.forEach(function (k) { showError(k, errors[k]); });
            var first = wrapOf(keys[0]);
            var focusable = first && first.querySelector('input:not([type="hidden"]), select, textarea');
            if (first && first.scrollIntoView) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (focusable) focusable.focus({ preventScroll: true });
            return;
        }

        var dq = findDisqualifier(state.screen);
        if (dq) {
            disqualify(dq);
            return;
        }

        // Contact screen: xverify the email / phone on the server first (submit.php?action=verify).
        if (screenHasContact(state.screen)) {
            verifyContact(advance);
            return;
        }
        advance();
    }

    function advance() {
        var list = activeScreens();
        var pos = list.indexOf(state.screen);
        if (pos < list.length - 1) {
            saveLead();
            go(list[pos + 1]);
            return;
        }
        submitIntake();
    }

    function screenHasContact(index) {
        return screenQuestions(index).some(function (q) {
            return (q.key === 'email' || q.key === 'phone') && isVisible(q, state.answers) && !isEmpty(state.answers[q.key]);
        });
    }

    // Rejected values are shown on their field; if the check can't run (network), the visitor moves on
    // and the server checks again at the final submit.
    function verifyContact(next) {
        setBusy(true, T('verifying', 'Checking your details…'));
        formError(null);
        post('verify', { answers: { email: state.answers.email || '', phone: state.answers.phone || '' } }).then(function (res) {
            setBusy(false);
            updateChrome();
            if (res.status === 422 && res.data.errors) {
                var keys = Object.keys(res.data.errors);
                keys.forEach(function (k) { showError(k, res.data.errors[k]); });
                var first = wrapOf(keys[0]);
                var input = first && first.querySelector('input');
                if (input) input.focus();
                return;
            }
            next();
        }).catch(function () {
            setBusy(false);
            updateChrome();
            next();
        });
    }

    function setBusy(on, label) {
        busy = on;
        var btn = root.querySelector('[data-next]');
        if (btn) {
            btn.disabled = on;
            if (label) btn.firstChild.textContent = label;
        }
    }

    function formError(message) {
        var box = root.querySelector('.form-error');
        if (!box) return;
        box.textContent = message || '';
        box.hidden = !message;
    }

    function submitIntake() {
        setBusy(true, T('checking', 'Checking your answers…'));
        formError(null);

        post('intake', { answers: collectAnswers(), tracking: tracking() }).then(function (res) {
            var data = res.data;
            if (res.status === 422 && data.errors) {
                var keys = Object.keys(data.errors);
                var index = screenOfKey(keys[0]);
                busy = false;
                go(index === -1 ? state.screen : index);
                keys.forEach(function (k) { showError(k, data.errors[k]); });
                formError(T('review', 'Please review the highlighted answers.'));
                return;
            }
            if (!data.success) {
                setBusy(false);
                updateChrome();
                formError(data.message || T('error', 'Something went wrong. Please try again.'));
                return;
            }
            if (data.disqualified && data.disqualified.length) {
                busy = false;
                if (data.redirect) {
                    location.href = nextUrl(data.redirect);
                    return;
                }
                disqualify(data.disqualified[0]);
                return;
            }
            persist();
            location.href = nextUrl(data.redirect || 'product.php');
        }).catch(function () {
            setBusy(false);
            updateChrome();
            formError(T('offline', 'We could not reach the server. Check your connection and try again.'));
        });
    }

    function disqualify(dq) {
        if (cfg.disqualifiedUrl) {
            forget();
            location.href = nextUrl(cfg.disqualifiedUrl);
            return;
        }
        state.view = 'dq';
        state.dq = dq;
        persist();
        render();
        window.scrollTo(0, 0);
    }

    function renderDisqualified() {
        var dq = state.dq || {};
        var back = el('button', { type: 'button', className: 'btn-pill' }, [T('dq_back', 'Go back and change my answer')]);
        back.addEventListener('click', onBack);
        root.replaceChildren(el('div', { className: 'quiz-form' }, [
            el('span', { className: 'quiz-badge quiz-badge-warn' }, [T('dq_badge', 'Not eligible online')]),
            el('h1', { className: 'quiz-title', tabindex: '-1', text: T('dq_title', 'We can’t offer treatment online right now') }),
            el('p', { className: 'quiz-helper', text: T('dq_text', 'Based on your answer, our clinicians aren’t able to safely prescribe this program through an online visit. Please talk to your healthcare provider about other options.') }),
            dq.label ? el('div', { className: 'dq-answer' }, [
                el('p', { className: 'dq-q', text: dq.label }),
                el('p', { className: 'dq-a', text: dq.value })
            ]) : null,
            el('div', { className: 'quiz-actions' }, [back])
        ]));
        updateChrome();
    }

    function render() {
        if (state.view === 'dq') renderDisqualified();
        else renderScreen();
    }

    if (backBtn) backBtn.addEventListener('click', onBack);
    render();
})();
