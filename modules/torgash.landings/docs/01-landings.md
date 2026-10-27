# Product landings

A landing is a piece of a product page made of HTML and CSS with pictures of
its own — a promo, a "why us", a designer's page for a whole range — written
once and shown on as many products as it fits.

## Where it stands

- **Above the description** and **below the description** — around the
  product's own text, in its "Description" section.
- **Instead of the description** — the page shows the landing and not the
  text. The description itself stays the product's: feeds, exports, the
  `.md` page and the meta description go on using it. A product with no
  text of its own and a landing instead still has its "Description".
- **A tab of its own** — a section with its own name, among the others by
  its position (the description is 10, the characteristics 20). The shop can
  rename or move it later like any section.

Landings in one place stand by their position, smaller first.

**Fold like the description** (not for a tab): a long landing shows its
first screenful, a fade and **Read in full**, at the height set for the
description (Settings → Catalogue, "Fold a long description at, px"; 0 there means
nothing folds). Opening it keeps the reader where they were; folding it back
returns them to its top. Off by default.

## For which products

- **Products picked by hand** see it.
- **Conditions** — the same display rules as blocks and banners: a category
  (with its subcategories), a label, stock, "the description is missing", a
  storefront, a language, a customer group, the visitor's town, days of the
  year, weekdays, hours.
- With neither, **every product** sees it.
- A product **left out** never does, whatever the conditions say — "every
  laptop except this one".

"Shown from / until" and the switch come on top of all that. On a product's
own form the "Landings" tab says which landings it shows and why, and
answers for that product alone: as the landing says, show it here, never here.

## Words per language

The HTML is written per language, like any text in the admin (the language
picker at the top). A language without its own text shows the base one. The
CSS is one for all languages.

## Its CSS is its own

A landing stands in its own shadow root: the shop's styles do not reach into
it (a designer's `.hero` or `.card` is not daisyUI's), and its styles do not
leak out. The page's font and text colour still come through. In the CSS,
`:host` (or `:scope`) is the landing itself. `@font-face` works; `@import`
is dropped — bring fonts as files.

## Files

Upload pictures (WebP, PNG, JPEG, GIF, AVIF), fonts and video on the
landing's form, and name them in the HTML and CSS as `assets/name.webp`.
Each file is checked by its contents as well as its name. SVG is not
accepted: served from the shop's own address it could run a script.

## The product's data inside

Placeholders put in the product's own data: `{product_name}`, `{price}`,
`{old_price}`, `{product_sku}`, `{brand}`, `{feature:code}` and the rest of
the picker. One landing for a category then speaks about each product.

## No scripts

HTML is cleaned when printed, as everything staff write: no `<script>`, no
`onclick`, no forms. Animations are CSS. A widget that needs JavaScript goes
through Code snippets, with its own display rules.

## ZIP

A designer's folder as a ZIP — `index.html`, `style.css`, `assets/…`, maybe
inside one folder; `index.ru.html` and the like for other languages. "New
from a ZIP" on the list makes a landing (switched off until you look at it);
"Replace from the ZIP" on a landing replaces its HTML, CSS and files and keeps
where and for whom. A whole HTML document is cut down to its body; its
`<style>` elements move to the CSS; `<script>`, `<link>`, `<meta>`,
`<title>` are dropped, and the screen says what was. "Download as ZIP" gives
the same shape back.

## Looking before switching it on

"Look at it" on the form opens a product with the landing as saved, even
while it is switched off — only for staff who may edit landings.
