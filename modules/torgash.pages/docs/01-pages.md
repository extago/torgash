# Static pages

A page is a title, a text and an address: *About us*, *Delivery and payment*,
*Terms*, *Privacy policy*. It lives in the ROOT — `/delivery` — and at
`/ru/delivery` and every other language your storefronts speak, without you
doing anything.

That address is safe by construction: a page is looked up only after every
other route has failed, so it can never take an address away from the
catalogue, the cart or a sale. It gets what would otherwise have been a 404.

## Sections

Put a page **inside** another one and you have a section: an *Information
centre* holding delivery, warranty and terms. What that gives you, with no
further settings:

* the address follows — `/info` and `/info/delivery`;
* **breadcrumbs** on every page below the top;
* a **section navigation** beside the text, listing the section's pages with
  the current one marked (two levels deep; switch it off per page with
  *Section navigation* — a lone privacy policy has no section to show);
* a **sidebar** and an area **under the text**, both ordinary block zones
  (*Appearance → Blocks*): put a banner, a contact panel or the share buttons
  there. An empty zone draws nothing at all, not an empty column.

Moving a page into a section, or renaming the section, rewrites the addresses
below it **and leaves a 301 on every one of them**. Deleting a section does not
delete its pages: they move up into its place and redirect from their old
addresses.

## The address, and why renaming is safe

Leave the **Address** field empty and it is made from the title (a Russian
title is transliterated). It is only this page's own segment — the full address
is the section's plus this one. Two pages under the SAME parent cannot share a
segment; two different sections can each have a `delivery`.

Renaming a page **leaves a 301 redirect behind**. That matters more here than
anywhere else in the shop: the address of a delivery or privacy page ends up in
printed material, in marketplaces' forms and in other people's links, and
tidying it up must not turn all of those into a 404.

## Published, and shown when

Two separate things:

* **Published** is the switch. A draft is not reachable at all.
* **Shown when** is the same set of conditions a content block and a sale use
  — this storefront, this language, this customer group. No conditions means
  everywhere. A page that fails its conditions answers 404 rather than
  rendering: a page describing something the visitor cannot have is worse than
  no page.

**In the sitemap** is separate again, because a published page is not always
one you want indexed — a thank-you page, a landing a campaign links to
directly.

## The text

The editor is the same one product descriptions use, and **placeholders work
here** exactly as they do in an HTML block: `{callback_link}` for the call-back
dialog, the icon tokens, and anything a module registers. What a placeholder
does not know about resolves to nothing, so a page keeps working when the
module that provided it is switched off.

## Linking to it

Pages do not add themselves to the menu — that is **Appearance → Menus**, where
you add an item pointing at the page's address. The 301 above is what keeps
that link working if the address changes later.

## What a page is not

Deliberately, this module has one kind of row: a text page. In older engines
the pages table doubles as links, forms, blog posts and polls; here

* a **link** is a menu item, and menus already do that;
* a **form** is *Enquiries*, which owns an inbox, statuses and notifications a
  page could not;
* a **blog post** and a **poll** are entities with their own lists and feeds.

The page tree is for SECTIONS, not for the site's navigation. The menu still
answers "where can I go from anywhere"; a section answers "where am I, and what
else is here". Do not try to rebuild the whole menu out of nested pages.
