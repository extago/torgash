"""Builds a locality pack out of an OpenStreetMap country extract.

    python tools\\build-locations-osm.py --country=UA --languages=en,uk,ru ^
        --pbf=D:\\AI\\database-cities2\\ukraine-260827.osm.pbf ^
        --match=data\\geo\\UA\\UA-cities.csv --out=data\\geo

Writes the same two CSVs the GeoNames builder writes (tools/build-locations.php),
so the locations module imports either without knowing the difference.

Why a second builder, and why Python: OSM ships as .osm.pbf, a protobuf stream
of nodes/ways/relations, and the only sane reader is libosmium. pyosmium is its
binding; there is no PHP equivalent worth trusting with four gigabytes. Install
with `pip install osmium shapely` (this repo uses a venv beside the data — see
docs/PLAN.md).

What OSM has that GeoNames does not, measured:

                        Ukraine            Russia
    local name          99.6% vs 82%       100% vs 87%
    postal code           95% vs 16 rows    21% vs 349 rows
    district             100% vs derived   100% vs 1%

Two ways to put a settlement in its district, chosen per country:

  * codes — Ukraine's KATOTTG sits on both the settlement and the boundary
    (UA74|100|390|010|054825), so the hierarchy is a string prefix join. Exact,
    and costs nothing.
  * boundaries — everywhere else: the admin boundaries are cut out into a small
    file of their own, assembled into polygons, and every settlement is placed
    by point-in-polygon. The cut is what makes this affordable — assembling
    every area in a 4 GB extract builds every building in the country and takes
    ten minutes; the same work on the cut-out boundaries takes seconds.

Licence: OSM data is ODbL 1.0, which is share-alike. A pack built from it is a
derived database and must be distributed under ODbL with attribution to
OpenStreetMap contributors. That is recorded in the pack's manifest; it does
not affect the engine's own licence, only this data.
"""

import argparse
import collections
import csv
import io
import json
import math
import os
import re
import sys
import time

import osmium
import shapely
from shapely import STRtree

# Windows consoles default to cp1251 here, and every name in this file is
# Cyrillic; without this the first print() kills the build.
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# What counts as a place someone can be delivered to. Suburbs, neighbourhoods
# and localities are deliberately out: they are parts of a city (or not
# settlements at all), and a customer writes the city.
SETTLEMENT_PLACES = {'city', 'town', 'village', 'hamlet'}

# Levels worth reading: country (for the capital), region, district, and the
# municipal level whose centre is a "community centre" in our vocabulary.
ADMIN_LEVELS = ('2', '4', '6', '7', '8')

# The two levels that become a region and a district in the pack.
REGION_LEVEL = '4'
DISTRICT_LEVEL = '6'

# KATOTTG is UA + oblast(2) + raion(3) + hromada(3) + kind(3) + serial(6).
KATOTTG_OBLAST = 4
KATOTTG_RAION = 7

TYPE_CAPITAL = 'capital'
TYPE_REGION = 'region_center'
TYPE_DISTRICT = 'district_center'
TYPE_COMMUNITY = 'community_center'
TYPE_SETTLEMENT = 'settlement'

# Which level's centre is which kind of place.
CENTRE_TYPES = {'4': TYPE_REGION, '6': TYPE_DISTRICT, '7': TYPE_COMMUNITY, '8': TYPE_COMMUNITY}

CYRILLIC_TO_LATIN = {
    'щ': 'shch', 'ш': 'sh', 'ч': 'ch', 'ж': 'zh', 'х': 'kh', 'ц': 'ts', 'ю': 'iu', 'я': 'ia',
    'є': 'ie', 'ї': 'i', 'й': 'i', 'ы': 'y', 'э': 'e', 'ё': 'e', 'а': 'a', 'б': 'b', 'в': 'v',
    'г': 'h', 'ґ': 'g', 'д': 'd', 'е': 'e', 'з': 'z', 'и': 'y', 'і': 'i', 'к': 'k', 'л': 'l',
    'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f',
    'ь': '', 'ъ': '',
}

LATIN_FOLDS = [
    ('shch', 's'), ('sh', 's'), ('ch', 'c'), ('zh', 'z'), ('kh', 'h'), ('ts', 'c'),
    ('ya', 'a'), ('ia', 'a'), ('yu', 'u'), ('iu', 'u'), ('ye', 'e'), ('ie', 'e'), ('yi', 'i'),
    ('j', 'i'), ('y', 'i'), ('g', 'h'), ('w', 'v'), ('x', 'ks'), ('q', 'k'),
]

