/**
 * A gallery inside a text: several pictures the reader leafs through, with a
 * strip of thumbnails, instead of twenty <img> one under another — the
 * "unboxing in 20 photos" article, where putting each one into a paragraph
 * would be a chore and reading them would be a scroll.
 *
 * Saved as plain markup, `<div data-gallery><img …><img …></div>`, so it means
 * something to every reader of the text and not only to this editor: without
 * a script the storefront's CSS shows it as a swipeable strip, with one
 * torgash.media's `content-gallery.mjs` turns it into the stage, arrows,
 * thumbnails and the full-screen viewer; the .md rendition, the search index
 * and a feed simply see images.
 *
 * The pictures are the record's own content images — the same panel the
 * image button opens. While a gallery is SELECTED that panel adds to it
 * instead of to the text (a click on a plaque, and every file uploaded), which
 * is why this node has no upload of its own: one place keeps the files.
 *
 * An ATOM: its pictures live in one attribute and the node view draws them,
 * so an edit beside it never reaches inside, and the order, the alt and the
 * removal are buttons rather than a cursor wandering between images.
 */
import { Node } from './vendor/tiptap-core.mjs';

const ATTR = 'data-gallery';

function readImages(element) {
    return [...element.querySelectorAll('img')]
        .filter((img) => img.getAttribute('src'))
        .map((img) => ({
            src: img.getAttribute('src'),
            alt: img.getAttribute('alt') || '',
            width: parseInt(img.getAttribute('width'), 10) || null,
            height: parseInt(img.getAttribute('height'), 10) || null,
        }));
}

/** The page's words and icons (field.blade.php) — this file holds neither. */
function kit() {
    const labels = document.querySelector('[data-tiptap-gallery-labels]');
    const icon = (name) => document.querySelector(`template[data-tiptap-gallery-icon="${name}"]`)?.innerHTML.trim() ?? '';

    return { words: labels?.dataset || {}, icon };
}

function button(className, title, html) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = className;
    element.title = title || '';
    element.setAttribute('aria-label', title || '');
    element.innerHTML = html;

    return element;
}

export const ContentGallery = Node.create({
    name: 'contentGallery',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            images: {
                default: [],
                parseHTML: readImages,
                // Drawn as children by renderHTML below, never as an attribute.
                renderHTML: () => ({}),
            },
        };
    },

    parseHTML() {
        // Above the paragraph's priority, so the wrapper is never read as an
        // ordinary <div> of images.
        return [{ tag: `div[${ATTR}]`, priority: 100 }];
    },

    renderHTML({ node }) {
        // Width and height stay on every picture, as on a single one: they
        // are what stops the page jumping while it loads. `lazy` because a
        // gallery is mostly pictures nobody has leafed to yet.
        return ['div', { [ATTR]: '' }, ...(node.attrs.images || []).map((image) => ['img', {
            src: image.src,
            alt: image.alt || '',
            ...(image.width ? { width: image.width } : {}),
            ...(image.height ? { height: image.height } : {}),
            loading: 'lazy',
        }])];
    },

    addCommands() {
        return {
            /** Appends pictures to the gallery that is selected; false when none is. */
            addGalleryImages: (images) => ({ state, tr, dispatch }) => {
                const { node, from } = state.selection;

                if (node?.type.name !== this.name) return false;

                if (dispatch) {
                    const known = new Set(node.attrs.images.map((image) => image.src));
                    const added = images.filter((image) => image.src && !known.has(image.src));

                    tr.setNodeMarkup(from, undefined, { ...node.attrs, images: [...node.attrs.images, ...added] });
                }

                return true;
            },
        };
    },

    addNodeView() {
        return ({ node, getPos, editor }) => {
            const { words, icon } = kit();

            const dom = document.createElement('div');
            dom.className = 'torgash-gallery-block';
            dom.contentEditable = 'false';

            const bar = document.createElement('div');
            bar.className = 'torgash-gallery-block-bar';
            const title = document.createElement('span');
            const add = button('btn btn-xs gap-1', words.add, icon('image-plus') + `<span>${words.add || '+'}</span>`);
            bar.append(title, add);

            const grid = document.createElement('div');
            grid.className = 'torgash-gallery-block-grid';

            const empty = document.createElement('div');
            empty.className = 'torgash-gallery-block-empty';
            empty.textContent = words.empty || '';

            dom.append(bar, grid, empty);

            let current = node;

            const position = () => {
                const at = getPos();

                return typeof at === 'number' ? at : null;
            };

            const select = () => {
                const at = position();
                if (at !== null) editor.chain().focus().setNodeSelection(at).run();
            };

            const save = (images) => {
                const at = position();
                if (at === null) return;

                editor.chain().command(({ tr }) => {
                    tr.setNodeMarkup(at, undefined, { ...current.attrs, images });

                    return true;
                }).setNodeSelection(at).run();
            };

            const move = (index, step) => {
                const images = [...current.attrs.images];
                const target = index + step;

                if (target < 0 || target >= images.length) return;

                [images[index], images[target]] = [images[target], images[index]];
                save(images);
            };

            function draw() {
                const images = current.attrs.images || [];

                title.textContent = (words.title || 'Gallery') + (images.length ? ' · ' + images.length : '');
                empty.classList.toggle('hidden', images.length > 0);
                grid.replaceChildren();

                images.forEach((image, index) => {
                    const tile = document.createElement('div');
                    tile.className = 'torgash-gallery-block-tile';

                    const img = document.createElement('img');
                    img.src = image.src;
                    img.alt = '';

                    // The alt is committed on `change`, not per keystroke: every
                    // commit redraws the tiles, and a redraw under the cursor
                    // would take the field away mid-word.
                    const alt = document.createElement('input');
                    alt.type = 'text';
                    alt.className = 'input input-bordered input-xs w-full';
                    alt.placeholder = words.alt || 'alt';
                    alt.value = image.alt || '';
                    alt.addEventListener('change', () => {
                        save(current.attrs.images.map((item, i) => (i === index ? { ...item, alt: alt.value } : item)));
                    });

                    const tools = document.createElement('div');
                    tools.className = 'torgash-gallery-block-tools';

                    const left = button('btn btn-ghost btn-xs px-1', words.earlier, icon('chevron-left'));
                    left.disabled = index === 0;
                    left.addEventListener('click', () => move(index, -1));

                    const right = button('btn btn-ghost btn-xs px-1', words.later, icon('chevron-right'));
                    right.disabled = index === images.length - 1;
                    right.addEventListener('click', () => move(index, 1));

                    const remove = button('btn btn-ghost btn-xs px-1 text-error ms-auto', words.remove, icon('x'));
                    remove.addEventListener('click', () => save(current.attrs.images.filter((_, i) => i !== index)));

                    tools.append(left, right, remove);
                    tile.append(img, alt, tools);
                    grid.append(tile);
                });
            }

            // "Add pictures" selects this gallery and asks for the image panel,
            // which adds to whatever gallery is selected. An event rather than
            // a call: the panel belongs to the field, not to this node.
            add.addEventListener('click', () => {
                select();
                dom.dispatchEvent(new CustomEvent('tiptap:gallery-add', { bubbles: true }));
            });

            draw();

            return {
                dom,
                update(updated) {
                    if (updated.type !== current.type) return false;

                    const changed = updated.attrs.images !== current.attrs.images;
                    current = updated;
                    if (changed) draw();

                    return true;
                },
                // Its own buttons and fields are not edits of the document;
                // a click on the frame itself still selects the node.
                stopEvent: (event) => Boolean(event.target.closest?.('button, input')),
                ignoreMutation: () => true,
            };
        };
    },
});
