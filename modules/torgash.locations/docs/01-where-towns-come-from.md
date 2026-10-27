# Where the towns come from

Countries, regions, districts and towns are not typed in by hand. They come
as **packs**: two CSV files per country (`UA-states.csv`, `UA-cities.csv`)
and a `manifest.json` saying what they were built from. The town window,
delivery zones, pickup points, city pages and the checkout's town field all
read them.

## What ships with the engine

The folder `data/geo` (the **Folder with prepared packs** setting) holds:

| File | What | Source | Licence |
|---|---|---|---|
| `countries.csv` | the world's 252 countries, codes, currencies, phone codes | GeoNames | CC BY 4.0 |
| `UA/` | Ukraine: 27 regions, ~29 000 places, districts, postal codes | OpenStreetMap | ODbL 1.0 |
| `RU/` | Russia: regions, ~145 000 places, districts, postal codes | OpenStreetMap | ODbL 1.0 |

A shop installed with its country gets the country list and its own pack
loaded by the installer (the pack in the background — see System →
Background tasks). Any other shop: **Locations → Import**, press **Import**
beside the country; the list of countries is loaded on the **Countries** tab.

## Another country

Build its pack with one of the two builders in the engine's source code
(`tools/`), then drop the files into the folder or upload them on the Import
screen.

**From GeoNames** — simplest, PHP only:

1. From <https://download.geonames.org/export/dump/> download the country's
   `<CC>.zip` (e.g. `PL.zip`), `admin1CodesASCII.txt` and
   `alternateNamesV2.zip`; unzip them into one folder.
2. `php tools/build-locations.php --country=PL --languages=en,pl --source=<that folder> --out=data/geo`

**From OpenStreetMap** — more local names, a district for nearly every
place and real postal codes, but needs Python with pyosmium and shapely:

1. The country's `.osm.pbf` from <https://download.geofabrik.de/>.
2. `python tools/build-locations-osm.py --country=PL --languages=en,pl --pbf=<file> --out=data/geo`

## No pack at all

Add the towns you deliver to by hand under **Places**. An import never
touches a place you added or edited, so a pack loaded later fills in around
them.

## Licences

Both sources are free to use in a shop and ask for a credit:
"© OpenStreetMap contributors" (ODbL 1.0) and "GeoNames" (CC BY 4.0). A pack
built from OpenStreetMap may only be passed on under the same ODbL licence.
Every pack records its source, date and licence in its `manifest.json`; the
Import screen shows it.