# Words that say what KIND of unit a region is rather than which one it is.
REGION_GENERICS = ('автономна', 'автономная', 'автономный', 'автономного', 'республіка',
                   'республика', 'область', 'обл', 'ао', 'край', 'округ', 'місто', 'город',
                   'oblast', 'krai', 'okrug', 'city', 'autonomous', 'republic', 'of', 'the')

CITY_MARKERS = ('місто', 'город', 'city')
REGION_MARKERS = ('область', 'oblast', 'республіка', 'республика', 'republic', 'край', 'округ')


def skeleton(value):
    """A loose Latin skeleton, so "Високе" and "Vysoke" compare equal.

    The same crude folding the PHP builder uses (tools/build-locations.php);
    both exist to answer "same place?", not to transliterate properly.
    """
    if not value:
        return ''
    out = ''.join(CYRILLIC_TO_LATIN.get(ch, ch) for ch in value.lower())
    for src, dst in LATIN_FOLDS:
        out = out.replace(src, dst)
    out = re.sub(r'[^a-z]', '', out)
    return re.sub(r'(.)\1+', r'\1', out)


# --- Romanisation -------------------------------------------------------------
#
# Not the same job as skeleton() above, which folds two spellings together to
# answer "same place?" and is unreadable on purpose. This produces a name a
# person is meant to READ: the Latin spelling a pack falls back to when the
# mappers never wrote name:en.
#
# It is needed because OSM's English coverage is wildly uneven, measured on the
# 2026-08-27 extracts: Ukraine has name:en on 99% of settlements and 90% of
# districts, Russia on 19.7% and 74%. Without a fallback the Russian pack is a
# Cyrillic-only pack with a few hundred English exceptions, which defeats the
# point of shipping English at all.
#
# What is NOT done here: translating the generic word. "Кандалакшский
# муниципальный округ" comes out as "Kandalakshskiy munitsipalnyy okrug", not
# "Kandalaksha District". Mappers themselves write District for 82% of the ones
# they did tag, so a small table would read better — it is a deliberate later
# step, not an oversight.
#
# Russian follows BGN/PCGN without diacritics, which is the scheme OSM's own
# name:en already uses (Shchelkovo, Yekaterinburg); Ukrainian follows the
# official KMU 2010 romanisation, which is what Ukrainian road signs and
# passports use. Both are position-dependent at the start of a word, hence the
# separate INITIAL tables.

ROMANISE_RU = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'zh',
    'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o',
    'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'kh', 'ц': 'ts',
    'ч': 'ch', 'ш': 'sh', 'щ': 'shch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu',
    'я': 'ya',
}

ROMANISE_UK = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'h', 'ґ': 'g', 'д': 'd', 'е': 'e', 'є': 'ie',
    'ж': 'zh', 'з': 'z', 'и': 'y', 'і': 'i', 'ї': 'i', 'й': 'i', 'к': 'k', 'л': 'l',
    'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
    'ф': 'f', 'х': 'kh', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'shch', 'ь': '',
    'ю': 'iu', 'я': 'ia',
}

# Letters of the other Cyrillic alphabets a Russian or Ukrainian extract carries
# — Tatar, Bashkir, Chuvash, Sakha, Belarusian and the rest. A name written in
# one of them would otherwise come out half-Latin, and the whole romanisation is
# then thrown away (see romanise()).
ROMANISE_EXTRA = {
    'ә': 'a', 'ө': 'o', 'ү': 'u', 'ұ': 'u', 'һ': 'h', 'ҳ': 'h', 'җ': 'zh', 'җ': 'zh',
    'ң': 'ng', 'ҥ': 'ng', 'ҕ': 'g', 'ӷ': 'g', 'ҫ': 's', 'ӑ': 'a', 'ӗ': 'e', 'ӳ': 'u',
    'ӱ': 'u', 'ӧ': 'o', 'ӥ': 'i', 'ӹ': 'y', 'ў': 'w', 'қ': 'q', 'ғ': 'gh', 'ц': 'ts',
    'ї': 'i', 'є': 'ie', 'і': 'i', 'ґ': 'g', 'ё': 'yo',
}

# At the start of a word both schemes prefer the y-forms: Yekaterinburg, not
# Ekaterinburg; Yalta, not Ialta.
ROMANISE_INITIAL = {
    'ru': {'е': 'ye'},
    'uk': {'є': 'ye', 'ї': 'yi', 'й': 'y', 'ю': 'yu', 'я': 'ya'},
}

