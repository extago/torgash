# Options

What a product offers beyond itself: which processor, how many power supplies,
an extended warranty, installation. A choice can be a **service** priced by a
formula, a **product** with its own stock, or a whole **set of products** to
pick from.

## An option and its choices

An option is a group: a name, a hint, whether choosing is required, and how it
is shown.

| Shown as | What the customer sees |
|---|---|
| One of, as a dropdown | a `select` |
| One of, as a list | radio buttons |
| Any of, tick boxes | checkboxes |
| A quantity for each choice | a number beside every choice |
| Rows you add, each with a quantity | "Add…" — as many rows as needed |
| The customer types a line | a text field |
| The customer types a paragraph | a text box |

**Choosing is required** is the tick that sometimes has to be on: without an
answer the product does not go into the basket, and the form says what is
missing.

**Counted per item** is the difference between two options that look alike: a
processor is per server (two servers, twice the processors), an installation is
charged once for the line however many are bought.

**An option the customer types** — an engraving, a name on a shirt, a note for
the workshop — is charged by its FIRST choice: name it "Engraving", give it a
price, and that is what the words cost. With no choices at all the words are
free and still go to the order. "Longest text allowed" refuses in the form
rather than at the till, and what was written is part of what was bought: two
engravings are two lines, the same words twice are one line of two.

A choice is one of three kinds:

* **A service or an extra** — a name and a price. Nothing comes off a shelf, and
  the money is inside the line's price. This is what a warranty, an engraving or
  a licence is.
* **A product** — named by SKU or id. Its name, photograph, link and
  availability are the product's own, and in the order it becomes **a line of
  its own**, so the warehouse, 1C and a return all deal with the goods they
  know. A product that cannot be bought right now is not offered as a choice.
* **A set of products to pick from** — a category with everything under it, a
  label, a filter. Each product in the set behaves as the kind above once it is
  chosen, and only a product that is still in the set can be bought.

**A choice's product can go, and nothing will say so.** A choice whose product
has been disabled or deleted is simply not drawn — which is right: an answer
nobody can buy is not an answer. But when EVERY answer goes, the question is not
drawn either, and a required question is checked all the same: the customer
presses Buy and is told to choose something that is not on the page, so the
product stops being buyable at all. Hence two statements under System →
Invariants: *every required question has an answer that can be bought* (a
refusal) and *every answer that is a product has a product behind it* (a
warning).

## What an answer costs

The price is a rule, never a second price list:

| Rule | What it adds |
|---|---|
| A fixed amount | the amount, in the currency it was typed in |
| A percentage of the product price | e.g. +10% of the product |
| An amount plus a percentage | the owner's warranty: 500 + 10% of the product |
| Multiply the product price | ×1.15 — charged as the difference |
| The linked product's own price | what that product costs, optionally ±% and ± an amount |
| Free | nothing |

Every rule is measured against **the product's own price before any option**, so
the order of the options never changes the money and one multiplier can never
be applied to another. An amount typed in another currency is kept as typed and
follows that rate exactly as a product's price does — the books keep the derived
number.

## Where an option applies

An option written **for one product** is edited on that product, in its Options
tab.

A **shared** option is placed by conditions — "everything in Servers", "every
product with this characteristic" — and a category always means everything under
it. A shared option is never re-priced per product: its price is a formula
read against whatever product it is shown on, which is what makes it shareable
at all. If one product needs a different number, use **Copy into this product**:
the copy is its own option, the shared one stops being shown there, and what
is on screen afterwards says what it costs.

A product can also switch a shared option off — **Do not ask it here** —
for the one item in the category that genuinely has no such option.

## When an option is asked

A question can be made to wait for another one: **When it is asked → Only after
this option is answered**. "Memory — only with a processor."

Until the option it waits for has been answered it is not on the page at all.
That is the whole of it, and it is deliberately the same answer everywhere: it
cannot be required of anybody, an answer given to it earlier is dropped rather
than kept, and nothing is charged for it. So a customer who picks memory and
then changes to a processor with the memory soldered on is not billed for a
module that is not in the machine and is nowhere on screen.

Leave **…and only these answers count** empty and any answer will do, which is
the usual case. Tick some and the option appears only for those — a Windows
licence only with an Intel board.

