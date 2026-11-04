# The template language

An export profile built from **its own template** writes the file around the
shop's data: a marketplace feed (Yandex Market, Avito, Google), a CRM's XML,
a price list in any shape. The template is text with tags in it. The
language is a subset of Liquid, the one Shopify's merchants write their
themes in, so a feed written for Shopify reads the same here.

Nothing in a template runs as code. A tag can print a value, test it, walk
a list, keep a result under a name and run it through the engine's own
filters. It cannot call the shop, read a file, or reach anything but the
data it was handed. A template that does too much work on one row is
stopped and told so, with the line.

## Values

```
{{ item.sku }}
{{ item.name.ru }}
{{ item.price | price }}
{{ shop.name }}
```

Inside the rows block a product is `item`: a column is `item.<key>`, a text
in one language `item.<key>.<language>`, a price type's own price
`item.price_type.<CODE>`, a characteristic `item.feature.<code>`. A product
feed also offers `item.id`, `item.url`, `item.available`, `item.category_id`,
`item.price`, `item.old_price`, `item.brand`, `item.pictures` (a list of
addresses) and `item.params` (a list of characteristics with `name`,
`value`, `code`, `own`). A column the template names is read even when it is
not ticked in the profile.

Outside the rows: `profile.name`, `profile.generated_at`; for products
`shop.name`, `shop.url`, `shop.company`, `shop.currency`, `shop.currencies`
(`id`, `rate`), `shop.categories` (`id`, `parent_id`, `name`).

An element of a list by its number: `item.pictures.0`, or by a value:
`item.name[shop.locale]`. A name that is not there is simply empty.

Every value is escaped for the file's format, so a quote in a product name
cannot break an XML or JSON file. `| raw` prints it as it is, `| cdata`
wraps it in `<![CDATA[ … ]]>`; in a plain-text file nothing is escaped
unless you ask with `| escape`.

## Filters

A filter changes the value before it is printed; several go in a row, left
to right. An argument comes after a colon; several are separated by commas.
An argument may be a value too: `| default: shop.name`.

```
{{ item.description | strip_tags | squish | truncate: 3000 | cdata }}
{{ item.name | remove: item.brand | trim }}
{{ item.weight_g | divide: 1000 }}
```

