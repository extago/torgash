<?php

/**
 * Builds a per-country locality pack out of a GeoNames dump.
 *
 *     php.exe tools\build-locations.php --country=UA --languages=en,uk,ru \
 *         --source=D:\AI\database-cities --out=data\geo
 *
 * Reads (all from --source, downloaded from https://download.geonames.org/export/dump/
 * and unzipped):
 *   <CC>.txt                 every feature in the country (<CC>.zip)
 *   admin1CodesASCII.txt     region codes and their English names
 *   alternateNamesV2.txt     names per language, ~750 MB, ALWAYS streamed
 *
 * Writes into <out>/<CC>/ the pair the Import screen lists, the same pair
 * tools/build-locations-osm.py writes (docs/architecture/locations.md):
 *   <CC>-states.csv   regions, one name column per language
 *   <CC>-cities.csv   populated places, `;`-separated
 *   manifest.json     what was built from what — the Import screen shows it
 *
 * GeoNames licence is CC-BY 4.0 — the shop shipping this data owes an
 * attribution, which is why the manifest records the source.
 */

ini_set('memory_limit', '2G');

$options = parseOptions($argv);
$country = strtoupper($options['country'] ?? '');
$languages = array_values(array_filter(array_map('trim', explode(',', $options['languages'] ?? 'en'))));
$source = rtrim($options['source'] ?? 'D:\AI\database-cities', '\\/');
$out = rtrim($options['out'] ?? 'data/geo', '\\/');

if ($country === '' || $languages === []) {
    fwrite(STDERR, "usage: build-locations.php --country=UA --languages=en,uk,ru [--source=DIR] [--out=DIR]\n");
    exit(1);
}

$countryFile = "{$source}/{$country}.txt";
$admin1File = "{$source}/admin1CodesASCII.txt";
$altFile = "{$source}/alternateNamesV2.txt";

foreach ([$countryFile, $admin1File, $altFile] as $file) {
    if (! is_file($file)) {
        fwrite(STDERR, "missing: {$file}\n");
        exit(1);
    }
}

/**
 * Feature codes we keep, and what we call them.
 *
 * Deliberately NOT kept: PPLQ/PPLW/PPLH/PPLCH — abandoned, destroyed and
 * historical places. They are real history and useless as a delivery address;
 * a shop that needs one can add it by hand.
 *
 * GeoNames has no notion of the local settlement class (місто / селище / село),
 * so "type" below is about administrative role, not about the prefix a
 * Ukrainian address would use. Deriving that needs KATOTTG, which is a separate
 * source — see docs/PLAN.md §10.
 */
const PLACE_TYPES = [
    'PPLC' => 'capital',
    'PPLA' => 'region_center',
    'PPLA2' => 'district_center',
    'PPLA3' => 'community_center',
    'PPLA4' => 'community_center',
    'PPLA5' => 'community_center',
    'PPLG' => 'settlement',
    'PPL' => 'settlement',
    'PPLS' => 'settlement',
    'PPLL' => 'settlement',
    'PPLF' => 'settlement',
    'PPLR' => 'settlement',
    'PPLX' => 'city_district',
];

/** Alphabets a language is actually written in, for the tags GeoNames lies about. */
const CYRILLIC_LANGUAGES = ['uk', 'ru', 'be', 'bg', 'mk', 'sr', 'kk'];

/** Letters that exist in one of the two languages and not in the other. */
const UKRAINIAN_ONLY = 'іїєґІЇЄҐ';
const RUSSIAN_ONLY = 'ыэъёЫЭЪЁ';
@mkdir("{$out}/{$country}", 0777, true);

$started = microtime(true);

// ---------------------------------------------------------------- pass 1 ----
// The country file, streamed once: administrative units become the area tree,
// populated places go to a temporary file, and every id involved is remembered
// so the huge alternate-names file can be filtered against it.

echo "reading {$country}.txt\n";

$areas = [];          // "UA.32" => [level, parent, geonameid, ascii]
$adminIds = [];       // geonameid => area code
$placeCount = 0;
// geonameid => the name the source file itself carries. Kept (rather than a
// bare "true") because it is the only evidence of what the place is called
// TODAY, and pass 2 scores every candidate name against it.
$wanted = [];

foreach (readAdmin1($admin1File, $country) as $code => $row) {
    $areas[$code] = ['level' => 1, 'parent' => null, 'geonameid' => $row['geonameid'], 'ascii' => $row['name']];
    $adminIds[$row['geonameid']] = $code;
    $wanted[$row['geonameid']] = $row['name'];
}

