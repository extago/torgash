/*
 * What scrolls sideways in the admin — and what, on a phone, does not.
 *
 * Every list sits in an `overflow-x-auto` box. A table wider than its box:
 *
 *  - on a phone (under 48rem) is drawn as CARDS instead: `admin-cards
 *    admin-auto-cards` go on the table, every cell is told which it is
 *    (`data-cell`: check / title / field / actions / wide / blank) and a
 *    field is told its column's heading (`data-label`); app.css draws the
 *    rest. Scrolling half a screen under a fixed half was measured and
 *    rejected (PLAN §62.9). A list with cards of its own (`admin-cards`
 *    already on the table) is left to them; `admin-no-cards` keeps a table
 *    a table. Cards stay cards until the box is wider than the table was
 *    (remembered on the box): folded, a table always "fits".
 *
 *  - on a wider screen scrolls inside its box, marked `data-scroll-table`,
 *    and app.css keeps its first column in place — and the name beside it
 *    when the first column is the checkboxes and the second has a heading
 *    (`data-checks`, that column's width in --checks-width; an image
 *    column with no heading is not a name and slides with the rest).
 *    `data-scrolled` and `data-more` say whether something is hidden on the
 *    left and on the right, for the shadows.
 *
 * A table that fits is left unmarked, so a wide screen keeps its tables
 * exactly as they were.
 *
 * A row of tabs — or of filter buttons: `admin-strip`, a `join` of buttons —
 * is one strip that scrolls on a narrow screen: the active one is brought
 * into view when the page opens.
 *
 * Boxes and strips that HTMX swaps in are taken up as they arrive; a row
 * swapped into a folded table is told its cells again.
 */
