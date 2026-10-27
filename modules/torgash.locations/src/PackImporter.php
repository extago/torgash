<?php

namespace Torgash\Locations;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

/**
 * Reads a prepared pack (two CSVs per country, see tools/build-locations.php)
 * into the registry.
 *
 * The rule that shapes everything here: an import is a DIFF, never a reload.
 * A typical city module overwrites the table on re-import, which means a
 * shop that added a city the dataset does not list loses it on the next update
 * — the exact failure the user hit in a live shop, where the base had no
 * Simferopol and the courier delivered there anyway. So:
 *
 *   - rows are matched by geonameid, the pack's stable key;
 *   - a row a human edited is left completely alone;
 *   - a row that vanished from the pack is flagged, not deleted, because an
 *     order may point at it.
 */
class PackImporter
{
    /** Columns the states file must have, in any order. */
    public const STATE_COLUMNS = ['country', 'state_code', 'name_en'];

    /** Columns the cities file must have. */
    public const CITY_COLUMNS = ['country', 'state_code', 'name_en'];

    /**
     * A row has to say which place it IS, and packs differ on how: a GeoNames
     * pack keys on geonameid, an OSM one on ref ("osm:n26150422"). One of the
     * two is enough — a file with neither is not a pack.
     */
    public const CITY_KEY_COLUMNS = ['ref', 'geonameid'];

    /** Rows per statement. Big enough to matter, small enough to stay readable in a log. */
    public const CHUNK = 1000;

    /** @var array<string, int> "UA|32" => state id */
    protected array $stateIds = [];

    /**
     * @param  callable|null  $onProgress  called with the number of rows done
     * @param  bool  $replace  wipe this country's rows first — see wipe()
     * @return array{states: int, created: int, updated: int, skipped: int, flagged: int, deleted: int}
     */
    public function import(string $statesFile, string $citiesFile, ?callable $onProgress = null, bool $replace = false): array
    {
        $counts = ['states' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'flagged' => 0, 'deleted' => 0];

        if ($replace) {
            $counts['deleted'] = $this->wipe($this->countryOf($citiesFile));
        }

        $counts['states'] = $this->importStates($statesFile);

        $seen = [];
        $done = 0;
        $country = null;
        $batch = [];

        foreach ($this->rows($citiesFile, self::CITY_COLUMNS, self::CITY_KEY_COLUMNS) as $row) {
            $country ??= $row['country'];
            $attributes = $this->placeAttributes($row);

            // Without the pack's own key there is nothing to match on next
            // time, so such a row would duplicate itself on every import; and a
            // row with no name in any language cannot be rendered, searched or
            // chosen, which makes it furniture rather than a place.
            if ($attributes['ref'] === '' || $attributes['name'] === []) {
                $counts['skipped']++;

                continue;
            }

            $seen[] = $attributes['ref'];
            $batch[] = $attributes;

            if (count($batch) >= self::CHUNK) {
                $this->flush($batch, $counts);
                $batch = [];
                $onProgress && $onProgress($done += self::CHUNK);
            }
        }

        if ($batch !== []) {
            $done += count($batch);
            $this->flush($batch, $counts);
            $onProgress && $onProgress($done);
        }

        $counts['flagged'] = $this->flagMissing($country, $seen);

        return $counts;
    }

    /**
     * Rows this pack says it is taking over from an older one.
     *
     * A pack built from a different source gives the same town a different key
     * ("osm:n26150422" where the shop has "geonames:703448"), and without this
     * the import would insert a second Kyiv, leave the first flagged as gone,
     * and every shipping zone would go on pointing at the row nobody updates
     * any more. So a row may name its predecessor, and the key is moved onto
     * the existing row before the upsert — same row, same id, new source.
     *
     * Skipped when the new key already exists (the second run of the same
     * import) and, as everywhere here, when a person has edited the row.
     *
     * @param  list<array<string, mixed>>  $batch
     */
    protected function adopt(array $batch): void
    {
        $pairs = [];

        foreach ($batch as $row) {
            if (($row['previous_ref'] ?? '') !== '' && $row['previous_ref'] !== $row['ref']) {
                $pairs[] = [$row['previous_ref'], $row['ref']];
            }
        }

        if ($pairs === []) {
            return;
        }

        $values = implode(',', array_fill(0, count($pairs), '(?,?)'));

        DB::update(
            "update m_locations_places p set ref = v.new_ref
               from (values {$values}) as v(old_ref, new_ref)
              where p.ref = v.old_ref
                and p.edited = false
                and p.source = 'import'
                and not exists (select 1 from m_locations_places q where q.ref = v.new_ref)",
            array_merge(...$pairs),
        );
    }

