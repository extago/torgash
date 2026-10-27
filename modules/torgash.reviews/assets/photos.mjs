/**
 * A review's photographs open in the shop's own viewer (torgash.media's
 * lightbox.mjs, `storefront: true`) — the same one the product's gallery uses,
 * never a second library.
 *
 * Bound ONCE to the document rather than to the links, because the list is
 * replaced by HTMX whenever a reader sorts, filters or asks for more, and a
 * listener on the old links would leave the new ones opening a bare file.
 * The links stay real links to the picture, so without this script (or with
 * a modifier key held) a photo still opens — in a tab, as it used to.
 *
 * A click opens the photos of ITS group — `[data-review-photos]`: one review,
 * or the strip of every customer's photo over the list — so the arrows walk
 * through what the reader was looking at, not the whole page.
 *
 * Imported as a module, so a section drawn twice still binds one listener.
 */

// Core serves every module's assets side by side (/modules/<id>/assets/).
const media = new URL('../../torgash.media/assets', import.meta.url).href;

// The viewer's files while the pointer is on its way to a photo, so the
// click opens at once (torgash.media's preloadLightbox()).
document.addEventListener('pointerover', (event) => {
    if (event.target.closest?.('a[data-review-photo]')) {
        import(media + '/lightbox.mjs').then((m) => m.preloadLightbox(media));
    }
}, { passive: true });

document.addEventListener('click', async (event) => {
    const link = event.target.closest?.('a[data-review-photo]');

    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();

    const links = [...(link.closest('[data-review-photos]') ?? link.parentElement).querySelectorAll('a[data-review-photo]')];
    const items = links.map((a) => ({
        url: a.href,
        thumb: a.querySelector('img')?.getAttribute('src') || a.href,
        width: parseInt(a.dataset.width, 10) || 0,
        height: parseInt(a.dataset.height, 10) || 0,
        alt: a.querySelector('img')?.alt || '',
    }));

    const { openLightbox } = await import(media + '/lightbox.mjs');

    openLightbox({
        items,
        index: Math.max(0, links.indexOf(link)),
        base: media,
        storefront: true,
        title: document.querySelector('h1')?.textContent.trim() || '',
    });
});
