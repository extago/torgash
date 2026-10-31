# Automations (torgash.automations)

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

## Automations (torgash.automations)

A rule is trigger → delay → conditions → actions. Triggers are HOOK NAMES, not
an invented event registry (`checkout.order.changed` and friends, extended via
`automations.triggers`); an action is a class behind `automations.actions`, so a
vendor-specific integration stays thin — the engine owns the trigger, delay,
conditions, retries and the run log. Conditions have the same shape as the
shipping module's (`kind`/`operator`/`value` + `describe()` in words) but their
own code: those interrogate a cart, these a placed order, and automations must
not depend on a module a digital shop uninstalls.

Non-negotiables, each with a test: conditions are re-checked when the rule
actually RUNS (after the delay the order may have been paid or cancelled); the
`Idempotency-Key` is minted once per trigger so all three attempts carry the
same one; a webhook URL is refused when any of the host's addresses is
loopback/private/reserved and redirects are never followed (`Actions\Ssrf` —
169.254.169.254 is the cloud metadata endpoint); and the body template is NOT
Blade — `Torgash\Automations\Template` substitutes dotted paths and repeats
`{{#order.items}}…{{/order.items}}`, escaping values for JSON, because
compiling admin-typed text would make the rule editor a way to run code.

**Rules do not chain.** An internal action edits the order, which announces a
change, which is a trigger — so while a rule runs, no rules are queued
(`RuleGuard`, and `RunRule` flushes `OrderChanges` inside that window). Depth
counters were the alternative; this one fits in a sentence. An action that
changes the order goes through the shop's own code, never its own copy:
`set_status` calls `Torgash\Checkout\Orders\OrderStatusChanger`, which is what
the admin's own status select calls. **An exported rule template carries no URL
and no header value** — both are always shop-specific, the import asks for them
by name, and the imported rule arrives disabled.

**A rule is about a SUBJECT, and the event says which** (`Subjects\Subject`,
registry `automations.subjects`, answered by whoever owns the data): this module
registers `order` itself, `torgash.enquiries` registers `enquiry`
(`Enquiries\Automations\EnquirySubject`) — its events, its conditions DECLARED
as data (`kind => label, operators, input: list|amount|categories|text|csv|none,
options, bare`), its variables grouped for the picker, `refs()` for the run log.
The rule stores nothing new (`Rule::subjectKey()` reads the event); the form
switches conditions, actions and variables as the event changes; `sync()` and
template import keep only what the rule's subject understands. Subjects are
asked in `app->booted()`, since boot order is not ours. An action says which
subjects it can act on (`Action::subjects()`, [] = any — webhook, Telegram) and
may DECLARE its settings (`HasFields`: a status, a manager), which is how a
module adds one without touching this form; one used on the wrong subject is
refused in the run log, never guessed at. The run log carries `subject` +
`subject_id` (`order_id` kept for order runs). Enquiry events are hooks the
module announces anyway, plus two it gained for this: a status change announced
by the MODEL (`enquiries.enquiry.status_changed`, every door alike) and
`enquiries.enquiry.overdue` — `Enquiries\Overdue`, every five minutes, by the
inbox's own `reply_within_hours`, ONCE per wait (`overdue_announced_at` compared
with when the wait began, so the customer writing again re-arms it).

**Telegram is an action of its own, not a webhook**, only because the bot token
is part of the Bot API's URL: as a webhook it would sit in the URL field, the
run log and every export. Here it is a credential — never drawn back into the
form (a blank box keeps it, matched by the action row's id), masked in the log,
asked for by name on import. Text goes through `Template::render(..., HTML)`
with `parse_mode=HTML`: the admin's tags stay tags, a customer's `<b>` stays
text. Variables for a PERSON exist beside the machine ones (`total_formatted`,
`status_label`, `admin_url`), and the picker is a card beside the form that
inserts where the cursor was — its braces are built in JavaScript, since Blade
reads a literal pair in a template as its own echo (it did, twice).

- **A message links what it names (2026-10-31)**: `order.admin_url` is the
  order's card, `item.url` the product's own address on the storefront and
  `item.admin_url` its card in the admin ('' once the product is gone —
  `Context::forOrder` looks the lines' products up in ONE query). Written as
  `<a href="{{ item.url }}">` in a Telegram text: values are HTML-escaped,
  and an address escapes to itself.
  A line also says what the product IS (`Context::line`): `item.image_url`,
  `item.variation` ("black, 256 GB" — the answers on the group's variation
  axes), `item.features.<CODE>` (a characteristic by its code, the shop's
  rows only — the product's own free-text rows have no code) and
  `item.features_text`, `item.brand`, `item.weight` (grams),
  `item.options_text` and, for a part of a configured product,
  `item.option_label` / `item.is_part`. The template has no nested loops,
  so lists are joined into one string. Products are loaded in ONE query
  with their media and characteristics, never one per line.