$placesTmp = "{$out}/{$country}/places.tmp";
$tmp = fopen($placesTmp, 'w');
$handle = fopen($countryFile, 'r');

while (($line = fgets($handle)) !== false) {
    $f = explode("\t", rtrim($line, "\r\n"));

    if (count($f) < 19) {
        continue;
    }

    [$id, $name, $ascii, , $lat, $lon, $class, $code, , , $admin1, $admin2, $admin3, , $population] = $f;

    // Administrative units: the districts and communities that let two villages
    // of the same name in the same region be told apart. The screenshot the
    // user sent does exactly this — "Запоріжжя (м., Запорізький р-н)".
    if ($class === 'A' && in_array($code, ['ADM2', 'ADM3'], true)) {
        $areaCode = $code === 'ADM2'
            ? "{$country}.{$admin1}.{$admin2}"
            : "{$country}.{$admin1}.{$admin2}.{$admin3}";
        $parent = $code === 'ADM2' ? "{$country}.{$admin1}" : "{$country}.{$admin1}.{$admin2}";

        $areas[$areaCode] = [
            'level' => $code === 'ADM2' ? 2 : 3,
            'parent' => $parent,
            'geonameid' => (int) $id,
            'ascii' => $ascii ?: $name,
        ];
        $adminIds[$id] = $areaCode;
        $wanted[$id] = $ascii ?: $name;

        continue;
    }

    if ($class !== 'P' || ! isset(PLACE_TYPES[$code])) {
        continue;
    }

    // The narrowest unit the row names, so a place can be told apart from its
    // namesake in the next district. Codes are GeoNames' own and are only
    // meaningful joined against the ADM2/ADM3 rows above.
    $area = "{$country}.{$admin1}";
    if ($admin2 !== '') {
        $area .= ".{$admin2}";
    }
    if ($admin3 !== '') {
        $area .= ".{$admin3}";
    }

    fwrite($tmp, json_encode([
        'id' => (int) $id,
        'country' => $country,
        'area' => $area,
        'type' => PLACE_TYPES[$code],
        'feature' => $code,
        'name' => $name,
        'ascii' => $ascii,
        'lat' => (float) $lat,
        'lon' => (float) $lon,
        'population' => (int) $population,
    ], JSON_UNESCAPED_UNICODE)."\n");

    $wanted[(int) $id] = $ascii ?: $name;
    $placeCount++;
}

fclose($handle);
fclose($tmp);

printf("  %d places, %d areas, %d ids to name\n", $placeCount, count($areas), count($wanted));

// ---------------------------------------------------------------- pass 2 ----
// The 750 MB file, streamed once and never held in memory. Only rows whose
// geonameid is in $wanted survive, and of those only the languages asked for
// plus the flagged variants worth keeping as search aliases.
//
// Choosing WHICH of several names in one language is the display name is the
// whole difficulty, because GeoNames' language tags are unreliable in three
// separate ways, all of them present in the Ukrainian data:
//
//   uk | Zolochivskyi Raion     ← Latin text tagged Ukrainian, and listed
//   uk | Золочівський Район       first, so "first wins" picked the Latin one
//   ru | Володимирівка          ← Ukrainian text tagged Russian
//   uk | Володимирівка (pref=1) ← the pre-rename name, flagged official, while
//   uk | Високе                   the current name sits below it unflagged
//
// So a name is scored rather than taken in order: wrong script never becomes a
// display name (it becomes a search alias instead), a value written with the
// other language's letters is re-tagged, and matching the place's CURRENT name
// outweighs GeoNames' "preferred" flag — that is what keeps a renamed village
// from being labelled with the name it was renamed away from.

echo "streaming alternateNamesV2.txt (this is the slow part)\n";

$names = [];    // id => lang => ['value' => string, 'score' => int]
$aliases = [];  // id => [name, ...]
$postal = [];   // id => [code, ...]
$wantedLangs = array_flip($languages);
$read = 0;

$handle = fopen($altFile, 'r');

