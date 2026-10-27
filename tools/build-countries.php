<?php

/**
 * Builds the country list out of a GeoNames dump.
 *
 *     php.exe tools\build-countries.php --languages=en,uk,ru \
 *         --source=D:\AI\database-cities --out=data\geo
 *
 * Reads countryInfo.txt (252 rows) for the codes and alternateNamesV2.txt for
 * the translated names — the country's geonameid is the join.
 *
 * Its own script rather than a mode of build-locations.php: that one is built
 * around "one country at a time", and this is one file for the whole world,
 * imported once and rarely touched again.
 */

ini_set('memory_limit', '1G');

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $argument, $matches)) {
        $options[$matches[1]] = $matches[2] ?? true;
    }
}

$languages = array_values(array_filter(array_map('trim', explode(',', $options['languages'] ?? 'en'))));
$source = rtrim($options['source'] ?? 'D:\AI\database-cities', '\\/');
$out = rtrim($options['out'] ?? 'data/geo', '\\/');

$infoFile = "{$source}/countryInfo.txt";
$altFile = "{$source}/alternateNamesV2.txt";

foreach ([$infoFile, $altFile] as $file) {
    if (! is_file($file)) {
        fwrite(STDERR, "missing: {$file}\n");
        exit(1);
    }
}

$started = microtime(true);

// ------------------------------------------------------------ countryInfo ---

$countries = [];
$wanted = [];
$handle = fopen($infoFile, 'r');

while (($line = fgets($handle)) !== false) {
    if (str_starts_with($line, '#')) {
        continue;
    }

    $f = explode("\t", rtrim($line, "\r\n"));

    if (count($f) < 17 || $f[0] === '') {
        continue;
    }

    $countries[$f[0]] = [
        'code' => $f[0],
        'code3' => $f[1],
        'numeric' => $f[2],
        'name_en' => $f[4],
        'continent' => $f[8],
        'currency' => $f[10],
        // Both of these are here for the address form we are heading towards:
        // a phone field that knows its prefix and a postal code that can be
        // checked before the order is taken rather than after it fails.
        'phone' => $f[12],
        'postal_format' => $f[13],
        'postal_regex' => $f[14],
        'geonameid' => (int) $f[16],
    ];

    $wanted[(int) $f[16]] = $f[0];
}

fclose($handle);

printf("%d countries in countryInfo.txt\n", count($countries));

// -------------------------------------------------------- translated names ---

echo "streaming alternateNamesV2.txt\n";

$names = [];
$wantedLangs = array_flip($languages);
$handle = fopen($altFile, 'r');

while (($line = fgets($handle)) !== false) {
    $f = explode("\t", rtrim($line, "\r\n"), 9);

    if (count($f) < 4) {
        continue;
    }

    $code = $wanted[(int) $f[1]] ?? null;

    if ($code === null || ! isset($wantedLangs[$f[2]])) {
        continue;
    }

    // Historic and colloquial names are wrong for a country picker — nobody
    // wants to ship to "Zaire" — so only plain and preferred ones are kept.
    if (($f[6] ?? '') === '1' || ($f[7] ?? '') === '1') {
        continue;
    }

    if (! isset($names[$code][$f[2]]) || ($f[4] ?? '') === '1') {
        $names[$code][$f[2]] = $f[3];
    }
}

fclose($handle);

// ------------------------------------------------------------------ output ---

if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

$file = "{$out}/countries.csv";
$nameHeaders = array_map(fn (string $code) => "name_{$code}", $languages);

$csv = fopen($file, 'w');
fputcsv($csv, array_merge(
    ['code', 'code3', 'numeric', 'continent', 'currency', 'phone', 'postal_format', 'postal_regex'],
    $nameHeaders,
    ['geonameid'],
), ';', '"', '');

$translated = 0;

foreach ($countries as $code => $country) {
    $countryNames = $names[$code] ?? [];
    // countryInfo's own English name is the guaranteed fallback.
    $countryNames[$languages[0]] ??= $country['name_en'];

    if (count($countryNames) > 1) {
        $translated++;
    }

    fputcsv($csv, array_merge(
        [
            $country['code'], $country['code3'], $country['numeric'], $country['continent'],
            $country['currency'], $country['phone'], $country['postal_format'], $country['postal_regex'],
        ],
        array_map(fn (string $lang) => $countryNames[$lang] ?? '', $languages),
        [$country['geonameid']],
    ), ';', '"', '');
}

fclose($csv);

printf(
    "done in %ds — %d countries (%d with a translated name) → %s\n",
    microtime(true) - $started,
    count($countries),
    $translated,
    $file,
);