    /**
     * One statement per chunk instead of a select-then-write per row — the
     * difference between a minute and a few seconds on a Russian pack.
     *
     * The "leave edited rows alone" rule lives in the WHERE of the DO UPDATE,
     * so it is the database refusing to overwrite them rather than a check
     * some future caller could forget. RETURNING (xmax = 0) is how Postgres
     * tells an insert from an update, which is where the counts come from:
     * rows that conflicted AND were protected come back in neither.
     *
     * @param  list<array<string, mixed>>  $batch
     * @param  array<string, int>  $counts
     */
    protected function flush(array $batch, array &$counts): void
    {
        $this->adopt($batch);

        $now = now();
        $columns = ['ref', 'geonameid', 'country', 'state_id', 'district', 'type', 'name', 'aliases',
            'latitude', 'longitude', 'population', 'postal_from', 'postal_to', 'codes', 'source'];
        $json = ['name', 'district', 'codes'];

        $placeholders = [];
        $bindings = [];

        foreach ($batch as $row) {
            $placeholders[] = '('.implode(',', array_fill(0, count($columns) + 2, '?')).')';

            foreach ($columns as $column) {
                // FORCE_OBJECT because an empty PHP array encodes as [], and the
                // generated search_text column calls jsonb_each_text on it: one
                // nameless row in a 145 000-row pack aborted the entire import.
                $bindings[] = in_array($column, $json, true) && $row[$column] !== null
                    ? json_encode($row[$column], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)
                    : $row[$column];
            }

            $bindings[] = $now;
            $bindings[] = $now;
        }

        $updates = implode(',', array_map(
            fn (string $column) => "{$column} = excluded.{$column}",
            array_diff($columns, ['ref']),
        ));

        $returned = DB::select(
            'insert into m_locations_places ('.implode(',', $columns).',created_at,updated_at) values '
            .implode(',', $placeholders)
            ." on conflict (ref) do update set {$updates}, missing_in_source = false, updated_at = excluded.updated_at"
            .' where m_locations_places.edited = false and m_locations_places.source = \'import\''
            .' returning (xmax = 0) as inserted',
            $bindings,
        );

        foreach ($returned as $row) {
            $counts[$row->inserted ? 'created' : 'updated']++;
        }

        // Everything the statement declined to touch was protected.
        $counts['skipped'] += count($batch) - count($returned);
    }

    /**
     * Places this country's pack no longer lists. Kept, because an address or
     * an order may already point at one; flagged, so the admin can see what
     * the dataset dropped and decide.
     *
     * @param  list<string>  $seen
     */
    protected function flagMissing(?string $country, array $seen): int
    {
        if ($country === null) {
            return 0;
        }

        // Through a temporary table rather than a NOT IN list: a Russian pack
        // has 192 000 keys, and no database should be asked to plan a query
        // with that many bound parameters.
        DB::statement('create temporary table if not exists locations_seen (ref varchar(64) primary key)');
        DB::statement('truncate locations_seen');

        foreach (array_chunk($seen, 5000) as $chunk) {
            DB::table('locations_seen')->insertOrIgnore(
                array_map(fn (string $ref) => ['ref' => $ref], array_filter($chunk)),
            );
        }

        $flagged = DB::update(
            'update m_locations_places p set missing_in_source = true
               where p.country = ?
                 and p.source = \'import\'
                 and p.edited = false
                 and p.ref is not null
                 and not exists (select 1 from locations_seen s where s.ref = p.ref)',
            [$country],
        );

        DB::statement('drop table if exists locations_seen');

        return $flagged;
    }

