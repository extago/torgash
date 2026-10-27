/*
 * Corner notices (<x-notice>, core). The server draws them; this only takes
 * them away. A notice's life is the CSS animation of its bottom bar, which
 * pauses under the pointer and on focus, so "hold it while I read" needs no
 * timer here, and the notice leaves when the bar ends. At most three stay:
 * a fourth pushes the oldest out.
 */
(() => {
    const MAX = 3;

    function dismiss(notice) {
        if (!notice || notice.dataset.leaving) {
            return;
        }

        notice.dataset.leaving = '1';
        notice.classList.add('is-leaving');
        // The leave animation removes it; the timeout covers a browser that
        // runs no animation at all.
        const gone = () => notice.remove();
        notice.addEventListener('animationend', (e) => { if (e.target === notice) gone(); });
        setTimeout(gone, 400);
    }

    document.addEventListener('animationend', (e) => {
        if (e.target.classList && e.target.classList.contains('torgash-notice-life')) {
            dismiss(e.target.closest('[data-notice]'));
        }
    });

    document.addEventListener('click', (e) => {
        const close = e.target.closest && e.target.closest('[data-notice-close]');

        if (close) {
            dismiss(close.closest('[data-notice]'));
        }
    });

    document.addEventListener('keydown', (e) => {
        const notice = e.key === 'Escape' && e.target.closest && e.target.closest('[data-notice]');

        if (notice) {
            dismiss(notice);
        }
    });

    document.addEventListener('htmx:oobAfterSwap', (e) => {
        const stack = e.detail && e.detail.target;

        if (stack && stack.id === 'torgash-notices') {
            [...stack.querySelectorAll('[data-notice]:not([data-leaving])')].slice(MAX).forEach(dismiss);
        }
    });
})();
