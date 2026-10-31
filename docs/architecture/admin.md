# Admin frame: search, dashboard, sidebar, manifest

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **"Where is that set?" is one box in the admin header**
  (`App\Core\Admin\AdminSearch`, `/admin/search`, `/` or Ctrl+K). Nothing is
  indexed or stored: it reads the sidebar's core screens, every module's
  `admin_menu` and every setting through the same `core.settings.fields` filter
  the settings screen uses, matching EVERY language an entry is written in. A
  setting is linked as `/admin/settings/<module>#setting-<key>` (lit by
  `.torgash-setting:target`). What none of those can say — the shop's own rows —
  a module adds through `core.admin.search`; the word a label does not contain
  goes in `keywords` ({en, ru}) on an `admin_menu` item or a setting.
- **The admin frame heads a page ONCE.** The top bar never repeats the title
  (it holds the way to the storefront, the search and the bell); the content,
  centred at up to 1600px, is headed by `@section('title')` unless the page
  draws its own — an `<h1>` in its rendered content, or
  `@section('own_heading', '1')` for a form whose sticky bar carries its name
  and Save. **The bell is `App\Core\Admin\AdminNotifications`** over
  `core.admin.notifications`: a module adds rows `{key, label, count, url,
  icon}` about its OWN queue, checks the viewer's permission itself, and may
  answer unconditionally — a zero is dropped. The count IS the queue (new
  orders, enquiries awaiting a reply, reviews to moderate): nothing is stored
  as "read".
- **The dashboard is WIDGETS from whoever owns the data**
  (`App\Core\Admin\Dashboard`, `core.admin.dashboard.widgets`, asked with
  `['user']`): `{key, title, icon, size quarter|third|half|wide|full,
  position, can?, link?, render}` — core draws the frame and names no module,
  and the page is COLUMNS, never a grid of cells: a band across the top plus
  2/3+1/3, 1/3+2/3, thirds, halves or one (`Dashboard::LAYOUTS`), each column
  a stack, because widgets of different heights leave holes in a grid; `size`
  only picks the column an unplaced widget starts in, and a widget lays itself
  out by its column's width (`@container`), never the screen's;
  `render` is asked only for a widget that is shown, and one that throws is
  reported and replaced by a note. A widget with numbers and a short list
  renders core's `admin.dashboard.list` rather than drawing its own. What a
  person hid and the order they left them in is THEIR row
  (`users.admin_preferences` — layout, zones, hidden; written past Eloquent so
  a layout is not an account edit in the audit), arranged on a board of tiles
  dragged between zones; a widget nobody placed takes its own position,
  so a new module's appears unasked. "Sales" on it are orders in a status that
  takes goods off the shelf (`inventory_action = decrease`). The old one-string
  `core.admin.dashboard` hook is still drawn, as one widget.
- **The sidebar is DATA** (`App\Core\Admin\AdminMenu`): sections that name
  what an owner looks for — `catalog`, `sales`, `marketing`, `customers`,
  `logistics`, `content` (Appearance), `data`, `security`, `system` — core's
  own screens as items, and each module's `admin_menu` item placed by its
  `group`. A core screen is added in `AdminMenu::coreItems()`, which
  `AdminSearch` reads too — one list, never two. `store`/`appearance` are old
  names read for ever; a group nobody declared lands in "Other"; a module may
  declare a section through `core.admin.sections`. The active item is the
  LONGEST item address the page is under (an edit page belongs to its list).
- A manifest may carry `author` ({name, url, email}) and `links`
  ([{label, url, icon}]), printed under the module on the modules screen. Read
  them through `author()` / `links()`: a third party wrote them, so only
  http(s)/mailto addresses survive and an unknown icon falls back rather than
  throwing under APP_DEBUG. How it is RECOGNISED is the manifest's too:
  `icon` (a lucide name, else its first menu item's), `logo` (a picture inside
  its own assets/, served by `admin.modules.logo` for any module on disk and
  with a sandboxing CSP — a third party's SVG is a document that can carry
  script), `color` (a #hex the tile is tinted with) and `section` (which
  sidebar section a module with no menu item belongs to — a payment gateway is
  Sales). Read through `icon()` / `logoFile()` / `color()` and
  `AdminMenu::sectionOf()`, which the settings screen files modules by too.
