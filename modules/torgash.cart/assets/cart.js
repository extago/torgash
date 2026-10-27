/*
 * Torgash cart — the shared dialog and the list buttons (the corner notice
 * after an add is core's: <x-notice>, public/assets/js/notices.js).
 *
 * Everything drawn here is HTML from the server; this only opens and closes
 * it. The dialog (#torgash-cart-dialog) is filled two ways, both by HTMX:
 *  - the choice window — a gear button's hx-get into its body;
 *  - "added to your basket" — out-of-band, in the answer to an add, when the
 *    shop's setting says so.
 * Filled → opened. An add made FROM the dialog whose answer did not fill it
 * again (the shop shows a notice, or nothing) closes it: the choice is made.
 * A refusal (HX-Retarget, back into the buy box) changes nothing — the
 * customer is still choosing.
 *
 * A list's add form (data-list-buy-add) that succeeded is marked in-cart, and
 * its button turns green by CSS.
 *
 * Every request says which parts of the basket the page shows (the badge, the
 * tab bar's count, the basket itself), and an add answers into those, out of
 * band — see CartController::SHOWS.
 */
(() => {
    const dialog = () => document.getElementById('torgash-cart-dialog');
    let filledByThisRequest = false;

    function open() {
        const d = dialog();

        if (d && !d.open) {
            d.showModal();
        }
    }

    function close() {
        const d = dialog();

        if (d && d.open) {
            d.close();
        }
    }

    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-cart-dialog-close]')) {
            e.preventDefault();
            close();
        }
    });

    // Emptied on close, so a stale window never flashes before the next one.
    document.addEventListener('close', (e) => {
        if (e.target && e.target.id === 'torgash-cart-dialog') {
            document.getElementById('torgash-cart-dialog-body').innerHTML = '';
        }
    }, true);

    document.body.addEventListener('htmx:afterSwap', (e) => {
        if (e.target && e.target.id === 'torgash-cart-dialog-body') {
            open();
        }
    });

    document.body.addEventListener('htmx:oobAfterSwap', (e) => {
        const target = e.detail && e.detail.target;

        if (!target) {
            return;
        }

        if (target.id === 'torgash-cart-dialog-body') {
            filledByThisRequest = true;
            open();
        }
    });

    // Which parts of the basket this page shows, so the answer to an add
    // brings exactly those (CartController::SHOWS). The cart and checkout
    // have no header badge on purpose; an add that AIMED at it was never sent
    // at all there. Asked on every request: the page is the one that knows,
    // and a header is cheaper than a wrong guess.
    document.body.addEventListener('htmx:configRequest', (e) => {
        const shows = [
            ['badge', 'cart-badge'],
            ['tabbar', 'tabbar-badge-cart'],
            ['contents', 'cart-contents'],
        ].filter(([, id]) => document.getElementById(id)).map(([part]) => part);

        e.detail.headers['X-Torgash-Cart-Shows'] = shows.join(',');
    });

    document.body.addEventListener('htmx:beforeRequest', () => {
        filledByThisRequest = false;
    });

    document.body.addEventListener('htmx:afterRequest', (e) => {
        const detail = e.detail || {};
        const xhr = detail.xhr;
        const form = detail.elt;

        if (!form || !detail.successful || (xhr && xhr.getResponseHeader('HX-Retarget'))) {
            return;
        }

        // Only an ADD: a request the buy form itself sent, to the cart. Not
        // any request from inside it — an option's field asks for the build's
        // price as it is typed into (options.quote), from within the very same
        // form, and taking that for an add closed the choice window on the
        // first letter of an engraving (measured).
        if (form.tagName !== 'FORM' || !/\/cart\/add\//.test(form.getAttribute('hx-post') || '')) {
            return;
        }

        if (form.hasAttribute('data-list-buy-add')) {
            form.setAttribute('data-in-cart', '');
        }

        if (form.closest('#torgash-cart-dialog') && !filledByThisRequest) {
            close();
        }
    });
})();
