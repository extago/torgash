# SEO

Every page of the shop already says something about itself without this module:
the title is the product's name, the description the first sentences of its
text, and the address, the languages and the structured data are the engine's
own. What this module adds is the part a person would otherwise do a thousand
times by hand — writing that text once and letting it apply to a whole
catalogue — and a screen showing what is still empty.

## Where the fields live

The metadata itself belongs to the product, the category, the brand and the
page, on their own edit forms under **SEO**. That is deliberate: a meta
description is a property of the product. It goes out in an export, it comes in
over the API, and it stays exactly where it is if this module is uninstalled.

Each of them has:

* **Page title** — what goes in the browser tab and, usually, in the blue line
  of a search result. Empty means the shop uses the name.
* **Meta description** — the text under that line. About 150–160 characters.
* **Heading (H1)** — the heading in the page itself, when it should differ from
  the title. Empty means it is the title.
* **Canonical address** — fill in only when this page is a copy of another one.
* **Keep out of search engines** — the page stays open to anyone with the link.

## Templates

*SEO → Templates* is where one sentence is written for many pages. A template
says three things:

* **Pages** — products, categories, brands or pages.
* **Where** — everywhere, or one category. A template written for a category
  also governs everything under it, and **the nearest one wins**: a rule for
  "Laptops" beats the shop-wide rule for every laptop, and a rule for
  "Gaming laptops" beats both.
* **When** — *only where nothing is typed in* (the default, and the safe one:
  anything a person wrote survives), *always* (for a catalogue whose imported
  titles are worse than the template), or *do nothing here* — which is how one
  category opts out of a shop-wide rule.

### What goes in a template

Anything in braces is filled in per page:

```
{product} — {category|lower} | {storefront}
```

`{product}`, `{category}`, `{category_path}`, `{brand}`, `{min_price}`,
`{max_price}`, `{total_products}`, `{storefront}`, `{currency}`, and
`{feature:ram_gb}` for one characteristic by its code. The full list, with what
each one means, is under the fields on the template form.

Two things worth knowing:

* **`|lower`, `|upper`, `|ucfirst`** change the case: `{category|lower}`.
* **Square brackets** around a token disappear *with their own text* when the
  token is empty. `{product}[ — {feature:color}]` prints the dash only when the
  product has a colour, so nothing ever reads "Laptop — ".

A token nobody registered is left on the page exactly as typed, so a misspelt
one is visible rather than silently blank.

### Photographs

The same template row carries **Image alt text** and **Image title**. They are
used only for photographs nobody captioned in the gallery — a caption typed by
hand always wins, unless the template is set to *always*. `{image_number}` is
blank for the first photograph, so `{product}[ {image_number}]` reads "Laptop",
"Laptop 2", "Laptop 3".

The alt text is what a reader who cannot see the page is told about the picture.
It is the field a busy catalogue never gets round to, which is why it is here.

## What is still empty

*SEO → What is still empty* lists every page of one kind next to what it says
about itself. Pick the kind, filter to the ones without a title, and either type
over what you see or tick the rows and press **Fill the ticked rows from the
template**.

Each empty box shows, in grey, what the page is printing meanwhile — the
template's answer where one applies, or the shop's own. Filling in writes the
text onto the record itself, so it stays if this module is removed.

Typing beats the template on the same submit: if you type a title into a row and
also tick it, what you typed is what gets saved.

## What this module does not do

Addresses, redirects, `robots.txt`, `sitemap.xml`, hreflang and the structured
data are the engine's, not this module's — they work with it uninstalled and are
configured in *Settings → Store*. Analytics counters are `torgash.snippets`.