- **Products are FOUND, never listed into a page.** A shop may have a hundred
  thousand: `<x-admin.picker>` takes a `source` (an address answering `?q=`
  with `[{id, label}]`) and then needs `options` only for the chips already
  chosen — `Torgash\Catalog\Admin\ProductLookup` gives both (`search()`,
  `options()`), served by each screen's OWN route behind its own permission
  (related products, a collection's, a landing's, a look's point). The
  picker's "outside" is the field AND its list — on the list alone, the click
  that opened it closed it again.
- **What an admin may type in braces is ONE picker**, `<x-admin.placeholders>`
  — the registry, its groups and the syntax notes are global, so the blocks
  form, the SEO template form and the product tab form all open the same
  window. It inserts into the field that was last focused; its `fields`
  selector must match `input:not([type])`, since our own text fields are
  written without one.

## Control sizes in the admin (2026-10-06)

The admin keeps its OWN scale, separate from the storefront's (which lives in
app.css under `.torgash-storefront`): the admin is dense, the shop is for
fingers.

- Form fields are FULL size (`input`, `select`, `textarea`, `file-input`) —
  including the rows of a repeater inside a form (price tiers, feature
  values, rule rows): a smaller row under a full-size field reads as a
  different form. Rows that line up with something below share one grid.
- `-sm` is the admin's working size for everything else: every button and
  the list toolbar (search, filter selects, the bulk bar and its dialog).
- Checkboxes, toggles and radios are ALWAYS `-sm` — one size on a screen.
- No `btn-xs` (the 20px remove-cross inside a picker chip is part of the
  chip, not a button), no `text-[11px]` (`text-xs`), no `h-10` to make a
  small button match a field — use a full-size button.

- **A list's head is one shape (2026-10-29, the owner's choice after
  variants on the modules screen):** the layout's heading with an ⓘ hint
  beside it (`@section('title_hint', …)` — what the screen is for, in a
  popover; never a paragraph in the card), and ABOVE the card one row
  (`admin-tabs-row` in app.css): the states as rounded tabs
  (`nav.admin-tabs > a.admin-tab`, the active one on a card-coloured bed
  with a hairline ring — the one thing readable in the dark theme, where a
  `join` of buttons was not), their counts in grey pills
  (`admin-tab-count`; `is-error` / `is-warning` / `is-info` when a count
  is an alarm — waiting reviews, errors, blocks) and the screen's actions
  at the row's end (`admin-tabs-actions`), FULL-size `btn` so they stand
  level with the 40px tabs — the one place in the admin that is not
  `-sm`. A screen with no states puts its actions level with the heading
  (`@section('title_actions')`). The tabs are LINKS carrying every other
  filter (`fullUrlWithQuery([… 'page' => null])`), never submit buttons of
  the search form: Enter in the search box would press the first of them;
  the form carries the state back in a hidden input. Inside the card the
  search takes the full width (`label.input.input-sm.grow.min-w-48`), the
  other filters beside it; a rows-per-page select only where a list is
  long (products, characteristics, blog). The row scrolls sideways at any
  width with the wheel and fades at its edges (`admin-scroll.js`); on a
  phone the tabs take the whole line and the actions wrap under them,
  right-aligned. A module's own screens (reviews / criteria / other
  sites; countries / regions / places) stay the quiet underlined
  `tabs tabs-border` under the heading, and so do a form's sections
  (`tabs-bordered` never existed in daisyUI 5; `tabs-box` is gone).
  A period picker (search and wishlist statistics, speed) is the same
  `admin-tabs`. Nothing else draws a `join` of `btn-sm` as tabs.

