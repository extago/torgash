# Campaigns

**Marketing → Campaigns** — letters to the subscribers: news, a sale, new
arrivals.

## The letter

A subject, a preheader (the grey line an inbox shows beside the subject), a
heading and the text, in the shop's letter frame (colour, logo, footer —
System → Letters), in each reader's language: one language is written per
save, like every text of the shop; a reader whose language the campaign has
no words in gets the base language.

The reader's language is their ACCOUNT's when they have one — the shop
remembers it whenever they switch the storefront's language, on any device —
else the language of the page they subscribed on.

In the text:

- `{product:SKU}` alone in its paragraph — the product's card: its picture,
  name, price and a button. A product the shop does not show is left out.
- `{products:SKU1,SKU2,SKU3}` alone in its paragraph — a grid of tiles with
  pictures: three a row (two for two or four products; `|2` at the end says
  how many). On a phone the tiles stand one under the other.
- `{name}` and `{email}` — the reader's own (the name of their account, if
  they have one). They work in the subject and the heading too.
- Pictures, galleries and HTML blocks — the editor's own buttons, once the
  campaign is saved.

Every letter ends with "Unsubscribe" — the campaign cannot lose it — and
carries the headers mail clients use for their own unsubscribe button
(Gmail and Yahoo require them of anybody sending in bulk).

## Who gets it

Every **subscribed** address; with conditions, those that meet ALL of them:

| Condition | From |
|---|---|
| Language of the letters | the subscriber |
| Where they subscribed | the subscriber |
| Bought / Did not buy (in the last N days) | orders |
| Bought with delivery to a town | orders |
| Has points (at least N) | points |

An order is a purchase unless it was cancelled, declined, failed, merged or
is still waiting for its online payment. The number beside the heading is
counted as the conditions change, and once more when the campaign goes out.

## Sending

**Send a test** — one letter to your address (or the one typed), counting
nothing. **Send now** or **at a time** — the letters go out in the
background, at the pace set in the module's settings ("Campaign letters per
minute": ask the mail server what it allows). A campaign that waits can go
back to a draft; one that is going out can be stopped — the letters already
sent stay sent. Once it is going out, its words stay as they were sent;
**Duplicate** makes the next issue.

The background work needs the queue worker and the scheduler (`schedule:run`
every minute), like every background task of the shop.

## The numbers

Sent, opened, clicked, unsubscribed, not delivered — each reader counted
once — and the links they clicked. Opens are counted by a picture in the
letter: a mail client that does not show pictures is counted only when its
reader clicks a link.

## For developers

A condition is a kind of the registry `newsletter.segments` — answered by the
module that owns the data, narrowing a query of `m_newsletter_subscribers`
(see `Torgash\Newsletter\Campaigns\Segments`). A kind nobody answers any more
sends to nobody.
