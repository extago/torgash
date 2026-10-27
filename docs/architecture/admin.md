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

- **A row's status switches through `<x-admin.status-menu>`** — a solid badge with a chevron opening the choices (HTMX `target`, or small POST forms in a list no form wraps). Never a badge that flips on a click: it gave no hint it was a control. Landings, currencies, languages and storefronts use it; products and categories draw the same control by hand.
