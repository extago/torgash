# Files and feeds

An export profile is one file: which data, which columns, which format. You
can download it from the admin, or give its address to whoever fetches the
file — a CRM, a wholesaler, a marketplace.

Data exchange → Export profiles.

## A feed for Yandex Market

Make a profile for products in XML, built from **Its own template**, and pick
"Yandex Market (YML)" under **Start from a ready template**. Delivery terms
in it are an example — change them to yours.

Characteristics go into `<param>` lines: the shop's characteristics first,
then the product's own rows (the **Individual attributes** card). An own row
called like a characteristic the product already has a value of is left
out — a marketplace takes two lines with one name for a mistake.

To send the shop's characteristics only, put the line inside `{{^own}}`:

```
{{#item.params}}{{^own}}<param name="{{ name }}">{{ value }}</param>{{/own}}{{/item.params}}
```

## Keeping the file current

Two ways, on the **Updating the file** card. Either, both, or neither.

**By schedule.** Pick from the list, or type a cron expression yourself —
five fields: minute, hour, day of the month, month, day of the week:

| Expression | When |
|---|---|
| `0 3 * * *` | every day at 03:00 |
| `*/30 * * * *` | every 30 minutes |
| `0 9-21 * * 1-5` | every hour from 9:00 to 21:00, Monday to Friday |
| `0 6,18 * * *` | at 06:00 and 18:00 |

The next three builds are shown under the field as you type.

**When its data changes.** Every five minutes the shop looks whether what the
export reads — products, categories, characteristics, prices, photos, stock —
has changed since the file was built. Nothing changed, nothing is built. If
something did, the file is built again, but not more often than the interval
you choose: fifty edits in an hour make two builds, not fifty. A visit to a
product page is not a change.

A schedule is worth keeping beside it: some changes leave no trace in the
data — a promotion that starts at midnight.

**Neither** — the address builds a fresh file each time it is asked.

## The feed address

With a schedule or the change rule on, the address hands out the last file
built, at once, however often it is asked. Files are built in the
background: System → Background tasks lists every build and why it ran, and
the profile says when the file was built, how many rows it has, its size and
how long it took. Only the newest file is kept.

None of it happens unless the server starts the shop's scheduler every
minute — the command is shown on the profile.