    /**
     * Everything this country has, gone — including places added by hand.
     *
     * The escape hatch for "I made a mess of it": a normal import protects
     * edited rows, which is exactly what you do NOT want when the edits are the
     * mess. Destructive on purpose, and never the default: the screen asks for
     * it explicitly and says what it takes with it.
     */
    public function wipe(?string $country): int
    {
        if ($country === null) {
            return 0;
        }

        $deleted = Place::where('country', $country)->delete();
        State::where('country', $country)->delete();

        return $deleted;
    }

    /** The country a pack is for, read from its first row. */
    public function countryOf(string $citiesFile): ?string
    {
        foreach ($this->rows($citiesFile, self::CITY_COLUMNS) as $row) {
            return $row['country'] ?: null;
        }

        return null;
    }

    /**
     * The world's countries. One global file, imported on its own, and the only
     * part of the registry a shop can reasonably curate by hand — which is why
     * an existing row keeps its enabled flag when the file is imported again.
     *
     * @return array{created: int, updated: int}
     */
    public function importCountries(string $file, ?callable $onProgress = null): array
    {
        $counts = ['created' => 0, 'updated' => 0];
        $done = 0;

        foreach ($this->rows($file, ['code', 'name_en']) as $row) {
            $country = Country::firstOrNew(['code' => strtoupper($row['code'])]);
            $wasThere = $country->exists;

            $fields = [
                'code3' => ($row['code3'] ?? '') ?: null,
                'numeric_code' => ($row['numeric'] ?? '') ?: null,
                'continent' => ($row['continent'] ?? '') ?: null,
                'currency' => ($row['currency'] ?? '') ?: null,
                'phone' => ($row['phone'] ?? '') ?: null,
                'postal_format' => ($row['postal_format'] ?? '') ?: null,
                'postal_regex' => ($row['postal_regex'] ?? '') ?: null,
                'geonameid' => ($row['geonameid'] ?? '') ?: null,
            ];

            $names = $this->names($row);

            if ($wasThere && ($country->edited || $country->source === 'manual')) {
                // Touched by a person, so the pack no longer overwrites: it only
                // fills what is still empty and ADDS name languages that are
                // missing. That is the case this whole flag exists for — a shop
                // adds a language, translates the country list by hand, and the
                // next import must bring new data without undoing that work.
                $fields = array_filter($fields, fn ($value, $key) => blank($country->{$key}) && filled($value), ARRAY_FILTER_USE_BOTH);
                $names = ($country->name ?? []) + $names;
            }

            $country->fill($fields + ['name' => $names]);

            $counts[$wasThere ? 'updated' : 'created']++;
            $country->save();

            $onProgress && $onProgress(++$done);
        }

        return $counts;
    }

    protected function importStates(string $file): int
    {
        $count = 0;
        $pending = [];

        // Resolved first, written second, because a pack may RENUMBER regions
        // and the numbers overlap: handing Vinnytsia its ISO code 05 collides
        // with the region GeoNames numbered 05 until that one moves too. So a
        // row about to change its code is parked on a code nothing else can
        // hold, and the real ones are handed out once every row has let go.
        foreach ($this->rows($file, self::STATE_COLUMNS) as $row) {
            $state = $this->stateFor($row);

            if ($state->exists && $state->code !== $row['state_code']) {
                $state->code = '~'.$state->id;
                $state->save();
            }

            $pending[] = [$state, $row];
        }

        foreach ($pending as [$state, $row]) {
            // A region a person made or renamed is theirs, and only its number
            // and its id come from the pack, so its places still land in it:
            // a code is bookkeeping, a name is a decision.
            $theirs = $state->exists && ($state->edited || $state->source === 'manual');

            $state->fill($theirs ? ['code' => $row['state_code']] : [
                'code' => $row['state_code'],
                'name' => $this->names($row),
                // Only overwritten when the pack actually carries one: an OSM
                // pack has no GeoNames ids, and wiping them would throw away
                // the row's link back to where it came from.
                'geonameid' => ($row['geonameid'] ?? '') !== ''
                    ? (int) $row['geonameid']
                    : $state->geonameid,
            ]);

            if (! $state->exists || $state->isDirty()) {
                $state->save();
                $count++;
            }

            $this->stateIds[$row['country'].'|'.$row['state_code']] = $state->id;
        }

        return $count;
    }

