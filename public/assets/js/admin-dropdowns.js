/*
 * Dropdowns inside a scrolling box.
 *
 * A daisyUI dropdown opens on :focus-within and hangs its menu off the trigger
 * with position:absolute. Every admin table sits in an `overflow-x-auto`
 * wrapper so a wide one can scroll sideways — and a box that scrolls one way
 * clips both — so the status menu of the LAST row opened into the wrapper's
 * bottom edge and had to be scrolled to.
 *
 * While such a dropdown is open, its menu is lifted out of the box:
 * position:fixed at the trigger's own coordinates, opened UPWARDS when there
 * is no room below, aligned to the trigger's end for `dropdown-end`, kept
 * inside the window. Nothing changes for a dropdown whose ancestors do not
 * clip (the header's, a form's gear). A scroll re-places the menu under its
 * trigger (a scroll of the menu itself — a long list of managers — is left
 * alone), and a trigger scrolled out of sight closes it.
 */
(() => {
    const clips = (element) => {
        for (let node = element.parentElement; node && node !== document.body; node = node.parentElement) {
            const style = getComputedStyle(node);
            if (/(auto|scroll|hidden|clip)/.test(style.overflowX + ' ' + style.overflowY)) return true;
        }
        return false;
    };

    const parts = (dropdown) => ({
        menu: dropdown.querySelector(':scope > .dropdown-content'),
        trigger: dropdown.querySelector(':scope > [tabindex], :scope > summary, :scope > button'),
    });

    const place = (dropdown) => {
        const { menu, trigger } = parts(dropdown);
        if (! menu || ! trigger || ! clips(dropdown)) return;

        Object.assign(menu.style, { position: 'fixed', margin: '0', top: '0', left: '0', right: 'auto', bottom: 'auto', zIndex: '60' });
        menu.dataset.lifted = '1';

        const rect = trigger.getBoundingClientRect();
        const height = menu.offsetHeight;
        const width = menu.offsetWidth;
        const gap = 4;
        const below = window.innerHeight - rect.bottom;
        const upwards = below < height + gap * 2 && rect.top > below;

        let left = dropdown.classList.contains('dropdown-end') ? rect.right - width : rect.left;
        left = Math.max(8, Math.min(left, window.innerWidth - width - 8));

        menu.style.top = (upwards ? Math.max(8, rect.top - height - gap) : rect.bottom + gap) + 'px';
        menu.style.left = left + 'px';
    };

    document.addEventListener('focusin', (event) => {
        const dropdown = event.target.closest && event.target.closest('.dropdown');
        if (dropdown && event.target === parts(dropdown).trigger) {
            // Now, in the same task, before anything is painted: :focus-within
            // has already shown the menu and reading its size forces the layout.
            // A timer later, the menu was painted once inside the table and the
            // box flashed a scrollbar.
            place(dropdown);
        }
    });

    // Nothing is undone on close: daisyUI fades the menu out, and put back
    // inside the table for that fade it flashed the box's scrollbar again. A
    // hidden fixed menu takes no room anywhere, and the next open places it
    // anew.

    // Scrolling moves the trigger and not a fixed menu, so the menu follows it
    // — focusing a trigger itself scrolls its box into view, so closing on
    // scroll would close every menu the instant it opened. A trigger scrolled
    // out of sight takes its menu with it.
    let pending = false;
    window.addEventListener('scroll', (event) => {
        if (event.target instanceof Element && event.target.closest('.dropdown-content')) return;
        if (pending) return;
        pending = true;
        setTimeout(() => {
            pending = false;
            const active = document.activeElement;
            const dropdown = active && active.closest && active.closest('.dropdown');
            if (! dropdown || ! dropdown.contains(active) || ! parts(dropdown).menu?.dataset.lifted) return;
            const rect = parts(dropdown).trigger.getBoundingClientRect();
            rect.bottom < 0 || rect.top > window.innerHeight ? active.blur() : place(dropdown);
        }, 16);
    }, true);
})();
