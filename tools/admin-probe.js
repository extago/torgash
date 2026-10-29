/*
 * The admin on a phone and a tablet, measured (PLAN §62).
 *
 * Paste into the browser console on any admin page while signed in as staff
 * (the frames share the page's origin and session). Every screen of the
 * sidebar menu — and, with `details: true`, the first record each list links
 * to — is opened in a frame of each width and measured once it has settled:
 *
 *   overflow  the page is wider than the screen; `by` names the element that
 *             sticks out furthest (the deepest one past the right edge)
 *   tables    tables that scroll sideways inside their box (admin-scroll.js
 *             marks them; on a phone a wide table is folded into cards
 *             instead and is not counted)
 *   targets   controls measured on the page (the share of small ones is
 *             small / targets)
 *   small     controls under 40px on their short side — buttons, fields,
 *             tabs, checkboxes (a box inside a label is measured by the label,
 *             a field inside daisyUI's `label.input` by that label, and an
 *             invisible ::after ring around a control counts as the control)
 *   iconOnly  buttons and links with an icon, no words and no aria-label
 *
 * `touch: true` (the default) applies the stylesheet's `pointer: coarse`
 * rules inside the frames: a mouse cannot ask for them, and a phone is what
 * is measured. Results: window.__probe; probe.summary() prints the table.
 *
 *   const probe = await adminProbe({ widths: [390, 820, 1180] });
 *   probe.summary();  probe.worst('small', 390);
 *
 * Writes nothing: only GET pages are opened, and a frame's forms are never
 * submitted. Pages are opened one at a time.
 */
