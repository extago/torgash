# Access control and customers

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- Access control: a module declares its permissions in the manifest, either as
  a bare name or as `{"name": ..., "label": {"en":…,"ru":…}}` — read them with
  `ModuleManifest::permissionNames()` / `permissionLabel()`, never `->permissions`
  directly. Core's own live in `CoreSettings::permissions()`. Admin screens are
  `/admin/administrators` and `/admin/permission-groups` (a spatie role is a
  "permission group" on screen), gated by `staff.manage`; the system screens each have
  their own (`settings.manage`, `storefronts.manage`, `currencies.manage`,
  `languages.manage`, `redirects.manage`, `tasks.manage` — `admin.access` alone
  opens none of them), and a link into one is drawn only for someone holding it; `App\Core\Access\
  AccessGuard` holds the anti-escalation rules and the `admin` super role.
  **Staff vs customer is `User::staff()` / `User::customers()` — "has at least
  one role", never "has the admin role"**: the latter silently puts every
  manager into the customer list and the customer export.
  What a module knows about STAFF reaches the administrators list and card
  through `core.admin.staff.facts` (context `ids`, `[userId => [{key, label,
  icon?, tone? good|bad, hint?, url?}]]`) — the customers list's own seam for
  the other half of the users table; the guard answers it with the second
  factor.
  **The customer screens name no module** (and are gated by core's own
  `customers.manage`, customer groups included): what a customer DID is asked for —
  `core.admin.customers.facts` (context `ids`, one answer for a whole page of
  the list: `[userId => [{key, label, value, hint?, url?}]]`) and
  `core.admin.customer.panels` (context `customer`, a list of
  `{key, position, html}` drawn on the card). Checkout answers both
  (`Checkout\Admin\CustomerOrders`: orders, money PAID — `paid_total`, never
  `total` filtered by statuses — and a link to `orders?customer=<id>`),
  enquiries answers both too (`Enquiries\Admin\CustomerEnquiries`, by
  `user_id` alone — never by email — linking `enquiries?customer=<id>`), and
  the wishlist answers the card (`Wishlist\Admin\CustomerWishlist`, behind
  `wishlist.stats`). **An account is made and deleted here too.** Made with no
  role (that is what makes it a customer); a blank password stores a random
  one nobody knows. Deleting ASKS first: `core.admin.customer.delete_notes`
  (context `customer`, a list of `{key, label, deleted}`) is each owner saying
  what becomes of its rows, printed in the card's dialog before anything is
  touched; orders, enquiries and reviews stay under the name they were written
  with, addresses and the wishlist go. What a module must DO is
  `core.user.deleting` (context `user`), fired by the `User` model for staff
  and customers alike — for a column with no foreign key (reviews' `user_id`
  is set to NULL there; `author_name` already holds the name).

### What a manager's group opens (2026-10-30)

- **A module's admin screens are wrapped in its FIRST declared permission**
  (`can:<x>.manage` around its admin routes, in the provider), and **a
  menu item that names no `can` is gated by that permission**
  (`ModuleManager::adminMenu()`), so the sidebar and the admin search never
  offer a screen that answers 403. A module with no permission of its own
  says `can` on the item (kits and option groups borrow `catalog.manage`);
  `StaffScenariosTest::test_every_module_menu_item_is_gated` fails on one
  that does not. Core's Modules list is `modules.manage` (the system's);
  a module's documentation stays every manager's, since a settings page
  links to it.
- **A permission's ROW is made at install and at upgrade** — and, for a
  manifest that gained one without passing through either (a checkout run
  from git), by `ModuleManager::syncPermissions()` on every
  `CodeChanged::refresh()`: a permission with no row cannot be granted,
  and the dev shop's `orders.manage` was missing for weeks before anyone
  tried to build an order manager's group.
- **`<x>.manage` includes `<x>.view`** (Gate::before in CoreServiceProvider):
  a module that opens its lists to a READER declares `<x>.view` and puts
  the reading routes under it in a file of their own (the catalogue:
  `routes-admin-view.php` — the products and categories lists, a product's
  card, the lookup pickers ask), the rest staying under `.manage`; its
  views hide every control that changes a row behind `@can('<x>.manage')`
  (the catalogue's lists lose the bulk bar, the checkboxes, the status
  menu, "+ New" and the trash; the product's form loses the gear and Save
  and says "Read only"), and the menu items of those lists say
  `can: <x>.view`. A group made before the view permission existed loses
  nothing: its editors read by the rule above. The order manager, the
  marketer and the warehouse of `StaffScenariosTest` hold `catalog.view`
  and nothing more of the catalogue. The permissions stay per module
  otherwise: there is no `orders.view`, and coupons are the order
  manager's (`orders.manage`), not the marketer's.
- `StaffScenariosTest` keeps five groups a shop actually makes — a content
  manager (everything but the system, the staff, the automations and the
  code snippets), an order manager, an SEO specialist, a marketer, a
  warehouse — against the screens each may and may not open and the
  sidebar each sees.

### The account menu (`core.account.nav`)

