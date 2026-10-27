# Place matching

CDEK addresses everything by its own integer **city code**: the points of a
city, a price from one city to another. So each of your places has to carry a
code before a customer types a town — the match is done once, offline, and
stored in `m_cdek_place_map`.

## Why not by name

Russia has dozens of villages with any given name ("Одинцово" alone is in ten
regions). Name alone identifies nothing.

## Why not by coordinates first

Both sides have coordinates, but CDEK's are wrong for some of its **oldest**
rows — measured: its Иркутск sits 500 km from Irkutsk, and the Moscow-oblast
Чехов is placed on Sakhalin, next to the Sakhalin one. Those old rows are the
big towns, exactly the ones that must not be missed. So the administrative
names decide and coordinates only break ties:

1. **name + region + district** — CDEK's `sub_region` against your place's
   district ("Одинцовский городской округ" on both sides). Most matches land
   here.
2. **name + region**, exactly one in CDEK's list — and either you have a single
   place of that name in the region, or the coordinates agree.
3. Several in the region: the **nearest**, within 30 km and at least twice as
   close as the next one.
4. The same across the country — for regions the two sides spell differently.
5. A name that is **unique** in CDEK's whole directory, when nothing contradicts
   it.

Region and district names are compared after dropping the words that only say
what kind of unit it is ("область", "республика", "городской округ" …) and
folding ё to е.

## What is left over

Places CDEK does not list (or spells differently) stay unmatched and are not
offered CDEK. Bind them by hand on the data screen; a manual binding is never
overwritten by a later run. Automatic bindings are recomputed on every run,
so a city CDEK has dropped stops being offered.
