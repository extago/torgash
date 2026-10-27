# HTML blocks

The visual editor keeps a text in a strict shape: paragraphs, headings of three
levels, lists, a quote, code, a rule, links and pictures. Anything else — a
`<div>` with a background, a table, an embedded video, a `style` or a `class` on
anything — is something it cannot represent, and before HTML blocks it threw
such markup away the first time somebody edited the text visually. Silently.

An **HTML block** is where that markup lives. It is shown in the text as a
framed preview; the markup inside is kept exactly as written, and editing the
paragraphs around it never touches it.

## Adding one

- **The HTML button** on the toolbar inserts an empty block and opens its code
  box. Type or paste the markup and press *Apply*.
- **Through the source view** (`</>`): paste the markup there and switch back.
  Every top-level element the editor cannot carry faithfully is turned into an
  HTML block by itself, so nothing is lost on the way back.

To change a block, press *Edit HTML* on its frame or double-click it.

## What the preview is

A sealed frame: the page's own stylesheet is loaded into it so the block looks
close to what the storefront shows, but scripts in it do not run while you are
editing, and a `<style>` in it cannot restyle the admin. On the storefront the
markup is printed as it is — the same as the rest of the text, which only
people with the right to edit it can write.

## On the page

A block is saved as `<div data-html-block>…your markup…</div>`. The wrapper has
no styling of its own; it is only how the editor recognises the block the next
time the text is opened.

The button can be removed or moved like any other: it is `html` in the
editor's *Toolbar buttons* setting.