A module adds a page to the customer's account with one item —
`['label', 'url', 'icon', 'active', 'badge'?, 'quick'?]` — and it appears in
the account sidebar AND the header's account menu (theme
`layouts/partials/account-menu`). The header menu is SHORT by default (theme
setting `account_menu`): only items that say `'quick' => true` (the orders,
the wishlist) and any item with a badge are on top; the rest fold under
"All sections". The profile is the name plate, staff get "Control panel".

### Signing in pauses, and staff HTML is cleaned (audit 2026-10-15)

**Both sign-in forms pause after wrong passwords — core's, always on**
(`App\Core\Access\LoginThrottle`): five a minute per login from one client,
thirty in ten minutes per client whatever logins it tries, refused BEFORE the
password is looked at. The guard's blocking still counts the same `Failed`
events and may shut an address out; this is the floor under it, because a
shop must not need an optional module switched from "watch" to "block" for its
password box to stop answering a guesser. There is deliberately no counter per
account across all addresses: anybody could then lock the owner out of their
own account by guessing at it. A **client** is `App\Core\Support\ClientAddress`
— an IPv4 address, or an IPv6 /64 (one household holds a whole /64, so a new
last group is not a fresh allowance); `FormGuard`, the `Verifier` and the
guard's blocking count by it too. The second factor's own count is in
guard.md.

**admin/admin opens a developer's machine and nothing else**
(`App\Core\Access\InstallPassword`). `CoreSeeder` makes
`admin@torgash.local` / `admin` only under `local`/`testing`; anywhere else it
makes no administrator and says to run **`php artisan torgash:admin <email>`**
(`AdminCommand`: makes the owner or gives the account a new password — typed
twice, or made up and shown once; at least 10 characters and never `admin`).
It is also the way back in when the owner's password is lost. And should a
seeded account survive the move to a live server, both login forms refuse the
password `admin` BEFORE the attempt — whoever the account, so nobody is signed
in and out again and the answer says nothing about which accounts exist; it
counts as a miss for `LoginThrottle`.

**Text the staff write is printed through `safe_html()`**
(`App\Core\Content\SafeHtml`, symfony/html-sanitizer): descriptions of
products, categories, brands and filter pages, pages, articles, promotions,
product tabs, product info blocks. The right to edit content is not the right
to run scripts on a shop whose admin shares its origin and session (PLAN
§50.3): a script in a description ran as the owner and could make itself an
administrator. Kept: the editor's markup, tables, figures, `details`, media,
`class` and `style`, frames from the sites in Settings → Core → Content
(`content_frame_hosts`, plus `core.content.frame_hosts`). Removed: scripts,
`<style>`, forms, every `on…` handler, `javascript:` links and every attribute
nobody listed — which is what removes Alpine's `x-*`/`@…` and HTMX's `hx-*`
too, both of them code on a page that loads them. Cleaned when PRINTED, not
when saved, so imports, the API, 1C and every text already stored are covered;
placeholders are expanded AFTER cleaning, since `{form:…}` and a product card
are the engine's own markup — `safe_html($text, $context)` does both, in
that order. Some permissions are code by design and are not
cleaned — Code snippets, HTML blocks in zones, banner templates, the theme's
CSS, letters: grant them as you would grant access to the server.

**A settings field of type `action` may name a `permission`** (a button on a
module's settings card that starts work, such as `torgash.media`'s "Rebuild all
thumbnails", `media.manage`). The settings screen asks only `settings.manage`, so
the button is hidden from whoever lacks the permission, and its route checks the
same permission itself with `can:`. Hiding alone is not the check.

**Headers and the content security policy** (PLAN §53). `App\Core\Http\SecurityHeaders`
(global) sets nosniff, Referrer-Policy, X-Frame-Options SAMEORIGIN and
Permissions-Policy, adds HSTS over https on a non-development host, and drops
X-Powered-By. A header the server already set is left alone. On every HTML
answer it sends `App\Core\Http\Csp::policy()` as a strict policy:

- `script-src` is this request's nonce plus `strict-dynamic` (a nonced loader may
  load what it loads, so tag managers work), with `unsafe-eval` for Alpine and
  htmx;
- `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'self'`.

The nonce is scoped and forgotten per request. `Csp::sign()` adds it to the
shop's own code (Code snippets) and never touches a tag written inside a
script's text. Reports go to `POST /csp-report`, outside the web group and
throttled, one line per distinct violation a day in `storage/logs/csp-*.log`.
The policy is ENFORCED by default; core setting `csp_mode` (Core → Content)
switches it to report-only or off — for a module written before the rule.
No inline handlers: `public/assets/js/actions.js` gives `data-confirm`,
`data-open-dialog`, `data-close-dialog`, `data-print`, `data-select`,
`data-submit-on-change`, anything else is Alpine `x-on:` (CspTest fails on
an `on*=""` attribute). A `srcdoc` frame runs under its PARENT's policy:
whoever puts fetched HTML into one replaces its nonces with the page's own
(the banner template preview).

A pasted link goes only to the public internet: `App\Core\Http\OutboundUrl`
resolves the host, refuses private addresses, pins the request to the checked
IP, and checks each redirect hop.
