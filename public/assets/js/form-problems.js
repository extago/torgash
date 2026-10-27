/*
 * <x-form-problems> (core): a form's problems beside its button.
 *
 * The browser's own checks fire an `invalid` event on every field that
 * fails, one after another, in the same task; they are collected, the
 * browser's bubble is cancelled, and the list is drawn once. A field is
 * named by its fieldset's legend or its label (the star of "required"
 * dropped), marked with aria-invalid, and unmarked as soon as it is
 * touched. What the server refused arrives printed in the box with the
 * field's name in data-field — those fields are marked the same way.
 */
(() => {
    function fieldName(field) {
        const legend = field.closest('fieldset')?.querySelector('legend');
        const label = field.labels?.[0];
        const group = field.type === 'radio' ? field.closest('section')?.querySelector('h2, h3') : null;
        // innerText, not textContent: a legend may hold two wordings and show
        // one ("Full name" / "Contact person" for a company).
        // A field's own aria-label is the most precise name it has (the town
        // under a "Country and town" legend).
        const text = field.getAttribute('aria-label') || (group || legend || label)?.innerText
            || field.placeholder || field.name || '';

        return text.replace(/\*/g, '').replace(/\s+/g, ' ').trim();
    }

    function sentence(box, field) {
        const template = field.validity.valueMissing
            ? (field.type === 'radio' || field.tagName === 'SELECT' ? box.dataset.choose : box.dataset.missing)
            : box.dataset.wrong;

        return template.replace(':field', fieldName(field));
    }

    function mark(field) {
        field.setAttribute('aria-invalid', 'true');
    }

    function unmark(field) {
        if (field.getAttribute('aria-invalid') !== 'true') {
            return;
        }

        const form = field.form;
        const box = form && document.querySelector(`[data-form-problems="${form.id}"]`);
        const group = field.name && form ? form.querySelectorAll(`[name="${CSS.escape(field.name)}"]`) : [field];
        group.forEach((f) => f.removeAttribute('aria-invalid'));
        box?.querySelectorAll('.torgash-problem').forEach((p) => {
            if (p._field === field || (field.name && p.dataset.field === field.name)) {
                p.remove();
            }
        });
    }

    function bring(field) {
        // The middle of the screen clears any sticky header, whatever its height.
        field.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        field.focus({ preventScroll: true });
    }

    function setup(box) {
        const form = document.getElementById(box.dataset.formProblems);

        if (!form || form._formProblems) {
            return;
        }

        form._formProblems = true;
        const template = box.nextElementSibling?.matches('[data-form-problem-template]') ? box.nextElementSibling : null;
        let batch = null;

        form.addEventListener('invalid', (e) => {
            e.preventDefault();

            if (!batch) {
                batch = [];
                setTimeout(() => {
                    draw(batch);
                    batch = null;
                });
            }

            batch.push(e.target);
        }, true);

        function draw(fields) {
            box.replaceChildren();
            const seen = new Set();

            fields.forEach((field) => {
                const key = field.name || field;

                if (seen.has(key)) {
                    return;
                }

                seen.add(key);
                mark(field);

                if (template) {
                    const line = template.content.firstElementChild.cloneNode(true);
                    line.lastElementChild.textContent = sentence(box, field);
                    line._field = field;
                    line.dataset.field = field.name || '';
                    box.append(line);
                }
            });

            if (fields[0]) {
                bring(fields[0]);
            }
        }

        // What the server refused: mark its fields, bring the first one up.
        const refused = [...box.querySelectorAll('[data-field]')]
            .map((p) => p.dataset.field && form.elements.namedItem(p.dataset.field))
            .filter(Boolean)
            .map((f) => (f instanceof RadioNodeList ? f[0] : f));
        refused.forEach(mark);

        if (refused[0]) {
            bring(refused[0]);
        } else if (box.children.length) {
            box.scrollIntoView({ block: 'center' });
        }

        form.addEventListener('input', (e) => unmark(e.target), true);
        form.addEventListener('change', (e) => unmark(e.target), true);
    }

    function setupAll() {
        document.querySelectorAll('[data-form-problems]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupAll);
    } else {
        setupAll();
    }
})();
