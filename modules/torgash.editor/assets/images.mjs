/**
 * Images inside a text: the panel, and the node that carries them.
 *
 * The files belong to the record being edited — the media module answered
 * `editor.images` with somewhere to put them, and owns everything about
 * storing one. What this half owns is the DOCUMENT: where an image sits, what
 * it says, how big it is drawn.
 *
 * Two things are deliberately not here:
 *
 * - **No thumbnails.** The plaques show the stored original, scaled by CSS. A
 *   content image is not a preview of anything, so the media module generates
 *   no conversions for it, and asking for one here would ask for a file that
 *   does not exist. What keeps that cheap is that the original is already
 *   bounded — every upload is downscaled to the shop's maximum dimension.
 * - **No drag handles.** TipTap's own image extension can do it (its `resize`
 *   option builds a resizable node view) and it is switched off: the owner
 *   asked for number fields, a node view that throws takes the whole editing
 *   surface with it, and the option's shape is undocumented enough that "it
 *   worked when I tried it" is the most that could honestly be said. Two
 *   fields and a dropdown are exact, keyboard-reachable, and cannot break the
 *   surface they are editing.
 */
import Image from './vendor/tiptap-extension-image.mjs';

/**
 * The stock image node plus one attribute of our own.
 *
 * `size` renders as `data-size` and is the difference between "this picture is
 * 400px wide" and "this picture is as wide as the column". It is also what
 * tells "as uploaded" from "set by hand" when the editor is opened again a
 * month later, which the numbers alone cannot: an auto-sized image carries its
 * natural width too.
 *
 * Width and height stay on the tag in every mode — they are what stops the
 * page jumping while the image loads — and the storefront's own
 * `.torgash-rich img` rule (`max-width: 100%; height: auto`) is what keeps
 * them from breaking a phone.
 */
export const ContentImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            size: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-size'),
                renderHTML: (attributes) => (attributes.size ? { 'data-size': attributes.size } : {}),
            },
        };
    },
});

const KB = 1024;

function humanSize(bytes) {
    if (!bytes) return '';

    return bytes >= KB * KB
        ? (bytes / KB / KB).toFixed(1) + ' MB'
        : Math.max(1, Math.round(bytes / KB)) + ' KB';
}

function show(element, on, display = 'flex') {
    element.classList.toggle('hidden', !on);

    if (display) element.classList.toggle(display, on);
}

/**
 * Wires the panel found inside `root` to `editor`. Returns a handle with an
 * open() for the toolbar button, or null when this field has no panel — the
 * ordinary case for a record that has not been saved yet, and the reason the
 * toolbar button is not drawn either.
 */