# KMU 2010's one digraph: зг is zgh, so it cannot be read back as ж (zh).
ROMANISE_DIGRAPHS = {'uk': {'зг': 'zgh'}}

# The Ukrainian apostrophe is dropped, not carried over and not treated as a
# space: KMU 2010 writes Кам'янське as Kamianske, so the letter after it is in
# the MIDDLE of the word (ia) and not at its start (ya). Mappers write it with
# any of three characters.
APOSTROPHES = "'’ʼ՚‘"

ROMANISE = {
    'ru': dict(ROMANISE_EXTRA, **ROMANISE_RU),
    'uk': dict(ROMANISE_EXTRA, **ROMANISE_UK),
}


def romanise(text, language):
    """The Latin spelling of a name, or '' if it cannot be produced honestly.

    Returns '' when a LETTER is left that no table knows, rather than a name
    that is Latin in one half and Cyrillic in the other: half a word is not
    more readable than none, and an empty column is a fact the manifest can
    count while a mangled one silently looks fine.

    Only letters count. An earlier version asked for a wholly ASCII result and
    threw away 202 Russian names over their punctuation — «Стенькино», Участок
    № 4 — which is the opposite of the point: the guillemets and the № are the
    parts an English reader already reads.
    """
    table = ROMANISE.get(language)

    if not table or not text:
        return ''

    initial = ROMANISE_INITIAL.get(language, {})
    digraphs = ROMANISE_DIGRAPHS.get(language, {})
    out = []
    start = True
    i = 0

    while i < len(text):
        char = text[i]

        if char in APOSTROPHES:
            i += 1
            continue

        if not char.isalpha():
            out.append(char)
            start = True
            i += 1
            continue

        pair = text[i:i + 2].lower()
        piece = digraphs.get(pair)
        size = 2 if piece is not None else 1

        if piece is None:
            lower = char.lower()
            piece = initial.get(lower) if start else None
            piece = table.get(lower, lower) if piece is None else piece

        if char.isupper():
            piece = piece[:1].upper() + piece[1:]

        out.append(piece)
        start = False
        i += size

    latin = ''.join(out)

    return '' if any(c.isalpha() and not c.isascii() for c in latin) else latin


def fill_english(names, local, base):
    """Give a name a base-language spelling when the mappers never wrote one.

    Mutates `names` and says whether it had to invent the value, so the
    manifest can report how much of a pack is real English and how much is
    transliteration.
    """
    if base == local or names.get(base) or not names.get(local):
        return False

    latin = romanise(names[local], local)

    if not latin:
        return False

    names[base] = latin

    return True


def parse_postal(value):
    """OSM writes one code, a range, or a semicolon list. We keep the extremes."""
    if not value:
        return '', ''
    codes = re.findall(r'\d{5}', value)
    if not codes:
        return '', ''
    codes.sort()
    return codes[0], codes[-1]


def region_keys(*names):
    """A region's full name skeletons, and the same with the generic words gone."""
    full, core = set(), set()

    for name in names:
        if not name:
            continue
        full.add(skeleton(name))
        words = [w for w in re.split(r'[\s\-]+', name.lower()) if w and w not in REGION_GENERICS]
        core.add(skeleton(' '.join(words)))

    return full - {''}, core - {''}


def significant_words(*names):
    """The words of a region's names that actually name it.

    "Ханты-Мансийский автономный округ — Югра" and "Ханты-Мансийский АО" share
    {ханты, мансийский}; everything else in either is either generic or an
    addition one source made and the other did not.
    """
    words = set()

    for name in names:
        if not name:
            continue
        for word in re.split(r'[\s\-—–]+', name.lower()):
            word = re.sub(r'[^\w]', '', word, flags=re.UNICODE)
            if word and word not in REGION_GENERICS and len(word) > 2:
                words.add(skeleton(word))

    return words - {''}


def region_kind(*names):
    """"City" or "region" — the only distinction the stripped names lose."""
    text = ' '.join(n.lower() for n in names if n)

    if any(marker in text for marker in CITY_MARKERS):
        return 'city'
    if any(marker in text for marker in REGION_MARKERS):
        return 'region'

    return ''


