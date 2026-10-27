# Place matching

Nova Poshta addresses branches by a settlement `Ref` (a UUID), never by name.
So before the module can offer the carrier for a town, that town has to be
tied to a Nova Poshta settlement. The carrier's own plug-ins solve this
by *selling* a pre-matched data pack; this module works it out from the API.

## How a match is made

`Match with the registry` runs a chain, stopping at the first step that is
unambiguous:

1. **name + region + district**
2. **name + region** — only when exactly one candidate remains
3. **postal code** — the carrier's settlement index falls inside the place's
   `postal_from..postal_to` range, and only one candidate does
4. **coordinates** — the nearest candidate, but only if it is closer than
   30 km *and* twice as close as the next one
5. **name unique in the country + coordinates agree** — for Kyiv, Sevastopol
   and the like, where the two sides name the region differently

Anything still ambiguous is left for a human. Re-running the match replaces
every automatic binding; a binding you made by hand (`manual`) is never
touched.

## The unmatched list

The data screen lists places with no match. For each, the picker searches the
carrier's directory and shows, for every candidate, the distance and whether
its postal code falls in range — so eight identical "Іванівка"s stop being a
coin toss. Click **This one** to bind, **Unbind** to undo.

## Why some places never match

On live Ukrainian data roughly 2 800 places have no Nova Poshta counterpart at
all: Crimea and Sevastopol (the carrier has zero rows there), much of Donetsk
and Luhansk, and villages the carrier simply does not serve. Those places just
do not offer Nova Poshta — which is the same thing the carrier's own module
does.
