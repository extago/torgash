# Setting up CDEK

## 1. The keys

Paste the **account** and **secure password** from the CDEK business cabinet
(Integration → API keys) into the module's settings (**Modules → CDEK → the
gear**). The data screen has a **Test it** button that asks CDEK for a token
there and then, so you learn straight away whether the keys work.

No contract yet? Tick **Test environment** and leave both fields empty: the
module talks to CDEK's test server with CDEK's own published test account.
Its directory is a sample (about 1 300 pickup points), not the whole country —
untick it before the shop goes live.

## 2. Sync the city directory

**Directory and matching** (a link in the settings) → **Sync the directory**.
This pulls CDEK's ~140 000 Russian cities into `m_cdek_cities` (about a
minute). It runs as a background task — watch it under **Background tasks**.
Once the scheduler is running it refreshes itself weekly.

Other countries CDEK serves (BY, KZ, AM, KG, UZ) are added in the
**Countries** setting.

## 3. Match places to your registry

**Match with the registry** ties each of your places to a CDEK city code. On
the full Russian registry this lands about 93 % of places automatically; the
rest are places CDEK does not list or spells differently ("Ростов Великий" is
"Ростов" there). A place with no match is simply not offered CDEK. See
[Place matching](?doc=place-matching).

Unmatched places are listed on the same screen, where you can bind one by hand
— a manual binding survives every later re-match. **Save the mapping** keeps
the result as a CSV you can restore on another server.

## 4. Pickup points

Points are fetched **lazily, per city**: the first checkout that names a city
pays for one request, everyone after reads the table (a day by default). CDEK
also answers for the whole country at once, so **Fetch all pickup points**
loads everything in one pass; the scheduler repeats it nightly. With every
point loaded, a customer in a village with none is shown the nearest towns
that have one.

## 5. The delivery method

Add a shipping method (**Shipping → Methods**), set **Priced by → CDEK**, and
choose:

- **This method delivers** — to a pickup point, or by courier;
- **Point types** — pickup points, parcel terminals, or both (none ticked =
  both);
- **Tariff** — Parcel, Express or Economy parcel;
- **You hand parcels to CDEK** — at a CDEK office, or CDEK's courier collects
  them. Together with the tariff and the customer's choice this gives the
  exact CDEK tariff code (136 "склад-склад", 368 "склад-постамат" …), which is
  frozen onto the order.

For live prices also set the **sender city** on the data screen (or give your
warehouse a town). Without it the method is priced from your own rate table,
which is a supported way to run it.

The price is CDEK's `total_sum`: delivery plus VAT plus, with **Declare the
goods' value** on, the insurance on the cart total. It is quoted in roubles; a
shop in another currency needs a RUB currency with a rate.