class Units:
    """Every administrative unit in the extract, by OSM relation id.

    Read from relations only: a boundary mapped as a bare way carries neither
    the admin_centre member nor, usually, the official code.
    """

    def __init__(self, languages):
        self.languages = languages
        self.local = languages[1] if len(languages) > 1 else languages[0]
        self.by_id = {}       # relation id => unit
        self.centre_of = {}   # node id => the admin level that node is the centre of
        self.capitals = set()  # node ids a country relation calls its centre

    def read(self, pbf):
        for obj in osmium.FileProcessor(pbf, osmium.osm.RELATION).with_filter(
                osmium.filter.KeyFilter('boundary')):
            tags = dict(obj.tags)

            if tags.get('boundary') != 'administrative':
                continue

            level = tags.get('admin_level')

            if level not in ADMIN_LEVELS:
                continue

            names = {lang: tags.get('name:' + lang, '') for lang in self.languages}
            # In Russia the plain `name` IS the local name and mappers do not
            # duplicate it into name:ru; in Ukraine both are usually present.
            names[self.local] = names.get(self.local) or tags.get('name', '')

            self.by_id[obj.id] = {
                'id': obj.id,
                'level': level,
                'names': names,
                'iso': tags.get('ISO3166-2', ''),
                'katotth': tags.get('katotth', ''),
                'name': tags.get('name', ''),
            }

            # The relation names its own capital, which is how a settlement
            # learns that it is a district centre without any list of ours.
            #
            # The BIGGEST unit a node is the centre of wins: a district capital
            # is also the centre of its own municipality, and whichever relation
            # the file happens to list first would otherwise decide — which is
            # why a first pass reported 106 district centres out of 136.
            for member in obj.members:
                if member.role == 'admin_centre' and member.type == 'n':
                    if level == '2':
                        self.capitals.add(member.ref)
                    elif level < self.centre_of.get(member.ref, '9'):
                        self.centre_of[member.ref] = level

        # A region or a district is printed beside a town to tell it from its
        # namesake, so it needs the same English fallback the town gets — and
        # needs it more, because level 6 is tagged worse than level 4.
        for unit in self.by_id.values():
            unit['english_derived'] = fill_english(unit['names'], self.local, self.languages[0])

    def type_of(self, node_id):
        if node_id in self.capitals:
            return TYPE_CAPITAL

        return CENTRE_TYPES.get(self.centre_of.get(node_id), TYPE_SETTLEMENT)


class CodeHierarchy:
    """Ukraine: the settlement's own KATOTTG says which units it belongs to."""

    def __init__(self, units, country):
        self.country = country
        self.regions = {}    # UA74    => unit
        self.districts = {}  # UA74100 => unit

        for unit in units.by_id.values():
            code = unit['katotth']

            if not code:
                continue

            if unit['level'] == REGION_LEVEL:
                self.regions[code[:KATOTTG_OBLAST]] = unit
            elif unit['level'] == DISTRICT_LEVEL:
                self.districts[code[:KATOTTG_RAION]] = unit

    def place(self, tags, lon, lat):
        code = tags.get('katotth', '')

        # The code doubles as the country filter: a Geofabrik extract carries a
        # strip of every neighbour, and those have no Ukrainian code.
        if not code.startswith(self.country):
            return None

        region = self.regions.get(code[:KATOTTG_OBLAST])

        return {
            'state_code': (region or {}).get('iso', '').replace(self.country + '-', '')
                          or code[2:KATOTTG_OBLAST],
            'region': region,
            'district': self.districts.get(code[:KATOTTG_RAION]),
            'codes': {'katotth': code, 'koatuu': tags.get('koatuu', '')},
        }