while (($line = fgets($handle)) !== false) {
    $read++;

    // Cheap reject first: most of this file is other countries.
    $f = explode("\t", rtrim($line, "\r\n"), 9);

    if (count($f) < 4) {
        continue;
    }

    $id = (int) $f[1];

    if (! isset($wanted[$id])) {
        continue;
    }

    $lang = $f[2];
    $value = trim($f[3]);
    $preferred = ($f[4] ?? '') === '1';
    $short = ($f[5] ?? '') === '1';
    $colloquial = ($f[6] ?? '') === '1';
    $historic = ($f[7] ?? '') === '1';

    if ($value === '') {
        continue;
    }

    if ($lang === 'post') {
        // Postal codes ride in this file under a fake language. Not every
        // place has them, so this is a bonus, not a replacement for the
        // separate postal-code dump.
        $postal[$id][] = $value;

        continue;
    }

    if ($lang === '') {
        // Untagged rows are mostly transliterations, but not always: the
        // Ukrainian name of a "міськрада" exists in this file ONLY as an
        // untagged row, which is why two districts stayed in Latin. Kept when
        // it is demonstrably this place's current name written in Cyrillic —
        // then resolveNames can promote it — and dropped otherwise, because
        // the pack does not need a fourth spelling of every village.
        if (preg_match('/\p{Cyrillic}/u', $value) && skeleton($value) === skeleton($wanted[$id])) {
            $aliases[$id][] = $value;
        }

        continue;
    }

    // Ukrainian text tagged ru (and the reverse) is common enough to matter:
    // the letters decide, not the tag.
    $lang = retagByAlphabet($lang, $value);

    if (! isset($wantedLangs[$lang])) {
        continue;
    }

    // A historical or colloquial name is never a display name, but it is
    // exactly what someone types into a search box — "Kiev", "Днепропетровск".
    // Same for a name written in the wrong alphabet for its language: useless
    // on a label, useful in the search index.
    if ($historic || $colloquial || ! scriptFits($lang, $value)) {
        $aliases[$id][] = $value;

        continue;
    }

    $score = ($preferred ? 40 : 0)
        + (skeleton($value) === skeleton($wanted[$id]) ? 60 : 0)
        - ($short ? 5 : 0);

    if (! isset($names[$id][$lang])) {
        $names[$id][$lang] = ['value' => $value, 'score' => $score];

        continue;
    }

    if ($score > $names[$id][$lang]['score']) {
        $aliases[$id][] = $names[$id][$lang]['value'];
        $names[$id][$lang] = ['value' => $value, 'score' => $score];
    } elseif ($value !== $names[$id][$lang]['value']) {
        $aliases[$id][] = $value;
    }
}

fclose($handle);

printf("  %d lines read, names for %d ids, postal codes for %d\n", $read, count($names), count($postal));

// ---------------------------------------------------------------- pass 3 ----
// Postal codes, if the separate postal dump was dropped next to the others.
// GeoNames keeps them in their own dataset (zip/<CC>.zip), keyed by place NAME
// and region rather than by geonameid, so this is a name join and therefore
// approximate — which is why the pack carries a range per city rather than
// pretending to know every code.

$postalRanges = readPostalRanges($source, $country);

if ($postalRanges !== []) {
    printf("  postal dump: %d name+region keys\n", count($postalRanges));
} else {
    echo "  no postal dump (drop zip/{$country}.txt next to {$country}.txt to fill the range columns)\n";
}

// ---------------------------------------------------------------- output ----
// Two CSVs per country, the shape the locations module imports: one for the
// regions, one for the places in them.

// The three name columns: English as the guaranteed base, the country's own
// language, and one more (Russian for a Ukrainian pack). When the local
// language IS Russian the last column simply repeats it, so the file shape
// stays identical for every country and one importer can read them all.
$base = $languages[0];
$local = $languages[1] ?? $base;
$extra = $languages[2] ?? ($local === 'ru' ? 'ru' : null);

// Name columns carry the LANGUAGE CODE, not a role: name_en, name_uk, name_ru.
// A "name_local" column looked tidier and was a mistake — the importer stored
// it under that literal key, so a shop switched to Ukrainian asked for uk,
// found nothing and fell back to English. The code is what every translated
// field in this project is keyed by.
$nameColumns = array_values(array_unique(array_filter([$base, $local, $extra])));

// Region of a place, and the district inside it. Districts are level 2 (raion),
// not level 3 (hromada), for a measured reason: every raion has a name in the
// local language, only 77% of hromadas do, and the raion is what an address is
// actually written with.
$districtOf = [];

