/**
 * A block of raw HTML inside a text — the answer to "the visual editor ate my
 * markup".
 *
 * TipTap keeps a document in a strict schema: whatever no extension describes
 * (a <div>, a table, an iframe, a `style` or `class` on anything) is thrown
 * away the moment the text is parsed, and the next visual edit writes the
 * text back without it — silently. This node is the one place such markup
 * lives: an ATOM holding its HTML verbatim in an attribute, saved as
 * `<div data-html-block>…</div>`, so an edit to the paragraph beside it never
 * touches it.
 *
 * Two ways in: the toolbar's HTML button inserts an empty one, and
 * `wrapUnrepresentable()` turns every top-level element the schema could not
 * carry faithfully into one BEFORE the editor parses the text — on first load
 * and when coming back from the source view — so pasting a banner into the
 * source and switching back loses nothing.
 *
 * The preview is a sandboxed <iframe> with no scripts: the markup is the
 * shop's own (the text is admin-authored and printed as HTML on the
 * storefront already), but a <style> in it must not restyle the admin, and a
 * <script> has no business running while somebody is editing a page. The
 * storefront's stylesheet is linked into the frame, so the preview is close to
 * what the page will show.
 */
import { Node } from './vendor/tiptap-core.mjs';

const ATTR = 'data-html-block';

/* What the schema carries faithfully, and which attributes it keeps on each. */
const INLINE = { strong: [], b: [], em: [], i: [], s: [], strike: [], u: [], code: [], br: [], a: ['href', 'target', 'rel'], span: [] };
const BLOCK = {
    p: [], h1: [], h2: [], h3: [], ul: [], ol: ['start'], li: [], blockquote: [], pre: [], hr: [],
    img: ['src', 'alt', 'title', 'width', 'height', 'data-size'],
};
const KEEPS = { ...INLINE, ...BLOCK };

function representable(element) {
    const tag = element.tagName.toLowerCase();

    // A span is only an inline wrapper with nothing on it; with a class or a
    // style it is exactly the markup this node exists to keep.
    if (!(tag in KEEPS)) return false;

    for (const attribute of element.attributes) {
        if (!KEEPS[tag].includes(attribute.name)) return false;
    }

    // The editor's heading levels stop at 3: an <h4> would come back an <h1>.
    return [...element.children].every(representable);
}

/**
 * The HTML with every top-level element the schema cannot carry wrapped as an
 * HTML block. Text and representable elements pass through untouched; an
 * existing block is left as it is.
 */
export function wrapUnrepresentable(html) {
    if (!html || !/<[a-z]/i.test(html)) return html;

    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    let changed = false;

    [...doc.body.children].forEach((element) => {
        // A gallery (gallery.mjs) is a node of its own, not foreign markup.
        if (element.hasAttribute(ATTR) || element.hasAttribute('data-gallery') || representable(element)) return;

        const wrapper = doc.createElement('div');
        wrapper.setAttribute(ATTR, '');
        element.replaceWith(wrapper);
        wrapper.appendChild(element);
        changed = true;
    });

    return changed ? doc.body.innerHTML : html;
}

function preview(frame, html) {
    const css = document.querySelector('meta[name="torgash-storefront-css"]')?.content || '/assets/css/app.css';

    frame.srcdoc = `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${css}">`
        + '<style>html,body{margin:0;padding:8px;background:transparent}</style></head>'
        + `<body class="torgash-rich">${html}</body></html>`;

    // Same-origin without scripts: the frame cannot run anything, and the
    // editor can still read its height to fit it.
    frame.onload = () => {
        const height = frame.contentDocument?.documentElement?.scrollHeight || 60;
        frame.style.height = Math.min(Math.max(height, 40), 600) + 'px';
    };
}

export const HtmlBlock = Node.create({
    name: 'htmlBlock',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    addAttributes() {
        return {
            html: { default: '' },
        };
    },

    parseHTML() {
        // Above the paragraph's priority, so the wrapper is never read as an
        // ordinary <div> of paragraphs.
        return [{ tag: `div[${ATTR}]`, priority: 100, getAttrs: (element) => ({ html: element.innerHTML }) }];
    },

    renderHTML({ node }) {
        // A real element rather than a spec array: a spec cannot carry raw
        // markup, and the whole point is that the markup goes out verbatim.
        const div = document.createElement('div');
        div.setAttribute(ATTR, '');
        div.innerHTML = node.attrs.html || '';

        return div;
    },

    addCommands() {
        return {
            insertHtmlBlock: (html = '') => ({ commands }) => commands.insertContent({ type: this.name, attrs: { html } }),
        };
    },

    addNodeView() {
        return ({ node, getPos, editor }) => {
            const labels = document.querySelector('[data-tiptap-html-labels]')?.dataset || {};

            const dom = document.createElement('div');
            dom.className = 'torgash-html-block';
            dom.contentEditable = 'false';

            const bar = document.createElement('div');
            bar.className = 'torgash-html-block-bar';
            const title = document.createElement('span');
            title.textContent = labels.title || 'HTML';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn btn-xs btn-ghost';
            edit.textContent = labels.edit || 'Edit HTML';
            bar.append(title, edit);

            const frame = document.createElement('iframe');
            frame.setAttribute('sandbox', 'allow-same-origin');
            frame.className = 'torgash-html-block-frame';

            const editor_ = document.createElement('div');
            editor_.className = 'torgash-html-block-editor hidden';
            const code = document.createElement('textarea');
            code.className = 'textarea textarea-bordered w-full font-mono text-xs';
            code.rows = 8;
            code.spellcheck = false;
            const actions = document.createElement('div');
            actions.className = 'flex gap-2 justify-end';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-xs btn-ghost';
            cancel.textContent = labels.cancel || 'Cancel';
            const apply = document.createElement('button');
            apply.type = 'button';
            apply.className = 'btn btn-xs btn-primary';
            apply.textContent = labels.apply || 'Apply';
            actions.append(cancel, apply);
            editor_.append(code, actions);

            dom.append(bar, frame, editor_);

            let current = node;
            preview(frame, current.attrs.html);

            const open = () => {
                code.value = current.attrs.html;
                editor_.classList.remove('hidden');
                code.focus();
            };
            const close = () => editor_.classList.add('hidden');

            edit.addEventListener('click', open);
            dom.addEventListener('dblclick', open);
            cancel.addEventListener('click', close);
            apply.addEventListener('click', () => {
                const position = getPos();
                if (typeof position !== 'number') return;

                editor.chain().focus().command(({ tr }) => {
                    tr.setNodeMarkup(position, undefined, { ...current.attrs, html: code.value });

                    return true;
                }).run();
                close();
            });

            // A block just inserted from the toolbar opens straight into its
            // code: an empty frame is nothing to click on.
            if (!current.attrs.html) setTimeout(open, 0);

            return {
                dom,
                update(updated) {
                    if (updated.type !== current.type) return false;
                    if (updated.attrs.html !== current.attrs.html) preview(frame, updated.attrs.html);
                    current = updated;

                    return true;
                },
                // Typing inside the code box is not an edit of the document.
                stopEvent: (event) => editor_.contains(event.target),
                ignoreMutation: () => true,
            };
        };
    },
});