Options may wait in a chain (processor → memory → ECC), and each link goes when
the one before it does. They may not wait for each other in a circle: the form
refuses to write one, because two options that quietly vanish from every product
are impossible to explain.

A shared option may only wait for another shared one — it is asked on thousands
of products, and one particular product's question means nothing to it.

## In the cart and in the order

The cart shows **one line with its contents**: "Server ×2" and under it the
processors, the licences, the installation. "Change this build" reopens the
product page with those answers in it; adding it again merges into the same
line, because a line's identity IS its answers.

The order has more lines than the cart. Everything that is a product becomes its
own line, marked as part of the line it was chosen for; a service stays inside
the price of its line and is named there. The two descriptions always add up to
the same money.

## The price on the page

It is worked out by the server on every change, by the same code that prices the
cart line. Nothing about a price rule exists in JavaScript, so a page can never
quote a number the cart then disagrees with.

## The price in a listing

A product that cannot be bought without answering something is quoted **from**
its cheapest build — its own price plus the cheapest answer to every question
marked *Required*. That is what a card, the product page, the feed and the
structured data all print, because a price nobody is able to pay is not a
price, and a feed quoting one disagrees with the page it links to.

Only *Required* questions count. An optional extended warranty leaves the price
alone: the product really is buyable without it.

Where a required question offers a whole set of products, the cheapest one
**that can actually be bought** sets the price — something out of stock is an
answer the shop would refuse anyway.

Beside a "from" price the shop's old, struck-through price is not shown: it is
what the bare product used to cost, and setting it against the cheapest build
would claim a discount that was never given.

In an export profile the field is **Price to buy (with required options)**,
beside the plain **Price**. A price feed wants that one.

## Changing a build after the order is placed

Open the order, press **Edit items**, then **Build** on the line. The same
questions appear, answered as the customer answered them, and **Apply** puts
the new build on the order.

The line stays the line it was — it keeps its number, its warehouse history and
anything already pointing at it — while the parts change with the answers: a
16 GB module swapped for a 32 GB one goes back on the shelf and the new one
comes off it, in one step.

What it costs: the product keeps the price this order sold it at, so "10% of
the product" still means 10% of what was agreed. A newly chosen part is priced
as it is today. Either way the unit price field on the row is still yours to
type over.

A choice the shop has switched off since is refused here too — the back office
cannot sell what the shop no longer sells. A part listed under a line is not
configured on its own: change it on the line it belongs to.

## A file the customer sends

Set the question's type to **The customer uploads a file** — a layout for a
business card, a logo to engrave, a photograph to print. It is priced exactly
as a typed option is: by the first choice in the group, or free if it has none.

Per question you can set which **formats** are taken and the **largest file**;
leave them empty to use the shop-wide settings. The format is checked against
what the file really is, not against its name, and executable or script formats
are never accepted whatever you type.

The file is kept off the public web: nobody can reach it by guessing an address.
You open it from the order, and the customer opens their own from the page they
sent it on.

Files travel with the order. One nobody bought is deleted after the number of
days in the settings, so an abandoned basket does not fill the disk; a file that
reached an order is kept for as long as the order is.

## Options in a spreadsheet

Admin → Import/Export takes four profiles for this: **Option group**, **Option
choice**, **Kit** and **Kit slot**. Export a question and its answers, change
what you need in a spreadsheet, and import it back — a shop with the same
warranty on two hundred products reprices it in one file.

Every row has a **Code**: a short stable name that identifies it in the file.
You do not have to invent one — a row that arrives without it is given one —
but if you type your own, it is kept, including when you rename the question.

Nothing in the file refers to a row by its number. A question names its product
by **SKU**, an answer names its question by **Code**, a kit slot names its
characteristic by the characteristic's own code. That is what lets the same
file be imported into another shop and still mean what it meant.

Import the groups before the choices, and the kits before the slots: an answer
whose question is not there yet has nothing to attach to. A reference the shop
cannot resolve — an unknown SKU, an unknown code — leaves that row as it was
rather than moving it somewhere wrong.

Amounts travel as you typed them, in minor units, with their currency beside
them. The shop's own books are worked out on import, so a file made in one
shop keeps its meaning in another.
