# Speed: where the time goes

The Speed screen (System → Speed) answers one question: **what is this page
spending its time on?** Not "the shop is slow", but "the product page spends
100 ms starting the engine, 100 ms in the database, 35 ms in module hooks — and
the reviews module is 30 of those".

## What is measured

A share of real requests (`Share of requests measured`, 20% by default). For
each one:

* **Engine start** — from PHP starting to the engine being ready. The same on
  every page; large when caches are off or the disk is slow.
* **Database** — every query, its time and its shape.
* **Outside calls** — requests to other services (a payment gateway, a
  carrier's API), with their host.
* **Module hooks** — each module's hook listeners, their OWN time (a hook a
  module fires that another module answers is counted to the one answering).
* **Code & templates** — everything nobody above claimed: the page's
  controller, its templates, PHP itself.

The five add up to the page's total. Everything is written after the page has
been sent, as hourly totals per page — never a row per visit.

## Reading the screen

**Where the time goes** — pages by the time they cost the shop in TOTAL. A
product page opened ten thousand times at 250 ms costs more than a report
opened twice at three seconds, so it is on top. "Typical" is the median, "1 in
20" the 95th percentile: a page that is usually fast but sometimes takes two
seconds shows as a small "typical" and a large "1 in 20". The bar is one
typical visit split into the five parts — hover it for the numbers.

**Modules** — what each module's hooks add to a page they run on, and how much
of that is database. A module near the top with most of its time in the
database is asking for data it could ask for once per page.

**Asked again and again (N+1)** — the same query repeated while ONE page was
built, with the line of code that asked. This is nearly always a loop fetching
one row at a time. If "asked from" is a template (`view: …blade.php`),
something on the page loads its own data per item — the fix is to load it for
the whole list before the loop.

**The most expensive queries** — query shapes by total database time. One
slow query shows as a high "slowest"; a cheap query asked everywhere shows as
many runs.

**Timelines** — pages slower than the threshold keep a full timeline: every
query, outside call and hook in order, on one time axis.

## Trace my pages

The button at the top measures every page YOU open for ten minutes, whatever
the sample, and keeps each one's timeline. Press it, open the page that feels
slow in another tab, come back — its timeline is at the top of the list.

It is keyed by your address (it has to be decided before a session exists),
so anyone behind the same address is traced too for those minutes. That costs
them nothing.

## What it costs

An unmeasured request: one setting read and one check per query and per hook.
A measured one: a few milliseconds of bookkeeping and four statements written
after the response has gone. Switch it off in the module's settings and even
the bookkeeping stops. Old hours go after `keep_days` (30 by default), every
night.

## What it does not measure

What happens in the BROWSER — images, scripts, layout shifts (Core Web
Vitals). Those arrive from visitors' browsers, which means a public endpoint
accepting data, and that is a separate, guarded piece of work.
