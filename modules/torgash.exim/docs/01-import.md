# Import

Data exchange → Import takes a CSV, XML or YML file — your own export coming
back, a supplier's price list, another engine's catalogue — and writes its
rows into the shop: products, categories, characteristics and the rest of
the list.

## Changing many products at once

Names, addresses, descriptions, categories, prices, stock, characteristics:
for a hundred products a file is quicker than a hundred forms.

1. Export the products with the columns you are going to change (an export
   profile in CSV), or write the file yourself: a `sku` column and the
   columns to change.
2. Change the cells in a spreadsheet and save it as CSV.
3. Import it. A row finds its product by **External ID**, then by **SKU**.
   Only the columns the file has are written; everything else stays as it
   was.

A changed address keeps the old one as a redirect, as the product form does.
A row whose SKU matches no product makes a new one — unless **Create what is
not found** is off, and then it is skipped. A new product needs a name: a row
without one is skipped and named in the result, and the rest of the file goes
in.

## Columns

Each column of the file goes to one of the shop's fields, or is skipped. The
screen guesses: a field's own name — its key (`sku`, `name_ru`,
`price_units`) or its title as the export writes it ("Name (RU)") — what
other systems call it ("Product code", "Артикул", "VAR Feature 2 [Color]"),
a characteristic by its name. A text goes to one language, or to every one
at once ("All languages").

A column can carry a **formula** that changes its value on the way in:
`ending($value * 1.2, 90)` turns a purchase price into a price ending in 90,
`col("Закупка")` reads another column. A row the formula cannot work out
keeps that field as it was, and the result says which lines.

## Presets

How a file was read — where its columns go, their formulas, the delimiter and
the options below — is kept as a **preset** under the name you give it. The
next file with the same columns (four in five are enough) is read the same
way without setting anything up.

## Check, then import

**Check before importing** shows what the import would do: how many rows
create, update or skip a record, the first hundred of them, and the lines a
formula or a missing name stops. Nothing has changed yet. **Import** then
runs in the background: Background tasks shows it, with its counts and notes
("line 12: category created"). Photos given as links are downloaded
afterwards by a task of their own.

An upload waits under **Unfinished imports** until it is imported or deleted;
one left alone for a week is deleted with its file.

## From the console

The same import without the screen — for a script, or a file that comes every
day:

    php artisan exim:import prices.csv --dry-run
    php artisan exim:import prices.csv

The columns are matched as the screen matches them, by their names or by the
preset saved for them, and the rows are written by the same background task,
listed under Background tasks. A column that goes to no field stops it:
rename the column, save a preset for the file on the screen, or add
`--skip-unknown`. A formula that cannot be read stops it too.

| Option | |
|---|---|
| `--dry-run` | What **Check before importing** says; nothing is changed |
| `--preset="Supplier"` | Read the file by this preset (its name or number) |
| `--no-preset` | Match the columns by their names alone |
| `--skip-new` / `--create-new` | Rows that match nothing: skipped (a price list) / created |
| `--images=missing` | Photo links: `empty` (only products without photos), `missing`, `replace` |
| `--entity=category` | Not products; `--fields` beside it lists what that takes |
| `--fields` | Every field and the column names it is read from |
| `--user=you@example.com` | The import is this staff member's, in Background tasks and the audit trail |
| `--queue` | Hand it to the queue worker instead of running it in the console |

Without `--user` the import is nobody's, and leaves no audit trail — as
nothing done from the console does. How a file is read (delimiter, encoding,
the XML record) and its formulas come from its preset: set them up once on
the screen, then run the file from the console.
