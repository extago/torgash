# Connecting 1C

In 1C, create an exchange node with a site and give it three things:

    Адрес сайта:        https://your-shop/commerceml
    Имя пользователя:   anything
    Пароль:             an API token

The address `https://your-shop/1c_exchange.php` answers exactly the same — a
decade of 1C configurations, articles and support scripts have that path
hard-coded, and a shop moving from another engine should be able to paste in the
address it already has.

## The old address, and the one file this module writes

That second spelling needs a file to exist. Web servers hand a `.php` address to
PHP only when the file is really there and answer 404 otherwise, so the request
never reaches the shop's own code no matter what its routes say — the one
address the module cannot help being asked for is the one it would never hear.

So installing this module writes `public/1c_exchange.php`: eleven lines that
correct `SCRIPT_NAME` and hand the request to the shop's front controller.
Uninstalling deletes it. That is the only file this module puts outside its own
directory, and it is deleted by content, never by name — if something else is
already living at that address (the engine you are migrating away from, most
likely) it is left exactly where it is and the exchange screen says so.

The screen shows the state of the address and can put the file back or take it
away. Take it away if you would rather write a rewrite rule of your own:

    # nginx
    rewrite ^/1c_exchange\.php$ /index.php last;

## Why a token and not a password

This credential goes into a settings file on somebody's accounting machine and
stays there for years. A person's password is the wrong thing to put there: it
cannot be revoked without locking that person out, it grants everything they can
do for ever, and it is one screenshot away from being everyone's.

So the password field takes an **API token** (Admin → API tokens). It must be
allowed to write, and its owner must hold "Set up the 1C exchange". Revoking it
stops the exchange and nothing else. The user name is ignored — 1C insists on
one, the token already says who this is, and making them agree would only invent
a way to fail.

## What happens on the wire

Worth knowing, because nothing about it is guessable and every symptom looks the
same from 1C's side:

1. `?type=catalog&mode=checkauth` — the shop answers `success`, a cookie name
   and a cookie value. That value opens a **conversation**: it addresses the
   directory the files will land in.
2. `?mode=init` — the shop says whether it can unzip and the largest chunk it
   will take. 1C splits bigger files itself.
3. `?mode=file&filename=import.xml` — one chunk per request, **appended**.
   Images arrive the same way, under `import_files/`.
4. `?mode=import&filename=import.xml` — a batch of products, then `progress`.
   1C asks again. And again, until the shop answers `success`.

That last step is why there is no queue worker in any of this: the protocol was
built to be sliced, and an exchange that depended on a worker nobody started
would say "in progress" for ever. If the import times out, lower **Products per
request** in the module's settings.

Every answer is a bare word on the first line and a message on the second. If 1C
reports a failure with no explanation, something in front of the shop is
answering instead of it — a maintenance page, a login redirect, a proxy error.
The exchange log (Admin → 1C exchange) shows what actually arrived.

## The first exchange

Set **Products → Nothing — receive and record, change nothing** before the first
run.

That first exchange then writes no products at all. What it does do is fill the
matching screen with everything 1C sent — its price types, its characteristics,
its variants — so you can see the real vocabulary of your own 1C before it is
allowed to touch anything. Then match what should be matched, turn the strategy
up, and run it again.
