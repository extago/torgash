# Reviews

A review is one person's verdict on one product: a mark, what they liked, what
they did not, and whether they would recommend it. The module owns the form,
the moderation queue, the average printed beside the product, and the criteria
a shop rates by.

## Who may write one

**Never a guest.** An account is the cheapest honest barrier there is: it costs
a customer a minute and costs a review farm the only thing it has, which is
volume. It is also what lets the shop answer "who wrote this" a year later.

**Buying is a MARK, not a gate.** Somebody who bought the product here gets a
"Verified purchase" badge, which is what a reader actually believes. Everybody
else still has a voice, because people buy by phone, as a present, or in a shop
down the road — a strict shop ends up with fewer reviews than it has customers.
Switch *Only customers who bought the product may review it* on if you want the
stricter rule anyway.

**One review per person per product.** A second opinion is an edit of the
first, not a second row.

## The criteria

*Reviews → Rating criteria* is the shop's own list of what people are asked to
rate — "Sound", "Fit", "Battery life". A criterion with no categories is asked
about every product; attach one to a category and it is asked there **and in
everything under it**, because in this engine a category always means itself
and its subcategories. That is why there is no "include subcategories" box to
tick.

With criteria configured the overall mark is the average of what the customer
filled in (they may skip what they cannot judge). With none at all, the form
asks for a single overall mark instead.

Deleting a criterion keeps the scores people already gave it: they did give
them, and tidying a list must not rewrite what somebody said. It simply stops
being asked and stops being printed.

## Moderation

Reviews arrive **waiting for a check** unless you switch *Check reviews before
publishing* off. The screen opens on that queue rather than on everything, and
the tabs say so.

* **Publish** puts the review on the page and into the average.
* **Reject** takes it back out — the same act in reverse, and the record of
  what was said stays.
* **Reply** publishes the shop's public answer *under* the review, and
  publishes the review with it: an answer to a complaint nobody can read is
  worse than either half alone. A shop that does not want the complaint
  published rejects it instead of answering it.
* **Delete** is for what should never have been stored at all — a phone
  number, an address, a real name somebody typed into a public box.

## What the shop gets for free

* the mark under the product's name and on every card in a listing, primed in
  one query per page;
* `aggregateRating` in the product page's structured data, printed only when
  there is something behind it;
* **Best rated first** in the catalogue's sort list — tick it in *Catalog*
  settings, since a module never adds a storefront control by itself. Products
  with no reviews keep their place at the end rather than disappearing.

## Rewards for reviewing

This engine awards nothing by itself. The *Line above the form* setting prints
whatever the shop wants to promise (bonus points, a discount code) above the
form, and the shop decides who earned it — which is the right place for that
decision while nothing here can read a review and judge it.

## Photos in a review

Settings → Reviews → **Photos a review may carry**. 0 turns them off.

A photo is published with the review it came with, so a review waiting for
moderation shows nobody its pictures — you do not moderate photographs
separately, and the answer to an unwanted one is to reject the review.

Only images are taken, checked by what the file really is rather than by its
name, and resized by the media module's own settings. A photo that will not do
is reported and the review is still saved: the words are the valuable half.

## "Was this review helpful?"

Every published review carries a pair of buttons and a count. Only signed-in
readers can answer — a vote counted per visit is one a script can cast in a
loop, and a ranking that can be bought is worse than none. One answer per
person, changeable, and pressing the same button again takes it back. Nobody
votes on their own review.

Readers can then sort by **Most helpful**, which counts the "yes" answers
alone: subtracting the "no" ones would let a group bury a review by voting it
down. The other orders are newest, highest rated and lowest rated. The control
works without JavaScript.

## Reviews in the API and in a file

The API resource is `admin/reviews`. It lists reviews and the shop's answers
together — a customer's answer waits in the same moderation queue — and
`?only=reviews` narrows it. Publishing or rejecting goes through the shop's own
moderation, so the product's average really does follow; answering is an
action, not a field, so the answer is badged, the review is published and the
person who wrote it is emailed.

Import/Export takes a **Review** profile. The export is the whole list; the
import is for the one job a shop has once — bringing its reviews over from the
engine it is leaving. Rows are matched on **External ID**, so running the file
twice updates rather than doubles. Three things a file cannot do: mark a review
as a verified purchase (that is worked out from this shop's own orders and is
what readers believe most), set the helpful counts (they are about this shop's
readers), or carry photographs.