foreach ($areas as $code => $area) {
    if ($area['level'] !== 2) {
        continue;
    }

    $areaAliases = $aliases[$area['geonameid']] ?? [];
    $areaNames = resolveNames($names[$area['geonameid']] ?? [], $areaAliases, [$local], $area['ascii']);

    // A district is translated for the same reason a name is — it is printed
    // beside the town to tell two namesakes apart, so a reader who cannot read
    // it cannot tell them apart. GeoNames' ascii name is the guaranteed base,
    // the same fallback the places themselves get below.
    //
    // "Полтавський Район" and "Житомирський район" are the same word with two
    // capitalisations in GeoNames; a filter listing both looks broken.
    $districtNames = [];

    foreach ($nameColumns as $lang) {
        $value = $areaNames[$lang] ?? ($lang === $base ? $area['ascii'] : '');

        if ($value !== '') {
            $districtNames[$lang] = normalizeUnitName($value);
        }
    }

    $districtOf[$code] = $districtNames;
}

$statesFile = "{$out}/{$country}/{$country}-states.csv";
$citiesFile = "{$out}/{$country}/{$country}-cities.csv";

$statesOut = fopen($statesFile, 'w');
$nameHeaders = array_map(fn (string $code) => "name_{$code}", $nameColumns);

fputcsv($statesOut, array_merge(['country', 'state_code'], $nameHeaders, ['geonameid']), ';', '"', '');

$stateCount = 0;

foreach ($areas as $code => $area) {
    if ($area['level'] !== 1) {
        continue;
    }

    $stateAliases = $aliases[$area['geonameid']] ?? [];
    $areaNames = resolveNames($names[$area['geonameid']] ?? [], $stateAliases, [$local], $area['ascii']);

    fputcsv($statesOut, array_merge(
        [$country, substr($code, strlen($country) + 1)],
        array_map(
            fn (string $lang) => normalizeUnitName($areaNames[$lang] ?? ($lang === $base ? $area['ascii'] : '')),
            $nameColumns,
        ),
        [$area['geonameid']],
    ), ';', '"', '');

    $stateCount++;
}

fclose($statesOut);

$citiesOut = fopen($citiesFile, 'w');
fputcsv($citiesOut, array_merge(
    ['geonameid', 'country', 'state_code'],
    array_map(fn (string $code) => "district_{$code}", $nameColumns),
    ['type'],
    $nameHeaders,
    ['latitude', 'longitude', 'population', 'postal_from', 'postal_to', 'aliases'],
), ';', '"', '');

$tmp = fopen($placesTmp, 'r');
$withRegional = 0;
$withPostal = 0;

while (($line = fgets($tmp)) !== false) {
    $place = json_decode($line, true);
    $id = $place['id'];

    $aliasList = $aliases[$id] ?? [];
    // A local name may be sitting in the alias pile because GeoNames tagged it
    // with the wrong language; resolveNames takes it back if it is demonstrably
    // this place's current name.
    $placeNames = resolveNames($names[$id] ?? [], $aliasList, [$local], $place['ascii'] ?: $place['name']);
    // Every place must have a name in the base language, or it cannot be
    // rendered at all; GeoNames' own ascii name is the guaranteed fallback.
    $placeNames[$base] ??= $place['ascii'] ?: $place['name'];

    if (isset($placeNames[$local]) && $local !== $base) {
        $withRegional++;
    }

    $parts = explode('.', $place['area']);
    $state = $parts[1] ?? '';
    $district = $districtOf["{$country}.{$state}.".($parts[2] ?? '')] ?? [];

    $aliasList = array_values(array_unique(array_filter(
        $aliasList,
        fn (string $alias) => ! in_array($alias, $placeNames, true),
    )));

    // The codes GeoNames itself attached to this place always win; the name
    // join is only consulted when it knows nothing.
    $codes = $postal[$id] ?? [];

    if ($codes === []) {
        $codes = $postalRanges[postalKey($state, $placeNames[$local] ?? $placeNames[$base])] ?? [];
    }

    sort($codes, SORT_STRING);

    if ($codes !== []) {
        $withPostal++;
    }

    fputcsv($citiesOut, array_merge(
        [$id, $country, $state],
        array_map(fn (string $lang) => $district[$lang] ?? '', $nameColumns),
        [$place['type']],
        array_map(fn (string $lang) => $placeNames[$lang] ?? '', $nameColumns),
        [
            $place['lat'],
            $place['lon'],
            $place['population'],
            $codes[0] ?? '',
            $codes !== [] ? end($codes) : '',
            implode('|', array_slice($aliasList, 0, 12)),
        ],
    ), ';', '"', '');
}

