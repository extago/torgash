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

## Which products go in

The **Which products** card of a product profile. Every field left empty
takes everything; together the fields narrow, so a product must pass all of
them.

- **Statuses** — active alone by default, which is what the catalogue lists;
  tick hidden or disabled to take them too.
- **Availability** — the shop's availability statuses (in stock, preorder, out of stock, its own): the product's is one of the ticked ones.
- **Price from / to** in the shop's currency, **stock from / to**, and the
  three switches: only what can be ordered now (the availability status
  allows it and, where it tracks stock, there is some), only with a
  description (in any language), only with a photo (its own, or by address
  while the shop allows pictures by address).
- **In categories / not in categories** — lists of ticks with a search by
  name, each with or without the subcategories. Nothing ticked on the first
  means every category.
- **Wearing a label** — at least one of the ticked ones.
- **Characteristics** — any number of conditions on the shop's
  characteristics: *equals*, *is not*, *is one of* (comma-separated), *is
  none of*, *contains*, *does not contain*, *is greater than*, *is at
  least*, *is less than*, *is at most*, *is empty*, *is set*. A value is
  compared as text in every language and case; the number comparisons read
  the value's leading number, so "128 GB" is 128. *Is not*, *is none of*,
  *does not contain* and *is empty* also pass a product that has no such
  characteristic at all. With two or more conditions you choose whether a
  product must pass all of them or any of them.
- **Also take these SKUs** — products named by SKU, either *as well as* the
  categories and characteristics above (the default) or *only those of them
  that also pass* the above. **Never take these SKUs** wins over everything.
- **Stop words** — a product mentioning any of the words in a ticked field
  (name, short description, description, SEO title and description), in any
  language, is left out: `refurbished`, `display sample`.

The card says how many products match right now; the number is counted
when the form opens, so it is of the last save.

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
