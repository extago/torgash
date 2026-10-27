# Newsletter

Collects the addresses of people who want the shop's news and offers.
Sending campaigns from the shop comes later; for now the list goes to a
mailing service as a CSV.

## Where people subscribe

- **The "Newsletter subscription" block** — put it in any zone (the footer,
  the home page). Drawn by the theme, or by **your own HTML**:

  | In your HTML | What it is |
  |---|---|
  | `{form}` | the field and the button — required |
  | `{heading}` `{text}` `{button}` | the block's own words, in the visitor's language |
  | `{privacy_link}` | "privacy policy" as a link (the module's setting), or nothing |
  | `{subscribers}` | how many are subscribed |

  and every shop placeholder (`{shop_name}`, `{phone}`…). Your own HTML is
  written by those who may write raw HTML in blocks.
- **Under the basket** — a small form (setting "Offer to subscribe on the
  basket page").
- **At checkout** — the tick "Subscribe to news and offers" (setting); the
  order's email is subscribed when the order is placed. The tick is not on
  from the start unless you choose so — a tick put there for the customer is
  not their consent.
- **In the customer's account** — "Newsletter", a switch.

## Confirmation

Every new address gets a letter with a link; it counts only once the link
is followed ("Confirm every address by a letter", on by default). Not asked
again: a signed-in customer whose address is already confirmed. The link
opens a page with a button — a mail service's scanner opens every link in a
letter by itself and must not confirm anybody. The letter's words are on
Admin → Letters, "Newsletter: confirm the subscription".

The form is guarded like the shop's other forms: a trap for bots, a limit
per address and per visitor, a CAPTCHA if the guard module asks for one.

## Unsubscribing

`/newsletter/unsubscribe/<token>` — a page with a button, no sign-in. Letters
about orders still come.

## Marketing → Subscribers

The list by status (subscribed, waiting for confirmation, unsubscribed),
search, where from. **Download CSV** gives what the screen shows. **Import**
takes addresses one per line or the first column of a CSV, as subscribed —
only addresses whose owners agreed elsewhere; somebody who unsubscribed here
stays unsubscribed.

## Personal data

A subscriber is in the shop's personal-data registry: exported with the
evidence of consent (when, from which address, what was asked) and erased on
request.

## For developers

`newsletter.subscriber.changed` (`subscriber`, `from`, `to`) — for a mailing
service's connector or an automation.
