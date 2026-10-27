/*
 * <x-popover>'s two small helpers.
 *
 * 1. The arrow, everywhere. CSS draws it; what CSS cannot know is whether the
 *    browser flipped the panel above its button for lack of room, so this
 *    tells it — `data-side` (the panel is below or above the button) and
 *    `--arrow-x` (where the button's middle is) — on opening, and again on
 *    scroll and resize while it is open. The panel's position stays the
 *    browser's.
 * 2. Placing, only in a browser without CSS Anchor Positioning (iOS 18 and
 *    older), where the Popover API opens the panel in the middle of the
 *    screen: next to its button, below, or above when there is no room.
 */
(function () {
    // Loaded by the component AND by the storefront layout (a shop's own HTML
    // block may hold a popover too, with no component to push the script):
    // the listeners below must exist once.
    if (window.torgashPopoverAnchor) return;
    window.torgashPopoverAnchor = true;

    var anchors = !!(window.CSS && CSS.supports('anchor-name: --a'));
    var open = null;
    var watching = null;

    // Its button: the one that opens it — or, for a panel opened by a script
    // (`data-anchor`, a selector), the first such element on screen: a header
    // drawn twice has two, one of them hidden.
    function buttonOf(panel) {
        var selector = panel.getAttribute('data-anchor');
        if (selector) {
            var list = document.querySelectorAll(selector);
            for (var i = 0; i < list.length; i++) {
                if (list[i].offsetParent !== null) return list[i];
            }
            return null;
        }

        return document.querySelector('[popovertarget="' + panel.id + '"]:not([popovertargetaction="hide"])');
    }

    function place(panel, b) {
        var gap = 8;
        var height = panel.offsetHeight;
        var below = b.bottom + gap + height <= window.innerHeight;
        var wantTop = panel.getAttribute('data-placement') === 'top';
        var top = (wantTop && b.top - gap - height >= 0) || !below ? b.top - gap - height : b.bottom + gap;

        panel.style.inset = 'auto';
        panel.style.margin = '0';
        panel.style.top = Math.max(8, top) + 'px';
        var align = panel.getAttribute('data-align');
        var start = align === 'start' ? b.left : align === 'end' ? b.right - panel.offsetWidth : b.left + b.width / 2 - panel.offsetWidth / 2;
        panel.style.left = Math.min(Math.max(8, start), window.innerWidth - panel.offsetWidth - 8) + 'px';
    }

    function arrow(panel, b) {
        var p = panel.getBoundingClientRect();
        panel.setAttribute('data-side', p.top >= b.top ? 'below' : 'above');
        panel.style.setProperty('--arrow-x', Math.min(Math.max(14, b.left + b.width / 2 - p.left), p.width - 14) + 'px');
    }

    function update() {
        if (!open) return;

        var button = buttonOf(open);
        if (!button) return;

        var b = button.getBoundingClientRect();
        if (!anchors) place(open, b);
        arrow(open, b);
    }

    document.addEventListener('toggle', function (event) {
        var panel = event.target;
        if (!panel.classList || !panel.classList.contains('torgash-popover')) return;

        open = event.newState === 'open' ? panel : null;
        clearInterval(watching);

        // Read now (`toggle` comes after the panel is shown), and again while
        // it stays open: a browser applies a flip (position-try) when IT
        // decides — the next frame, or later in a tab it throttles — and
        // keeps the last successful position meanwhile. Five reads a second
        // of one small box, and only while a panel is open.
        if (open) {
            update();
            watching = setInterval(update, 200);
        }
    }, true);

    // 3. Hover hints (`<x-popover hover>`): open as the mouse comes onto the
    //    button, or on focus, close when the mouse has left both the button
    //    and the panel. A tap still toggles it the ordinary way; a mouse
    //    click on an already-open hint is swallowed, or it would close what
    //    the hover just opened.
    var showTimer = null;
    var hideTimer = null;
    var lastPointer = 'mouse';

    function hintParts(node) {
        var button = node && node.closest ? node.closest('[data-popover-hover]') : null;
        if (button) return { button: button, panel: document.getElementById(button.getAttribute('popovertarget')) };

        var panel = node && node.closest ? node.closest('.torgash-popover') : null;
        var owner = panel && document.querySelector('[data-popover-hover][popovertarget="' + panel.id + '"]');
        return owner ? { button: owner, panel: panel } : null;
    }

    function showHint(panel) {
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        // At once — the owner's call; the fade-in is the only softening.
        if (!panel.matches(':popover-open')) panel.showPopover();
    }

    function hideHint(panel) {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            if (panel.matches(':popover-open')) panel.hidePopover();
        }, 120);
    }

    document.addEventListener('pointerdown', function (event) { lastPointer = event.pointerType; }, true);

    document.addEventListener('pointerover', function (event) {
        if (event.pointerType !== 'mouse') return;
        var parts = hintParts(event.target);
        if (parts) showHint(parts.panel);
    });

    document.addEventListener('pointerout', function (event) {
        if (event.pointerType !== 'mouse') return;
        var parts = hintParts(event.target);
        if (!parts) return;
        var to = hintParts(event.relatedTarget);
        if (!to || to.panel !== parts.panel) hideHint(parts.panel);
    });

    document.addEventListener('focusin', function (event) {
        var parts = hintParts(event.target);
        if (parts && event.target === parts.button) showHint(parts.panel);
    });

    document.addEventListener('focusout', function (event) {
        var parts = hintParts(event.target);
        if (parts && event.target === parts.button) hideHint(parts.panel);
    });

    document.addEventListener('click', function (event) {
        var parts = hintParts(event.target);
        if (parts && event.target.closest('[data-popover-hover]') && lastPointer === 'mouse' && parts.panel.matches(':popover-open')) {
            event.preventDefault();
        }
    }, true);

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
})();
