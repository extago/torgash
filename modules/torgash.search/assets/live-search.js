/*
 * Torgash live search — the window under the search box.
 *
 * Attaches to every form[data-live-search] holding an input[name=q]. The
 * window's contents are HTML from the server (/search/suggest), never data
 * assembled here: the server already knows how a product, a price and a
 * stock line look, and a second copy of that in JavaScript would drift.
 *
 * Behaviour, as a combobox (WAI-ARIA 1.2 pattern):
 * - typing waits `delay` ms after the last key, then asks; an older answer
 *   arriving after a newer one is dropped (AbortController);
 * - fewer than `min` letters shows the visitor's recent searches instead;
 * - ArrowDown / ArrowUp move through [role=option], Enter opens the chosen
 *   one (or submits the form when none is chosen), Escape closes;
 * - a click outside closes; focus back on the box reopens what was there;
 * - the form's `scope` radios (the "site / catalogue" switch a module draws
 *   inside the form) ride every request, re-ask when changed and set the
 *   box's placeholder;
 * - the window does not scroll; its product list does, and a
 *   [data-live-search-more] marker scrolled into view in it is replaced by
 *   the next batch it names (the server decides whether there is one);
 * - a buy button that succeeded shows "in cart" — both states are drawn by
 *   the server, this only flips them; a refusal (HX-Retarget) flips nothing.
 *
 * The window lives in <body>, NOT inside the search form, and is placed under
 * the box by its position on screen. Inside the form, the buy buttons' own
 * <form>s were nested forms: the parser drops the inner tag and keeps its
 * inputs, so pressing "add to cart" submitted the SEARCH with `qty=1`
 * (measured — the CLAUDE.md nested-form trap, reached through innerHTML).
 */