class BoundaryHierarchy:
    """Everywhere else: the polygons decide.

    The boundaries are first cut into a file of their own. That is the whole
    trick — asking pyosmium to assemble areas straight from a 4 GB extract
    builds every building in the country and takes ten minutes; the same work
    on the cut-out boundaries takes seconds.
    """

    def __init__(self, units, pbf, country, cache_dir):
        self.units = units
        self.country = country
        self.polygons = []
        self.meta = []
        self.cut = os.path.join(cache_dir, country.lower() + '-admin.osm.pbf')

        self._cut_boundaries(pbf)
        self._assemble()
        self.tree = STRtree(self.polygons)

    def _cut_boundaries(self, pbf):
        if os.path.exists(self.cut):
            os.remove(self.cut)

        kept = 0
        # remove_tags=False: the ways and nodes a boundary is made of come along
        # for the ride, and the relations keep the tags we are about to read.
        with osmium.BackReferenceWriter(self.cut, ref_src=pbf, remove_tags=False) as writer:
            for obj in osmium.FileProcessor(pbf, osmium.osm.RELATION).with_filter(
                    osmium.filter.KeyFilter('boundary')):
                tags = obj.tags
                if (tags.get('boundary') == 'administrative'
                        and tags.get('admin_level') in (REGION_LEVEL, DISTRICT_LEVEL)):
                    writer.add(obj)
                    kept += 1

        print('  {} boundaries cut into {} ({:.0f} MB)'.format(
            kept, os.path.basename(self.cut), os.path.getsize(self.cut) / 1e6))

    def _assemble(self):
        wkb = osmium.geom.WKBFactory()
        broken = 0

        for obj in osmium.FileProcessor(self.cut).with_areas():
            if obj.type_str() != 'a':
                continue

            tags = dict(obj.tags)

            if (tags.get('boundary') != 'administrative'
                    or tags.get('admin_level') not in (REGION_LEVEL, DISTRICT_LEVEL)):
                continue

            unit = self.units.by_id.get(obj.orig_id())

            if unit is None:
                continue

            try:
                geometry = shapely.from_wkb(bytes.fromhex(wkb.create_multipolygon(obj)))
            except Exception:
                broken += 1
                continue

            if geometry.is_empty:
                broken += 1
                continue

            self.polygons.append(geometry)
            self.meta.append((geometry.area, unit))

        print('  {} polygons assembled{}'.format(
            len(self.polygons), ' ({} could not be built)'.format(broken) if broken else ''))

    def place(self, tags, lon, lat):
        hits = self.tree.query(shapely.Point(lon, lat), predicate='within')

        if len(hits) == 0:
            return None

        region = district = None

        # Smallest first, so a city that is a region of its own (Moscow inside
        # Moscow Oblast) wins over the region that surrounds it.
        for _, unit in sorted((self.meta[i] for i in hits), key=lambda pair: pair[0]):
            if unit['level'] == REGION_LEVEL and region is None:
                region = unit
            elif unit['level'] == DISTRICT_LEVEL and district is None:
                district = unit

        # No region of this country means the point is in a neighbouring one:
        # the extract always carries a strip of them, and this is the exact way
        # to say so.
        if region is None or not region['iso'].startswith(self.country + '-'):
            return None

        return {
            'state_code': region['iso'].replace(self.country + '-', ''),
            'region': region,
            'district': district,
            'codes': {key: tags[key] for key in ('oktmo', 'okato') if tags.get(key)},
        }


def read_settlements(pbf, units, hierarchy, languages):
    """Every place node the hierarchy recognises as being in this country."""
    local = units.local
    base = languages[0]
    rows, outside, nameless = [], 0, 0

    for obj in osmium.FileProcessor(pbf, osmium.osm.NODE).with_filter(
            osmium.filter.KeyFilter('place')):
        tags = dict(obj.tags)

        if tags.get('place') not in SETTLEMENT_PLACES:
            continue

        placed = hierarchy.place(tags, obj.location.lon, obj.location.lat)

        if placed is None:
            outside += 1
            continue

        names = {lang: tags.get('name:' + lang, '') for lang in languages}
        # Where a country writes its own language as the plain `name` (Russia
        # does, Ukraine mostly does not), that is the local name.
        names[local] = names.get(local) or tags.get('name', '')

        if not any(names.values()):
            # A place with no name in any language cannot be shown, searched or
            # picked. OSM has a handful of them; the pack does not need them.
            nameless += 1
            continue

        derived = fill_english(names, local, base)

        # Everything else worth typing into a search box: the pre-rename name,
        # local variants, and whichever spelling is not already a column.
        aliases = []
        for key in ('old_name', 'alt_name', 'name', 'int_name', 'loc_name', 'official_name',
                    'old_name:' + local, 'alt_name:' + local):
            for part in (tags.get(key) or '').split(';'):
                part = part.strip()
                if part and part not in names.values() and part not in aliases:
                    aliases.append(part)

        postal_from, postal_to = parse_postal(tags.get('postal_code') or tags.get('addr:postcode'))
        digits = re.sub(r'[^\d]', '', tags.get('population', '') or '')

        rows.append({
            'osm_id': obj.id,
            'names': names,
            'english_derived': derived,
            'aliases': aliases[:12],
            'lat': obj.location.lat,
            'lon': obj.location.lon,
            'population': int(digits) if digits else 0,
            'postal_from': postal_from,
            'postal_to': postal_to,
            'type': units.type_of(obj.id),
            'state_code': placed['state_code'],
            'region': placed['region'],
            'district': placed['district'],
            'codes': placed['codes'],
        })

    return rows, outside, nameless