- **One size and one colour per control (PLAN §63.2, 2026-10-29):** every
  checkbox, toggle and radio in the admin is `-sm`, and nothing is `-xs`
  (a field in a row is `input-sm`; the box a cell keeps its text and its
  field in is `h-8`, that field's height, so the row does not jump). The
  finger sizes above come from app.css, never from a bigger class. A
  toggle is `toggle-success` — green is "works", the colour the status
  badge says it in — or `toggle-warning` where "on" is a caution (noindex,
  a gateway's test mode); a checkbox or radio carries no colour, except
  `checkbox-error` on a box that deletes or replaces. The editor's toolbar
  fields wait for its buttons (theme 4). A badge is one of two: solid
  coloured for a STATE (a status, dealt with, answered, applied), `badge-ghost`
  for a neutral tag (a type, a module's name, "yours", a region, required);
  never `badge-outline` or `badge-soft`, and always `badge-sm` (`-lg` on a
  figure that heads a block, `-xs` on the bell's count alone). A sentence
  is not a badge: a note beside a result is text with an icon. A button is
  level with its neighbour: full beside a form's full-size field or as a
  dialog's "Save / Cancel", `-sm` as a row's action, over `-sm` filters or
  as "+ add a row" inside a form (neutral `btn btn-sm`, never ghost — ghost
  is a quiet row action); "Save" is always `btn-primary`; `btn-xs` only on
  the tiny round × of a chip (`min-h-0 h-5`, the tap area from app.css).
  Red says how far an action goes: a row's delete is the ghost trash icon;
  a bulk bar's delete and an action that can be undone (block, revoke,
  decline, replace) are `btn-error btn-outline`; a form's own "Delete" and
  "remove this row" are ghost red text; SOLID red is the final "Yes" of a
  confirmation and "erase for good" alone. The storefront's
  scale is the other one (DESIGN-REFERENCE.md): there nothing is `-sm`.

- **A row's status switches through `<x-admin.status-menu>`** — a solid badge with a chevron opening the choices (HTMX `target`, or small POST forms in a list no form wraps). Never a badge that flips on a click: it gave no hint it was a control. Landings, currencies, languages and storefronts use it; products and categories draw the same control by hand.

## The admin on a phone and under a finger (PLAN §62, 2026-10-29)

The computer's admin stays as it was (small tolerances allowed — the owner,
2026-10-29); a narrow screen changes things only through the width
(`max-md:`, `max-xl:`, app.css media queries) and a finger only through
`@media (pointer: coarse)`. All of it is in app.css, "The admin on a phone
and under a finger".

- **The header below `lg`** is ☰, the storefront, search, the bell and the
  profile: the content language, the currency of amounts and the colour
  scheme move into the profile menu. Under a finger they stay there up to
  72rem (`.admin-bar-choices` / `.admin-menu-choices` in app.css): a tablet
  held sideways has room for the sidebar, not for those three once its
  buttons grow (1024px overflowed).

- **An edit screen's bar is `<x-admin.form-bar>`**, its Cancel and Save in
  the `actions` slot (written AFTER the bar's own content — a named slot
  first leaves an output buffer open). On a phone the bar sticks to the top
  and the actions stand fixed at the foot of the screen, under the thumb.
  Never copy the bar's classes into a form again: a new form uses the
  component.
- **The page's gutter is `--admin-gutter`** (24 → 16 → 12 px as the screen
  narrows); whatever runs edge to edge (`-mx-…`) uses it, not `6`.
- **Under a finger** controls grow to 40 px (`btn-sm`, `input-sm`,
  `select-sm`, tabs, menu rows), fields to 16 px text (iPhone zooms into
  anything smaller — `select` too), checkboxes to 24 px with an invisible
  ring that takes the tap, and a label holding a box to a 40 px row (the
  label is the target). A badge that is a button, and the × that takes a
  value out of a chip (`.badge > button`), take taps around themselves the
  same way. Nothing to do in a template: write the admin's usual `-sm`
  sizes and the media query does the rest; a control that is not one of
  them (a toolbar's `btn-xs`) asks with `pointer-coarse:` itself.
- **An icon-only button carries `aria-label`** beside its `title` — a
  finger never sees a title. A code-like field (`sku`, `slug`, a key) carries
  `autocapitalize="off" autocorrect="off" spellcheck="false"`; money
  `inputmode="decimal"`, a count `inputmode="numeric"`.
- **Wide things scroll inside their own box, never the page — and on a
  phone a table does not scroll at all.** Tabs, a row of filter buttons
  marked `admin-strip` and a `join` of buttons alone are one strip that
  scrolls sideways under 64rem, the active one brought into view
  (`/assets/js/admin-scroll.js`). A table in `overflow-x-auto` that does
  not fit is, under 48rem, FOLDED into cards by that script
  (`admin-auto-cards`, PLAN §62.9: half a screen fixed and the other half
  scrolling was measured on the products list and rejected by the owner):
  each row a card with the row's name first (the first column with a
  heading), every other cell under it with its column's heading at the
  left, the buttons last, the row's checkbox at the top left corner. The
  cells are told what they are by `data-cell` (check / title / field /
  actions / wide / blank) and a field its heading by `data-label`; a row
  HTMX swaps in is told again. `admin-no-cards` on a table keeps it a
  table. From 48rem up a table that does not fit scrolls inside its box
  with its first column kept in place — and the name beside it when the
  first is the checkboxes and the second has a heading (an image column
  is not a name) — with a shadow at the edge that hides more; a table that
  fits is left alone. A `.label` wraps and a field in a `join` gives way to
  its button at any width (where everything fits, neither changes a
  thing); under 64rem a mono string breaks anywhere; under 40rem besides, a
  grid's item may be narrower than its content, a row with buttons wraps
  them, and a card's padding is 1rem (`--card-p`).
- **A list a manager works from a phone is drawn as cards OF ITS OWN** —
  the SAME table: `admin-cards` plus a list's own class on the table,
  `data-cell` on its cells, and a grid per kind of list in app.css naming
  where each cell stands (`admin-order-cards`, `admin-line-cards`,
  `admin-product-cards`, `admin-customer-cards`, `admin-enquiry-cards`).
  The generic fold above is the fallback for every other list; a list the
  owner lives in gets a grid that reads at a glance. One set of controls:
  a second `@foreach` for the phone would double every checkbox and status
  switch. The bulk bar of such a list is `admin-bulk-bar` (fixed at the
  foot on a phone). A row whose cells change shape while it is edited in
  place says so with a class the grid can read (`is-editing` on the
  products row: the price and the stock become fields across the card).
  A cell that needs a word beside its value on the card carries it in
  `data-label` (`Stock`), printed by the card's CSS only. A grid's last
  column that holds a badge is `minmax(max-content, auto)`: an `auto`
  track beside a `1fr` one is sized to its content's minimum and the badge
  stood 5px past the card. The box around cards is `overflow-x: clip`: a
  finger's invisible ring reaches past the last badge, and a box that may
  scroll scrolled those 6px.
- **Moderation on a phone is buttons, not a menu behind a badge**: a
  review that is waiting shows Publish and Reject (`md:hidden`) inside the
  element HTMX swaps, so they go with the waiting (`torgash.reviews`'s
  `_status`). The same shape for any queue a manager clears from a phone.
- **Photos from a phone**: the gallery editor is one photo per row under
  40rem (`admin-gallery*` in app.css), and a camera's JPEG or PNG is drawn
  down to the Media "Max dimension" in the browser before it is sent
  (media.md).
- **Anything reordered by dragging is `x-sort`** (Alpine Sort over
  SortableJS, vendored, loaded before Alpine in the layout — PLAN §62
  phase 3): `x-sort="move($item, $position)"` on the list,
  `x-sort:item="<the row's id>"` on each row and `x-sort:handle` on its
  grip, `x-sort:config="{ delay: 150, delayOnTouchOnly: true }"` so a
  finger that scrolls is not a finger that drags. The row is named by its
  own id and the list is keyed by it, never by the index (LESSONS.md: a
  key of 0 is dropped, and an index-keyed `x-for` puts the row back). The
  handler moves the row in the data; Alpine redraws. Beside the grip,
  under a finger only (`pointer-coarse:`), the arrows "Move earlier" /
  "Move later" (vertical under the grip in the file editors, inline in the
  slider and the characteristics); the hint under the list says "drag" to
  a mouse and "the arrows" to a finger. The gallery, documentation, video,
  3D models, a slider's banners and the product's own characteristics all
  follow this; the dashboard keeps its own arrows and column menu.
- **The sidebar as a strip of icons**, from `lg`, is this person's own
  choice (`App\Core\Admin\Preferences`, `admin_preferences.sidebar`,
  written by `POST admin/preferences/sidebar` from the button at the
  sidebar's foot; the layout prints `data-sidebar` on the drawer, so the
  page is drawn folded). The words carry `admin-sidebar-label` and go; a
  section's list becomes a popover (`torgash-popover admin-sidebar-flyout`,
  anchored to its summary, placed by popover-anchor.js where the browser
  cannot) that the summary's click toggles instead of the details; the
  sections stay open underneath so the browser renders what the popover
  shows, and their memory in localStorage ignores what the strip does.
  Below `lg` the drawer is the drawer whatever was remembered.
