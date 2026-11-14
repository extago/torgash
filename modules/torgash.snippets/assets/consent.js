/*
 * Switches on snippets that were printed waiting for a click (PLAN §49):
 * inside a <template data-consent-click> until the visitor presses its button.
 * Code waiting for CONSENT is core's (assets/js/consent-gate.js).
 *
 * A script that arrives inside a template never runs, and neither does one
 * moved into the page as it is — so each is re-created, which is what makes a
 * browser execute it.
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

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-consent-click-button]');
        if (!button) return;

        var template = document.getElementById(button.getAttribute('data-target'));
        if (template) run(template);
        button.remove();
    });
})();
