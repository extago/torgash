/*
 * The small things markup used to say with inline handlers (onclick=,
 * onsubmit=), said as data attributes instead — so a page runs under a content
 * security policy that allows no inline script (PLAN §53). One delegated
 * listener per event, so markup that arrives over HTMX works too.
 *
 *   data-confirm="Delete this?"   on a form: asked before it is sent;
 *                                 on a button or link: asked before the click
 *                                 does anything (a formaction, htmx, Alpine).
 *   data-open-dialog="id"         opens the <dialog id="id">.
 *   data-close-dialog             closes the dialog it is in.
 *   data-print                    prints the page.
 *   data-select                   selects the field's text on click.
 *   data-inert-text               text inside a link that is read, not
 *                                 followed (a label's hint on a card): a
 *                                 click on it does not open the link (app.css
 *                                 stops the link being dragged meanwhile, so
 *                                 a drag over it selects).
 *   data-submit-on-change         sends its form when the value changes
 *                                 (a filter's select); requestSubmit, so htmx
 *                                 and data-confirm hear it like a click.
 *
 * Listeners run in the CAPTURE phase on the document: before htmx's and
 * Alpine's own listeners on the element, so a "no" stops all of them.
 */
(() => {
    const refuse = (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
        event.stopPropagation();
    };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            refuse(event);
        }
    }, true);

    document.addEventListener('change', (event) => {
        const field = event.target instanceof Element ? event.target.closest('[data-submit-on-change]') : null;
        field?.form?.requestSubmit();
    }); // bubbling: the field's own listeners (Alpine's x-model) first

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const asking = target.closest('[data-confirm]:not(form)');
        if (asking && !window.confirm(asking.dataset.confirm)) {
            refuse(event);
            return;
        }

        const opener = target.closest('[data-open-dialog]');
        if (opener) {
            document.getElementById(opener.dataset.openDialog)?.showModal();
            return;
        }

        const closer = target.closest('[data-close-dialog]');
        if (closer) {
            closer.closest('dialog')?.close();
            return;
        }

        if (target.closest('[data-print]')) {
            window.print();
            return;
        }

        const field = target.closest('[data-select]');
        if (field && typeof field.select === 'function') {
            field.select();
        }

        if (target.closest('[data-inert-text]')) {
            event.preventDefault();
        }
    }, true);
})();
