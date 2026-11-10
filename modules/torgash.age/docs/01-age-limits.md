# Age limits

Goods only adults may buy — tobacco and vapes, alcohol, goods for adults —
are marked with an age. The module then does two things: it keeps such goods
from a visitor who has not said they are old enough, and it makes every order
of them remind the manager to check a document when it is handed over.

## Where an age is set

- **A category** — on its form, in "Age limit" beside "Who sees it". It holds
  everything under the category: its subcategories and every product in any
  of them.
- **A product** — the same field on the product's form, for a single product
  in an ordinary category.
- **The whole shop** — the setting "Age for the whole shop": a shop of alcohol
  or of goods for adults.

Rules add up and never soften each other: a product in an 18+ category stays
18+ whatever its own field says. The form shows what a product or a category
already gets from above.

Settings → Age limits → "Where an age is set" lists every category and product
with an age in one place.

## What a visitor who has not confirmed their age sees

Chosen in the settings for the whole shop, and for a single category or
product beside its age:

- **Photos covered** — every photo of the goods becomes a plate saying 18+:
  in lists, on the product's page, in the quick view and the basket, in the
  sitemap and in what the page tells search engines and messengers. The
  goods are still sold and found as usual.
- **Not in lists or search** — the goods are not in categories, search, the
  home page's blocks or anywhere else goods are listed. Their own addresses
  still open (with the photos covered), and their category's page asks for
  the age first. Whether the category itself is in the menu is its status.
- **Shown as usual** — nothing is hidden; the age is asked only with the
  order.

The page of such a product, and of its category, carries a strip above it
with the button "I am 18 or older". Pressed, it shows the goods, and the
answer is kept in the browser for 30 days. A plate in a row of other goods —
"you may also like", the products looked at — asks nothing on that page: it
says 18+ itself and leads to the product's page, which asks.

Staff signed in to the admin always see everything; "Sign in as the customer"
shows the shop as a customer sees it.

## The window "Are you 18 or older?"

Off by default. It can ask on the pages of goods and categories with an age,
or on every page until the visitor answers. It is a courtesy, not a check:
anybody can press "Yes". It does not hide the page from search engines.

## The order

An order of goods with an age asks the buyer to tick "I am 18 or older", at
checkout and in one click; without the tick the order is not placed. The
order's page in the admin then carries a card **"18+: check an ID"** — hand
the goods over only after seeing a document that shows the buyer's age.

An order a manager creates in the admin, one placed through the API or
brought from 1C gets the same card, without the buyer's tick.

## What it is not

A tick and a button prove nothing: anybody can press them, and most do so
without reading. The only real check is the document at delivery or at the
pickup point — the module makes sure nobody forgets to ask for it. A check
through a verification service (a bank ID, a document scan) is a separate
step that needs a service chosen for the shop's country.