(() => {
    const seen = new WeakSet();
    const phone = matchMedia('(width < 48rem)');
    const marks = ['data-scroll-table', 'data-checks', 'data-scrolled', 'data-more'];

    const edges = (box) => {
        const left = Math.abs(box.scrollLeft);
        box.toggleAttribute('data-scrolled', left > 1);
        box.toggleAttribute('data-more', left + box.clientWidth < box.scrollWidth - 1);
    };

    const words = (cell) => (cell ? cell.textContent.replace(/\s+/g, ' ').trim() : '');
    // "The checkboxes": a cell holding a box and no words — the words of a
    // label around the box ("Select all on this page", shown on a phone)
    // are the box's own.
    const isChecks = (cell) => {
        if (! cell || cell.hasAttribute('colspan') || ! cell.querySelector('input[type="checkbox"]')) return false;
        const rest = cell.cloneNode(true);
        rest.querySelectorAll('label').forEach((label) => label.remove());
        return words(rest) === '';
    };
    const isBlank = (cell) => words(cell) === '' && ! cell.querySelector('img, svg, input, select, textarea, button, a, .badge');

    // Which cell is which, for the card. Done again for rows swapped in
    // later: cheap, and nothing is changed that was already right.
    const label = (table) => {
        const heads = table.tHead && table.tHead.rows[0] ? [...table.tHead.rows[0].cells] : [];
        const checkAt = heads.findIndex(isChecks);
        const titleAt = heads.findIndex((th, i) => i !== checkAt && words(th) !== '');
        heads.forEach((th, i) => {
            if (i !== checkAt) return;
            th.dataset.cell = 'check';
            th.dataset.label = th.querySelector('input').getAttribute('aria-label') || '';
        });
        for (const body of table.tBodies) {
            for (const row of body.rows) {
                let at = 0;
                for (const cell of row.cells) {
                    const i = at;
                    at += cell.colSpan || 1;
                    let kind;
                    if (cell.colSpan > 1) kind = 'wide';
                    else if (i === checkAt) kind = 'check';
                    else if (i === titleAt) kind = 'title';
                    else if (isBlank(cell)) kind = 'blank';
                    else if (words(heads[i]) === '') kind = cell.querySelector('.btn, button') ? 'actions' : 'title';
                    else kind = 'field';
                    cell.dataset.cell = kind;
                    if (kind === 'field') cell.dataset.label = words(heads[i]);
                    else delete cell.dataset.label;
                }
            }
        }
    };

    const fold = (box, table) => {
        box.dataset.tableWidth = table.scrollWidth;
        label(table);
        table.classList.add('admin-cards', 'admin-auto-cards');
        marks.forEach((name) => box.removeAttribute(name));
    };

    const unfold = (box, table) => {
        table.classList.remove('admin-cards', 'admin-auto-cards');
        delete box.dataset.tableWidth;
    };

    const measure = (box) => {
        const table = box.querySelector(':scope > table');
        if (! table) return;

        if (table.classList.contains('admin-auto-cards')) {
            if (phone.matches && box.clientWidth <= (parseFloat(box.dataset.tableWidth) || 0) + 1) {
                label(table);
                return;
            }
            unfold(box, table);
        }

        // Cards of their own on a phone: nothing to scroll (what a finger's
        // invisible rings add past the last badge is not "more").
        if (phone.matches && table.classList.contains('admin-cards')) {
            marks.forEach((name) => box.removeAttribute(name));
            return;
        }

        // Marked, the name column may wrap (app.css) and the table then fits
        // its box only BECAUSE it is marked: unmarked it would not, marked
        // it would — an oscillation the customers list showed at 820px. So a
        // marked table stays marked until the box is wider than the table
        // was when it was measured as a plain table (kept on the box).
        let scrolls;
        if (box.hasAttribute('data-scroll-table')) {
            scrolls = box.clientWidth + 1 < (parseFloat(box.dataset.tableWidth) || 0);
        } else {
            scrolls = box.scrollWidth > box.clientWidth + 1;
            if (scrolls) box.dataset.tableWidth = box.scrollWidth;
        }
        if (scrolls && phone.matches && ! table.matches('.admin-cards, .admin-no-cards')) {
            fold(box, table);
            return;
        }

        box.toggleAttribute('data-scroll-table', scrolls);
        if (! scrolls) {
            marks.slice(1).forEach((name) => box.removeAttribute(name));
            delete box.dataset.tableWidth;
            return;
        }

        const head = (table.tHead && table.tHead.rows[0]) || (table.tBodies[0] && table.tBodies[0].rows[0]);
        const first = head && head.cells[0];
        const second = head && head.cells[1];
        const checks = isChecks(first) && words(second) !== '';
        box.toggleAttribute('data-checks', checks);
        if (checks) box.style.setProperty('--checks-width', first.getBoundingClientRect().width + 'px');
        edges(box);
    };

    const resized = new ResizeObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.target.classList.contains('admin-tabs')) return edges(entry.target);
            measure(entry.target.closest('[data-scroll-box]') || entry.target);
        });
    });

    const centre = (tabs) => {
        const active = tabs.querySelector(':scope > :is(.tab-active, .btn-active, .is-active, [aria-selected="true"], [aria-current="page"])');
        if (! active || tabs.scrollWidth <= tabs.clientWidth) return;
        const strip = tabs.getBoundingClientRect();
        const tab = active.getBoundingClientRect();
        tabs.scrollLeft += (tab.left + tab.width / 2) - (strip.left + strip.width / 2);
    };

    // A list's row of tabs (`admin-tabs`) scrolls sideways at any width:
    // the fades at its edges say what is hidden, and on a computer the
    // wheel over it scrolls it — a mouse has no other way along a row.
    const strips = new WeakSet();
    const watchStrip = (strip) => {
        if (strips.has(strip)) return;
        strips.add(strip);
        strip.addEventListener('scroll', () => edges(strip), { passive: true });
        resized.observe(strip);
        edges(strip);
    };
    document.addEventListener('wheel', (event) => {
        const strip = event.target.closest && event.target.closest('.torgash-admin :is(.admin-tabs, .tabs, .admin-strip, .join)');
        if (! strip || event.deltaX || ! event.deltaY || strip.scrollWidth <= strip.clientWidth + 1) return;
        strip.scrollLeft += event.deltaY;
        event.preventDefault();
    }, { passive: false });

    const scan = (root) => {
        root.querySelectorAll('.torgash-admin .overflow-x-auto').forEach((box) => {
            const table = box.querySelector(':scope > table');
            if (! table || seen.has(box)) return;
            seen.add(box);
            box.setAttribute('data-scroll-box', '');
            box.addEventListener('scroll', () => edges(box), { passive: true });
            measure(box);
            // The box when the window changes, the table when its rows do.
            resized.observe(box);
            resized.observe(table);
        });
        root.querySelectorAll('.torgash-admin .admin-tabs').forEach(watchStrip);
        root.querySelectorAll('.torgash-admin :is(.admin-tabs, .tabs, .admin-strip, .join)').forEach(centre);
    };

    scan(document);
    phone.addEventListener('change', () => document.querySelectorAll('[data-scroll-box]').forEach(measure));
    document.addEventListener('htmx:afterSettle', (event) => {
        const target = event.target;
        if (! (target instanceof Element)) return;
        scan(target);
        const table = target.closest('table.admin-auto-cards');
        if (table) label(table);
    });
})();
