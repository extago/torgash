# Collections

A collection is a page of products gathered by conditions — the catalogue's
own filter, saved — with its own address, cover, text and dates.
"Summer for the cottage", "Everything Apple under 30 000", "Gifts for him".

## Which products

The same words as the catalogue's filter panel:

- **Categories** — any of those picked, each with everything under it.
- **Characteristics** — values of any characteristic, the brand among them.
  Values of one characteristic: any of them; several characteristics: each
  must match.
- **Labels** — any of those picked, as the cards show them: the products a
  label was put on by hand and the ones its rules give it to ("New" for a
  fresh product, "Hit" by sales). A label switched off gives nothing. A
  label with a "discount" rule counts only its hand-picked products — for a
  discount use "On sale" from the filter.
- **From the filter** — the filter's yes/no boxes: in stock, on sale, and
  whatever a module adds there.
- **Price** from and to, in the shop's currency.

Everything filled in must hold at once. **Products picked by hand** are added
to what the conditions find; **products left out** never appear. With nothing
filled in and nothing picked the collection is empty — never the whole
catalogue by accident. The form shows how many products match now.

Conditions are worked out when the page is opened: a new product that fits
appears in the collection by itself.

## The page

`/collections/<address>` — the cover and the description, then the
catalogue's own listing of the collection's products, with its filters (only
what can narrow this set), sorts and pages. `/collections` lists every
collection that is shown and in the list.

- **Shown** and **Shown from / until** — outside them the page answers 404.
- **In the list of collections** — off, the page still answers: for a
  banner, a menu item or a letter to point at.
- **For search engines** — the page title and meta description; empty, the
  name and the start of the description. Shown collections are in the
  sitemap.

## Looks

A look is a picture of a room or an outfit with points on the products in
it. On the collection's form, under "Looks": add pictures and save, then
click a picture to put a point and choose its product (a point without a
product is not kept); a caption and a position per picture. On the page the
looks stand above the products: a point opens a small card of its product —
its photo, name, price, "More about this product" and, with the basket
module, "Quick view". A point on a product the storefront does not show is
not drawn. Points are kept in percent, so they stay put at any width.

## Blocks

- **Collections** — tiles with the cover and name: the collections you pick,
  in your order, or every listed one.
- **Products of a collection** — any product block: as its source choose
  "Collection" and the collection.
- **Looks of a collection** — the pictures with their points, anywhere (the
  home page): choose the collection.
- A menu item can point at the list of collections.
