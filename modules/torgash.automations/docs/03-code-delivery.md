# Code delivery

**Customers → Code delivery.** A verification code — signing in, getting
back into an account, confirming a phone — goes by email out of the box.
Here it gets other ways to travel: an SMS gateway, Telegram, any service
that takes an HTTP request. Not a module per provider: a channel is one
request the shop composes, with the webhook's own machinery.

The customer is offered every switched-on channel that can reach their
address, in the order of **Position**, smallest first. Every code sent is
on **Customers → Verification codes**.

A phone becomes something a customer can sign in with only once some channel
can carry a code to a phone. Until then the shop's setting **A customer
account must have** falls back to email, and the account's phone row is not
drawn.

## A new channel

**+ New channel** offers ready fillings: **TurboSMS**, **Telegram Gateway**
(the code arrives from Telegram's own "Verification Codes" account) and
**My SMS gateway** — a blank to fill. A filling only writes the form for
you and is forgotten: a provider changing its API is fixed by editing the
channel, not by an update.

**General:**

* **Name** — the customer sees it on the button: "Send the code by …".
* **Delivers to** — phone, email or both.
* **Only to these countries** — country codes, `UA PL`. Empty — any. A paid
  channel should name its countries: a form that sends codes on demand is
  what gets pointed at premium numbers abroad.
* **Codes a day, at most** — past it the channel stops offering itself until
  tomorrow. A send the provider refused outright (a wrong number, a country
  it does not serve) is not counted: it was not charged.

**Request:** **Method**, **Address**, **Headers, one per line**, **Body**
(JSON, form fields or no body), and **Secrets, one per line: name=value**.
In the address, the headers and the body:

| Placeholder | What it is |
|---|---|
| `{{ phone }}` | the number, digits only |
| `{{ phone_plus }}` | the number with its `+` |
| `{{ email }}` | the address |
| `{{ code }}` | six digits |
| `{{ text }}` | the whole message, in the customer's language |
| `{{ secret.name }}` | a value from the secrets box |

Secrets are stored encrypted and never shown again: an empty value keeps the
saved one, a line removed removes the secret. They are masked out of every
error the log keeps.

**Answer rules** — how the provider's reply is read, one rule per line:
`path.in.the.answer = value`, several values with `|`.

* **Sent, when ALL hold**
* **Wrong number, when ANY holds** — the customer is told the number cannot
  be right.
* **Cannot reach it, when ANY holds** — the customer is offered another way.

A reply other than 2xx is never a success.

## Trying it

* **Check the connection** — the **Free check request (GET)**: a request
  that proves the key works without sending a message, such as the balance.
* **Send a test code** — one real message. It costs what the provider
  charges and counts against the day. Save the form first: the test uses
  what is saved.

A switched-off channel is not offered to customers; its own test still
works.

## What it refuses

Before anything is sent: a private or reserved host (the same rule as every
webhook), an address outside the channel's countries, a landline for an SMS,
and a channel that has used up its day. A code is sent at once, not from the
queue: "that number cannot be right" has to reach the customer while they
are looking at the screen.
