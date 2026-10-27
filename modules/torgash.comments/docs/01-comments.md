# Comments

Comments live in one table and attach to whatever a module has registered as
commentable — a page today, a product when the catalogue registers itself. That
is why there is no "page comments" screen and no "product reviews" screen: one
inbox, one set of rules, one place to look when something is wrong.

## Putting them on a page

Comments are a **block**. Go to *Appearance → Blocks*, pick the zone you want
them in (a page's *under the text*, for instance) and add a **Comments** block.
The block works out what it is commenting on from the page it is drawn on, so
the same block under a product would comment on the product.

That is also the switch: no block, no comments. **Read only** keeps what is
already there visible and stops taking new ones — which is the difference from
deleting the block.

## Moderation

Everything arrives **waiting for a check** by default, and the shop is emailed
about it (*Settings → Comments*, or the storefront's own contact address). Leave
premoderation on: a shop that finds casino adverts under its terms of sale
switches comments off for good, which is the same outcome as never having them.

*Comments* in the admin shows what is waiting, with two buttons — publish or
reject — and a reply box. **Answering publishes the question along with the
answer**: half a conversation on a page is worse than none.

A reply is marked as coming from the shop. Threads are one level deep on
purpose; a discussion deeper than that is a forum, and nobody reads a forum in
a page's footer.

## Who may comment

Anyone, unless you tick *Only signed-in customers may comment*. A signed-in
author is always signed with their account's own name — they cannot type
somebody else's — and their email is taken from the account. A guest's email is
optional and is never shown on the storefront.

## Spam

The form carries the same protection as the enquiry forms: a honeypot field, an
encrypted stamp of how long the form has been on screen (a form submitted the
instant it appeared was not typed into), and a rate limit per address and per
person, counted separately from the enquiry forms so asking a question does not
use up commenting. There is deliberately no CAPTCHA — a module can add one by
answering the `core.form.check` hook.
