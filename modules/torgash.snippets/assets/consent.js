/*
 * Switches on snippets that were printed waiting (PLAN §49): inside a
 * <template data-consent="statistics"> until the visitor agrees to that
 * category, or <template data-consent-click> until they press its button.
 *
 * A script that arrives inside a template never runs, and neither does one
 * moved into the page as it is — so each is re-created, which is what makes a
 * browser execute it. The banner announces consent as a DOM event
 * (`torgash:consent`); this file has never heard of any privacy module, and
 * that module has never heard of snippets.
 */
(function () {
    function run(template) {
        var fragment = template.content.cloneNode(true);

        fragment.querySelectorAll('script').forEach(function (old) {
            var fresh = document.createElement('script');
            for (var i = 0; i < old.attributes.length; i++) {
                fresh.setAttribute(old.attributes[i].name, old.attributes[i].value);
            }
            fresh.text = old.textContent;
            old.replaceWith(fresh);
        });

        template.replaceWith(fragment);
    }

    document.addEventListener('torgash:consent', function (event) {
        var allowed = (event.detail && event.detail.categories) || [];

        document.querySelectorAll('template[data-consent]').forEach(function (template) {
            if (allowed.indexOf(template.getAttribute('data-consent')) !== -1) run(template);
        });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-consent-click-button]');
        if (!button) return;

        var template = document.getElementById(button.getAttribute('data-target'));
        if (template) run(template);
        button.remove();
    });
})();
