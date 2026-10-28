/*
 * The theme configurator's phone and tablet frames (admin/themes/customize)
 * answer as a touch screen.
 *
 * A frame is as narrow as a phone, but its pointer is the admin's mouse: every
 * `@media (hover: hover)` holds and every `(hover: none)` does not, so a card
 * lifted on hover and showed all of its corner — what no phone shows, and
 * what the theme's "on a phone" settings could not be seen changing. A script
 * cannot change what the browser says about the pointer, so here every @media
 * that asks about hover or the pointer is rewritten to the answer a touch
 * screen gives, and put back for the computer.
 *
 * The configurator calls `torgashPreviewTouch(true|false)` on each load of the
 * frame and on each change of device; the answer is kept in `window.name`,
 * which outlives a link followed inside the frame, so the next page answers as
 * a touch screen before anyone asks. Printed only for staff who may manage
 * themes, in a frame (<x-theme.style />) — never for a customer.
 */
(function () {
    if (window.torgashPreviewTouch) return;

    var NAME = 'torgash-preview-touch';
    // Each rule's own text, to put back and never to swap twice.
    var own = new WeakMap();
    // Turned only where this device answers otherwise: a tablet used to
    // configure already says "none".
    var hovers = window.matchMedia('(hover: hover)').matches;
    var fine = window.matchMedia('(pointer: fine)').matches;

    function touch(text) {
        return text.replace(/\b((?:any-)?)(hover|pointer)\s*:\s*(hover|none|fine|coarse)\b/g, function (all, any, feature, value) {
            if (feature === 'hover' && hovers && (value === 'hover' || value === 'none')) {
                return any + 'hover: ' + (value === 'hover' ? 'none' : 'hover');
            }
            if (feature === 'pointer' && fine && (value === 'fine' || value === 'coarse')) {
                return any + 'pointer: ' + (value === 'fine' ? 'coarse' : 'fine');
            }
            return all;
        });
    }

    function walk(rules, on) {
        for (var i = 0; i < rules.length; i++) {
            var rule = rules[i];
            if (rule instanceof CSSMediaRule) {
                var text = own.has(rule) ? own.get(rule) : rule.media.mediaText;
                if (/hover|pointer/.test(text)) {
                    own.set(rule, text);
                    var wanted = on ? touch(text) : text;
                    if (rule.media.mediaText !== wanted) rule.media.mediaText = wanted;
                }
            }
            if (rule instanceof CSSImportRule) {
                try { walk(rule.styleSheet.cssRules, on); } catch (e) {}
            } else if (rule.cssRules) {
                walk(rule.cssRules, on);
            }
        }
    }

    function apply() {
        var on = window.name === NAME;
        for (var i = 0; i < document.styleSheets.length; i++) {
            // A sheet from another address (a font service) cannot be read.
            try { walk(document.styleSheets[i].cssRules, on); } catch (e) {}
        }
    }

    window.torgashPreviewTouch = function (on) {
        window.name = on ? NAME : '';
        apply();
    };

    // Printed before the page's stylesheets: each one is turned as it
    // arrives (`load` does not bubble, so it is heard on the way down), and
    // what HTMX brings in with its own <style> after it settles.
    apply();
    document.addEventListener('load', function (event) {
        if (event.target instanceof HTMLLinkElement || event.target instanceof HTMLStyleElement) apply();
    }, true);
    document.addEventListener('DOMContentLoaded', apply);
    window.addEventListener('load', apply);
    document.addEventListener('htmx:afterSettle', apply);
})();
