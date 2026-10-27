/*
 * The cookie banner's answer (PLAN §49), without leaving the page.
 *
 * The forms post to /privacy/consent like any form; here they go over fetch,
 * and the answer — the categories now allowed — is announced as the DOM event
 * `torgash:consent`, which whoever printed waiting code listens for (the
 * snippets module's consent.js). Google Consent Mode gets its update too.
 * "#cookie-settings" anywhere opens the window of choices again.
 */
(function () {
    var root = document.querySelector('[data-privacy]');
    if (!root) return;

    var dialog = root.querySelector('[data-privacy-dialog]');

    function announce(categories) {
        if (root.getAttribute('data-google') === '1' && typeof window.gtag === 'function') {
            var grant = function (c) { return categories.indexOf(c) !== -1 ? 'granted' : 'denied'; };
            window.gtag('consent', 'update', {
                ad_storage: grant('marketing'),
                ad_user_data: grant('marketing'),
                ad_personalization: grant('marketing'),
                analytics_storage: grant('statistics'),
                functionality_storage: grant('functional'),
                personalization_storage: grant('functional')
            });
        }

        document.dispatchEvent(new CustomEvent('torgash:consent', { detail: { categories: categories } }));
    }

    root.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-privacy-form]');
        if (!form) return;

        event.preventDefault();

        var body = new FormData(form);
        // The pressed button's own name/value, which FormData leaves out.
        if (event.submitter && event.submitter.name) body.set(event.submitter.name, event.submitter.value);

        fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
            credentials: 'same-origin'
        })
            .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
            .then(function (data) {
                var banner = root.querySelector('[data-privacy-banner]');
                if (banner) banner.remove();
                if (dialog && dialog.open) dialog.close();
                announce(data.categories || []);
            })
            .catch(function () { form.submit(); });
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href$="#cookie-settings"]');
        if (!link || !dialog) return;

        event.preventDefault();
        dialog.showModal();
    });
})();