    /**
     * The region row this line is about.
     *
     * Found by ref, never by code: a region's code is not its identity. ISO
     * 3166-2 calls Dnipropetrovsk "12" and GeoNames calls Kyiv City "12", so
     * matching on the code walks straight into another region's row — measured,
     * not imagined: a first attempt renamed Zaporizhzhia into Ivano-Frankivsk.
     *
     * A pack with no refs at all (the older GeoNames files) still works: the
     * ref such a pack would be given today is what the migration wrote into
     * the rows it built.
     *
     * @param  array<string, string>  $row
     */
    protected function stateFor(array $row): State
    {
        $ref = trim((string) ($row['ref'] ?? ''))
            ?: (($row['geonameid'] ?? '') !== '' ? 'geonames:'.$row['geonameid'] : '');
        $previous = trim((string) ($row['previous_ref'] ?? ''));

        if ($ref !== '') {
            if ($state = State::firstWhere(['country' => $row['country'], 'ref' => $ref])) {
                return $state;
            }

            // The pack names the row it takes over: same region, new source,
            // so the row keeps its id and everything already pointing at it.
            if ($previous !== '' && ($state = State::firstWhere(['country' => $row['country'], 'ref' => $previous]))) {
                $state->ref = $ref;

                return $state;
            }
        }

        // A shop whose regions predate refs, or a pack that carries none: the
        // code is all there is to go on.
        return State::firstWhere(['country' => $row['country'], 'code' => $row['state_code']])
            ?? new State(['country' => $row['country'], 'ref' => $ref ?: null]);
    }

    /** @param array<string, string> $row */
    protected function placeAttributes(array $row): array
    {
        $geonameid = (int) ($row['geonameid'] ?? 0);
        // An older pack has no ref column; the key it would be given today is
        // exactly what the migration wrote into the existing rows, so such a
        // pack still updates them instead of doubling them.
        $ref = trim((string) ($row['ref'] ?? '')) ?: ($geonameid > 0 ? 'geonames:'.$geonameid : '');

        // Official identifiers of whatever register the source follows. Kept
        // because they outlive the source's own ids, and because a carrier's
        // directory is joined against them.
        $codes = array_filter([
            'katotth' => trim((string) ($row['katotth'] ?? '')),
            'koatuu' => trim((string) ($row['koatuu'] ?? '')),
        ]);

        return [
            'ref' => $ref,
            'previous_ref' => trim((string) ($row['previous_ref'] ?? '')),
            'geonameid' => $geonameid ?: null,
            'codes' => $codes ?: null,
            'country' => $row['country'],
            'state_id' => $this->stateId($row['country'], $row['state_code'] ?? ''),
            'district' => ($this->translations($row, 'district_') ?: $this->undeterminedDistrict($row)) ?: null,
            'type' => ($row['type'] ?? '') ?: 'settlement',
            'name' => $this->names($row),
            'aliases' => ($row['aliases'] ?? '') ?: null,
            'latitude' => ($row['latitude'] ?? '') !== '' ? (float) $row['latitude'] : null,
            'longitude' => ($row['longitude'] ?? '') !== '' ? (float) $row['longitude'] : null,
            'population' => (int) ($row['population'] ?? 0),
            'postal_from' => ($row['postal_from'] ?? '') ?: null,
            'postal_to' => ($row['postal_to'] ?? '') ?: null,
            'source' => 'import',
        ];
    }