export function bindImages(root, editor) {
    const panel = root.querySelector('[data-tiptap-images]');
    if (!panel) return null;

    const endpoint = panel.dataset.endpoint;
    const list = panel.querySelector('[data-tiptap-image-list]');
    const empty = panel.querySelector('[data-tiptap-image-empty]');
    const plaque = panel.querySelector('[data-tiptap-image-plaque]');
    const errorBox = panel.querySelector('[data-tiptap-image-errors]');
    const busy = panel.querySelector('[data-tiptap-image-busy]');
    const props = panel.querySelector('[data-tiptap-image-props]');
    const altInput = panel.querySelector('[data-tiptap-image-alt]');
    const sizeSelect = panel.querySelector('[data-tiptap-image-size]');
    const manualBox = panel.querySelector('[data-tiptap-image-manual]');
    const widthInput = panel.querySelector('[data-tiptap-image-width]');
    const heightInput = panel.querySelector('[data-tiptap-image-height]');
    const galleryHint = panel.querySelector('[data-tiptap-gallery-hint]');

    let items = [];
    let loaded = false;
    let marking = null;

    const isOpen = () => !panel.classList.contains('hidden');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    function errors(messages) {
        errorBox.textContent = (messages || []).join('\n');
        errorBox.classList.toggle('hidden', !(messages || []).length);
    }

    /**
     * Every call answers with the record's full, freshly-read list, so the
     * panel never has to work out what the server ended up with — the same
     * contract the gallery editor's endpoints keep.
     */
    async function send(path, options = {}) {
        busy.classList.remove('hidden');
        errors([]);

        try {
            const response = await fetch(endpoint + path, {
                method: options.method || 'POST',
                headers: Object.assign(
                    { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                    options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' },
                ),
                body: options.body,
            });

            const data = await response.json().catch(() => null);

            if (!data) {
                errors(['HTTP ' + response.status]);

                return;
            }

            items = data.items || items;
            loaded = true;
            errors(data.errors || []);
            render();
        } catch (e) {
            errors([String(e)]);
        } finally {
            busy.classList.add('hidden');
        }
    }

    function render() {
        list.replaceChildren();
        empty.classList.toggle('hidden', items.length > 0);

        items.forEach((item) => {
            const node = plaque.content.firstElementChild.cloneNode(true);

            node.dataset.url = item.url;
            node.querySelector('[data-thumb]').src = item.url;
            node.querySelector('[data-name]').textContent = item.name;
            node.querySelector('[data-meta]').textContent = [
                humanSize(item.size),
                item.width && item.height ? item.width + '×' + item.height : '',
            ].filter(Boolean).join(' · ');

            node.querySelector('[data-insert]').addEventListener('click', () => insert(item));

            // Two steps rather than a confirm(): a modal dialog blocks the
            // whole page (and everything that drives it), and the gallery
            // editor beside this one already asks the same way. Both messages
            // come from the template as data attributes, so they are the
            // reader's language and not a string frozen into a .mjs file.
            const remove = node.querySelector('[data-delete]');

            remove.addEventListener('click', () => {
                if (remove.dataset.armed !== 'yes') {
                    remove.dataset.armed = 'yes';
                    remove.classList.add('btn-error', 'opacity-100');
                    remove.title = used(item.url) ? remove.dataset.armedUsed : remove.dataset.armedTitle;

                    return;
                }

                send('/' + item.id, { method: 'DELETE' });
            });

            list.appendChild(node);
        });

        markUsed();
    }

    /**
     * Which files the text no longer mentions. Answered here rather than by
     * the server, because the client is the only one that knows what is being
     * typed right now — and the whole point of the marker is that it moves.
     *
     * Nothing is detached automatically on the strength of it: an image cut
     * out of a paragraph may be going back in after lunch, so the panel says
     * so and a person decides. Sweeping on save is how somebody loses a
     * diagram they had temporarily removed.
     */
    function used(url) {
        return editor.getHTML().includes(url);
    }

    function markUsed() {
        const html = editor.getHTML();

        list.querySelectorAll('[data-url]').forEach((node) => {
            show(node.querySelector('[data-unused]'), !html.includes(node.dataset.url), '');
        });
    }

    /** One serialisation of the document per pause in typing, not per keystroke. */
    function markUsedSoon() {
        clearTimeout(marking);
        marking = setTimeout(() => isOpen() && markUsed(), 300);
    }

    /**
     * Inserted at the natural size the server measured — that is what "as
     * uploaded" means, and having the numbers on the tag from the start is
     * what stops the page reflowing as the image arrives.
     */
    function insert(item) {
        // A selected gallery takes the picture instead of the text.
        if (galleryOpen()) {
            editor.chain().focus().addGalleryImages([picture(item)]).run();
            markUsed();

            return;
        }

        editor
            .chain()
            .focus()
            .setImage({ src: item.url, alt: '', width: item.width ?? null, height: item.height ?? null })
            .run();

        markUsed();
    }

    /** Is a gallery (gallery.mjs) the selected node — the one the panel fills? */
    function galleryOpen() {
        return editor.isActive('contentGallery');
    }

    function picture(item) {
        return { src: item.url, alt: '', width: item.width ?? null, height: item.height ?? null };
    }

    // ---------------------------------------------------------- the properties

    /** The real pixel size of the selected image, for keeping its proportions. */
    function natural() {
        const dom = editor.view.nodeDOM(editor.state.selection.from);
        const img = dom?.tagName === 'IMG' ? dom : dom?.querySelector?.('img');

        return img?.naturalWidth ? { width: img.naturalWidth, height: img.naturalHeight } : null;
    }

    function update(attributes) {
        editor.chain().focus().updateAttributes('image', attributes).run();
    }

    function syncProps() {
        if (galleryHint) show(galleryHint, galleryOpen(), '');

        const selected = editor.isActive('image');

        show(props, selected);

        if (!selected) return;

        const attrs = editor.getAttributes('image');

        altInput.value = attrs.alt ?? '';
        sizeSelect.value = attrs.size === 'full' || attrs.size === 'manual' ? attrs.size : 'auto';
        widthInput.value = attrs.width ?? '';
        heightInput.value = attrs.height ?? '';
        manualBox.classList.toggle('hidden', sizeSelect.value !== 'manual');
    }

    altInput.addEventListener('input', () => update({ alt: altInput.value }));

    sizeSelect.addEventListener('change', () => {
        const mode = sizeSelect.value;

        manualBox.classList.toggle('hidden', mode !== 'manual');

        if (mode === 'auto') {
            const size = natural();

            // Falls back to leaving the numbers alone when the image has not
            // loaded yet: no aspect ratio is worse than a slightly stale one.
            update(size ? { size: null, width: size.width, height: size.height } : { size: null });
        } else {
            // 'full' keeps its numbers on purpose — they are the aspect ratio,
            // and the CSS is what makes it full width. Dropping them would
            // bring back exactly the layout jump this is careful about.
            update({ size: mode });
        }

        syncProps();
    });

    /**
     * Typing one side computes the other from the real proportions. Nobody
     * wants to do that arithmetic, and a squashed picture is what happens when
     * they get it wrong.
     */
    function manualSize(from) {
        const size = natural();
        const width = parseInt(widthInput.value, 10) || null;
        const height = parseInt(heightInput.value, 10) || null;

        if (from === 'width' && width && size) {
            heightInput.value = Math.round((width / size.width) * size.height);
        }

        if (from === 'height' && height && size) {
            widthInput.value = Math.round((height / size.height) * size.width);
        }

        update({
            size: sizeSelect.value === 'full' ? 'full' : 'manual',
            width: parseInt(widthInput.value, 10) || null,
            height: parseInt(heightInput.value, 10) || null,
        });
    }

    widthInput.addEventListener('change', () => manualSize('width'));
    heightInput.addEventListener('change', () => manualSize('height'));

    panel.querySelector('[data-tiptap-image-remove]').addEventListener('click', () => {
        editor.chain().focus().deleteSelection().run();
        markUsed();
    });

    // ------------------------------------------------------------- the actions

    panel.querySelector('[data-tiptap-image-file]').addEventListener('change', (event) => {
        const files = Array.from(event.target.files || []);

        if (files.length) {
            const body = new FormData();

            files.forEach((file) => body.append('images[]', file));
            withGallery(send('', { body }));
        }

        // Cleared so the same file can be chosen again after a refusal.
        event.target.value = '';
    });

    /**
     * Files uploaded while a gallery is selected go into it as well — twenty
     * photos for an unboxing are one upload, not twenty clicks afterwards.
     * "New" is whatever the answer lists that the panel did not know before.
     */
    function withGallery(request) {
        // Before the list has arrived, "new" cannot be told from "old".
        if (!galleryOpen() || !loaded) return;

        const known = new Set(items.map((item) => item.id));

        request.then(() => {
            const added = items.filter((item) => !known.has(item.id));

            if (added.length && galleryOpen()) {
                editor.chain().addGalleryImages(added.map(picture)).run();
                markUsed();
            }
        });
    }

    const urlInput = panel.querySelector('[data-tiptap-image-url]');
    const fetchButton = panel.querySelector('[data-tiptap-image-fetch]');

    if (urlInput && fetchButton) {
        const fromUrl = () => {
            const url = urlInput.value.trim();

            if (!url) return;

            withGallery(send('/from-url', { body: JSON.stringify({ url }) }));
            urlInput.value = '';
        };

        fetchButton.addEventListener('click', fromUrl);
        urlInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                fromUrl();
            }
        });
    }

    const close = () => show(panel, false);

    panel.querySelector('[data-tiptap-images-close]').addEventListener('click', close);

    editor.on('update', markUsedSoon);

    editor.on('selectionUpdate', () => {
        syncProps();

        // Clicking a picture is how a person asks to change it, so the panel
        // opens itself for that — and only for that, never while typing.
        if (editor.isActive('image') && !isOpen()) open();
    });

    function open() {
        show(panel, true);
        syncProps();

        // Fetched on first open rather than on mount: a form with three rich
        // fields would otherwise ask for the same list three times on every
        // render, and most of the time nobody opens the panel at all.
        loaded ? render() : send('', { method: 'GET' });
    }

    return { open, close };
}