def read_legacy_places(path):
    """The pack this one replaces, indexed on a coarse lat/lon grid."""
    grid = collections.defaultdict(list)

    if not path or not os.path.isfile(path):
        return grid

    with open(path, encoding='utf-8') as handle:
        for row in csv.DictReader(handle, delimiter=';'):
            try:
                lat, lon = float(row['latitude']), float(row['longitude'])
            except (ValueError, KeyError, TypeError):
                continue

            ref = row.get('ref') or ('geonames:' + row['geonameid'] if row.get('geonameid') else '')

            if not ref:
                continue

            keys = {skeleton(value) for column, value in row.items()
                    if column.startswith('name_') and value} - {''}
            grid[(round(lat, 1), round(lon, 1))].append(
                (lat, lon, keys, ref, row.get('geonameid', '')))

    return grid


def read_legacy_states(path):
    """Region key by name, so the pack can say which region it replaces.

    The key is the ref the old pack's rows carry ("geonames:686966"), not the
    old code: codes collide between numbering schemes, refs do not.
    """
    by_full, by_core, by_words = {}, collections.defaultdict(list), []

    if not path or not os.path.isfile(path):
        return by_full, by_core, by_words

    with open(path, encoding='utf-8') as handle:
        for row in csv.DictReader(handle, delimiter=';'):
            ref = row.get('ref') or ('geonames:' + row['geonameid'] if row.get('geonameid') else '')

            if not ref:
                continue

            names = [value for column, value in row.items() if column.startswith('name_')]
            full, core = region_keys(*names)

            for key in full:
                by_full.setdefault(key, ref)
            for key in core:
                by_core[key].append((ref, region_kind(*names)))

            words = significant_words(*names)

            if words:
                by_words.append((ref, words))

    return by_full, by_core, by_words


def previous_state_ref(names, legacy):
    """Which row this region replaces in the pack being replaced.

    Full name first. Only when that fails do the generic words come off, and
    then the kind has to agree — "Київська область" and "Місто Київ" both
    shorten to "Київ", and handing the oblast the city's row would move every
    town in the region into the city.

    Last resort: one name's significant words being a subset of the other's.
    Sources disagree about how much of a long name to write — GeoNames says
    "Чукотский АО" and "Кузба́сс" where OSM says "Чукотский автономный округ"
    and "Кемеровская область — Кузбасс" — and a subset is still only accepted
    when exactly one region on the other side matches it.
    """
    by_full, by_core, by_words = legacy
    full, core = region_keys(*names)

    for key in full:
        if key in by_full:
            return by_full[key]

    kind = region_kind(*names)

    for key in core:
        candidates = by_core.get(key, [])

        if len(candidates) == 1:
            return candidates[0][0]

        same_kind = [ref for ref, other in candidates if other == kind and kind]

        if len(same_kind) == 1:
            return same_kind[0]

    words = significant_words(*names)

    if words:
        overlapping = {ref for ref, other in by_words
                       if words <= other or other <= words}

        if len(overlapping) == 1:
            return overlapping.pop()

    return ''


def match_legacy(rows, grid):
    """Give every settlement the identity it already has in the shop.

    Without this the pack would introduce a second row for every town: same
    place, new key, and every shipping zone still pointing at the old row. So
    each settlement is matched to the row it replaces (a shared name skeleton,
    within 12 km) and the pack says so — `previous_ref` is the importer's
    instruction to adopt that row rather than insert a new one.

    An old row is claimed by exactly one settlement: the nearest one wins,
    because two villages sharing a name in one district are a real thing, and
    letting both adopt the same old row is a lie the database cannot hold.
    """
    claims = {}

    for index, row in enumerate(rows):
        keys = {skeleton(name) for name in row['names'].values()} - {''}
        best = None

        for dlat in (-0.1, 0.0, 0.1):
            for dlon in (-0.1, 0.0, 0.1):
                cell = grid.get((round(row['lat'] + dlat, 1), round(row['lon'] + dlon, 1)), ())
                for lat, lon, legacy_keys, ref, geonameid in cell:
                    if not (keys & legacy_keys):
                        continue
                    km = math.hypot((lat - row['lat']) * 111,
                                    (lon - row['lon']) * 111 * math.cos(math.radians(row['lat'])))
                    if km <= 12 and (best is None or km < best[0]):
                        best = (km, ref, geonameid)

        if best is None:
            continue

        km, ref, geonameid = best

        if ref not in claims or km < claims[ref][0]:
            claims[ref] = (km, index, geonameid)

    for ref, (_, index, geonameid) in claims.items():
        rows[index]['previous_ref'] = ref
        rows[index]['geonameid'] = geonameid

    return len(claims)