fclose($tmp);
fclose($citiesOut);
unlink($placesTmp);

file_put_contents("{$out}/{$country}/manifest.json", json_encode([
    'country' => $country,
    'languages' => ['base' => $base, 'local' => $local, 'extra' => $extra],
    'generated_at' => date('c'),
    'source' => [
        'name' => 'GeoNames',
        'url' => 'https://download.geonames.org/export/dump/',
        // CC BY 4.0 means the shop shipping this data owes an attribution.
        'licence' => 'CC BY 4.0',
        'files' => array_map(fn (string $file) => [
            'file' => basename($file),
            'size' => filesize($file),
            'modified' => date('c', filemtime($file)),
        ], array_filter([$countryFile, $admin1File, $altFile, postalFile($source, $country)])),
    ],
    'counts' => [
        'states' => $stateCount,
        'cities' => $placeCount,
        'cities_with_local_name' => $withRegional,
        'cities_with_postal' => $withPostal,
    ],
    'sha256' => [
        basename($statesFile) => hash_file('sha256', $statesFile),
        basename($citiesFile) => hash_file('sha256', $citiesFile),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

printf(
    "done in %ds — %d cities (%d with a local name, %d with a postal range), %d states → %s/%s\n",
    microtime(true) - $started,
    $placeCount,
    $withRegional,
    $withPostal,
    $stateCount,
    $out,
    $country,
);

// ------------------------------------------------------------------ utils ---

function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $argument, $matches)) {
            $options[$matches[1]] = $matches[2] ?? true;
        }
    }

    return $options;
}

/** @return array<string, array{geonameid: int, name: string}> "UA.32" => ... */
function readAdmin1(string $file, string $country): array
{
    $rows = [];
    $handle = fopen($file, 'r');

    while (($line = fgets($handle)) !== false) {
        [$code, $name, , $geonameid] = explode("\t", rtrim($line, "\r\n")) + [null, null, null, 0];

        if (str_starts_with((string) $code, $country.'.')) {
            $rows[$code] = ['geonameid' => (int) $geonameid, 'name' => $name];
        }
    }

    fclose($handle);

    return $rows;
}

