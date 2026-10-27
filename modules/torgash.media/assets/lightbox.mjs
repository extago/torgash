/**
 * Thin wrapper over PhotoSwipe 5 (MIT, vendored under ./vendor/).
 *
 * Opened programmatically from a list of items rather than bound to <a> tags,
 * because the admin gallery's slides come from an Alpine array that changes as
 * photos are uploaded, deleted and reordered — there is no stable DOM to bind.
 *
 * The storefront gallery can use the same entry point; keep it free of anything
 * admin-specific.
 *
 * Item shape: { url, width, height, alt, title, name }
 */

let lightboxModule;
let stylesheet;

/** One shared import, so a second open does not refetch 240 KB. */
function loadLightbox() {
    lightboxModule ??= import('./vendor/photoswipe-lightbox.esm.min.js').then((m) => m.default);

    return lightboxModule;
}

/**
 * The stylesheet is normally a <link> in the layout, but this module can be
 * pulled in by a view that never got one — inject it once, idempotently.
 */
function ensureStyles(base) {
    stylesheet ??= new Promise((resolve) => {
        if (document.querySelector('link[data-photoswipe]')) {
            resolve();

            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = base + '/vendor/photoswipe.css';
        link.dataset.photoswipe = '';
        link.addEventListener('load', resolve);
        link.addEventListener('error', resolve);
        document.head.append(link);
    });

    return stylesheet;
}

let coreModule;

/** The viewer itself (185 KB), which the lightbox would otherwise fetch only once it opens. */
function loadCore() {
    coreModule ??= import('./vendor/photoswipe.esm.min.js');

    return coreModule;
}

/**
 * Fetch everything an open needs — both PhotoSwipe files and the stylesheet —
 * BEFORE the click. A page with photos calls it when the browser is idle:
 * fetched on the click they arrived one after another, each a request of its
 * own, and the first open of the gallery took seconds.
 */
export function preloadLightbox(base) {
    return Promise.all([loadLightbox(), loadCore(), ensureStyles(base)]).catch(() => {});
}

/** PhotoSwipe needs real pixel dimensions up front; probe anything missing them. */
function measure(item) {
    if (item.width && item.height) {
        return Promise.resolve({ width: item.width, height: item.height });
    }

    return new Promise((resolve) => {
        const probe = new Image();
        probe.addEventListener('load', () => resolve({ width: probe.naturalWidth, height: probe.naturalHeight }));
        // A broken image still has to open, or one bad file makes the whole
        // gallery unclickable.
        probe.addEventListener('error', () => resolve({ width: 1600, height: 1200 }));
        probe.src = item.url;
    });
}

/** PhotoSwipe ships no caption UI of its own — this is the documented recipe. */
function registerCaption(lightbox) {
    lightbox.on('uiRegister', () => {
        lightbox.pswp.ui.registerElement({
            name: 'caption',
            order: 9,
            isButton: false,
            appendTo: 'root',
            html: '',
            onInit: (el, pswp) => {
                el.style.cssText = 'position:absolute;bottom:0;left:0;right:0;padding:12px 20px;'
                    + 'text-align:center;font-size:13px;line-height:1.4;color:#fff;opacity:.75;pointer-events:none;';

                pswp.on('change', () => {
                    el.textContent = pswp.currSlide?.data?.caption || '';
                });
            },
        });
    });
}

/**
 * An icon the PAGE drew — `<template data-gallery-icon="heart">` holding an
 * `<x-icon>` — so this file writes no SVG of its own and the lightbox wears
 * the same Lucide pack as everything around it.
 */
function pageIcon(name) {
    return document.querySelector(`template[data-gallery-icon="${name}"]`)?.innerHTML.trim() ?? '';
}

const STRIP = 84; // the thumbnail strip's height, px

/**
 * The photos as a strip of thumbnails along the bottom — both viewers have it;
 * only what is behind it differs.
 */
function registerThumbs(pswp, items, background) {
    pswp.ui.registerElement({
        name: 'thumbs',
        order: 9,
        appendTo: 'root',
        html: '',
        onInit: (el) => {
            el.style.cssText = `position:absolute;left:0;right:0;bottom:0;height:${STRIP}px;display:flex;gap:8px;`
                + `align-items:center;justify-content:safe center;overflow-x:auto;padding:0 12px;background:${background};`;
            const buttons = items.map((item, i) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.setAttribute('aria-label', `${i + 1}/${items.length}`);
                b.style.cssText = 'flex:none;width:64px;height:64px;border-radius:10px;overflow:hidden;background:#fff;'
                    + 'border:2px solid transparent;padding:0;cursor:pointer;';
                const img = document.createElement('img');
                img.src = item.thumb || item.url;
                img.alt = '';
                img.loading = 'lazy';
                img.style.cssText = 'width:100%;height:100%;object-fit:contain;';
                b.append(img);
                b.addEventListener('click', () => pswp.goTo(i));
                el.append(b);

                return b;
            });
            const mark = () => buttons.forEach((b, i) => {
                const on = i === pswp.currIndex;
                b.style.borderColor = on ? '#fff' : 'transparent';
                b.style.opacity = on ? '1' : '.6';
                if (on) b.scrollIntoView({ block: 'nearest', inline: 'center' });
            });
            pswp.on('change', mark);
            mark();
        },
    });
}

