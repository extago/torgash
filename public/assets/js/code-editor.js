/*
 * A textarea that colours what is typed into it — XML/HTML tags, attributes,
 * strings, comments, CDATA and {{ template }} tags — without a library:
 *
 *   <textarea data-code-editor>…</textarea>
 *   <button type="button" data-code-format="#id">  re-indents by the tags
 *   <button type="button" data-code-compact="#id"> drops indentation and blank lines
 *
 * The textarea stays the field (it is what the form sends, what the browser
 * undoes); its text is made transparent and a <pre> drawn underneath shows
 * the same text coloured, kept in step on every input and scroll. Formatting
 * goes through execCommand('insertText') where the browser has it, so Ctrl+Z
 * takes it back like any typing.
 */
(() => {
    const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    // One pass over the text; each alternative is one kind of token.
    const TOKENS = /(\{\{![\s\S]*?\}\})|(\{\{\s*[#^\/][\s\S]*?\}\})|(\{\{[\s\S]*?\}\})|(<!--[\s\S]*?-->)|(<!\[CDATA\[[\s\S]*?\]\]>)|(<\/?[A-Za-z_?][^<>]*?\/?>)/g;

    const span = (kind, text) => '<span class="ce-' + kind + '">' + escape(text) + '</span>';

    // Within one tag: its name, the attribute names, the quoted values.
    function tag(text) {
        const head = text.match(/^(<\/?\??)([\w:.\-]*)/);
        let out = escape(head[1]) + span('tag', head[2]);
        let at = head[0].length;
        const rest = /("[^"]*"|'[^']*')|([A-Za-z_:][\w:.\-]*)(?=\s*=)/g;
        rest.lastIndex = at;
        let match;
        while ((match = rest.exec(text))) {
            out += escape(text.slice(at, match.index)) + span(match[1] ? 'string' : 'attr', match[0]);
            at = match.index + match[0].length;
        }
        return out + escape(text.slice(at));
    }

    function highlight(source) {
        let out = '';
        let at = 0;
        source.replace(TOKENS, (match, comment, section, value, xmlComment, cdata, element, offset) => {
            out += escape(source.slice(at, offset));
            at = offset + match.length;
            if (comment || xmlComment) out += span('comment', match);
            else if (section) out += span('section', match);
            else if (value) out += span('value', match);
            else if (cdata) out += span('cdata', match);
            else out += tag(match);
            return match;
        });
        // A last empty line still needs height, or the caret has nowhere to sit.
        return out + escape(source.slice(at)) + '\n';
    }

    /*
     * Indentation by the XML tags only: a {{#section}} line is indented
     * where it stands but does not push its contents in, so what the feed
     * prints is laid out the way the template reads.
     */
    function format(source, unit = '    ') {
        let depth = 0;
        return source.split('\n').map((raw) => {
            const line = raw.trim();
            if (line === '') return '';
            const masked = line.replace(/\{\{[\s\S]*?\}\}/g, '').replace(/<!\[CDATA\[[\s\S]*?\]\]>/g, '').replace(/<!--[\s\S]*?-->/g, '');
            const opens = (masked.match(/<[A-Za-z_][^<>]*?>/g) || []).filter((t) => ! t.endsWith('/>')).length;
            const closes = (masked.match(/<\/[^<>]+>/g) || []).length;
            const leadingClose = /^<\//.test(line);
            if (leadingClose) depth = Math.max(0, depth - 1);
            const indented = unit.repeat(depth) + line;
            depth = Math.max(0, depth + opens - closes + (leadingClose ? 1 : 0));
            return indented;
        }).join('\n').replace(/\n{3,}/g, '\n\n');
    }

    const compact = (source) => source.split('\n').map((line) => line.trim()).filter((line) => line !== '').join('\n');

    function replaceAll(area, text) {
        area.focus();
        area.select();
        // Undoable where the browser allows it.
        if (! document.execCommand || ! document.execCommand('insertText', false, text)) {
            area.value = text;
        }
        area.dispatchEvent(new Event('input', { bubbles: true }));
        area.setSelectionRange(0, 0);
        area.scrollTop = 0;
    }

    function enhance(area) {
        if (area.dataset.codeEditorReady) return;
        area.dataset.codeEditorReady = '1';

        const wrap = document.createElement('div');
        wrap.className = 'ce-wrap';
        const view = document.createElement('pre');
        view.className = 'ce-view';
        view.setAttribute('aria-hidden', 'true');
        area.parentNode.insertBefore(wrap, area);
        wrap.append(view, area);
        area.classList.add('ce-area');
        area.setAttribute('wrap', 'off');
        // The coloured copy must lie letter for letter under the field.
        const style = getComputedStyle(area);
        ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'tabSize',
            'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
            'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth'].forEach((key) => { view.style[key] = style[key]; });

        const draw = () => { view.innerHTML = highlight(area.value); sync(); };
        const sync = () => { view.scrollTop = area.scrollTop; view.scrollLeft = area.scrollLeft; };

        area.addEventListener('input', draw);
        area.addEventListener('scroll', sync);
        // Tab indents instead of leaving the field; Shift+Tab leaves it.
        area.addEventListener('keydown', (event) => {
            if (event.key === 'Tab' && ! event.shiftKey && ! event.ctrlKey && ! event.altKey) {
                event.preventDefault();
                if (! document.execCommand || ! document.execCommand('insertText', false, '    ')) {
                    const start = area.selectionStart;
                    area.setRangeText('    ', start, area.selectionEnd, 'end');
                    draw();
                }
            }
        });
        new ResizeObserver(sync).observe(area);
        draw();
    }

    function boot(root = document) {
        root.querySelectorAll('textarea[data-code-editor]').forEach(enhance);
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-code-format], [data-code-compact]');
        if (! button) return;
        const area = document.querySelector(button.dataset.codeFormat || button.dataset.codeCompact);
        if (! area) return;
        replaceAll(area, button.hasAttribute('data-code-format') ? format(area.value) : compact(area.value));
    });

    window.TorgashCodeEditor = { boot, format, compact, highlight, replace: replaceAll };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => boot());
    else boot();
})();