    /**
     * Every `name_<code>` column becomes one entry of the JSONB value, keyed by
     * the language code itself.
     *
     * An earlier version used a fixed name_en/name_local/name_ru trio and
     * stored the middle one under the literal key "local". It read tidily and
     * was wrong: a shop switched to Ukrainian asks for `uk`, finds nothing and
     * silently shows English. Whatever codes a pack ships are the codes stored,
     * so adding a language to a pack needs no change here.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    protected function names(array $row): array
    {
        return $this->translations($row, 'name_');
    }

    /**
     * `<prefix><code>` columns → one JSONB value keyed by the language code.
     *
     * A `<prefix>local` column is skipped rather than guessed at: there is no
     * way to know from the file which language it holds, and storing a
     * Ukrainian name under a Russian code would be worse than not storing it.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    protected function translations(array $row, string $prefix): array
    {
        $values = [];

        foreach ($row as $column => $value) {
            if (! str_starts_with($column, $prefix) || trim((string) $value) === '') {
                continue;
            }

            $code = substr($column, strlen($prefix));

            if ($code === 'local' || $code === '') {
                continue;
            }

            $values[$code] = trim($value);
        }

        return $values;
    }

    /**
     * A pack from before districts had languages: one bare `district` column,
     * in whatever language that pack was written in.
     *
     * Filed under "und" (ISO 639-2, "undetermined") rather than guessed at.
     * The file does not say, and the two candidates differ by a letter —
     * "Броварський район" against "Броварский район" — so a country-shaped
     * guess would quietly store Ukrainian under `ru`. Nothing is lost by
     * admitting it: t() falls through to the first available value, so the
     * district still renders in every locale, and a pack that does name its
     * languages overwrites the key on the next import.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    protected function undeterminedDistrict(array $row): array
    {
        $district = trim((string) ($row['district'] ?? ''));

        return $district !== '' ? ['und' => $district] : [];
    }

    protected function stateId(string $country, string $code): ?int
    {
        if ($code === '') {
            return null;
        }

        return $this->stateIds[$country.'|'.$code] ??= State::where('country', $country)
            ->where('code', $code)
            ->value('id');
    }

    /**
     * Streams a semicolon-separated CSV as associative rows, so a 18 MB file
     * costs one line of memory rather than all of it.
     *
     * @param  list<string>  $required
     * @return \Generator<int, array<string, string>>
     */
    protected function rows(string $file, array $required, array $anyOf = []): \Generator
    {
        if (! is_file($file)) {
            throw new RuntimeException("Pack file not found: {$file}");
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, 0, ';', '"', '');

        if ($header === false) {
            throw new RuntimeException("Pack file is empty: {$file}");
        }

        // A byte-order mark would otherwise become part of the first column
        // name and every lookup against it would quietly miss.
        $header[0] = ltrim((string) $header[0], "\u{FEFF}");

        if ($anyOf !== [] && array_intersect($anyOf, $header) === []) {
            throw new RuntimeException(sprintf(
                'File %s has none of the key columns: %s.',
                basename($file),
                implode(' / ', $anyOf),
            ));
        }

        if ($missing = array_diff($required, $header)) {
            throw new RuntimeException(sprintf(
                'File %s is missing the column(s): %s. Expected a pack built by tools/build-locations.php.',
                basename($file),
                implode(', ', $missing),
            ));
        }

        while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            yield array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
        }

        fclose($handle);
    }

    /** Rows in the cities file, for a progress bar that means something. */
    public function countRows(string $file): int
    {
        $lines = 0;
        $handle = fopen($file, 'r');

        while (fgets($handle) !== false) {
            $lines++;
        }

        fclose($handle);

        return max(0, $lines - 1);
    }

    /**
     * Packs sitting in the configured folder, as country => [states, cities].
     *
     * @return array<string, array{states: string, cities: string}>
     */
    public static function available(string $root): array
    {
        $packs = [];

        foreach (glob($root.'/*/*-cities.csv') ?: [] as $cities) {
            $country = basename($cities, '-cities.csv');
            $states = dirname($cities)."/{$country}-states.csv";

            if (is_file($states)) {
                // What it was built from, as the builder wrote it down: the
                // Import screen shows the source, its date and its licence.
                $manifest = is_file(dirname($cities).'/manifest.json')
                    ? json_decode((string) file_get_contents(dirname($cities).'/manifest.json'), true)
                    : null;

                $packs[$country] = ['states' => $states, 'cities' => $cities, 'manifest' => is_array($manifest) ? $manifest : null];
            }
        }

        ksort($packs);

        return $packs;
    }
}
