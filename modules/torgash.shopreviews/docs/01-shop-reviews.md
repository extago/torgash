# Shop reviews

Reviews of the **shop**, not of a product: how the order went, the delivery,
the service, the site. Product reviews are a separate module (`torgash.reviews`).

## The page

`/reviews` (and `/ru/reviews`, `/uk/reviews`…) shows, from published reviews only:

- the average out of 5, the stars and how many reviews;
- the share who **recommend the shop** and who **will buy here again**;
- the average of every criterion (Reliability, Website, Delivery, Products, Service);
- how many reviews gave 5, 4, 3, 2 and 1;
- **Our rating on other sites** — Google, Hotline, a marketplace (see below);
- the reviews themselves, newest first, with the shop's answer under each.

The numbers are cached and recounted whenever a review is published,
rejected, deleted or answered.

## Putting it in a menu

The page is offered to every menu as **Shop reviews** (Menus → an item of kind
"Page"). To put it in the top strip beside the language switcher, as many
shops do:

1. Menus → create a menu, for example "Top strip: right", and add the page to it.
2. Blocks → zone **Header — top strip, before the language and currency** →
   add a **Menu** block showing that menu, look "A row of links (the header strip)".

The item can be renamed ("Reviews") and given an icon like any other. If the
module is uninstalled, the item disappears by itself.

## Writing a review

The **Leave a review** button opens the form: a mark for each criterion (the
overall mark is their average; with no criteria switched on, one mark is
asked), "I would recommend this shop", "I will buy here again", pros, cons,
the text, a name and a town. A guest also gives an email — it is never shown,
the shop's answer is sent there.

Settings → Shop reviews:

- **Who may write a review** — anyone, or signed-in customers only;
- **Check reviews before publishing** — on by default;
- which questions are asked (pros and cons, recommend, buy again, town);
- how the shop's answers are signed, and where new reviews are reported.

A signed-in customer who has an order here gets the mark **Customer of the
shop**. A guest never does: anybody can type somebody else's email.

The form is protected like every public form (hidden trap, pace limits); a
CAPTCHA can be switched on for it by name in the guard's settings.

## Moderation

Marketing → Shop reviews: tabs Waiting / Published / Rejected / All with
counts, search, filters, ticking several at once (or everything found).
The status badge changes the status in place. **Answer** publishes the
answer under the review — and the review with it — and emails it to the
author (once; editing the answer later sends nothing). Emptying the text
takes the answer away.

**Add a review** types in a review written somewhere else — on Google, by
phone, on the old site — with its own date and source ("From Google" is
printed beside it). It is published at once.

## Criteria and other sites

- **Rating criteria** — rename, reorder, switch off or add. Switching one off
  stops asking it; scores already given stay.
- **Other sites** — the shop's mark on Google, Hotline and so on, typed by
  hand with a link to where a reader can check it. Nothing is fetched from
  those sites; keep the numbers current.

## Blocks

- **Shop rating** — the average, stars and count with a link to the page, for
  a footer or a narrow column. Draws nothing until the first review is published.
- **Shop reviews** — the latest reviews as cards with the average, for the
  home page; can be limited to reviews rated at least N.

## Personal data

Exporting a person's data includes their shop reviews (found by account or
email). Erasing keeps the review on the site, signed "Customer", and removes
the name, town, email and address.
