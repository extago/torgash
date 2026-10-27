<?php

namespace Torgash\Shipping;

use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Shipping\Location;
use Torgash\Shipping\Models\Zone;

/**
 * Picks the one zone a location belongs to.
 *
 * "The one" is the point. Every rule that matches is a candidate, and the
 * narrowest wins: a rule naming this town beats one naming its region, which
 * beats one naming the country, which beats the catch-all. That is what lets a
 * shop say "courier everywhere, and in our own city also pickup" without the
 * two answers fighting — the city zone simply wins there.
 *
 * Ties are broken by the zone's own position, so two equally narrow zones have
 * a defined, admin-visible order rather than whatever the database returns.
 */
class ZoneMatcher
{
    /** @var array<string, ?Zone> memo for one request */
    protected array $memo = [];

    public function match(Location $location): ?Zone
    {
        if ($location->isEmpty()) {
            return null;
        }

        $key = implode('|', [$location->country, $location->state, $location->placeId, $location->postal]);

        return $this->memo[$key] ??= $this->find($location);
    }

    /**
     * The zone that covers the world — what "anywhere" means before the
     * customer has said where they are.
     *
     * Prices shown on a cart nobody has addressed yet come from here: a rate
     * assigned to the catch-all is by definition valid without knowing the
     * address, while a rate that exists only in one city has nothing to say
     * until the city is named. A shop that deleted the catch-all zone offers
     * nothing until then, which is the honest answer rather than a guess.
     */
    public function fallback(): ?Zone
    {
        $id = DB::table('m_shipping_zone_rules as r')
            ->join('m_shipping_zones as z', 'z.id', '=', 'r.zone_id')
            ->where('z.enabled', true)
            ->where('r.kind', 'country')
            ->where('r.value', '*')
            ->orderBy('z.position')
            ->orderBy('z.id')
            ->value('z.id');

        return $id ? Zone::find($id) : null;
    }

    protected function find(Location $location): ?Zone
    {
        $matches = DB::table('m_shipping_zone_rules as r')
            ->join('m_shipping_zones as z', 'z.id', '=', 'r.zone_id')
            ->where('z.enabled', true)
            ->where(function ($query) use ($location) {
                // The catch-all, which is what an untouched shop has.
                $query->where(fn ($q) => $q->where('r.kind', 'country')->where('r.value', '*'));

                if ($location->country !== null) {
                    $query->orWhere(fn ($q) => $q->where('r.kind', 'country')->where('r.value', $location->country));
                }

                if ($location->state !== null && $location->country !== null) {
                    // Region codes repeat between countries — "12" is a region
                    // in both Ukraine and Russia — so a region rule only counts
                    // inside its own country.
                    $query->orWhere(fn ($q) => $q->where('r.kind', 'state')
                        ->where('r.value', $location->country.':'.$location->state));
                }

                if ($location->placeId !== null) {
                    $query->orWhere(fn ($q) => $q->where('r.kind', 'place')->where('r.value', (string) $location->placeId));
                }

                if ($location->postal !== null && $location->postal !== '') {
                    $query->orWhere(fn ($q) => $q->where('r.kind', 'postal')->where('r.value', $location->postal));

                    // A prefix rule is stored as the prefix itself, so the
                    // comparison runs the other way round: does the customer's
                    // code start with what the rule says.
                    $query->orWhere(fn ($q) => $q->where('r.kind', 'postal_prefix')
                        ->whereRaw('? like r.value || \'%\'', [$location->postal]));

                    // A glob rule keeps the admin's own pattern ("98?78") and
                    // is translated the moment it is compared: * is any run,
                    // ? is one character — the usual postal syntax, and the
                    // reason the admin screen offers one textarea, not a row
                    // per prefix.
                    $query->orWhere(fn ($q) => $q->where('r.kind', 'postal_glob')
                        ->whereRaw("? like translate(r.value, '*?', '%_')", [$location->postal]));
                }
            })
            ->orderByDesc('r.level')
            ->orderBy('z.position')
            ->orderBy('z.id')
            ->select('z.id')
            ->first();

        return $matches ? Zone::find($matches->id) : null;
    }
}