| Filter | What it does |
|---|---|
| `price` | a price per unit as a number: 7199000 → 71990 |
| `money` | minor units as an amount: 1250 → 12.50 |
| `divide: N`, `multiply: N`, `plus: N`, `minus: N`, `modulo: N` | arithmetic (also `divided_by`, `times`) |
| `round: N`, `ceil`, `floor`, `abs`, `at_least: N`, `at_most: N` | rounding and bounds |
| `number: N` | written with exactly N decimals |
| `strip_tags` (`strip_html`), `squish`, `strip_newlines` | HTML out, spaces made one, line breaks out |
| `truncate: N`, `truncatewords: N` | at most N characters / words |
| `lower` (`downcase`), `upper` (`upcase`), `capitalize` | case |
| `trim` (`strip`), `lstrip`, `rstrip` | spaces at the ends |
| `replace: "a", "b"`, `replace_first: "a", "b"`, `remove: "a"` | text replaced (case matters) |
| `append: "text"`, `prepend: "text"` | text added after / before |
| `slice: from, length` | a piece of the text (0 is the first character; -3 the last three) |
| `split: ","` | the text cut into a list |
| `join: ", "`, `first`, `last`, `size`, `reverse`, `sort`, `sort: "field"`, `uniq`, `compact` | lists |
| `map: "field"`, `where: "field", "value"` | one field of every record; only the matching records |
| `default: "text"` | this text when the value is empty |
| `date: "d.m.Y"` | a date in this form (PHP's letters) |
| `url_encode` | safe inside a web address |
| `cdata`, `raw`, `escape` | how the value is printed |

Arithmetic hands on a number, so `| divide: 1000 | plus: 1` adds to 0.171,
not to "0.171". A number prints with up to four decimals; `number: N` for
exactly N.

## Conditions

```
{% if item.old_price > item.price %}
<oldprice>{{ item.old_price | price }}</oldprice>
{% endif %}

{% if item.feature.condition contains "как новый" %}
<Condition>Как новый</Condition>
{% elsif item.feature.condition contains "б/у" %}
<Condition>Б/у</Condition>
{% else %}
<Condition>Новое</Condition>
{% endif %}

{% unless item.available %}<Stock>0</Stock>{% endunless %}
```

A condition is an expression: `==` (or `=`), `!=`, `<`, `>`, `<=`, `>=`,
`contains` (a text holds the words, a list holds the value — case does not
matter), `and`, `or`, `not`, brackets, `+ - * /`, `&` to join text, and the
functions below. Two numbers compare as numbers, anything else as text
without regard to case. Nothing, false, 0, an empty text and an empty list
count as false.

The functions: `if(test, then, else)`, `empty(x)`, `default(x, other)`,
`contains(text, part)`, `starts_with`, `ends_with`, `len(x)`, `lower`,
`upper`, `capitalize`, `trim`, `left(text, n)`, `right(text, n)`,
`replace(text, a, b)`, `concat(a, b, …)`, `split(text, ",")`,
`join(list, ", ")`, `first(list)`, `last(list)`, `round(x, n)`, `roundup`,
`rounddown`, `ending(x, 90)`, `min`, `max`, `abs`. The same language is the
language of a column's formula on import.

Filters are not written inside a condition: keep the result under a name
first (`{% assign n = item.set | split: "," | size %}`), or use a function
(`len(item.pictures) > 0`).

## Lists

```
{% for p in item.pictures limit: 10 %}
<picture>{{ p }}</picture>
{% endfor %}

{% for o in options %}<Option>{{ o | trim }}</Option>{% endfor %}

{% for c in shop.categories %}
<category id="{{ c.id }}"{% if c.parent_id %} parentId="{{ c.parent_id }}"{% endif %}>{{ c.name }}</category>
{% endfor %}
```

Inside the loop `forloop.index` counts from 1 (`forloop.index0` from 0),
`forloop.first` and `forloop.last` say where you are, `forloop.length` how
many there are. `limit: N` takes the first N, `offset: N` skips N,
`reversed` turns the list round; `{% break %}` leaves the loop,
`{% continue %}` skips to the next element; `{% else %}` before `endfor`
prints when the list is empty. `(1..5)` counts from 1 to 5.

## Names

```
{% assign model = item.name | remove: item.brand | trim %}
{% assign memory = item.feature.memory | upcase %}
{% if memory == "1 TB" %}{% assign memory = "1024 ГБ" %}{% endif %}
<Model>{{ model }}</Model><MemorySize>{{ memory }}</MemorySize>

{% capture description %}
{{ item.name }} — {{ item.brand }}. {{ item.description | strip_tags | squish }}
{% endcapture %}
<description>{{ description | truncate: 3000 | cdata }}</description>
```

`assign` keeps a value under a name, `capture` keeps everything printed
between its tags. A name lives on from one row to the next, so a counter
across the rows is `{% assign n = n | plus: 1 %}`.

## One of several

```
{% case item.feature.condition %}
{% when "new" %}<Condition>Новое</Condition>
{% when "used", "worn" %}<Condition>Б/у</Condition>
{% else %}<Condition>?</Condition>
{% endcase %}
```

A map of "this → that" — the shop's colours as a marketplace names them —
is the same `case`, the shop's names in `when`, the marketplace's in
`assign`; case does not matter in the comparison. Names the map does not
mention keep theirs in the `else` branch:

```
{% case item.feature.color %}
{% when "Титановый синий", "Титановый чёрный" %}{% assign color = "Черный" %}
{% when "Синий" %}{% assign color = "Голубой" %}
{% else %}{% assign color = item.feature.color %}
{% endcase %}
<Color>{{ color }}</Color>
```

## The profile's variables

The card under the template holds values the template reads as
`vars.<name>`: the delivery cost, whether there is pickup, the name of the
company for this marketplace. Whoever changes a price edits the card, not
the XML.

```
<delivery-options><option cost="{{ vars.delivery_cost }}" days="{{ vars.delivery_days }}"/></delivery-options>
{% if vars.pickup %}<pickup>true</pickup>{% endif %}
```

## Comments and raw text

`{% comment %} … {% endcomment %}` is left out of the file; `{{! … }}` the
same, on one line. `{% raw %} … {% endraw %}` prints its tags as text.

## Whitespace

A tag that stands alone on its line takes the line with it, so a condition
or a loop leaves no blank lines behind. `{{-`, `-}}`, `{%-` and `-%}` eat
the spaces and line breaks beside them.

## The rows

What stands between `{% for item in rows %}` and `{% endfor %}` — or
between `{{#rows}}` and `{{/rows}}` — is repeated for every row, and the
rows are streamed: a feed of fifty thousand products is written row by row,
never held whole. The block must stand at the top level of the template,
not inside a condition. While streaming, `forloop.length` and `forloop.last`
are not known.

## Limits

A template may nest tags 32 deep, count a range up to 10 000, take 500 000
steps and print 8 MB for one row. Past any of these it is stopped and the
error names the line: a loop inside a loop over big lists is the usual
cause. A mistake in a tag refuses the form when the profile is saved; a
value that cannot be worked out while the file is built (a division by
zero) stops the build with the line.

## Templates written before

The tags of the earlier language still work: `{{#item.pictures}} … {{ . }}
… {{/item.pictures}}` once per element, `{{#item.old_price}} … {{/…}}` only
when there is a value, `{{^item.brand}} … {{/…}}` only when there is none.

## What a template does not do

A template describes one row. What is counted across the whole file — the
categories that actually have products in the feed, the first pictures of
each — is a value the shop works out and hands to the template, not
something the template gathers. If a feed needs such a value and there is
none, that is a request for the engine, not a longer template.
