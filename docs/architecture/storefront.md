# Storefront: themes, product page, phone, blocks, menus, display rules

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **A storefront's theme is known only per request** (`ResolveStorefront`),
  while the cascade is wired at boot from the configured default — so
  `ThemeManager::setActive()` with another theme RE-LAYS it (the view paths it
  added, every module namespace, the finder's cache, the theme's lang and
  zones). A theme is `themes/<dir>/theme.json` (+ `parent`), overriding whole
  files at the same path; the storefront form offers `ThemeManager::available()`.
  A theme's files are served from `/themes/<dir>/assets/<path>`
  (`ThemeAssetController`, any theme on disk, never outside assets/).
- **A theme's settings are DECLARED in theme.json and stored per storefront**
  (`App\Core\Themes\ThemeSettings`, `theme_setting()`, module id
  `theme.<dir>` in `settings`). A field says how it reaches the page: `css`
  (+ `unit` / `css_map`) → a custom property printed by `<x-theme.style />`
  under `:root:not(#_)` (beats daisyUI's `:has()` palette switch), `style` → a
  whole rule printed only while the value differs, `fonts` → @font-face from
  the theme's assets; anything else a template asks. A value equal to the
  FIRST declaration's default (`base_default`) prints nothing — the
  stylesheet already draws it, so an untouched shop is the theme as written
  and a child theme redeclaring a default still prints its own. Values going
  into CSS pass `clean()` (known key, an option that exists, a real #hex).
- **A colour has two palettes** (PLAN §52): a `color` field naming `dark`
  (the dark palette's value as the stylesheet draws it) gets a twin field
  `<key>__dark` (`ThemeSettings::DARK`) — empty = automatic, a #hex = exactly
  that. `palettes()` prints the light value for both palettes and the dark
  one over it under `:root[data-theme="torgash-dark"]:not(#_)` and the
  system-dark `:root:not([data-theme]):not(#_)`; automatic is
  `App\Core\Support\Colour::darkTwin()` by `role` (`text` rises until it
  reads on the dark page, `surface` sinks in OKLCH, anything else stays) and
  is printed only once the light colour was changed. `on` names the
  property for the text standing on the colour, printed white or near-black
  by contrast (`Colour::ink`). The configurator shows Light / Dark tabs, the
  contrast where the colour stands (`contrast`: `fill` against its text —
  `ink_field` / `on_default` say which while untouched — `text` against the
  panels, `surface` against the text) and "pick a readable shade", which
  changes the colour ONLY when pressed: the shop's colour prints exactly as
  chosen (the darkened-brand revert of 2026-10-16). The same formulas run in
  the configurator's script, so its "automatic" swatch is the page's colour.
- **Selling colours are ROLES, used by class** (PLAN §52 step 2): a price is
  `torgash-price`, a struck-through one `torgash-old-price` (never
  `line-through opacity-40` by hand), the "−20%" badge `badge
  torgash-discount`, "You save" / a lowered price `torgash-discount-text`,
  stock `torgash-in-stock` (a chip: `torgash-in-stock-chip`) /
  `torgash-out-of-stock`, stars `--torgash-star`. The variables default in
  app.css to what the templates drew, written in palette terms
  (`--torgash-in-stock: var(--color-primary)`), so an untouched shop is
  unchanged in both palettes and "in stock" follows the brand colour until
  the shop picks its own. A new template showing a price or stock uses these
  classes, or the shop's colour stops reaching it.
- **Colour combinations and the shop's own styles**: a theme lists
  `colour_schemes` (name + colours, only colour keys survive `clean()`); the
  configurator puts one INTO THE FORM — every colour it does not name back
  to the theme's, every dark twin automatic — so it previews and can be
  adjusted, and nothing is kept until Save. "Save as a style" keeps the
  form's values (saved or not, cleaned) under a name in `settings`
  (`theme.<dir>` / `_styles`, storefront null — every storefront wearing the
  theme sees them), applied like a preset (reset, then save). Up to 30.
- **Backgrounds** are a field type, `background`: `''` (the theme's colour),
  `gradient:#from:#to:<90|135|180>` or `image:<path>` (uploaded through the
  configurator's image route, `isUploadedImage()` on the way in), printed by
  `rules()` for the field's `selector` as background-IMAGE only — the zone's
  colour role and the text on it stay. The zones are hooks: the page
  (`body.torgash-storefront`), every header row (`torgash-header-bar` — put it
  on a new header partial) and `torgash-footer`.
- **The admin draws the SHOP's colours through `ShopPalette`**: the admin's
  stylesheet knows only the theme's defaults, so a screen previewing
  something "in the accent" (a label, a sale) asks
  `app(ShopPalette::class)->styleVars()` for its element's `style` (only the
  changed roles, with their text exactly as the storefront prints it) or
  `colours()` for the hexes. It reads the storefront's theme chain and saved
  values WITHOUT `setActive()` (that would re-lay the admin's views). A sale
  tinted `primary` / `secondary` / `accent` takes that colour's OKLCH hue.
  The configurator (`/admin/themes/customize/{storefront}`, `themes.manage`)
  keeps a DRAFT in the admin's session which the storefront draws only for a
  request marked `Sec-Fetch-Dest: iframe` from staff — so links inside the
  preview frame stay in the draft and a customer never sees it; opening the
  configurator forgets it. So the configurator lives on the storefront's OWN
  address: framing another site gets no cookies (and `frame-ancestors 'self'`
  refuses it anyway). The themes screen opens a storefront the current
  address does not draw (`Handoff::isHome()`) by a POST that issues a
  one-use, one-minute sign-in ticket (`App\Core\Access\Handoff`, landing on
  `admin/handoff/{token}`, good only on that storefront's domains); a module
  carries what the session proved via `core.auth.handoff` (guard: the second
  factor's stamp) and hears `core.auth.handed_over` (guard: the audit). Presets (`presets` in theme.json) replace the whole
  look and, when asked, announce `core.theme.preset_blocks`, which
  `torgash.blocks` answers with `ThemeDefaults::replaceWith()` — blocks are
  shared by every storefront, and the screen says so.
  The configurator is a screen of its own (no admin sidebar): ONE form holds
  every field of every section (a section only shows some, so a save never
  drops another section's values), Save goes in place as JSON so the frame
  keeps its page, and a change reloads the frame at the scroll position it
  had. Sections are named in `setting_sections` and may carry
  `setting_section_meta` — `icon`, and `device` ("phone": opening the section
  turns the frame into a phone). A phone or tablet frame is narrow, but its
  pointer is the admin's mouse, so `(hover: hover)` held there and a card
  lifted on hover as on no phone: in such a frame every `@media` asking about
  hover or the pointer is rewritten to a touch screen's answer
  (`assets/js/preview-touch.js`, printed by `<x-theme.style />` only when
  `ThemeSettings::framed()` — staff, in a frame), kept in `window.name` for
  the links followed inside. A device-dependent rule is therefore written as
  `@media (hover: …)`/`(pointer: …)`, never by sniffing in a script. Which
  buttons a card's photo shows is a theme setting per device
  (`card_corner_phone|tablet|computer`, printed as `data-card-corner-*` on
  `<body>`; app.css `.torgash-corner-more`). The page in the frame is picked from
  `core.theme.preview_pages` (items `['label' => English, 'icon', 'path',
  'position']`; core adds the home page and the account, the catalogue,
  cart and blog add theirs — core does not know there is a catalogue).
  `?section=<key>` opens a section directly.
  Field types: segmented, select, color, checkbox, image, code, and
  `number` (`min`/`max`/`step`; `clean()` refuses anything outside, and
  writes it as the default is written so "unchanged" still compares equal).
- Placeholders (`App\Core\Content\Placeholders`) understand three things beyond
  a name: MODIFIERS (`{category|lower}`), an ARGUMENT (`{feature:ram_gb}`,
  handed to the resolver as its second parameter) and an OPTIONAL FRAGMENT
  (`[ — {feature:color}]` — square brackets holding a token vanish with their
  own text when it is empty, which is what stops a title reading "Laptop — ").
  Brackets holding NO token the shop knows are left exactly as typed: they are
  somebody's punctuation, or JavaScript (`[{ id: 1 }]`), and eating them is a
  silent edit of pasted code. `expand($text, $context, escape: false)` for text
  going into an ATTRIBUTE or a `<script>`: escaping twice makes `&amp;amp;`.
- **`torgash.snippets` is code printed verbatim** — counters, pixels, chat
  widgets — into core's three slots, chosen by core's own display rules ("the
  pixel only on `checkout.confirmation`"). Nothing reaches the admin, because
  the admin has no such slots. Its own `snippets.manage` permission and the
  guard's audit are what guard it, not sanitising: an engine that tidies a
  provider's snippet breaks it. Order tokens on the thank-you page
  (`{order_number}`, `{order_total}`, `{order_currency}`,
  `{order_items_json}`) are registered by CHECKOUT and read the order off the
  ROUTE; they answer with bare numbers, never `format_price()` — a tag fed
  "1 042.25 $" records nothing.
- **The product page is TWO INDEPENDENT COLUMNS** (PLAN §38): the name across
  the page (SKU at its end), then left = gallery → `before_details` → details,
  right = buy panel → `after_buy_box`, each its own flex column — never grid
  ROWS shared between them (the right column spanning rows left a hole under
  the gallery), and the right column is NOT sticky (it is often taller than
  the screen). On a phone both are `display:contents` and `order-*` lays out
  photo → panel → wide row → details → under-panel. Because the left
  column's pieces come before the panel in the HTML, a slow connection
  painting a half-arrived page drew them under the photo and the panel then
  pushed them down (CLS 0.17 on PageSpeed mobile); app.css draws nothing of
  the left column but the gallery until `#product-buy-panel` is in the
  document (`.torgash-product-layout:not(:has(...))`). Anything a phone shows
  ABOVE something that comes earlier in the source needs the same answer —
  measured through a tunnel, never on localhost, where the page arrives in
  one piece and nothing shifts. Under the buy button,
  `cart.buy_box.actions` is for BUTTONS (one click) and `cart.buy_box.links`
  for the quiet row (wishlist, compare, ask, notify — `.torgash-buy-links`).
- **A product page has THREE places a module can put something, and they are
  three WIDTHS.** `catalog.product.buy_box` is the narrow sticky panel;
  `catalog.product.after_buy_box` is under it and just as narrow;
  `catalog.product.before_details` is the full width of the page, under the
  gallery and above the tabs — a kit lives there, because a kit is a ROW
  (several products side by side plus a totals column) and 400px is not a row.
  Inside the panel, `cart.buy_box.fields` is INSIDE the form (the options
  form, so answers ride the same POST as the quantity) and
  `cart.buy_box.actions` is OUTSIDE it, under the button: "buy in one click"
  and "ask about this product" are dialogs holding forms of their own, and a
  form inside a form is the trap below. **A card the shop can place must lay
  out by its OWN width, not the viewport's** — `@container` and `@2xl:`, never
  `lg:`: the kit card asked how wide the screen was, which is the wrong
  question, and so it drew a row inside the 400px column and ran off its edge.
  **A refused add answers with `HX-Retarget`**: the buy form aims at the
  header badge, which is right for the badge and wrong for a refusal — without
  it the little cart icon was replaced by an entire buy panel.
- `{token}` placeholders in admin-authored HTML carry the caller's CONTEXT:
  `expand_placeholders($text, $context)`, and the HTML block passes its zone
  context — so `{price}`, `{club_price}` and friends (registered by catalog in
  `Content\ProductPlaceholders`) answer about the product the zone is rendering
  and resolve to '' anywhere else. A resolver written as `fn () => …` keeps
  working: PHP ignores an argument a closure did not ask for.
- `{icon_gem}` in content comes from `IconPlaceholders`: a LIST (the core
  `icon_tokens` setting, plus whatever modules add through `core.icon_tokens`),
  never the whole 2035-icon pack — the placeholder picker is a menu a human
  reads. An unknown name registers nothing, so the typo stays visible.
- A theme ships zone content as `default_blocks` in `theme.json`, laid out ONCE
  by a migration guarded per zone — an upgrade may not drop a block back into an
  arranged zone. The way back is an ACTION, `Torgash\Blocks\ThemeDefaults` +
  `/admin/blocks/theme-defaults`: per zone, ADD what is missing (matched by type
  AND title, so pressing twice adds nothing and an edited theme block counts as
  present) or REPLACE the zone, which names what it will delete first. Whose
  brick it is lives in `m_blocks.source` (`theme` / NULL) — a COLUMN, because
  settings belong to the block type's own schema. A restored block gets only the
  rules its theme file describes; `rules_match` is NOT NULL, so "not described"
  is an absent key rather than a null. This is also the answer to "installed a
  second theme and its zones are empty".
- **The phone version is three partials of the theme, and its menu is a
  ZONE** (PLAN §38.3). Below `lg` the desktop headers stand down
  (`hidden lg:contents`) for a one-row header [menu][search], a bottom tab bar
  and a left drawer whose whole content is `storefront.mobile.menu` — blocks
  (`account`, `town`, `menu` as `drawer` over `catalog`, `menu` as `rows`
  over `mobile-links`, `locale`, `html`) the shop moves and restyles, laid
  out by `theme.json`. The bar is the MENU named by the theme setting
  `tabbar_menu` (seeded `tabbar`; first five entries with an address), whose
  entries are mostly `shortcut`s — the `storefront.tabbar` filter (a LIST of
  `key/label/icon/url|drawer/badge/order/active`), which is also the
  automatic bar when no menu is named; an entry with `'auto' => false` is
  offered to menus but kept out of the automatic five (the comparison), so
  cart and wishlist add their own; each tab's badge is ALWAYS in the
  markup as `#tabbar-badge-{key}` (hidden at zero) so an add can refresh it out
  of band beside the header's counter. Anything opens the drawer by
  dispatching `torgash-drawer`. A category's `icon` is a Lucide name, checked
  against the file before drawing. **What only a script can open is folded
  away only under `html.torgash-js`** (set by the theme's head): the filter
  panel becomes a full-screen `.torgash-sheet` behind a "Filters" button, a
  `.torgash-js-only` control is not drawn without a script, and "Catalogue" in
  the tab bar is a LINK a script turns into "open the menu" — so a browser
  with no script, or a theme that never sets the mark, keeps every control in
  the page's flow. Past the buy button a strip over the tab bar
  (`.torgash-buybar`, cart's buy box) submits the SAME form through `form=`;
  a product that asks something scrolls to the box instead.
- **The desktop header is two axes** (theme settings, section "Header"):
  `header` is the LAYOUT — `two_rows` (`header-two-rows.blade.php`) or
  `three_rows` (`header-three-rows.blade.php`: taller main row, icons with
  their word, the account menu as one of them) — and `header_palette` the
  COLOURS, dark bar (`.torgash-bar`) or the page's own, read inside either
  partial. It was one choice once ("dark" = two dark rows, "light" = three
  light ones); the schema keeps a shop's answer with `renamed` (old option
  → new name, also for imported files and presets) and `follows` (a new
  field with nothing stored takes its answer from another field's stored
  one) — `ThemeSettings::stored()`, which asks `SettingsManager::has()`
  because the theme registers its defaults. `header_contacts` draws the zone
  `storefront.header.after_search` (the phone block) after the search or up
  in the strip beside the language — ONE place, never both (a fixed popover id).
- **Each row of the desktop header sticks on its own.** The dark `<header>`
  is `display:contents`, so its thin top strip and its main row are the page's
  own boxes (a sticky row sticks only inside its parent, and a parent exactly
  as tall as both rows carries it away). `header_sticky` keeps the header,
  `header_sticky_rows` says which (`main` — the default, the strip scrolls
  away — or `all`), `category_strip_sticky` adds the category strip; the
  layout adds up what stays into `--torgash-header-h` and puts the strip at
  `--torgash-strip-top`. `catalog_button` off leaves the strip as the
  catalogue's menu: an entry opens its children on hover/focus, and what does
  not fit goes to the next PAGE of the strip ("› 1/2", measured by the script;
  with no script the row scrolls). The product page's section bar becomes
  fixed under that height across the screen while its sections are read.
- **Columns are the same idea as tabs**: adjacent blocks sharing `m_blocks.row`
  are drawn side by side on a 12-column grid (`width` 1/4…3/4 or empty = an
  equal share, `ZoneRenderer::spans()` always fills the row; phones stack).
  A block in a row is never also a tab. They are made on the zone screen —
  "join with the block below as columns / as tabs", "take out" — never by
  typing a word; up/down move a whole group, left/right a member inside it,
  and a group left with one member is tidied back to a plain block. A zone
  declares its SHAPE (`wide` / `narrow` / `inline` / `drawer`,
  `ZoneRegistry::SHAPES`, `shape` beside its label) and a block type what it
  `fits` plus a one-line `description`; the picker window offers what fits
  first and the rest one click away.
- **A block stands in a SECTION** (PLAN §51, `Torgash\Blocks\Section`,
  column `m_blocks.section`, beside `row` / `width` / `tabs` — where it
  stands, not its type's settings, so every type has it): `page` (the page's
  width, what every block had), `band` (background across the screen, content
  the page's width) or `full` (edge to edge — a slider across the screen);
  a background from the theme's tones or a real #hex; room above and below.
  Blocks drawn together (a row, tabs) stand in ONE band — the first block's,
  drawn or not. It is set on the ZONE screen (a line over each row or block in
  a `wide` zone, a window, `PATCH admin.blocks.section` for the group's first
  block), never in a block's own form: it belongs to the row, not the block. A band steps out of `<main>`'s
  container with `margin-inline: calc(50% - 50vw + scrollbar / 2)`: 100vw
  counts a classic scrollbar, which the storefront layout measures into
  `--torgash-scrollbar`; `html { overflow-x: clip }` (clip, not hidden — no
  scroll box, so sticky rows still stick).
- **The shop's own CSS classes** (`Torgash\Blocks\CssClasses`): a block's
  `m_blocks.css_class` goes onto its OUTERMOST element (every type draws its
  own root, so `ZoneRenderer::block()` merges it into that tag's `class`, or
  wraps output that starts with text or a `<style>` in a div); a band's
  `section.class` onto the `<section>` — and a band with nothing but a class
  is a plain `<div>`, without a band's margins, so naming a row never moves
  it. Only plain names pass (letters, digits, `-`, `_`); the form refuses
  anything else by name. They are hooks for the shop's stylesheet, not
  Tailwind utilities: the stylesheet is built from the templates.
- **Tabs are blocks standing side by side, never a block holding blocks.**
  `m_blocks.tabs` is a word (a column, like `source`): adjacent blocks in a zone
  sharing it are drawn as ONE row of radio tabs by `Torgash\Blocks\ZoneRenderer`,
  each named by `BlockTypes::heading()`; a block that renders nothing is not a
  tab. The home page's products are such a row — `products` blocks
  (`Torgash\Catalog\Selections`) in `storefront.home.products`, laid out by the
  theme. Where they come from is a registry, `catalog.product_sources`, storing
  ONE `kind:arg` value; a source whose argument means nothing any more narrows
  to NOTHING, and every query starts at `Product::listed()`. A select's
  `options` may be a closure — registration runs on every request.
- **A menu is DATA; where it stands is a BLOCK; how it looks is a LOOK**
  (`torgash.menus`, PLAN §39). An item is a KIND from `menus.item_kinds`
  (`link`, `page`, `category`, `heading`, `divider`); a shop's own page is
  pointed at BY NAME through `menus.pages` (a module registers its own —
  `route:wishlist.index`, `page:12`), because a typed `/sale` takes a Russian
  reader to the English page and outlives an uninstalled module. A category
  item opens itself (depth, `show_when` = always / listed / orderable /
  in_stock, `replace` to stand its children in its place — "the whole
  catalogue" is a category item with no category), counted by
  `Torgash\Menus\CategoryCounts` in ONE query per page over
  `Product::listed()` (so a chosen pickup point narrows it), cached under the
  hash of that query. Templates read `Torgash\Menus\Node`s from `MenuBuilder`
  and nothing else; the header's "Catalogue" and category strip take the menu
  named in the theme settings (`catalog_menu`, `strip_menu`, a theme field
  with `options_from`), falling back to the category tree in the same shape.
  The top strip is two zones, `storefront.header.top_start` / `top_end`.
- **The page's two edges are zones**: `storefront.page.top` (before every
  header, the phone's too — "the shop is being filled") and
  `storefront.page.bottom` (after the footer). Edge to edge, no container:
  a block that wants the page's width writes `container mx-auto` itself.
  Drawn only when something is in them.
- **Category and product pages have zones of their own**:
  `catalog.category.top` / `.bottom` (one template draws the catalogue, a
  category and a filter page; the context is `category` + `filter_page`, and
  both zones stand OUTSIDE the filter form, since a block may hold a form) and
  `catalog.product.top` / `.before_details` / `.bottom` (context `product`).
  "Which category is open" is the display rule `listing_category` (subtree,
  fails closed on the catalogue root) — the product rules ask about a product.
- Display rules ("show this only when…") are core's `App\Core\Display`: an
  engine (`DisplayRules::passes($rules, $context, all|any)`) plus a REGISTRY of
  kinds (`RuleKinds::register('product_category', [...'matches' => fn])`), which
  is what makes it different from the shipping and automations condition
  engines — those own every kind they ask about, this one is asked by one module
  about another's data. Core registers where/who (storefront, locale, visitor,
  customer group, route name); catalog registers the product ones. Two rules
  that must not drift: an unknown kind FAILS CLOSED (a rule from a disabled
  module hides the thing rather than being ignored), and so does a rule whose
  context is missing ("product is in X" on a page with no product). Blocks store
  them in `rules` + `rules_match` and ask through `Block::isVisible($context)`.
  **"When" is core's too** (PLAN §51): `calendar` (days of the year — `25.12`
  every year, `25.12.2026` that year only for a holiday that moves; a yearly
  period runs over New Year), `weekday`, `time_of_day` (over midnight) — all on
  the SHOP's clock, `shop_now()` (core setting `timezone`; the application's
  clock, what the database stores, is untouched). Two value types beyond
  none/text/number/options: `periods` (from–to rows, checked by
  `App\Core\Display\Periods`, a half-typed row dropped) and `search` — a list
  too long to send to the page (`search` = a type-ahead URL answering
  `{items: [{id, label}]}`, `labels` = names for chosen ids). A form that
  narrows the editor's groups offers `when` alongside `where` and `who`.
  An `options` kind whose options are drawn for a picker (the category tree,
  indented "— — iPhone Duo") gives `labels` too: `describe()` — the summary
  in a list — reads names from there, never the picker's indent.
  A product kind may say which products it can pass as a query (`narrow` =>
  fn (Builder $products, $rule)); `DisplayRules::narrow($query, $rules,
  all|any)` applies them, so a screen that wants ONE example product finds
  it in SQL (a landing's preview, `Torgash\Landings\PreviewProduct`). It
  only ever narrows — whatever comes back is still asked of `passes()` —
  so a kind that cannot say itself exactly in SQL (stock, "has no
  description") declares nothing; under ANY one such rule lets every
  product through, under ALL an unknown kind leaves none.
- Popups are `<x-modal>` (core, `resources/views/components/modal.blade.php`):
  a native `<dialog>` with three optional zones — `trigger` (an attribute),
  `title`/`icon`, the default slot, and a `footer` slot; `framed` puts the
  content in a box, `name` gives it a stable id so anything can
  `getElementById(name).showModal()`. It contains NO form of its own (daisyUI's
  own close idiom is `<form method="dialog">`, and a modal often sits inside
  another form). For TEMPLATES only — an admin writing a popup writes the same
  six lines of markup in an `html` block, which is why there is no popup block
  type: it would differ from the HTML block only by having a fixed schema. Same
  reasoning as the `Placeholders` docblock's "there is no contacts block type".
**"It is done" is ONE corner, and it is core's** — `<x-notice>` (title,
text, image or icon, a link onward) in an HTMX answer arrives out of band into
`#torgash-notices`, which core prints on every storefront page through
`core.footer`; `public/assets/js/notices.js` only takes notices away. Its life
is the CSS animation of its bottom bar (5s, paused under the pointer or focus),
at most three stack, newest on top, top right under the header. The basket's
`after_add = toast`, the wishlist's `notice_on_add` and the comparison all use
it — three modules that do not know each other must not own three corners.

- **The footer is built of blocks** (the owner's design, 2026-10-01). The theme
  owns the BANDS and the three columns — `storefront.footer.before`
  (advantages), `.about` / `.columns` / `.side` (left, middle, right — each a
  zone whose blocks stack, since a zone row puts ONE block per column),
  `.stores`, `.bottom`, `.credits` — and a band or column is drawn only when its
  zone printed something, the remaining columns sharing the width. So nothing
  invisible may be hung on a footer zone: the callback dialog moved to
  `core.footer`. **A block TYPE only where there is data or behaviour** (the
  owner's call — "I am not against code"): `payments` (Simple Icons marks, CC0, with their names on
  hover; Mir/SBP/banks as `Name | /path`), `stores` from `torgash.warehouses`
  (public warehouses by town, `locations.place.forms` for the case), menus.
  Everything that is only markup — the signature, the rating plate with the
  contacts under it, the advantages, the social cards — is an HTML block in the theme's
  `default_blocks`, written with tokens: `{year}`, `{logo}` / `{logo_dark}`,
  `{icon:name}` (any lucide icon — one picker entry, the short list stays),
  `{phone_actions}` / `{email_actions}` (the contact as core's
  `<x-contact-actions>` popover "Copy | Call / Write"; empty when unset, so a
  `[…]` fragment drops its whole row), `{address}` (the legal address),
  `{phones}` / `{socials}` (the storefront's LISTS — `Storefront::CONTACT_LISTS`,
  more numbers with a caption, social pages; `{phone}` stays the main one; a
  network left blank is read off the address; the mark comes from
  `torgash.blocks` through `core.brand.mark`, a globe without it; the
  messenger buttons of `{messengers}` take their marks the same way),
  `{mark:telegram}` (a brand mark; NOT `{brand}`, which is the product's brand,
  catalog's token — a token name is a flat namespace) — and a few CSS pieces in
  app.css so the HTML stays short: `.torgash-squircle`, `.torgash-stars`,
  `.torgash-rating-glow`, `.torgash-brand-card` (`--brand` inline). Social cards
  carry `data-social` / `data-social-count` for the shop's own counter script.
  How the bands are LAID OUT is the theme's settings, section "Footer"
  (`themes/default/views/layouts/partials/footer.blade.php`), two axes that
  combine: `footer_layout` (columns / compact — no advantages band, less air
  / centred) × `footer_palette` (dark = `.torgash-bar`, light = the page's
  palette, a line above); plus `footer_columns` (equal, 2:1:1, 1:2:1 as
  literal `lg:grid-cols-[…]`), `footer_dividers`, `footer_bottom` (spread /
  centred) and `footer_fold_menus`. The bottom line is ONE line whatever the
  blocks' rows say (`.torgash-footer-bottom`): a block taken out of its row
  once dropped the payment marks to a line of their own. Folding is the
  `columns` menu look's own markup (`data-fold`, `.torgash-fold-toggle`,
  hidden unless a container with `.torgash-footer-fold` asks, below md).
  Folded, the menus are ONE accordion: a single column of faint plates
  (menus that follow one another, or sit in the shop's row, closed up to
  the plates' gap), the WHOLE plate the button (the toggle's `::after`, a
  title that is a link stays a link above it), the chevron with no box of
  its own — a finger left the ghost button's square on it. Unfolded, the
  look lays out by its own width, at most four columns and never more than
  it has: a one-column menu fixed to two left an empty half beside it.
  Tailwind classes in theme.json are compiled (`@source "../../themes"`); ones a
  shop types into a block later are not — that is what "Your own CSS" is for.
- **The logo is one component, `<x-theme.logo :on-dark>`** — the theme's `logo`
  / `logo_on_dark` pictures, or the shop's name with the dot. Headers and the
  signature block use it. **The shop's own CSS** is the theme setting
  `custom_css` (type `code`), printed last by `<x-theme.style>` with `</style`
  neutralised; the shop's own JavaScript goes to Snippets (category
  "Necessary"), never a theme field — a second place for scripts would bypass
  consent.

- **Banners are the values of a TEMPLATE's fields** (`torgash.banners`, PLAN
  §51). A template (`m_banners_templates`) is HTML with `{tokens}` + CSS +
  `fields` (what each token IS: text / rich / image / link / color / select
  / checkbox / icon — `Torgash\Banners\Fields`), written in the admin
  (`banners.templates`, its own right: it is markup on the storefront) or
  restored from a PRESET file. Kind `banner` keeps HTML per SHAPE — `wide`,
  `card`, `strip` — over one set of fields and CSS; kind `frame` draws a
  slider and declares its slot shape. The grammar is CORE's
  (`Placeholders::expandWith()` — the same `{name|mod}` and one-level `[…]`
  that vanish with an empty token; unknown tokens stay visible), plus the
  templates' own `|optional` (empty without taking its fragment away) and
  `|width` / `|height` / `|small` on a picture. Every value is escaped for
  wherever the author put it; a colour is only a real #hex, a select only an
  option, a link only `/…` or `http(s)://…`; a `rich` field keeps
  `RichLine`'s few tags. A `<style>` typed into the HTML moves to the CSS on
  save (in the HTML it would repeat per slide); a `<script>` is dropped —
  scripts are Snippets, where consent is asked. A banner row stores only
  `values` (translatable words as `{en, ru}`) and pictures as media
  collections `f_<key>`; another template's values stay on the row, so
  switching back loses nothing.
- **A template's CSS is printed once per page, before its first use**
  (`PageStyles`, PerRequest) — no file to fetch, nothing on pages without it.
  Each banner is wrapped `<div class="torgash-banner" data-template="key"
  data-shape="…">` so CSS starts with `[data-template=key]`. app.css keeps
  only how the strip WORKS (viewport, track, per-view basis, the no-script
  scroll-snap fallback); how things LOOK is the templates' CSS.
- **Presets are files; the database is the shop's.** The module's
  `presets/` and a theme's `banner-templates/` (parent first, child over it):
  `<key>.json` (kind, label, shape, fields), `<key>.<shape>.html` or
  `<key>.html` for a frame, `<key>.css`. `install()` seeds them once
  (`Presets::seed`); "Restore from the preset" rewrites HTML/CSS/fields/shape
  and never touches banners' values; "Add from the theme" seeds missing keys;
  export/import is one JSON (`torgash: banner-template`), imported under a
  free key. Preset labels are English, read with `label_text()`.
- **A slider is a FRAME around banners; a block places it.** The frame's
  engine tokens: `{slides}` (the server-printed strip Embla moves —
  `Renderer::strip`), `{items}` (all at once, no script), `{dots}`
  (one per stop — fewer than slides when several are in view), `{prev}`,
  `{next}`, `{counter}`, `{progress}`, `{aside}` (the slider chosen "Beside
  it", one level deep). Settings are only how the strip moves (effect,
  autoplay, loop, per view / on a phone). A banner whose template does not
  draw the frame's shape is skipped (and flagged in the slider's form);
  dates and display rules belong to the BANNER, asked with the zone's
  context; nothing running prints '' so the block vanishes. The block type
  `banners` replaced torgash.blocks' `banner` (moved by `install()`).
  Embla Carousel (MIT, `assets/embla/`, fetched only by a page with a
  slider): daisyUI's `carousel` is only scroll-snap classes and cannot loop;
  Splide brings markup of its own. Off-screen slides are `inert` — and only
  they: what is in view comes from Embla's IntersectionObserver a frame
  AFTER it starts (and after every reInit, i.e. a resize), so `slider.js`
  takes it on `slidesInView` whenever the strip is at rest (while it moves,
  `settle` does). Synced only on select/settle, every card in view but the
  first stayed inert — no hover, no click — until the strip first moved.
  Autoplay waits under the pointer and focus and stops in a hidden tab — an
  automated test in a background tab, or in an occluded window that draws
  no frames (no frames, no observer), sees it stand still. A slider's settings are
  shaped in ONE place, `Slider::shapeOptions()` (the template preview uses
  it too): "in view" to a tenth (a fraction peeks), `drag` snap / glide
  (Embla `skipSnaps`) / free (`dragFree`), `step` one / view
  (`slidesToScroll: 'auto'`), `speed` → `duration`; arrows bind
  `disabled` to `canPrev`/`canNext`, so a frame's CSS hides them at an end. A row of blocks aligns
  columns to the top; a column holding banners stretches
  (`:has(> .torgash-block-banners)`).
- **A banner's click is a beacon; its video plays only while seen** (PLAN §51,
  steps 5–6). `banners.js` (pushed once by `Renderer::banner`, which marks
  every banner `data-banner="id"`) sends `navigator.sendBeacon` to
  `banners.click` — outside `web` (no session, no CSRF token), throttled —
  as a link inside a banner is followed; the count is a per-day upsert
  (`m_banners_clicks`), impressions are never counted, and the link stays a
  plain address. A `video` field (mp4/webm judged by its bytes, within the
  media library's `max_file_size`, no conversions) is `{video}`; `slider.js`
  plays it in the shown slide and pauses the rest, `banners.js` plays one
  outside a slider while it is on screen, and neither for reduced motion.
  Exim: entity `banner`, a column per field of the shop's banner templates,
  matched by id (`BannerExim`).
- **Category tiles are a block, not the home template** (2026-10-08). The
  home page's "Shop by category" is `category_tiles` (torgash.catalog,
  `views/blocks/category-tiles.blade.php`) in `storefront.home.content`, from
  the theme's `default_blocks`; an upgrading shop gets it from torgash.blocks'
  `seed_theme_blocks_for_home_categories` (that zone only, only while empty).
  The shop picks and ORDERS the categories through the block field type
  `picks` (an ordered list of ids drawn as `<x-admin.picker ordered>`; an id
  no longer offered is dropped on save) — none picked is the top level. A
  row that scrolls is the browser's own scroll, like the product carousel —
  not Embla, which is torgash.banners' and optional; `assets/tiles.js` only
  adds the thin indicator, arrows and mouse dragging. Every size follows from
  `--tile-pic` and `--tile-gap` (app.css, "Category tiles"). A category has
  TWO pictures: `icon_image` (a sign for a menu line) and `tile_image` (a
  photo for a tile); "auto" takes the tile picture, else the icon. Three
  looks (`under`, `inside`, `corner` — the name on top, the picture large in
  the corner) and two colourings: `plain` (theme tokens) or `tinted`, a hue
  per tile BY POSITION (`--tile-hue`, so neighbours always differ) from which
  the CSS derives `--tile-bg` / `--tile-ink` for light and dark alike.
  Sizes are whole px the shop types (`picture_size`, `gap_size`,
  `text_size`, clamped) and `padding` is CSS shorthand of 1–4 px numbers,
  parsed in the view into `--tile-pt/pr/pb/pl` — anything else (units,
  stray CSS) is the look's own padding, never printed into the style.
- **Sales and articles as home-page blocks** (2026-10-09). `promotion_deal`
  and `promotion_cards` are torgash.promotions' (registered only when
  torgash.blocks is installed), `blog_posts` is torgash.blog's. WHICH sale and
  the minute a timer counts to are `Torgash\Promotions\Showcase`, i.e. the
  model's `status()` / `countdownEndsAt()` — the same answer the sale page
  gives; a block never computes a deadline of its own. The deal with none
  picked is the running catalogue sale that CLOSES SOONEST and has products
  in view — so a shop of daily windows (`schedule`) gets a different deal as
  each opens and closes, and a sale with nothing to count (no window, no end
  date) is never a deal. The cards never show what is over or a sale without
  a page, and order running → paused → scheduled like the sale list.
  Neither block (nor any other) draws a background of its own: colour and
  room are the block's SECTION. A section's tone colours TEXT, which
  inherits — so whatever stands on the page's surface inside a section
  (`.bg-base-100`/`-200`: product cards, the timer's cells) takes
  `--color-base-content` back (app.css, base layer, under the section rules).
- **The small buy card** (2026-10-09, setting `sticky_buy_card`): the product
  page's right column is STRETCHED to the left one's height (still not sticky
  itself — it is often taller than the screen) and ends with a sticky card:
  picture, name, price, buy button, shown only once `#product-buy-panel` has
  left the top of the screen. Its button sends the first form INSIDE the
  panel (`requestSubmit()`), whichever module drew it — one set of controls;
  a form that fails `checkValidity()` (an option still to choose) is
  scrolled to and reported instead. Computer only; a phone has its own bar.

- **A sale on the product page** (2026-10-09) is torgash.promotions'
  `partials/product-note` through `catalog.product.price_extra`: a panel per
  running sale in the sale's own hue (the "Current promotions" cards'
  palette, by id), a tile with its banner or `Showcase::headline()`, "About
  the sale" opening an `<x-modal>` (banner or offer, period, windows in words,
  the shop's description, the way to the sale's page), the time left as
  cells (no "00 days" cell) and — only for a sale dated at both ends — a bar
  of how much of it has gone. A repeating sale has no bar: it would restart
  every night.
  Its LOOK is the shop's (promotion form → Storefront): `tint` (one of eight
  named hues, `Promotion::TINTS`; empty = by id from `AUTO_HUES`, the cards'
  old order, so existing sales kept their colour) and `badge` — `auto` (banner,
  else a short offer, else an icon), `picture` (collection `badge`, not the
  banner), `text` (translatable `badge_text`), `icon` (a Lucide name, kept only
  if the file exists), `none`. `Promotion::tile()` answers it — NOT `badge()`:
  a method named like a column is taken for a relation by Eloquent. A choice
  with nothing behind it falls back to `auto`. The cards block wears `tint`.
- **A form's problems are said beside its button** (`<x-form-problems
  form="id">`, core, 2026-10-12): what the server refused (the error bag)
  and what the browser's own checks stopped (`form-problems.js` catches
  `invalid`, cancels the bubble — it speaks the BROWSER's language and
  pointed under the sticky header) are one list, orange (`--torgash-attention`,
  "something is missing", not the error red). Each line names its field
  (its aria-label, else the legend / label) and marks it `aria-invalid`
  until touched; the first one is brought to the middle of the screen. The
  checkout's button and this box stand OUTSIDE `#checkout-summary`, which
  every re-quote replaces.
- **The phone's header has three shapes** (theme `mobile_header`): `search`,
  `search_mark` (the shop's sign — `mobile_mark`, else the first letter —
  inside the field) and `logo` (logo + magnifier + phone; the magnifier is a
  LINK to the search page that a script turns into "unfold the field"). No
  basket there: the tab bar has it, and a second count would be a second
  `#cart-badge`. **The phone icon opens a sheet from the bottom whose content
  is a ZONE**, `storefront.mobile.contacts` (seeded from `theme.json`: the
  number, "Call", the callback, messengers, email and hours, laid out for a
  thumb). The theme owns the behaviour (the `<dialog>`, its header and how it
  opens), the shop owns what is in it — the desktop contacts block cannot be
  reused there, it draws its own trigger. The icon stays a `tel:` link: an
  empty zone, or no script, dials straight away. Its icons are
  `.torgash-mobile-icon`, a square tinted from the bar's own text colour.
- **The town window's field holds the chosen town** (by GPS too) — the name
  as the value, district and region under it while it stands, selected on
  focus so typing replaces it; no autofocus once a town is chosen (it would
  pull the phone's keyboard over the answer). Its suggestions are in the
  FLOW under the field, and the body scrolls the field to its top when they
  open: placed absolutely inside the scrolling body they were cut off below
  the fold.
- **Zone tabs are a strip of their own** (`torgash.blocks` tabs view): the
  names scroll sideways and never wrap; panels sit below, shown by
  `:has(input[value=N]:checked)` rules in app.css for N < 12.
- **A product carousel has two kinds of arrow** (`side` over the cards,
  `head` small in the row above, or `none`), chosen per block for a
  computer/tablet and for a phone (`ProductBlock::arrowSettings()`, reused by
  the deal block). The row above holds the block's heading and "View all"
  when the carousel is given them, so small arrows share that line.

- The buy panel's small actions (`cart.buy_box.links` — ask, wishlist,
  compare) are ONE row of equal cells, icon over a word that may wrap
  (`.torgash-buy-links` in app.css). The row decides the shape; a module only
  draws a plain `.btn`. Side by side as icon-and-word they never fitted the
  panel ("Add to comparison" hung alone on a second line).
- A catalogue card's "this product asks something first" is the word
  "Choose", not a cart with a gear on it; the video mark carries the word too.
- A dialog opens on its first field (`autofocus`), not on its close button —
  the native dialog focuses the first focusable it finds, which is the ×.

**Error pages are the shop's own** (`resources/views/errors/`, a theme may
override any of them). `errors.frame` draws the message inside
`layouts.storefront` when the request came through the storefront (it has a
session and a resolved storefront), so a dead link keeps the visitor in the shop
with the header, a search and a way home. A server error (500/503), the admin and
a request without a session get `errors.minimal`, which reads no table — drawing
the header asks the database that may be what failed. Actions are LINKS, never
inline script: "back" is the Referer when it is this shop (for a form, the page
it posts to), so they work under a CSP. 419 (a form open too long) says so and
leads back to the form. The storefront is resolved after the CSRF check, so a
419 is drawn plain.

**Loading states: skeletons only where a part arrives AFTER the page.** The
storefront is drawn on the server, so a page never starts as grey boxes (a
request, a layout shift and a promise of content that is already there).
Where HTMX brings a part later: `<x-skeleton-card>` (a card, or `row`) in an
element the trigger names as `hx-indicator`, with class
`torgash-skeleton-when-busy` — hidden until htmx puts `htmx-request` on it,
no script of ours ("Show N more": `partials/listing-skeleton`, as many shapes
as are coming, up to two rows; the blog's "show more" swaps its button for
three via `group-[.htmx-request]`). A region that is REPLACED (reviews'
pages and sorts) dims instead: `torgash-dim-when-busy`. A dialog filled over
HTMX opens at once with a `<template>` skeleton (`htmx:beforeRequest`), not
after the answer — the town window is the worked example. Pictures keep their
box by a fixed aspect (cards are `aspect-square`), so nothing jumps when
they load.

- **"Share" is core's `<x-share :url :title :label>`** (`App\Core\Content\Share`):
  the networks are ONE core setting, `share_networks` (ticks, in the class's
  order), each a plain link to the network's share address; "Copy the link"
  always; on a phone `navigator.share` first. A module never lists networks
  of its own (the blog did, hard-coded).
- **Light / dark switch** is `layouts.partials.mode-switch` — no id, every
  switch kept in step by class; the desktop strip and the phone menu's top
  row both draw it.


## Broken edits on a live shop (PLAN §56, 2026-10-20)

- **A part of a page the page can do without is drawn as a `Fragment`**
  (`App\Core\Content\Fragment::isolate()`): a block, a zone, what modules
  add to the header, the footer, `<head>`, a product card's corner/meta/
  actions. One that throws is left out, the rest prints, the error is
  `report()`ed (and so reaches the guard's journal), and staff — only staff
  — see a red plate naming it with a link to fix it. Positions use
  `hook_render($hook, $context)` (same seed-and-accumulate as
  `hook_filter($hook, '', …)`, each listener isolated); `zone()` goes
  through it; `ZoneRenderer::block()` isolates each block. Under APP_DEBUG
  the exception is thrown on.
  NEVER for a form's fields, a payment method, a price or the cart: leaving
  out a part that matters is worse than an error page.
  **Never catch a view exception by hand**: `View::render()` calls
  `Factory::flushState()` on failure, wiping every section, stack,
  component and loop of the WHOLE page — the layout goes on and prints a
  page without its middle. `Fragment` snapshots that state and puts it
  back; `FragmentIsolationTest` fails without it.
- **A storefront can be CLOSED for visitors** (`storefronts.closed`), which
  is not switched off: its addresses answer with the `closed` view (theme
  overrides `closed.blade.php`) as 503 + `Retry-After` + `noindex`, staff
  (`User::isStaff()`) see the shop with a strip saying so
  (`components.staff-bar` on `core.body_start`), and `?preview=<token>`
  lets one visitor in until a new token is made. `ClosedStorefront` sits on
  the routes `StorefrontRoutes` registers and nothing else, so the admin,
  its sign-in, gateway callbacks, 1C and the API are untouched with no list
  of exceptions. A closed shop's 404 is drawn without the storefront layout.
- **Safe mode** (`App\Core\Support\SafeMode`, `safe_mode()`): the engine's
  `default` theme, no "Your own CSS", no snippets. A member of staff turns
  it on for their own session (Admin → System, POST `admin.safe_mode`);
  `TORGASH_SAFE_MODE=true` turns it on for everybody. A module that prints
  code the SHOP typed asks `safe_mode()` and leaves it out, as
  `torgash.snippets` does. The session flag is read before the roles, so an
  ordinary page pays nothing.

## A customer's language on the account (2026-10-23)

A language is in the address (`/ru/…`), so there is no switch request:
`App\Core\Storefronts\CustomerLocale` (called by ResolveStorefront) counts
moving from one language to another WITHIN a session (the session keeps the
last page's `locale`) as the signed-in customer's choice and writes
`users.locale` quietly; an account with none takes the first it is seen in;
a fresh session on the bare domain changes nothing. After any sign-in (the
`Login` event, whatever door) the next ordinary GET follows the account's
language once (`Alternates::forUrl`), then the customer may switch away.
`User::preferredLocale()` returns it — `Mail::to($user)` writes in it — and a
newsletter campaign's deliveries take it before the subscription's own.

- **One gutter per width, the header's**: 12px on a phone, 16px from `sm`, 24px from `lg` — the main column (`px-3 py-4 sm:p-4 lg:p-6`), the phone header (`px-3 sm:px-4`) and the footer's `$footerContainer` say the same; a full-bleed strip inside the page is `-mx-3 px-3 sm:-mx-4 sm:px-4`.
- **A card on a touch screen** shows the corner's FIRST button (the heart) and "…" opening the rest (`.torgash-corner-more`, `@media (hover: none)`); its price row (`.torgash-card-buy`) is a size container — a narrow card prints the price 16px and a price that still does not fit wraps the button below, never cut.
- **The page switcher** (`pagination::daisyui`) works out its own window: a phone gets first · what is on screen (one "2–3") · last, a wider screen two neighbours each side.
