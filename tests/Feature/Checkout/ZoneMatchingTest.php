<?php

namespace Tests\Feature\Checkout;

use Tests\TestCase;
use Torgash\Shipping\Models\Zone;
use Torgash\Checkout\Shipping\Location;
use Torgash\Shipping\ZoneMatcher;

/**
 * Exactly one zone wins, and which one decides whether a customer is offered
 * pickup, a courier, or nothing at all. Every rung of the ladder is pinned
 * here, because a wrong winner is invisible until someone in that town cannot
 * check out.
 */
class ZoneMatchingTest extends TestCase
{
    protected ZoneMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = app(ZoneMatcher::class);
    }

    public function test_the_catch_all_zone_matches_when_nothing_else_does(): void
    {
        // Shipped by the migration, so an untouched shop has somewhere to put
        // its rates.
        $zone = $this->match(new Location(country: 'PL'));

        $this->assertSame('Everywhere', $zone?->name['en']);
    }

    public function test_a_country_rule_beats_the_catch_all(): void
    {
        $this->zone('Ukraine', [['kind' => 'country', 'value' => 'UA']]);

        $this->assertSame('Ukraine', $this->match(new Location(country: 'UA'))?->name['en']);
        $this->assertSame('Everywhere', $this->match(new Location(country: 'PL'))?->name['en']);
    }

    public function test_a_region_beats_its_country_and_does_not_leak_into_another(): void
    {
        $this->zone('Ukraine', [['kind' => 'country', 'value' => 'UA']]);
        $this->zone('Kyiv region', [['kind' => 'state', 'value' => 'UA:12']]);

        $this->assertSame('Kyiv region', $this->match(new Location(country: 'UA', state: '12'))?->name['en']);

        // Region codes repeat between countries; "12" in Russia is not this.
        $this->assertSame('Everywhere', $this->match(new Location(country: 'RU', state: '12'))?->name['en']);
    }

    public function test_a_town_beats_its_region(): void
    {
        $this->zone('Ukraine', [['kind' => 'country', 'value' => 'UA']]);
        $this->zone('Kyiv region', [['kind' => 'state', 'value' => 'UA:12']]);
        $this->zone('Our city', [['kind' => 'place', 'value' => '703448']]);

        $zone = $this->match(new Location(country: 'UA', state: '12', placeId: 703448));

        $this->assertSame('Our city', $zone?->name['en'], 'the town zone lost to the region it sits in');
    }

    public function test_a_postal_code_is_the_narrowest_of_all(): void
    {
        $this->zone('Our city', [['kind' => 'place', 'value' => '703448']]);
        $this->zone('One street', [['kind' => 'postal', 'value' => '01001']]);

        $zone = $this->match(new Location(country: 'UA', placeId: 703448, postal: '01001'));

        $this->assertSame('One street', $zone?->name['en']);
    }

    public function test_a_postal_prefix_matches_the_start_of_the_code(): void
    {
        $this->zone('Ukraine', [['kind' => 'country', 'value' => 'UA']]);
        $this->zone('Kyiv codes', [['kind' => 'postal_prefix', 'value' => '01']]);

        $this->assertSame('Kyiv codes', $this->match(new Location(country: 'UA', postal: '01133'))?->name['en']);
        $this->assertSame('Ukraine', $this->match(new Location(country: 'UA', postal: '65001'))?->name['en']);
    }

    public function test_equally_narrow_zones_are_decided_by_their_order(): void
    {
        $this->zone('Second', [['kind' => 'country', 'value' => 'UA']], position: 20);
        $this->zone('First', [['kind' => 'country', 'value' => 'UA']], position: 10);

        $this->assertSame('First', $this->match(new Location(country: 'UA'))?->name['en']);
    }

    public function test_a_disabled_zone_never_wins(): void
    {
        $this->zone('Ukraine', [['kind' => 'country', 'value' => 'UA']], enabled: false);

        $this->assertSame('Everywhere', $this->match(new Location(country: 'UA'))?->name['en']);
    }

    public function test_an_empty_location_matches_nothing(): void
    {
        // Before the customer says where they are, no zone is claimed — and so
        // no shipping method is offered on a guess.
        $this->assertNull($this->matcher->match(new Location));
    }

    /** @param list<array{kind: string, value: string}> $rules */
    protected function zone(string $name, array $rules, int $position = 0, bool $enabled = true): Zone
    {
        $zone = Zone::create(['name' => ['en' => $name], 'position' => $position, 'enabled' => $enabled]);

        foreach ($rules as $rule) {
            $zone->rules()->create($rule);
        }

        return $zone;
    }

    protected function match(Location $location): ?Zone
    {
        // The matcher memoises per request; tests build the world as they go.
        return (new ZoneMatcher)->match($location);
    }
}
