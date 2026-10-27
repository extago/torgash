# Banners and sliders

Three things, three tabs: **Banners**, **Sliders**, **Templates**.

- A **template** says how something looks: HTML with `{tokens}` and its
  CSS. A *banner template* draws one banner; a *frame* draws a slider
  around its slides.
- A **banner** is what you type into its template's fields: words,
  pictures, a link, a colour. You never see the HTML while filling it in.
- A **slider** is a frame with banners in it, placed on the page by a
  **Banners** block (Content → Blocks).

## Shapes

A banner can be drawn in three shapes, and a template writes HTML for the
ones it can do:

- **Wide** — across the column: a main slide, a full-width band;
- **Upright card** — a card in a carousel, a cell in the catalogue's grid;
- **Low strip** — a row: the catalogue's list view.

A frame says which shape its slots are. A banner whose template cannot draw
that shape is skipped by the slider — the slider's form says so beside it.
One banner can stand in a wide slider on the home page and as a card
elsewhere, if its template draws both.

## Writing a template

```html
<div class="promo" style="background: {background}">
    [<span class="promo-type">{eyebrow}</span>]
    [<div class="promo-title">{title}</div>]
    [<img class="promo-cover" src="{cover}" alt="{alt|optional}" {loading}>]
    [<a class="promo-button" href="{link}">{button}</a>]
</div>
```

- `{title}` is a field. Write it in the HTML and it appears on the right,
  where you say what it is: a line of text, a text with tags, a picture, a
  link, a colour, a choice, a switch, an icon, or a list of links (the chips on
  a card — `{chips}` prints them one after another as `<a>`, styled by the
  wrapper around it: `.chips > a`). The banner's form asks for it
  that way.
- `[ … ]` disappears with everything inside it when the field in it is
  empty — no empty heading, no button without a link.
- `{alt|optional}` may be empty without taking its brackets away.
- A picture: `{cover}` its address, `{cover|width}` / `{cover|height}` its
  size, `{cover|small}` a thumbnail. `{loading}` makes the first slide's
  picture load at once and the others when near.
- Each banner is wrapped in `<div data-template="key" data-shape="…">`, so
  CSS can begin with `[data-template=key]` and touch nothing else.
- The CSS is printed once on a page that shows the template. A `<style>`
  written in the HTML moves to the CSS when saved; a `<script>` is removed —
  scripts go to Code snippets.

**Preview** under the editor draws the template with sample values as you
type.

A frame has tokens of its own, filled by the engine: `{slides}` (the strip
that turns), `{items}` (all banners at once, no script), `{dots}`, `{prev}`,
`{next}`, `{counter}`, `{progress}` and `{aside}` — the slider chosen
**Beside it** in the slider's settings, e.g. the cards over a full-width
slider.

Writing templates is its own right, **Write banner templates** — the people
who fill in banners do not need it.

## Presets

The module brings templates of its own (Big slide, Info card, Picture, Card
with chips; the
frames Slider, Carousel of cards, Column, Slider with a column); a theme can
add or replace them in its `banner-templates/` folder. They are copied in
when the module is installed; after that they are yours to change.
**Restore from the preset** (gear menu) puts one back to its files — the
banners keep what was typed into them. **Add from the theme** brings the
presets a new theme has. **Export** / **Import** move a template between
shops as one file.

## How a slider turns

- **In view** may be a fraction: 4.3 shows four cards and the edge of the
  fifth, so it is plain there is more.
- **A throw of the finger**: *turns one slide*; *glides and settles on a
  slide* — a strong throw passes several and comes to rest on a slide's
  edge; *glides freely* — it stops wherever it stops.
- **An arrow turns** one slide or all in view; **speed of a turn** fast,
  normal or slow.
- Without **Round and round** the arrow at an end hides. The Carousel of
  cards has frosted arrows on the cards' edges, shown under the pointer; on a
  touch screen there are none — a finger swipes.

## Between the products

A slider can also stand between the products of the catalogue: in the
slider's settings, **Also between the products in the catalogue** — after
which product, again every so many, in which listings (the same conditions:
"Category being viewed" takes in its subcategories). Its banners take the
places one by one: a card in a cell of the grid, a low strip in the list
view — the same banner in its template's other shape. A banner whose template
has no such shape sits that view out. A banner is an extra cell, so a page
still shows all its products.

## A video behind a slide

A template field of the type **Video (file)** takes an mp4 or webm — a loop of
a few seconds, without sound — uploaded on the banner's form (up to 10 MB, the
media library's limit). The Big slide has one: **Video behind the slide**,
with **Darken the video** so white words read over it. In a turning slider
only the slide on the screen plays its video; elsewhere a video plays while it
is on the screen. Never for a visitor who asked their system for less motion —
they see the slide's colour and picture.

## Clicks

A click on a link inside a banner is counted — per banner, per day — and
shown in the list (the last 30 days) and on the banner's form (7 days, 30
days, in all). Views are not counted: that is what the site's own statistics
are for. The link stays a plain link: no detour through the shop, "open in a
new tab" works as ever.

## Exchange

**Banner** is an entity of import and export (Export profiles, Import): a row per banner
with its template, name, dates, sliders (names, comma separated) and
conditions, and a column per field of the shop's banner templates — words
one per language, a picture as its address (downloaded on import when it is
a new one), a list of links as one JSON cell. A row is matched by its ID:
the shop's own file updates its banners; in another shop the rows are new.
Templates travel as their own files (Templates → Export).

## When a banner is shown

- switched **on**;
- between **Shown from / until**, if set;
- its **conditions** — the same as blocks have: storefront, language,
  customer group, page; **Calendar** for what comes back every year (the
  holidays: 25.12–08.01; a holiday whose date moves is given with its year,
  10.04.2026–13.04.2026), **Day of the week**, **Time of day**, all on the
  shop's clock (its time zone is in the shop's settings); and the visitor's
  **town**, **region** or **country** — the town they chose in the header. A
  visitor who has chosen none is in no town; "Visitor's town is known — they
  have not chosen a town" shows them the banner meant for everybody else.

A slider with nothing to show prints nothing, and its block disappears. It
turns by itself only while nobody points at it, never in a hidden tab, never
for a visitor who asked their system for less motion.

**Duplicate** makes the next banner from this one; the copy is off and in no
slider until you put it there.
