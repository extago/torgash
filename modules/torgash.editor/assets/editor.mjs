/**
 * Wires every [data-tiptap] block on the page to a TipTap editor that writes
 * HTML back into its paired <textarea>, so the surrounding form submits and
 * validates exactly as it did before the editor existed.
 *
 * The toolbar itself is rendered by field.blade.php — this only binds the
 * buttons it finds, keyed by data-tiptap-command. That keeps the icons on the
 * admin's shared <x-icon> set instead of a second one living in JS.
 *
 * Plain module script, no Alpine: alpine.min.js is a deferred classic script in
 * <head> and has already fired alpine:init by the time a module at the end of
 * <body> runs, so an Alpine.data() registration here would come too late.
 */
import { Editor } from './vendor/tiptap-core.mjs';
import StarterKit from './vendor/tiptap-starter-kit.mjs';
import { ContentImage, bindImages } from './images.mjs';
import { HtmlBlock, wrapUnrepresentable } from './htmlblock.mjs';
import { ContentGallery } from './gallery.mjs';

/* run: what the button does. active: when it should look pressed. */
const COMMANDS = {
    bold: { run: (c) => c.toggleBold().run(), active: (e) => e.isActive('bold') },
    italic: { run: (c) => c.toggleItalic().run(), active: (e) => e.isActive('italic') },
    strike: { run: (c) => c.toggleStrike().run(), active: (e) => e.isActive('strike') },
    code: { run: (c) => c.toggleCode().run(), active: (e) => e.isActive('code') },
    h1: { run: (c) => c.toggleHeading({ level: 1 }).run(), active: (e) => e.isActive('heading', { level: 1 }) },
    h2: { run: (c) => c.toggleHeading({ level: 2 }).run(), active: (e) => e.isActive('heading', { level: 2 }) },
    h3: { run: (c) => c.toggleHeading({ level: 3 }).run(), active: (e) => e.isActive('heading', { level: 3 }) },
    bullet: { run: (c) => c.toggleBulletList().run(), active: (e) => e.isActive('bulletList') },
    ordered: { run: (c) => c.toggleOrderedList().run(), active: (e) => e.isActive('orderedList') },
    quote: { run: (c) => c.toggleBlockquote().run(), active: (e) => e.isActive('blockquote') },
    codeblock: { run: (c) => c.toggleCodeBlock().run(), active: (e) => e.isActive('codeBlock') },
    hr: { run: (c) => c.setHorizontalRule().run() },
    clear: { run: (c) => c.unsetAllMarks().clearNodes().run() },
    undo: { run: (c) => c.undo().run() },
    redo: { run: (c) => c.redo().run() },
    html: { run: (c) => c.insertHtmlBlock().run() },
};

/** Inline link bar — a prompt() would block the whole page until dismissed. */
function bindLinkBar(root, editor) {
    const bar = root.querySelector('[data-tiptap-link]');
    if (!bar) return null;

    const input = bar.querySelector('input');

    const close = () => {
        bar.classList.add('hidden');
        bar.classList.remove('flex');
    };

    const apply = () => {
        const href = input.value.trim();
        const chain = editor.chain().focus().extendMarkRange('link');

        if (!href) {
            chain.unsetLink().run();
            close();

            return;
        }

        // With nothing selected there is no text to mark up, and setLink would
        // quietly do nothing — insert the URL as its own linked text instead.
        editor.state.selection.empty
            ? chain.insertContent({ type: 'text', text: href, marks: [{ type: 'link', attrs: { href } }] }).run()
            : chain.setLink({ href }).run();

        close();
    };

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            apply();
        }
        if (e.key === 'Escape') close();
    });

    bar.querySelector('[data-tiptap-link-apply]').addEventListener('click', apply);
    bar.querySelector('[data-tiptap-link-clear]').addEventListener('click', () => {
        input.value = '';
        apply();
    });

    return {
        open() {
            input.value = editor.getAttributes('link').href || '';
            bar.classList.remove('hidden');
            bar.classList.add('flex');
            input.focus();
        },
        close,
    };
}

function bindSourceToggle(root, editor, textarea) {
    const toggle = root.querySelector('[data-tiptap-source]');
    const surface = root.querySelector('[data-tiptap-surface]');
    if (!toggle) return;

    let showingSource = false;

    toggle.addEventListener('click', () => {
        showingSource = !showingSource;
        textarea.classList.toggle('hidden', !showingSource);
        surface.classList.toggle('hidden', showingSource);
        toggle.classList.toggle('btn-active', showingSource);

        // Coming back from source view, whatever was typed there wins.
        // Markup the schema cannot carry is wrapped as an HTML block first,
        // so switching back to the visual view keeps it.
        if (!showingSource) editor.commands.setContent(wrapUnrepresentable(textarea.value), { emitUpdate: false });
    });
}

