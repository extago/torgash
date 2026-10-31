/*
 * The storefront's phone mask (App\Core\Support\PhoneMask): every
 * <input type="tel"> on the page — the checkout, one click, the enquiry
 * forms, registration — including the ones a dialog draws later, so it
 * listens on the document rather than finding fields once.
 *
 * The pattern is literals and `_` for a digit, read from this script's own
 * data attributes (no JSON in markup). Only typing is helped: the server
 * still reads the number by its country's rules. A field marked
 * `data-phone-mask="off"` is left alone.
 */
(() => {
    const script = document.currentScript;
    if (!script) return;

    const mode = script.dataset.mode || '';
    const pattern = script.dataset.pattern || '';
    const trunk = script.dataset.trunk || '';
    const firstSlot = pattern.indexOf('_');
    const prefix = firstSlot > 0 ? pattern.slice(0, firstSlot) : '';
    const prefixDigits = prefix.replace(/\D/g, '');
    const slots = (pattern.match(/_/g) || []).length;

    const isMasked = (el) => el instanceof HTMLInputElement && el.type === 'tel' && el.dataset.phoneMask !== 'off';

    /** The digits of the national part: what the `_` slots are filled with. */
    const digitsOf = (value) => {
        let digits = value.replace(/\D/g, '');

        // The country code: already in the field (it starts with +), or
        // pasted whole ("380931234567").
        if (prefixDigits && digits.startsWith(prefixDigits)
            && (value.trim().startsWith('+') || digits.length >= slots + prefixDigits.length)) {
            digits = digits.slice(prefixDigits.length);
        }

        // The national prefix out of habit — "0931234567", "89161234567":
        // one digit too many, starting with it.
        if (trunk && digits.length > slots && digits.startsWith(trunk)) {
            digits = digits.slice(trunk.length);
        }

        return digits.slice(0, slots);
    };

    /** The digits laid into the pattern; a literal is written only when a digit follows it. */
    const format = (digits) => {
        if (digits === '') return '';

        let out = '';
        let i = 0;
        for (const char of pattern) {
            if (char === '_') {
                if (i >= digits.length) break;
                out += digits[i++];
            } else if (i < digits.length) {
                out += char;
            }
        }

        return out;
    };

    /** International: a plus, digits, the spaces the person typed — fifteen digits at most (E.164). */
    const international = (value) => {
        let count = 0;
        const body = value.replace(/[^\d ]/g, '').replace(/\s+/g, ' ').replace(/\d/g, (d) => (++count <= 15 ? d : ''));

        return body.trim() === '' ? '' : '+' + body.replace(/^\s+/, '');
    };

    const apply = (el) => {
        const before = el.value;
        const atEnd = el.selectionStart === before.length;
        // How many digits stood before the caret, to put it back after them.
        const digitsBeforeCaret = before.slice(0, el.selectionStart ?? before.length).replace(/\D/g, '').length;

        const after = mode === 'international' ? international(before) : format(digitsOf(before));
        if (after === before) return;

        el.value = after;

        if (atEnd || document.activeElement !== el) return;
        let seen = 0;
        let caret = after.length;
        for (let i = 0; i < after.length; i++) {
            if (/\d/.test(after[i]) && ++seen === digitsBeforeCaret) { caret = i + 1; break; }
        }
        el.setSelectionRange(caret, caret);
    };

    document.addEventListener('focusin', (e) => {
        const el = e.target;
        if (!isMasked(el)) return;

        if (!el.placeholder) {
            el.placeholder = mode === 'international' ? '+' : pattern;
        }
        // An empty field starts with the country code already in it.
        if (el.value === '') {
            el.value = mode === 'international' ? '+' : prefix;
        }
    });

    document.addEventListener('focusout', (e) => {
        const el = e.target;
        if (!isMasked(el)) return;

        // Only the code, nothing typed after it: as good as empty.
        if (el.value.replace(/\D/g, '') === prefixDigits || el.value.trim() === '+') {
            el.value = '';
        }
    });

    // Rubbing out a literal (a bracket, a dash) would leave the digits as
    // they were, and formatting would put it straight back: the digit before
    // it goes instead.
    document.addEventListener('beforeinput', (e) => {
        const el = e.target;
        if (!isMasked(el) || mode === 'international' || e.inputType !== 'deleteContentBackward') return;

        const caret = el.selectionStart ?? 0;
        if (caret !== el.selectionEnd || caret === 0 || /\d/.test(el.value[caret - 1])) return;

        let at = caret - 1;
        while (at >= 0 && !/\d/.test(el.value[at])) at--;
        // Nothing but the country code before it: leave the field to the browser.
        if (at < 0 || el.value.slice(0, at + 1).replace(/\D/g, '').length <= prefixDigits.length) return;

        e.preventDefault();
        el.value = el.value.slice(0, at) + el.value.slice(at + 1);
        el.setSelectionRange(at, at);
        apply(el);
    });

    document.addEventListener('input', (e) => {
        if (isMasked(e.target)) apply(e.target);
    });
})();