/**
 * The storefront's photo viewer on a PHONE (the owner's design; a computer
 * gets registerDesktopUi below): back at the top left, zoom at the top right, "1/5" and the heart
 * over the bottom corners, the photos as a strip of thumbnails beneath. The
 * heart is not a second wishlist button: it presses the one the page already
 * has (`[data-wishlist-toggle]`) and reads its state back, so the two can
 * never disagree — and on a page without one it is simply not drawn.
 */
function registerStorefrontUi(lightbox, items, onChange) {
    const pill = 'display:flex;align-items:center;gap:6px;border-radius:12px;background:rgba(15,23,42,.55);'
        + 'backdrop-filter:blur(6px);color:#fff;';

    lightbox.on('uiRegister', () => {
        const pswp = lightbox.pswp;

        pswp.ui.registerElement({
            name: 'back',
            order: 1,
            isButton: true,
            // The page's words, like its icons: this file speaks no language.
            title: document.querySelector('template[data-gallery-icon="arrow-left"]')?.dataset.label || '',
            html: pageIcon('arrow-left'),
            onClick: 'close',
            onInit: (el) => {
                el.style.cssText = pill + 'width:48px;height:48px;justify-content:center;margin:12px auto 0 12px;';
            },
        });

        pswp.ui.registerElement({
            name: 'count',
            order: 9,
            appendTo: 'root',
            html: '',
            onInit: (el) => {
                el.style.cssText = pill + `position:absolute;left:12px;bottom:${STRIP + 12}px;padding:6px 10px;font-size:14px;`;
                const draw = () => { el.innerHTML = pageIcon('image') + `<span>${pswp.currIndex + 1}/${pswp.getNumItems()}</span>`; };
                pswp.on('change', draw);
                draw();
            },
        });

        const toggle = () => document.querySelector('[data-wishlist-toggle] button[aria-pressed]');

        if (toggle()) {
            pswp.ui.registerElement({
                name: 'heart',
                order: 9,
                appendTo: 'root',
                isButton: true,
                html: pageIcon('heart'),
                onInit: (el) => {
                    el.title = document.querySelector('template[data-gallery-icon="heart"]')?.dataset.label || '';
                    el.style.cssText = pill + `position:absolute;right:12px;bottom:${STRIP + 12}px;width:48px;height:48px;justify-content:center;`;
                    const mirror = () => {
                        const on = toggle()?.getAttribute('aria-pressed') === 'true';
                        el.setAttribute('aria-pressed', on ? 'true' : 'false');
                        el.querySelector('svg')?.setAttribute('fill', on ? 'currentColor' : 'none');
                        el.style.color = on ? '#f87171' : '#fff';
                    };
                    mirror();
                    // The page's button is re-drawn by its own answer (HTMX), so
                    // look it up again every time rather than holding the old one.
                    document.body.addEventListener('htmx:afterSwap', mirror);
                    pswp.on('destroy', () => document.body.removeEventListener('htmx:afterSwap', mirror));
                },
                onClick: () => toggle()?.click(),
            });
        }

        registerThumbs(pswp, items, '#0f172a');

        pswp.on('change', () => onChange?.(pswp.currIndex));
    });
}

/**
 * The same viewer on a computer (the owner's call, after tac-defense's): the
 * page stays visible through a dimmed, blurred backdrop rather than going
 * black; the product's NAME at the top left, only a cross at the top right —
 * no heart, which is the buy panel's business and is one click away —
 * rounded-square arrows at the sides, the thumbnails along the bottom. Zooming is a click on
 * the photo, which is what PhotoSwipe does anyway, so it needs no button.
 */
