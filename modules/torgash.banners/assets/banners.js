/*
 * What every banner on a page needs (PLAN §51), loaded once by a page that
 * shows one:
 *
 *  - a click on a link inside a banner is counted: a beacon tells the shop
 *    which banner it was, the link itself is left alone (its own address, a
 *    new tab if the visitor wants one) and the beacon goes even as the page
 *    is left;
 *  - a video behind a banner (`video[data-autoplay]`) plays while it is on
 *    the screen and stops when it leaves it — never for a visitor who asked
 *    their system for less motion. Inside a turning slider the slider's own
 *    script decides instead: only the slide in view plays.
 */
(() => {
    if (window.torgashBanners) {
        return;
    }
    window.torgashBanners = true;

    const url = document.currentScript.dataset.url;

    const count = (event) => {
        const link = event.target.closest('a[href]');
        const banner = link && link.closest('.torgash-banner[data-banner]');

        if (!banner || !navigator.sendBeacon) {
            return;
        }

        const data = new FormData();
        data.append('banner', banner.dataset.banner);
        navigator.sendBeacon(url, data);
    };

    // A middle click opens a new tab without a "click" event.
    document.addEventListener('click', count, true);
    document.addEventListener('auxclick', (event) => event.button === 1 && count(event), true);

    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (calm || !('IntersectionObserver' in window)) {
        return;
    }

    const watcher = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            entry.isIntersecting ? entry.target.play().catch(() => {}) : entry.target.pause();
        });
    }, { threshold: 0.25 });

    const watch = () => document.querySelectorAll('.torgash-banner video[data-autoplay]').forEach((video) => {
        if (!video.closest('.torgash-slider')) {
            watcher.observe(video);
        }
    });

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', watch) : watch();
})();