/** Where the optional postal dump is looked for. */
function postalFile(string $source, string $country): ?string
{
    foreach (["{$source}/zip/{$country}.txt", "{$source}/zip_{$country}.txt", "{$source}/{$country}_zip.txt"] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Postal codes grouped by region + place name, so a city can be given the range
 * that covers it. The join is by name because that is all the dataset offers.
 *
 * @return array<string, list<string>>
 */
function readPostalRanges(string $source, string $country): array
{
    $file = postalFile($source, $country);

    if ($file === null) {
        return [];
    }

    $ranges = [];
    $handle = fopen($file, 'r');

    while (($line = fgets($handle)) !== false) {
        $f = explode("\t", rtrim($line, "\r\n"));

        if (count($f) < 5) {
            continue;
        }

        // country, postal code, place name, admin1 name, admin1 code, ...
        $key = postalKey($f[4], $f[2]);
        $ranges[$key][] = $f[1];
    }

    fclose($handle);

    return $ranges;
}

/** Names are compared folded and stripped of the usual noise. */
function postalKey(string $state, string $name): string
{
    $name = str_replace(['’', '‘', 'ʼ', '`', "'", '.', ',', '(', ')', '-', ' '], '', $name);

    return $state.'|'.mb_strtolower($name);
}


/**
 * The tag says ru, the letters say Ukrainian — believe the letters.
 *
 * GeoNames files "Володимирівка" under ru and "Житомирський район" under ru
 * for hundreds of Ukrainian places. Left alone, the Russian column of the pack
 * fills up with Ukrainian and the real Russian name (which is in the same file,
 * one line down) is thrown away as a duplicate.
 */
function retagByAlphabet(string $lang, string $value): string
{
    if ($lang === 'ru' && preg_match('/['.UKRAINIAN_ONLY.']/u', $value)) {
        return 'uk';
    }

    if ($lang === 'uk' && preg_match('/['.RUSSIAN_ONLY.']/u', $value)) {
        return 'ru';
    }

    return $lang;
}

/** Is this value written in the alphabet its language uses? */
function scriptFits(string $lang, string $value): bool
{
    $cyrillic = (bool) preg_match('/\p{Cyrillic}/u', $value);

    return in_array($lang, CYRILLIC_LANGUAGES, true) ? $cyrillic : ! $cyrillic;
}

/**
 * A loose Latin skeleton of a name, so "Високе" and "Vysoke" compare equal
 * while "Володимирівка" and "Vysoke" do not.
 *
 * Deliberately crude: it exists to answer "are these two strings the same
 * place name?", not to transliterate. Every romanisation of Cyrillic disagrees
 * about y/i, kh/h and ts/c, so those distinctions are folded away rather than
 * chosen between.
 */
function skeleton(string $value): string
{
    static $cache = [];

    if (isset($cache[$value])) {
        return $cache[$value];
    }

    static $cyrillic = [
        'щ' => 'shch', 'ш' => 'sh', 'ч' => 'ch', 'ж' => 'zh', 'х' => 'kh', 'ц' => 'ts',
        'ю' => 'iu', 'я' => 'ia', 'є' => 'ie', 'ї' => 'i', 'й' => 'i', 'ы' => 'y', 'э' => 'e', 'ё' => 'e',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'з' => 'z',
        'и' => 'y', 'і' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'ь' => '', 'ъ' => '',
    ];

    static $folds = [
        'shch' => 's', 'sh' => 's', 'ch' => 'c', 'zh' => 'z', 'kh' => 'h', 'ts' => 'c',
        'ya' => 'a', 'ia' => 'a', 'yu' => 'u', 'iu' => 'u', 'ye' => 'e', 'ie' => 'e', 'yi' => 'i',
        'j' => 'i', 'y' => 'i', 'g' => 'h', 'w' => 'v', 'x' => 'ks', 'q' => 'k',
    ];

    $out = strtr(mb_strtolower($value), $cyrillic);
    $out = strtr($out, $folds);
    $out = preg_replace('/[^a-z]/', '', $out);
    // "Bielsko" and "Bielssko" are one typo apart, not two places.
    $out = preg_replace('/(.)\1+/', '$1', (string) $out);

    return $cache[$value] = (string) $out;
}

/**
 * The names a place ends up published with, plus whatever the alias pile can
 * still contribute.
 *
 * A language with no name of its own gets one from the aliases ONLY when that
 * alias is demonstrably this place's current name — same skeleton as the name
 * the source file carries, and not written with the other language's exclusive
 * letters. Anything looser would label a village with the name it was renamed
 * away from, or file "Високе" as the Russian name, which are the two bugs this
 * guards against.
 *
 * Callers pass the languages worth filling this way — in practice the local
 * one. The base language always has GeoNames' own name to fall back on, and
 * guessing a THIRD language's spelling out of the local one is how "the
 * Russian name of this village" quietly becomes its Ukrainian name.
 *
 * Promoted aliases are removed from the pile by reference: a name is not also
 * an alias of itself.
 *
 * @param  array<string, array{value: string, score: int}>  $best
 * @param  list<string>  $aliasPile
 * @param  list<string>  $langs
 * @return array<string, string>
 */
function resolveNames(array $best, array &$aliasPile, array $langs, string $current): array
{
    $resolved = [];

    foreach ($best as $lang => $candidate) {
        $resolved[$lang] = $candidate['value'];
    }

    foreach ($langs as $lang) {
        if (isset($resolved[$lang])) {
            continue;
        }

        foreach ($aliasPile as $index => $alias) {
            if (scriptFits($lang, $alias)
                && retagByAlphabet($lang, $alias) === $lang
                && skeleton($alias) === skeleton($current)) {
                $resolved[$lang] = $alias;
                unset($aliasPile[$index]);

                break;
            }
        }
    }

    $aliasPile = array_values($aliasPile);

    return $resolved;
}

/**
 * "Полтавський Район" → "Полтавський район", "Житомирська Область" →
 * "Житомирська область".
 *
 * Only the generic tail is touched, and only when it is one of the words that
 * name a unit rather than a place. GeoNames capitalises it about half the time,
 * and a list showing both spellings reads as two different districts. English
 * names pass through untouched: "Oblast" is not in the list, and lowercasing it
 * would be wrong in a language that capitalises the word.
 */
function normalizeUnitName(string $name): string
{
    static $generic = ['район', 'округ', 'міськрада', 'громада', 'рада', 'область'];

    $parts = preg_split('/\s+/u', trim($name));

    if (count($parts) < 2) {
        return trim($name);
    }

    $last = mb_strtolower(end($parts));

    if (in_array($last, $generic, true)) {
        $parts[count($parts) - 1] = $last;
    }

    return implode(' ', $parts);
}