- **The sidebar has two views, and a star beside every heading (2026-10-30,
  the owner's idea):** "All" is every section; "Favourites" is the screens
  this person starred, a flat list in the sidebar's own order. The star
  stands beside the heading's ⓘ hint and speaks for the sidebar ITEM the
  page belongs to (`AdminMenu::active()` — a product's edit page stars
  "Products"), so the dashboard, which no item claims, has none. Both are
  this person's (`admin_preferences.favourites`, a list of item PATHS —
  `AdminMenu::pathOf()`, the same whatever host they came in on — and
  `admin_preferences.sidebar_view`), written by `POST admin/preferences/
  favourite` (only a path the sidebar shows this person, 422 otherwise)
  and `…/view`; the layout prints `data-sidebar-view` on the drawer so the
  page is drawn in the view chosen, and app.css switches the two lists —
  both are in the page, so the star moves an item between them without a
  reload (the `<li data-item-path>` is cloned from the sections). A star on
  a screen that is gone (a module switched off) is simply not drawn. Next to
  the switch, one button opens or closes every section at once (opens when
  any is closed), remembered in the same localStorage map as a click on
  each; the strip ignores it, as it ignores the sections' memory.
- **Measure, don't guess**: `tools/admin-probe.js`, pasted into the console
  of any admin page, opens every screen in hidden frames at the given widths
  and reports pages wider than the screen (and the element that makes them
  so), tables wider than their box, targets under 40 px under a finger (a
  ring drawn by an invisible `::after` counts) and icon-only buttons without
  a name — `adminProbe({ widths: [390, 820] })`, then
  `__probe.worst('overflow', 390)`.

