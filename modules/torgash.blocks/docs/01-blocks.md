# Blocks and zones

A **zone** is a named position a storefront template exposes — "the header,
after the search box", "the homepage, above everything". A **block** is a piece
of content placed in one. Modules declare their zones in `manifest.json`, the
active theme in its `theme.json`, and Blocks is where a shop owner fills them.

Each zone can be switched off as a whole without touching what is in it, so
turning it back on restores exactly the arrangement that was there.

## Restoring what the theme meant

A theme can ship content for its own zones (`default_blocks` in `theme.json`) —
the contacts dropdown in the default theme's header is one. That content is laid
out **once**, when the shop is installed, and never touched again: an upgrade has
no business dropping a block back into a zone somebody has arranged.

That guard is right and it left two things impossible, which **Blocks →
Restore from the theme** now fixes:

- an owner who took a zone apart could not get it back except by reading the
  theme file and rebuilding it by hand;
- a theme that adds `default_blocks` in a **new version** never reached an
  installed shop at all — the one pass that would have laid them out is already
  marked as run. Same reason a **second theme's** zones come up empty.

The screen lists every zone the active theme furnishes and, for each one, what
the theme ships against what is in there now. Two buttons, and they mean
different things:

- **Add what is missing** — the safe one. Blocks the theme ships that are not in
  the zone arrive at the end; everything already there stays exactly where it is,
  in its order, with its settings. Matched by type *and* title, so pressing it
  twice adds nothing the second time, and a theme block you have **edited**
  counts as present — you have it, you changed it, and a pristine second copy is
  not what the button says.
- **Replace the zone** — the zone goes back to exactly what the theme ships, in
  the theme's order, and everything currently in it is deleted. Which is why the
  confirmation lists what will be lost **by name** first, and says which of those
  are yours rather than the theme's: those are not in the theme file and will not
  come back.

### Whose block is it

Blocks laid out from a theme file are marked as the theme's; anything you add is
yours. That is the whole reason the mark exists — without it "replace" cannot
tell the theme's own header logo from a banner you spent an afternoon on, and a
confirmation that cannot tell you what it is destroying is a shrug with an OK
button on it.

Blocks that were seeded before the mark existed were matched up by zone, type and
title when it was added. The matching is best-effort in the safe direction: a
block edited beyond recognition stays *yours*, so at worst the confirmation warns
about something it need not have.

### Display rules

A restored block gets only the rules its theme file describes, and nothing
otherwise. No rules means "always show", which is the honest reading of a theme
that said nothing about when its block should appear — inventing a condition here
would hide the block for a reason nobody could find on the screen.

## Tabs are blocks standing side by side

There is no "tabs" block. Give several blocks in a zone the same word in **Show
as a tab** and, where they stand next to each other, they are drawn as one row
of tabs — each tab named by its block's heading. "Popular / New arrivals / On
sale" on the home page is three ordinary blocks: reorder them, switch one off,
or give one its own display rules, and the row follows.

- A block that prints nothing (a sale with nothing in it) is not a tab.
- A row left with one member is drawn as a heading over its content.
- A different word, or a block with no word, between them starts a new row.
- The tabs are plain radio buttons, so they work with no JavaScript.

## Products

The **Products** block type comes from the catalogue. It shows products from
one source — the whole catalogue, a category (always with its subcategories), a
label, a yes/no filter such as "On sale", a running promotion, or SKUs typed by
hand — in an order you pick (or the source's own: typed order for hand-picked
products, the shop's default sort otherwise), as a grid or a sideways-scrolling
carousel.

A source that no longer means anything — a deleted category, a label switched
off, a promotion that has ended — shows **nothing**, never the whole catalogue
under the old heading. A grid can show the first few and reveal the rest with a
"Show more" button; "View all" links to the source's own page where it has one.
