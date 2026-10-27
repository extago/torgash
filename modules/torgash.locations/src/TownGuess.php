<?php

namespace Torgash\Locations;

use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;
use Torgash\Locations\Models\Place;

/**
 * The town to offer in "Is your town …?" (setting `town_prompt`) — a guess,
 * never an answer: nothing here is stored as the visitor's town until they
 * press "Yes", which goes through the window's own save like a town picked by
 * hand. The display rules keep reading only the chosen town
 * (locations.md: nothing is GUESSED for them).
 *
 * `browser` is guessed in the browser (the coordinates never reach the page
 * render; `places.nearest` answers them); `ip` reads a city database file the
 * owner puts on the disk (DB-IP Lite or GeoLite2 — both .mmdb); every mode
 * falls back to the shop's own town, and with none set there is no question.
 */
class TownGuess
{
    public const MODES = ['off', 'default', 'browser', 'ip'];

    public function mode(): string
    {
        $mode = (string) setting('torgash.locations', 'town_prompt', 'off');

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /** What the server can guess by itself: by IP in that mode, else the shop's town. */
    public function guess(?string $ip): ?Place
    {
        return ($this->mode() === 'ip' ? $this->byIp($ip) : null) ?? $this->shopTown();
    }

    /** The shop's own town by its name in any language; of namesakes, the most populous. */
    public function shopTown(): ?Place
    {
        $name = trim((string) setting('torgash.locations', 'town_prompt_default', ''));

        return $name === '' ? null : $this->named($name);
    }

    public function byIp(?string $ip): ?Place
    {
        $path = trim((string) setting('torgash.locations', 'geoip_database', ''));

        if ($ip === null || $ip === '' || $path === '') {
            return null;
        }

        $file = preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) ? $path : base_path($path);

        if (! is_file($file)) {
            return null;
        }

        try {
            $reader = new Reader($file);
            $record = $reader->get($ip);
            $reader->close();
        } catch (\Throwable $e) {
            // A broken or half-copied file must not take the page with it.
            Log::warning('Town by IP: the city database could not be read', ['file' => $file, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($record)) {
            return null;
        }

        $country = strtoupper((string) ($record['country']['iso_code'] ?? ''));
        $lat = $record['location']['latitude'] ?? null;
        $lng = $record['location']['longitude'] ?? null;

        // The coordinates first: a database's English "Kiev" or "Dnipropetrovsk"
        // need not be any name the registry has, but the point is the point.
        if (is_numeric($lat) && is_numeric($lng)) {
            $place = Place::query()->available()
                ->when($country !== '', fn ($query) => $query->where('country', $country))
                ->nearest((float) $lat, (float) $lng)->first();

            if ($place) {
                return $place;
            }
        }

        $city = (string) ($record['city']['names']['en'] ?? '');

        return $city === '' ? null : $this->named($city, $country ?: null);
    }

    protected function named(string $name, ?string $country = null): ?Place
    {
        $wanted = mb_strtolower($name);
        $found = Place::query()->available()
            ->when($country, fn ($query) => $query->where('country', $country))
            ->search($name)->limit(20)->get();

        // An exact name before a longer one that merely contains it.
        return $found->first(fn (Place $place) => in_array($wanted, array_map(
            fn ($value) => mb_strtolower((string) $value), (array) $place->name,
        ), true)) ?? $found->first();
    }
}