/**
 * An empty gallery where the cursor is, selected, with the image panel open:
 * what is chosen or uploaded next goes into it (see images.mjs).
 */
function insertGallery(editor, images) {
    editor.chain().focus().insertContent({ type: 'contentGallery', attrs: { images: [] } }).run();

    // The inserted node is the last gallery before the cursor, which
    // insertContent leaves just after it.
    const cursor = editor.state.selection.from;
    let at = null;

    editor.state.doc.descendants((node, pos) => {
        if (node.type.name === 'contentGallery' && pos < cursor) at = pos;
    });

    if (at !== null) editor.commands.setNodeSelection(at);

    images?.open();
}

function mount(root) {
    const textarea = document.getElementById(root.dataset.target);
    const surface = root.querySelector('[data-tiptap-surface]');
    if (!textarea || !surface) return;

    const editor = new Editor({
        element: surface,
        // StarterKit 3 already bundles Link and Underline, so nothing extra has
        // to be vendored for the toolbar's link button.
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3] },
                link: { openOnClick: false, autolink: true },
            }),
            // Registered whether or not this field can upload anything,
            // because the node is what lets an <img> survive at all: a schema
            // without one makes ProseMirror drop every image it parses, so a
            // description written through the HTML source view would come back
            // without its pictures the next time the form was opened and
            // saved — silently, since the editor cannot report a tag it has no
            // way to represent.
            ContentImage,
            // Everything else — a <div>, a table, an iframe, a style or a
            // class — lives in an HTML block, which saves it verbatim.
            HtmlBlock,
            // Registered unconditionally for the same reason as the image:
            // a text that already holds a gallery must keep it even on a
            // field that cannot upload (see gallery.mjs).
            ContentGallery,
        ],
        content: wrapUnrepresentable(textarea.value),
        // The surface is what a screen reader lands on, so it carries the
        // field's name — the textarea it writes into is hidden.
        editorProps: {
            attributes: {
                role: 'textbox',
                'aria-multiline': 'true',
                ...(textarea.getAttribute('aria-label') ? { 'aria-label': textarea.getAttribute('aria-label') } : {}),
            },
        },
        onUpdate: ({ editor }) => {
            // Empty paragraphs would otherwise persist as "<p></p>" forever.
            textarea.value = editor.isEmpty ? '' : editor.getHTML();
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        },
    });

    const linkBar = bindLinkBar(root, editor);
    const images = bindImages(root, editor);

    // A gallery's own "Add pictures" — it has selected itself already.
    root.addEventListener('tiptap:gallery-add', () => images?.open());
    bindSourceToggle(root, editor, textarea);

    const bound = [];

    root.querySelectorAll('[data-tiptap-command]').forEach((button) => {
        const key = button.dataset.tiptapCommand;

        if (key === 'link') {
            button.addEventListener('click', () => linkBar?.open());

            return;
        }

        if (key === 'image') {
            button.addEventListener('click', () => images?.open());

            return;
        }

        if (key === 'gallery') {
            button.addEventListener('click', () => insertGallery(editor, images));

            return;
        }

        const command = COMMANDS[key];
        if (!command) return;

        button.addEventListener('click', () => command.run(editor.chain().focus()));
        bound.push({ button, command });
    });

    const refresh = () => bound.forEach(({ button, command }) => {
        button.classList.toggle('btn-active', Boolean(command.active && command.active(editor)));
    });

    editor.on('selectionUpdate', refresh);
    editor.on('transaction', refresh);
    refresh();

    // Extension seam: other admin code can reach the instance to add commands,
    // read content or drive it from tests, without this module exporting a
    // registry of live editors.
    root.tiptapEditor = editor;
    root.dispatchEvent(new CustomEvent('tiptap:ready', { detail: { editor }, bubbles: true }));
}

document.querySelectorAll('[data-tiptap]').forEach(mount);

/*
 * Another part of the form puts a line into the text: `tiptap:insert` with
 * { text, from } — a token such as {video:12}, as a paragraph of its own at
 * the cursor. It goes to the editor that was last focused inside the same
 * <form> as `from`, else the form's first one. The asker never needs to know
 * which field is rich or what the editor is.
 */
let lastFocused = null;
document.addEventListener('focusin', (event) => {
    const root = event.target.closest?.('[data-tiptap]');
    if (root) lastFocused = root;
});
document.addEventListener('tiptap:insert', (event) => {
    const { text, from } = event.detail || {};
    if (!text) return;
    const form = from?.closest?.('form');
    const candidates = [...(form || document).querySelectorAll('[data-tiptap]')].filter((root) => root.tiptapEditor);
    const root = candidates.includes(lastFocused) ? lastFocused : candidates[0];
    if (!root) return;
    root.tiptapEditor.chain().focus().insertContent({ type: 'paragraph', content: [{ type: 'text', text }] }).run();
    root.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
});