function registerDesktopUi(lightbox, items, title, onChange) {
    const square = 'display:flex;align-items:center;justify-content:center;border-radius:12px;'
        + 'background:rgba(255,255,255,.14);color:#fff;border:0;cursor:pointer;';

    lightbox.on('uiRegister', () => {
        const pswp = lightbox.pswp;
        const label = (name) => document.querySelector(`template[data-gallery-icon="${name}"]`)?.dataset.label || '';

        pswp.ui.registerElement({
            name: 'title',
            order: 1,
            html: '',
            onInit: (el) => {
                el.style.cssText = 'flex:1;min-width:0;display:flex;align-items:baseline;gap:12px;padding:0 20px;'
                    + 'color:#fff;font-size:16px;font-weight:600;line-height:60px;';
                const name = document.createElement('span');
                name.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                name.textContent = title || '';
                const count = document.createElement('span');
                count.style.cssText = 'flex:none;font-weight:400;font-size:14px;opacity:.65;font-variant-numeric:tabular-nums;';
                el.append(name, count);
                const draw = () => { count.textContent = pswp.getNumItems() > 1 ? `${pswp.currIndex + 1} / ${pswp.getNumItems()}` : ''; };
                pswp.on('change', draw);
                draw();
            },
        });

        pswp.ui.registerElement({
            name: 'shut',
            order: 20,
            isButton: true,
            title: label('x'),
            html: pageIcon('x'),
            onClick: 'close',
            onInit: (el) => { el.style.cssText = square + 'width:44px;height:44px;margin:8px 12px 0 0;'; },
        });

        if (items.length > 1) {
            [['prev', 'chevron-left', 'left:20px', () => pswp.prev()], ['next', 'chevron-right', 'right:20px', () => pswp.next()]]
                .forEach(([name, icon, side, go]) => pswp.ui.registerElement({
                    name,
                    order: 10,
                    appendTo: 'root',
                    isButton: true,
                    title: label(icon),
                    html: pageIcon(icon),
                    onClick: go,
                    onInit: (el) => {
                        el.style.cssText = square + `position:absolute;${side};top:calc(50% - ${STRIP / 2}px);`
                            + 'transform:translateY(-50%);width:52px;height:52px;';
                    },
                }));

            registerThumbs(pswp, items, 'transparent');
        }

        // Dimmed, not black: the page shows through, blurred. The blur goes on
        // the ROOT — it animates its own opacity, which makes it the backdrop
        // root, so a blur on the background inside it sees nothing (measured).
        pswp.on('firstUpdate', () => {
            pswp.element.style.setProperty('--pswp-bg', '#0f172a');
            pswp.element.style.backdropFilter = 'blur(8px)';
            pswp.element.style.webkitBackdropFilter = 'blur(8px)';
        });

        pswp.on('change', () => onChange?.(pswp.currIndex));
    });
}

/**
 * @param {{ items: Array, index?: number, base: string, storefront?: boolean, title?: string, onChange?: Function }} options
 *        base — URL prefix this module was served from, used to resolve ./vendor.
 *        storefront — the shop's own viewer (thumbnails, counter, heart on
 *        a phone; name, cross and arrows over a dimmed page on a computer);
 *        without it, the admin's plain one with a caption.
 *        title — what the computer's viewer prints at its top left.
 */
export async function openLightbox({ items, index = 0, base, storefront = false, title = '', onChange }) {
    if (!items?.length) return;

    const [PhotoSwipeLightbox] = await Promise.all([loadLightbox(), ensureStyles(base), loadCore()]);
    const sizes = await Promise.all(items.map(measure));

    if (storefront) {
        // The computer's layout wherever there is room for arrows beside the photo.
        const desktop = window.matchMedia('(min-width: 1024px)').matches;
        const thumbs = items.length > 1 ? STRIP : 0;

        const lightbox = new PhotoSwipeLightbox({
            dataSource: items.map((item, i) => ({
                src: item.url,
                msrc: item.thumb,
                width: sizes[i].width,
                height: sizes[i].height,
                alt: item.alt || '',
            })),
            pswpModule: loadCore,
            bgOpacity: desktop ? 0.82 : 1,
            close: false,
            counter: false,
            zoom: !desktop,
            arrowPrev: false,
            arrowNext: false,
            zoomTitle: document.querySelector('template[data-gallery-icon="zoom"]')?.dataset.label || 'Zoom',
            padding: desktop
                ? { top: 64, bottom: thumbs + 16, left: 96, right: 96 }
                : { top: 72, bottom: STRIP + 72, left: 12, right: 12 },
        });

        if (desktop) {
            registerDesktopUi(lightbox, items, title, onChange);
        } else {
            registerStorefrontUi(lightbox, items, onChange);
        }

        lightbox.init();
        lightbox.loadAndOpen(index);

        return;
    }

    // A fresh instance per open: the slide list is rebuilt on every gallery
    // change, and with no `gallery` selector this binds nothing to the page,
    // so it is collected once the overlay closes and the reference goes away.
    const lightbox = new PhotoSwipeLightbox({
        dataSource: items.map((item, i) => ({
            src: item.url,
            width: sizes[i].width,
            height: sizes[i].height,
            alt: item.alt || '',
            caption: item.title || item.alt || item.name || '',
        })),
        pswpModule: loadCore,
        bgOpacity: 0.9,
        padding: { top: 24, bottom: 56, left: 24, right: 24 },
    });

    registerCaption(lightbox);
    lightbox.init();
    lightbox.loadAndOpen(index);
}
