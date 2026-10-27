# Reviews (torgash.reviews)

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **`torgash.reviews` is a mark, not a comment thread.** A review is about a
  PRODUCT in a column, never a target registry like `torgash.comments` — every
  idea that makes it a review (the criteria, the verified purchase, the average
  on a card) is a product idea, and a registry here would have one member and
  still be unable to answer "did this person buy it". Never a guest: `user_id`
  is NOT NULL, because an account is the cheapest honest barrier and is what
  answers "who wrote this" a year later. Buying is a MARK (`verified`, worked
  out at submission and frozen), not a gate, unless the shop ticks
  `buyers_only` — a strict shop gets fewer reviews than it has customers. One
  review per person per product, a PARTIAL unique index because the shop's
  replies live in the same table under `parent_id` and share both columns; a
  reply publishes the review it answers, since an answer to a complaint nobody
  can read is worse than either half. **What is rated is the shop's own list**
  (`m_reviews_criteria` — a TABLE, because a criterion's name is translatable
  and settings are one-language), attached to nothing = everywhere, attached to
  a branch = everything under it, with no "include subcategories" switch, for
  the reason stated above. Deleting a criterion keeps the scores given for it
  (no FK). The average is a ROW per product (`m_reviews_aggregates` — a module
  may not put columns on the catalogue's table, and uninstalling must take the
  number off the cards), recomputed on publish/reject/delete and primed for a
  whole page off `catalog.products.listed`. The page section is registered
  through `catalog.product.tabs` (the first outside use of that seam), the mark
  through catalog's new `catalog.product.card_meta` / `catalog.product.title_meta`,
  `aggregateRating` through `catalog.product.structured_data`, and "best rated
  first" through `catalog.sorts` — registered but not offered until the shop
  ticks it, the flags' rule. The stars are RADIO buttons (daisyUI `rating`), so
  the form works with no JavaScript at all. This engine awards nothing for a
  review: `reward_note` prints the shop's own promise above the form.
- **A review's photograph is PUBLIC, and that is the one thing it does not
  share with an enquiry's attachment.** `Torgash\Reviews\Photos` follows
  `Enquiries\Attachments` in everything else (no endpoint of its own — it
  rides the review's POST; images only through `ImageProcessor`, which sniffs
  the mime from the bytes; a refused file never costs the review), but the
  disk is `public` like a product's gallery, because the picture is published
  under the review on the product page. Its moderation is the REVIEW's: a
  photo is only ever drawn with the review it belongs to, so a pending review
  shows nobody its pictures without a line of code saying so. Not a
  `MediaOwners` registration — that registry is for galleries an admin edits,
  and the answer to an unwanted photo is to reject the review.
  **"Was this helpful" is a row AND a counter** (`Reviews\Votes`): the row
  stops one person voting fifty times and lets them change or withdraw it (the
  button is the state, not a counter), the counter is what "most helpful"
  orders by, since a subquery per row is the N+1 a paginated list refuses.
  Signed in only — a vote per session is one a script casts by clearing a
  cookie — and never on your own review. **The ranking counts YES votes
  alone**: subtracting the noes would let a brigade bury a review by voting it
  down. The order lives in `Reviews\Listing`, one class for the section, the
  HTMX fragment and the API, closing every order with `id`; the control is
  LINKS (`?reviews=`), so it works with no JavaScript. A review's status moves
  only through `Reviews\Moderate`, because publishing and rejecting both move
  the product's average. Importing reviews is why `user_id` is nullable: a
  review that was not written here has no account and answers "who wrote this"
  with `author_name` + `external_id`; the public door still demands an
  account, and an import may never set `verified` or the vote counts — those
  are this shop's own findings about its own orders and readers.
- **The list a reader narrows** (2026-10-09). `Listing::state()` reads the
  WHOLE state from the address — the order (`reviews`, as before) and
  `review_stars`, `review_period` (30/60/365), `review_buyers`,
  `review_photos`, `review_shown` (whole pages of setting `per_page`, capped)
  — and `Listing::params()` writes it back, defaults left out; anything
  unknown is the default. Every control is a LINK carrying a state, the page
  address for no JS and `reviews.list` for HTMX (it replaces the list with
  itself), "show more" included. Stars bucket as `Review::stars()` rounds, so
  a filter and the spread bars agree. Thresholds are the shop's: filters from
  `filters_from` reviews (always while one is in force), the customers'
  photo strip (`photo_strip`, from two photos, published reviews only), and
  the most helpful praise (≥ 3.5) beside the most helpful complaint (< 2.5)
  only from `highlights_from` reviews AND with a yes vote behind each — "the
  most helpful of three" is a coin toss. "Nobody has reviewed it yet" is an
  empty list with NO filter in force, never a filter that left nothing.
- **Variations share their reviews** (2026-10-09, setting `share_variations`,
  on by default; older engines usually have it
  the other way round). A review stays on the product it was written about
  (`product_id` is what was bought and what `verified` was worked out
  against); only the READING is the group's. `Reviews\Group::ids()` is the one
  answer — the product alone, or every member of its `variation_group`, the
  bin included — and `Listing::published()`, `Aggregates::recalculate()`
  (which writes the SAME row onto every member, so cards, "best rated" and
  `aggregateRating` need no idea of groups), `Eligibility::hasReviewed()` (one
  review per person per model) and the invariant all ask it. The rows move
  when a group does, with no review moving: `Product::created/updated`
  (`variation_group`), `forceDeleted`, and the setting switched
  (`core.setting.changed` → `recalculateAll()`, also the admin's "Recount").
  A review about ANOTHER member says which ("About the variant: Black, 128 GB",
  `Group::labels()` — the group's axes, from `Variations::for()`).
- **A review's photographs open in the shop's viewer** — torgash.media's
  `lightbox.mjs` (`storefront: true`) through `assets/photos.mjs`: ONE
  delegated click listener (the list is swapped by HTMX), the group being the
  nearest `[data-review-photos]` (one review, or the strip). The link is the
  stored file with `data-width`/`data-height` from `Gallery::dimensions()`, so
  without JS — or with a modifier key — it still opens in a tab.
