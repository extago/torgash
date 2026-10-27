# API (torgash.api)

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- The API (`torgash.api`) is one controller over a REGISTRY: a module declares
  what it exposes with `ApiResources::register('products', [...])` — query,
  serializer, sortable columns, filters, `lookup` keys, an optional `update` and
  named `actions` — and paging, sorting, permissions and error shapes are
  applied from one place. Registration is answered lazily through the
  `api.resources` hook, because provider boot order is not ours to depend on.
  Auth is our own `m_api_tokens` (sha256 of the token, `abilities` narrowing it
  below its owner's rights); a call is an ordinary logged-in user, so every
  `can()` is the admin panel's. A status moves through
  `actions/status` → `OrderStatusChanger`, never a PATCH of the column: the
  column would look like it worked and move no stock. Storefront and language
  come from `X-Storefront` / `Accept-Language`.
- A resource may declare `create` (`fn (array $data): Model`, described by
  `creatable` / `create_description`) — `POST /api/v2/admin/{resource}` —
  for the few rows the shop's own code makes on request (a gift card is
  issued; an order is never written in, it is placed).
- `/api/v2/shop/*` is PUBLIC, exactly as the storefront's HTML is (only GET is
  routed for resources; the cart's token IS its authorisation). Its checkout is
  a conversation with the cart — `PATCH .../checkout` MERGES answers onto the
  `checkout` jsonb, `GET` prices the delivery for the address given so far,
  `POST .../checkout/complete` re-derives everything and places the order.
- The API's description is WRITTEN FROM the registry (`Torgash\Api\OpenApi` →
  public `/api/v2/openapi.json`, page `/admin/api/docs`), never kept beside it:
  a hand-written spec is a second source of truth about the first. So a module
  documents itself in its own `ApiResources::register()` — `description`,
  `schema` (what the serializer answers with), `writable` (what a PATCH takes),
  and the prose form of a filter/action (`['apply' => fn, 'description' => …]`,
  normalised in `register()`, so read a filter as `$filter['apply']`). Declare
  nothing and the page says "an open object" rather than inventing fields.
  Endpoints that are not resources (cart, checkout) are described in `OpenApi`
  and extended through the `api.openapi.paths` hook.
- A PATCH that writes RELATIONS resolves them before it writes anything and
  runs in one transaction: `categories` (the whole list; a number is an id, a
  string an `external_id`) refuses the call on one unknown key, and
  `variation_group` refuses a product that would duplicate a member on every
  axis (`Variations::for()['duplicates']`, checked after the save, inside the
  transaction). The admin tolerates such a pair and paints it; an exchange has
  nobody to look, so it gets a 422 naming the other product.
- A product is served with BOTH stores of its characteristics, each under its
  own name (catalog.md, "Two stores"): `characteristics` — the shop's, by
  code, with `value_code` for a picked value (the shop half: what the page's
  specifications show, in the request's language; the admin half: every one,
  every language, and the purpose) — and `features`, the product's own rows.
  `features` alone was served until 2026-10-27, and an agent reading it took
  the own rows for the characteristics. Eager-loaded in the resource's
  `query`, never per row.