(() => {
    const script = document.currentScript;

    if (!script) {
        return;
    }

    const config = {
        url: script.dataset.url,
        forget: script.dataset.forget,
        delay: parseInt(script.dataset.delay || '200', 10),
        min: parseInt(script.dataset.min || '2', 10),
        label: script.dataset.label || 'Suggestions',
        backdrop: script.dataset.backdrop === '1',
    };

    let counter = 0;

    function attach(form) {
        const input = form.querySelector('input[name="q"]');

        if (!input || form.dataset.liveSearchReady) {
            return;
        }

        form.dataset.liveSearchReady = '1';
        const id = 'live-search-' + (++counter);

        const panel = document.createElement('div');
        panel.id = id;
        panel.setAttribute('role', 'listbox');
        panel.setAttribute('aria-label', config.label);
        panel.className = 'torgash-live-search fixed z-50 flex flex-col overflow-hidden rounded-box border border-base-300 bg-base-100 text-base-content shadow-xl';
        panel.setAttribute('data-closed', '');
        document.body.appendChild(panel);

        // The page behind, dimmed while the box is in use — the shop's choice
        // (suggest_backdrop). It starts under the header, which stays lit:
        // that is where the box is.
        const backdrop = config.backdrop ? document.createElement('div') : null;

        if (backdrop) {
            backdrop.className = 'torgash-live-search-backdrop';
            backdrop.setAttribute('data-closed', '');
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.appendChild(backdrop);
            backdrop.addEventListener('click', () => {
                close();
                input.blur();
                dim(false);
            });
        }

        const isOpen = () => !panel.hasAttribute('data-closed');

        function dim(on) {
            if (!backdrop) {
                return;
            }

            if (on) {
                const top = (form.closest('header') || form).getBoundingClientRect().bottom;
                backdrop.style.top = Math.max(0, top) + 'px';
                backdrop.removeAttribute('data-closed');
            } else {
                backdrop.setAttribute('data-closed', '');
            }
        }

        // Under the box, as wide as the box (and never narrower than a phone
        // screen's worth) — on a phone the whole screen less the page's own
        // 12px edges, since there the box starts after the menu button and
        // the window hung off-centre beside it. Re-placed whenever the page
        // moves under it. Its
        // height is bounded by what is left of the screen below the box: the
        // window itself never scrolls — only its list of products does, so
        // "All results", the categories and the pages stay where the pointer
        // is going (a whole scrolling window moved them away mid-reach).
        function place() {
            const box = form.getBoundingClientRect();
            const phone = window.innerWidth < 768;
            const width = phone ? window.innerWidth - 24 : Math.min(Math.max(box.width, 320), window.innerWidth - 16);
            const left = phone ? 12 : Math.min(Math.max(8, box.left), window.innerWidth - width - 8);
            const top = box.bottom + 8;

            panel.style.top = top + 'px';
            panel.style.left = left + 'px';
            panel.style.width = width + 'px';
            panel.style.maxHeight = Math.max(200, Math.min(window.innerHeight * 0.75, window.innerHeight - top - 12)) + 'px';
        }

        window.addEventListener('resize', () => isOpen() && place());
        window.addEventListener('scroll', () => { if (isOpen()) { place(); } if (backdrop && !backdrop.hasAttribute('data-closed')) { dim(true); } }, { passive: true });

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', id);
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('autocomplete', 'off');

        let timer = null;
        let controller = null;
        let active = -1;

        const options = () => Array.from(panel.querySelectorAll('[role="option"]'));

        function open() {
            if (panel.innerHTML.trim() === '') {
                close();

                return;
            }

            place();
            panel.removeAttribute('data-closed');
            dim(true);
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            panel.setAttribute('data-closed', '');
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        }

        function highlight(index) {
            const list = options();

            list.forEach((el) => el.setAttribute('aria-selected', 'false'));

            if (list.length === 0) {
                active = -1;

                return;
            }

            active = (index + list.length) % list.length;
            const el = list[active];

            if (!el.id) {
                el.id = id + '-o' + active;
            }

            el.setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', el.id);
            el.scrollIntoView({ block: 'nearest' });
        }

        function render(html) {
            panel.innerHTML = html;
            active = -1;

            // The buy buttons in the window are htmx forms, like the product
            // page's: they must be processed, or they would submit as plain
            // forms and leave the page.
            if (window.htmx) {
                window.htmx.process(panel);
            }

            watch();

            const forget = panel.querySelector('[data-live-search-forget]');

            if (forget) {
                forget.addEventListener('click', (e) => {
                    e.preventDefault();
                    request(config.forget, 'POST');
                });
            }

            open();
        }

        function request(url, method = 'GET') {
            if (controller) {
                controller.abort();
            }

            controller = new AbortController();
            const token = document.querySelector('meta[name="csrf-token"]');

            fetch(url, {
                method,
                signal: controller.signal,
                credentials: 'same-origin',
                headers: {
                    'HX-Request': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-CSRF-TOKEN': token.content } : {}),
                },
            })
                .then((response) => (response.ok ? response.text() : ''))
                .then(render)
                .catch((error) => {
                    if (error.name !== 'AbortError') {
                        close();
                    }
                });
        }

        const scope = () => {
            const checked = form.querySelector('input[name="scope"]:checked');

            return checked ? checked.value : '';
        };

        function ask() {
            const q = input.value.trim();
            const s = scope();

            request(config.url + (config.url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q) + (s ? '&scope=' + encodeURIComponent(s) : ''));
        }

        function placeholder() {
            const checked = form.querySelector('input[name="scope"]:checked');

            if (checked && checked.dataset.placeholder) {
                input.placeholder = checked.dataset.placeholder;
            }
        }

        placeholder();

        // Opening the switch closes the window: the window lives in <body>
        // above the header, and would cover the switch's own menu (measured).
        const switcher = form.querySelector('.torgash-search-scope');

        if (switcher) {
            switcher.addEventListener('focusin', () => close());
        }

        form.querySelectorAll('input[name="scope"]').forEach((radio) => radio.addEventListener('change', () => {
            placeholder();
            // Close the switch's own menu (a popover: core's component).
            const menu = switcher && switcher.querySelector('[popover]');
            if (menu && menu.matches(':popover-open')) {
                menu.hidePopover();
            }
            input.focus();
            ask();
        }));

        // The next batch when the end of the product list comes into view —
        // measured against the LIST, which is what scrolls. The marker is
        // swapped for the batch, which carries the next marker, if any. A new
        // answer brings a new list, so the observer follows it.
        let loader = null;
        let observed = null;

        function load(marker) {
            marker.dataset.loading = '1';

            fetch(marker.dataset.liveSearchMore, {
                credentials: 'same-origin',
                headers: { 'HX-Request': 'true', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => (response.ok ? response.text() : ''))
                .then((html) => {
                    if (!marker.isConnected) {
                        return;
                    }

                    const holder = document.createElement('ul');
                    holder.innerHTML = html;
                    const added = Array.from(holder.children);
                    marker.replaceWith(...added);
                    added.forEach((el) => window.htmx && window.htmx.process(el));
                    watch();
                })
                .catch(() => marker.remove());
        }

        function watch() {
            const list = panel.querySelector('[data-live-search-products]');

            if (!list || !('IntersectionObserver' in window)) {
                return;
            }

            if (list !== observed) {
                if (loader) {
                    loader.disconnect();
                }

                observed = list;
                loader = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting && !entry.target.dataset.loading) {
                            loader.unobserve(entry.target);
                            load(entry.target);
                        }
                    });
                }, { root: list, rootMargin: '0px 0px 80px 0px' });
            }

            list.querySelectorAll('[data-live-search-more]:not([data-loading])').forEach((m) => loader.observe(m));
        }

        panel.addEventListener('htmx:afterRequest', (e) => {
            // The request the add form itself sent — never one from inside it.
            const add = e.detail && e.detail.elt && e.detail.elt.matches && e.detail.elt.matches('[data-live-search-add]') ? e.detail.elt : null;
            const xhr = e.detail && e.detail.xhr;

            if (!add || !e.detail.successful || (xhr && xhr.getResponseHeader('HX-Retarget'))) {
                return;
            }

            const box = add.closest('[data-live-search-buy]');
            const done = box && box.querySelector('[data-live-search-in-cart]');

            if (done) {
                add.classList.add('hidden');
                done.classList.remove('hidden');
            }
        });

        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(ask, input.value.trim().length < config.min ? 0 : config.delay);
        });

        input.addEventListener('focus', () => {
            dim(true);

            if (panel.innerHTML.trim() === '') {
                ask();
            } else {
                open();
            }
        });

        input.addEventListener('keydown', (e) => {
            const expanded = isOpen();

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                expanded ? highlight(active + 1) : ask();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (expanded) {
                    highlight(active - 1);
                }
            } else if (e.key === 'Enter' && expanded && active >= 0) {
                const chosen = options()[active];

                if (chosen && chosen.href) {
                    e.preventDefault();
                    window.location.href = chosen.href;
                }
            } else if (e.key === 'Escape') {
                if (isOpen()) {
                    close();
                } else {
                    input.blur();
                    dim(false);
                }
            }
        });

        const inside = (node) => node && (form.contains(node) || panel.contains(node));

        document.addEventListener('click', (e) => {
            if (!inside(e.target)) {
                close();
                dim(false);
            }
        });

        // Tab out of the box and the window's own controls: close when focus
        // has left both.
        const leave = (e) => {
            if (e.relatedTarget && !inside(e.relatedTarget)) {
                close();
                dim(false);
            }
        };

        form.addEventListener('focusout', leave);
        panel.addEventListener('focusout', leave);
    }

    function init() {
        document.querySelectorAll('form[data-live-search]').forEach(attach);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