## Texts — the shop's own wording over the lang files (2026-10-31)

Every string the code prints is an English key translated by lang files
(ADR-005), and a shop may want another word for one — "Cashback" for
"Bonus points", in Russian or in English alike. Written into a lang file
the word would go with the next update, so it is a ROW:
`text_overrides` (locale, key, value), read over the JSON strings by
`App\Core\Translation\TextLoader` (the framework's `FileLoader`, wrapped in
`CoreServiceProvider::register`) from `TextOverrides::for($locale)` — one
map per language in the application cache for ever, dropped when a row
changes. A change also empties the translator's loaded lines, flushes the
page cache and restarts the web and queue workers (they keep the texts they
booted with). No per-request cost: the translator keeps its lines for the
life of the worker.

- **Words only.** A text is printed escaped wherever it goes (a menu item,
  an aria-label, a placeholder, a letter's subject, an SMS, a string inside
  a script), so markup is refused (`TextOverrides::refusal`); the shop's
  own HTML has its own places (HTML blocks, snippets). A text keeps the
  key's `:placeholders` — none missing, none the key does not have — and a
  key with `|` keeps forms. No variables of the shop's own: a text has
  what its key has.
- **The screen** (Admin → Texts, `languages.manage`): every key the enabled
  modules', the theme chain's and core's lang files declare
  (`TextCatalogue`), searched in any language (`mb_stripos` over a few
  thousand strings), one form a text with a field per enabled language
  (English's file value is the key), Save appearing once a field is
  touched, a changed text marked and offering "Back to the original". A
  value equal to the file's is no row.
- **Not for content.** What the shop types (a product's name, a page) is
  translatable jsonb on its row; this is only for the engine's own words.