window.adminProbe = async function ({ widths = [390, 820, 1180], height = 800, touch = true, details = true, urls = null, settle = 600 } = {}) {
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    // The rules behind `@media (pointer: coarse)`, lifted out of the stylesheet.
    let coarse = '';
    if (touch) {
        const href = document.querySelector('link[rel="stylesheet"][href*="app.css"]').href;
        const css = await (await fetch(href, { cache: 'no-store' })).text();
        const opener = /@media\s*\(pointer:\s*coarse\)\s*(?:and\s*([^{]+))?\{/g;
        let match;
        while ((match = opener.exec(css))) {
            let depth = 1, at = opener.lastIndex;
            while (depth && at < css.length) { if (css[at] === '{') depth++; else if (css[at] === '}') depth--; at++; }
            const rules = css.slice(opener.lastIndex, at - 1);
            // "A finger and a width": the width stays the frame's to answer.
            coarse += (match[1] ? `@media ${match[1].trim()}{${rules}}` : rules) + '\n';
        }
    }

    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;border:0;visibility:hidden';
    document.body.appendChild(frame);

    const load = async (url, width) => {
        frame.style.width = width + 'px';
        frame.style.height = height + 'px';
        await new Promise((resolve) => { frame.onload = resolve; frame.src = url; });
        const doc = frame.contentDocument;
        if (coarse) {
            const style = doc.createElement('style');
            style.textContent = coarse;
            doc.head.appendChild(style);
        }
        await sleep(settle);
        return doc;
    };

    const visible = (el, view) => {
        const box = el.getBoundingClientRect();
        if (! box.width || ! box.height) return null;
        const style = view.getComputedStyle(el);
        if (style.visibility === 'hidden' || style.display === 'none' || Number(style.opacity) === 0) return null;
        return box;
    };

    const measure = (doc) => {
        const view = doc.defaultView;
        const root = doc.documentElement;
        const result = { overflow: Math.max(0, root.scrollWidth - root.clientWidth), by: null, tables: 0, targets: 0, small: [], iconOnly: 0 };

        if (result.overflow) {
            let worst = null;
            doc.querySelectorAll('body *').forEach((el) => {
                const box = el.getBoundingClientRect();
                if (box.right <= root.clientWidth + 1 || ! box.width) return;
                for (let up = el.parentElement; up && up !== doc.body; up = up.parentElement) {
                    if (/(auto|scroll|hidden|clip)/.test(view.getComputedStyle(up).overflowX)) return;
                }
                if (! worst || box.right > worst.right + 1 || (Math.abs(box.right - worst.right) <= 1 && worst.el.contains(el))) worst = { el, right: box.right };
            });
            if (worst) {
                const el = worst.el;
                result.by = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + '.' + [...el.classList].slice(0, 4).join('.') + ' "' + (el.textContent || '').trim().slice(0, 30) + '"';
            }
        }

        // What admin-scroll.js decided scrolls sideways; a table folded into
        // cards on a phone is not one, however wide it would have been.
        result.tables = doc.querySelectorAll('[data-scroll-table] > table').length;

        const seen = new Set();
        doc.querySelectorAll('button, .btn, [role="button"], a.tab, .tab, input:not([type="hidden"]), select, textarea, summary').forEach((el) => {
            if (el.closest('.drawer-side, [x-cloak], template, dialog:not([open])')) return;
            // Not a target: switched off, or there only for a screen reader.
            if (el.matches(':disabled, .btn-disabled, .sr-only')) return;
            let target = el;
            if (el.matches('input[type="checkbox"], input[type="radio"]')) target = el.closest('label') || el;
            // A field drawn inside daisyUI's `label.input` is tapped through the label.
            else if (el.matches('input, select') && el.parentElement?.matches('label.input, label.select')) target = el.parentElement;
            if (seen.has(target)) return;
            seen.add(target);
            const box = visible(target, view);
            if (! box) return;
            // A control may take taps around itself through an invisible ::after
            // (app.css: a checkbox's ring, a badge that opens a menu): the ring
            // counts as much as the control.
            let width = box.width, height = box.height;
            const after = view.getComputedStyle(target, '::after');
            if (after.content !== 'none' && after.position === 'absolute') {
                const out = (value) => Math.max(0, -parseFloat(value) || 0);
                width += out(after.left) + out(after.right);
                height += out(after.top) + out(after.bottom);
            }
            const side = Math.min(width, height);
            result.targets++;
            if (side < 40) result.small.push((target.tagName.toLowerCase() + '.' + [...target.classList].slice(0, 3).join('.') + ' ' + Math.round(side)).trim());
        });

        doc.querySelectorAll('a, button').forEach((el) => {
            if (el.closest('.drawer-side')) return;
            if (el.querySelector('svg') && ! el.textContent.trim() && ! el.getAttribute('aria-label') && visible(el, view)) result.iconOnly++;
        });

        return result;
    };

    let list = urls;
    if (! list) {
        list = [...new Set([...document.querySelectorAll('.drawer-side a[href]')]
            .map((a) => new URL(a.href, location.href))
            .filter((url) => url.origin === location.origin && url.pathname.startsWith('/admin'))
            .map((url) => url.pathname))];
    }

    const results = [];
    const record = async (url) => {
        const row = { url, at: {} };
        for (const width of widths) {
            try {
                const doc = await load(url, width);
                row.at[width] = measure(doc);
                if (details && width === widths[widths.length - 1] && ! url.match(/\/\d+(\/edit)?$/)) {
                    const path = url.replace(/\/$/, '');
                    const link = [...doc.querySelectorAll('a[href]')].map((a) => new URL(a.href).pathname)
                        .find((href) => href.startsWith(path + '/') && /\/\d+(\/edit)?$/.test(href));
                    if (link) row.detail = link;
                }
            } catch (error) {
                row.at[width] = { error: String(error) };
            }
        }
        results.push(row);
        console.log(url, Object.entries(row.at).map(([w, r]) => `${w}: +${r.overflow ?? '?'} small ${r.small ? r.small.length : '?'}`).join(' | '));
        return row;
    };

    for (const url of list) await record(url);
    if (details) {
        const detailUrls = [...new Set(results.map((row) => row.detail).filter(Boolean))];
        for (const url of detailUrls) await record(url);
    }
    frame.remove();

    const probe = {
        results,
        summary() {
            const table = {};
            widths.forEach((width) => {
                const at = results.map((row) => row.at[width]).filter((r) => r && ! r.error);
                table[width] = {
                    pages: at.length,
                    wider: at.filter((r) => r.overflow > 0).length,
                    'wide tables': at.filter((r) => r.tables > 0).length,
                    targets: at.reduce((sum, r) => sum + (r.targets ?? 0), 0),
                    'small targets': at.reduce((sum, r) => sum + r.small.length, 0),
                    'icon only': at.reduce((sum, r) => sum + r.iconOnly, 0),
                };
            });
            console.table(table);
            return table;
        },
        worst(metric = 'overflow', width = widths[0], count = 15) {
            const value = (r) => (metric === 'small' ? r.small.length : r[metric]);
            return results.filter((row) => row.at[width] && ! row.at[width].error)
                .sort((a, b) => value(b.at[width]) - value(a.at[width])).slice(0, count)
                .map((row) => ({ url: row.url, [metric]: value(row.at[width]), by: row.at[width].by, sample: row.at[width].small.slice(0, 5).join(', ') }));
        },
    };
    window.__probe = probe;
    return probe;
};
