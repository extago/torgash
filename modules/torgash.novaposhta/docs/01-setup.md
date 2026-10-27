# Setting up Nova Poshta

## 1. The API key

Paste your Nova Poshta API key into the module's settings
(**Modules → Nova Poshta → the gear**). The data screen has a **Check** button
that makes one live call, so you learn straight away whether the key works —
rather than from a background job that fails ten minutes later.

Everything below needs the key.

## 2. Sync the settlement directory

**Modules → Nova Poshta → its documentation link opens the data screen** →
**Sync directory**. This pulls the carrier's ~27 000 settlements and ~11 000
"cities" into `m_np_settlements` (about 17 seconds). It runs as a background
task — watch it under **System → Background tasks**.

Once the project's scheduler is running it refreshes itself weekly; until then,
press the button when the carrier's list has moved.

## 3. Match places to your registry

**Match with the registry** ties each Nova Poshta settlement to a
`torgash.locations` place. On live Ukrainian data this lands about 90% of
places automatically; the rest have no counterpart on the carrier's side
(Crimea, occupied areas, villages the carrier does not list) and simply do not
offer Nova Poshta. See [Place matching](?doc=place-matching).

A place with no match is shown in the unmatched list, where you can bind it by
hand — and a manual binding survives every later re-match.

## 4. Branches

Branches are fetched **lazily, per town**: the first checkout that names a town
pays for one request, everyone after reads the cache (a day, by default). For a
shop that would rather have the whole country in its database up front, **Load
all branches** walks every matched town, biggest first, inside a time budget —
re-run it and it continues where it stopped.

## 5. The delivery method

Add a shipping method (**Shipping → Methods**), set **Priced by → Nova Poshta**
on the Carrier tab, and pick the variant: **to a branch** or **by courier**.
For live pricing, also set the **sender city** on the data screen — without it
the method is priced from your own rate table instead, which is a supported way
to run it.