def write_pack(country, rows, regions, out, languages, legacy_states):
    os.makedirs(os.path.join(out, country), exist_ok=True)

    name_columns = ['name_' + lang for lang in languages]
    # A district is translated exactly like a name, and for the same reason: it
    # is printed beside the town to tell two namesakes apart, so a reader who
    # cannot read it cannot tell them apart. One column per language, so the
    # importer keys it by the language code and never has to guess.
    district_columns = ['district_' + lang for lang in languages]
    states_file = os.path.join(out, country, country + '-states.csv')
    cities_file = os.path.join(out, country, country + '-cities.csv')

    # Regions are numbered by ISO 3166-2, which is what the rest of the world
    # uses, but they are IDENTIFIED by ref: the GeoNames numbering overlaps with
    # ISO (Dnipropetrovsk is 12 to ISO and 04 to GeoNames, while 12 is GeoNames'
    # Kyiv City), so renumbering by code walks into another region's row. Each
    # line therefore names the row it replaces, and the importer moves the key
    # onto it — the region keeps its id, its towns, and any zone pointing at it.
    claims = {code: previous_state_ref(tuple(unit['names'].values()), legacy_states)
              for code, unit in regions.items()}

    # A predecessor belongs to one region or to none. Loosely matched names can
    # both point at the same old row — "Республика Алтай" and "Алтайский край"
    # both shorten to "Алтай" — and letting both adopt it would move one
    # region's towns into another. Neither gets it; they become new rows, and
    # the old one is left for a person to look at.
    duplicates = {ref for ref, times in collections.Counter(claims.values()).items()
                  if ref and times > 1}

    with open(states_file, 'w', encoding='utf-8', newline='') as handle:
        writer = csv.writer(handle, delimiter=';', quoting=csv.QUOTE_MINIMAL)
        writer.writerow(['country', 'state_code', 'ref', 'previous_ref'] + name_columns)

        for code, unit in sorted(regions.items()):
            names = unit['names']
            previous = claims[code]
            writer.writerow([country, code, 'osm:r{}'.format(unit['id']),
                             '' if previous in duplicates else previous]
                            + [names.get(lang, '') for lang in languages])

    with open(cities_file, 'w', encoding='utf-8', newline='') as handle:
        writer = csv.writer(handle, delimiter=';', quoting=csv.QUOTE_MINIMAL)
        writer.writerow(['ref', 'previous_ref', 'geonameid', 'country', 'state_code']
                        + district_columns + ['type']
                        + name_columns
                        + ['latitude', 'longitude', 'population', 'postal_from', 'postal_to',
                           'aliases', 'katotth', 'koatuu'])

        for row in rows:
            district = row['district']
            writer.writerow(
                ['osm:n{}'.format(row['osm_id']), row.get('previous_ref', ''),
                 row.get('geonameid', ''), country, row['state_code']]
                + [district['names'].get(lang, '') if district else '' for lang in languages]
                + [row['type']]
                + [row['names'].get(lang, '') for lang in languages]
                + [row['lat'], row['lon'], row['population'], row['postal_from'], row['postal_to'],
                   '|'.join(row['aliases']),
                   row['codes'].get('katotth', ''), row['codes'].get('koatuu', '')])

    return states_file, cities_file


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--country', default='UA')
    parser.add_argument('--languages', default='en,uk,ru')
    parser.add_argument('--pbf', required=True)
    parser.add_argument('--out', default='data/geo')
    parser.add_argument('--match', default='', help='the pack this one replaces')
    parser.add_argument('--hierarchy', default='', choices=['', 'codes', 'boundaries'])
    args = parser.parse_args()

    country = args.country.upper()
    languages = [lang.strip() for lang in args.languages.split(',') if lang.strip()]
    local = languages[1] if len(languages) > 1 else languages[0]
    started = time.time()

    print('reading administrative units from ' + os.path.basename(args.pbf))
    units = Units(languages)
    units.read(args.pbf)
    levels = collections.Counter(unit['level'] for unit in units.by_id.values())
    print('  by level: {} ({} administrative centres)'.format(
        dict(sorted(levels.items())), len(units.centre_of)))

    # Ukraine's settlements carry KATOTTG, which is exact and free; everywhere
    # else the polygons have to do the work.
    strategy = args.hierarchy or ('codes' if country == 'UA' else 'boundaries')
    print('placing settlements by ' + strategy)

    hierarchy = (CodeHierarchy(units, country) if strategy == 'codes'
                 else BoundaryHierarchy(units, args.pbf, country, os.path.dirname(args.pbf) or '.'))

    rows, outside, nameless = read_settlements(args.pbf, units, hierarchy, languages)
    print('  {} settlements ({} place nodes outside the country skipped — the extract '
          'carries a strip of every neighbour{})'.format(
              len(rows), outside, ', {} nameless'.format(nameless) if nameless else ''))

    grid = read_legacy_places(args.match)
    matched = match_legacy(rows, grid) if grid else 0

    if grid:
        print('  {} of them are rows the shop already has ({:.1f}%)'.format(
            matched, matched / max(len(rows), 1) * 100))

    legacy_states = (read_legacy_states(args.match.replace('-cities', '-states'))
                     if args.match else ({}, {}, []))

    # Only the regions somebody actually lives in get a row.
    regions = {}
    for row in rows:
        if row['region'] is not None:
            regions.setdefault(row['state_code'], row['region'])

    states_file, cities_file = write_pack(country, rows, regions, args.out, languages,
                                          legacy_states)

    base = languages[0]
    counted = collections.Counter(row['type'] for row in rows)
    with_local = sum(1 for row in rows if row['names'].get(local))
    with_postal = sum(1 for row in rows if row['postal_from'])
    with_district = sum(1 for row in rows if row['district'])

    # How much of the base language is real and how much this build invented.
    # Worth counting rather than assuming: it is the number that says whether a
    # pack is readable by someone who does not read the local alphabet, and it
    # is wildly different between countries (Ukraine barely needs the
    # fallback, Russia is mostly fallback).
    with_base = sum(1 for row in rows if row['names'].get(base))
    romanised = sum(1 for row in rows if row['english_derived'])
    districts = {row['district']['id']: row['district'] for row in rows if row['district']}
    districts_with_base = sum(1 for unit in districts.values() if unit['names'].get(base))
    districts_romanised = sum(1 for unit in districts.values() if unit['english_derived'])

    with open(os.path.join(args.out, country, 'manifest.json'), 'w', encoding='utf-8') as handle:
        json.dump({
            'country': country,
            'languages': {'base': languages[0], 'local': local,
                          'extra': languages[2] if len(languages) > 2 else None},
            'generated_at': time.strftime('%Y-%m-%dT%H:%M:%S%z'),
            'source': {
                'name': 'OpenStreetMap',
                'url': 'https://download.geofabrik.de/',
                'file': os.path.basename(args.pbf),
                'hierarchy': strategy,
                # ODbL is share-alike: a shop redistributing this pack owes the
                # same licence and the attribution below.
                'licence': 'ODbL 1.0',
                'attribution': '© OpenStreetMap contributors',
            },
            'counts': {
                'states': len(regions),
                'cities': len(rows),
                'cities_with_local_name': with_local,
                'cities_with_postal': with_postal,
                'cities_with_district': with_district,
                'cities_with_' + base + '_name': with_base,
                'cities_with_' + base + '_romanised': romanised,
                'districts': len(districts),
                'districts_with_' + base + '_name': districts_with_base,
                'districts_with_' + base + '_romanised': districts_romanised,
                'matched_to_previous_pack': matched,
                'by_type': dict(counted),
            },
        }, handle, ensure_ascii=False, indent=4)

    print('done in {:.0f}s — {} settlements ({} with a local name, {} with a postal code, '
          '{} with a district), {} regions → {}'.format(
              time.time() - started, len(rows), with_local, with_postal, with_district,
              len(regions), os.path.dirname(cities_file)))
    print('  by type: {}'.format(dict(counted)))
    print('  {}: {} settlements ({} romanised here), {} of {} districts ({} romanised)'.format(
        base, with_base, romanised, districts_with_base, len(districts), districts_romanised))


if __name__ == '__main__':
    main()
