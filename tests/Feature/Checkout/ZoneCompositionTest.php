<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Shipping\Models\Zone;
use Torgash\Checkout\Shipping\Location;
use Torgash\Shipping\ZoneMatcher;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

/**
 * A zone is composed, not hand-coded rule by rule: the editor posts lists of
 * countries, regions and towns and a block of postal patterns, and the
 * controller flattens all four into the rules the matcher already understands.
 *
 * Pinned here because the flattening is where a zone silently ends up covering
 * more or less than the admin drew — a postal pattern filed as the wrong kind,
 * a region that lost its country, a duplicate that shifts a tie.
 */
class ZoneCompositionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());
    }

    public function test_several_countries_become_one_rule_each(): void
    {
        $this->post('http://localhost/admin/shipping/zones', $this->form([
            'countries' => ['UA', 'ua', 'RO'],
        ]))->assertRedirect();

        $rules = $this->latestZone()->rules;

        // "ua" folded into "UA", so three inputs are two rules.
        $this->assertEqualsCanonicalizing(
            ['UA', 'RO'],
            $rules->where('kind', 'country')->pluck('value')->all()
        );
        $this->assertSame([1, 1], $rules->pluck('level')->all());
    }

    public function test_the_catch_all_checkbox_is_a_country_rule_at_level_zero(): void
    {
        $this->post('http://localhost/admin/shipping/zones', $this->form([
            'countries' => ['*'],
        ]))->assertRedirect();

        $rule = $this->latestZone()->rules->sole();

        $this->assertSame(['country', '*', 0], [$rule->kind, $rule->value, $rule->level]);
    }

    public function test_a_region_keeps_its_country_prefix(): void
    {
        $this->post('http://localhost/admin/shipping/zones', $this->form([
            'regions' => ['UA:12', 'bare-code-no-country'],
        ]))->assertRedirect();

        $rules = $this->latestZone()->rules;

        // The one without a ":" cannot be matched against anything and is dropped.
        $rule = $rules->sole();
        $this->assertSame(['state', 'UA:12', 2], [$rule->kind, $rule->value, $rule->level]);
    }

    public function test_a_town_id_becomes_a_place_rule(): void
    {
        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);
        $place = Place::create(['country' => 'UA', 'name' => ['en' => 'Kyiv'], 'source' => 'import']);

        $this->post('http://localhost/admin/shipping/zones', $this->form([
            'towns' => [(string) $place->id, 'not-a-number'],
        ]))->assertRedirect();

        $rule = $this->latestZone()->rules->sole();
        $this->assertSame(['place', (string) $place->id, 3], [$rule->kind, $rule->value, $rule->level]);
    }

    public function test_the_postal_block_is_split_into_three_kinds(): void
    {
        $this->post('http://localhost/admin/shipping/zones', $this->form([
            'postal' => "01001\n49*\n9?9\n  \n01001",
        ]))->assertRedirect();

        $rules = $this->latestZone()->rules;

        $this->assertSame('01001', $rules->firstWhere('kind', 'postal')?->value);
        $this->assertSame('49', $rules->firstWhere('kind', 'postal_prefix')?->value, 'a trailing * is a prefix');
        $this->assertSame('9?9', $rules->firstWhere('kind', 'postal_glob')?->value, 'a mid-string wildcard is a glob');
        $this->assertCount(3, $rules, 'the blank line and the repeat added nothing');
    }

    public function test_editing_a_zone_shows_its_postal_patterns_back_in_the_textarea(): void
    {
        $zone = Zone::create(['name' => ['en' => 'Codes'], 'enabled' => true]);
        $zone->rules()->createMany([
            ['kind' => 'postal', 'value' => '01001'],
            ['kind' => 'postal_prefix', 'value' => '49'],
            ['kind' => 'postal_glob', 'value' => '9?9'],
        ]);

        $this->get("http://localhost/admin/shipping/zones/{$zone->id}/edit")
            ->assertOk()
            // reconstructed: the prefix rule shows with its "*" put back.
            ->assertSee('01001')
            ->assertSee('49*')
            ->assertSee('9?9');
    }

    public function test_the_text_fallback_is_accepted_when_no_pick_list_posts(): void
    {
        // What the form sends with the locations module uninstalled: a textarea
        // instead of checkboxes.
        $this->post('http://localhost/admin/shipping/zones', $this->form([
            'countries_text' => "UA\nPL",
            'regions_text' => "UA:12",
        ]))->assertRedirect();

        $rules = $this->latestZone()->rules;

        $this->assertEqualsCanonicalizing(['UA', 'PL'], $rules->where('kind', 'country')->pluck('value')->all());
        $this->assertSame('UA:12', $rules->firstWhere('kind', 'state')?->value);
    }

    public function test_a_glob_postal_rule_matches_by_pattern(): void
    {
        $zone = Zone::create(['name' => ['en' => 'Odd codes'], 'enabled' => true]);
        $zone->rules()->create(['kind' => 'postal_glob', 'value' => '9?9']);

        $matcher = new ZoneMatcher;

        $this->assertSame('Odd codes', $matcher->match(new Location(country: 'UA', postal: '909'))?->name['en']);
        // "?" is exactly one character, so a four-digit code is not this zone.
        $this->assertNotSame('Odd codes', (new ZoneMatcher)->match(new Location(country: 'UA', postal: '9099'))?->name['en']);
    }

    public function test_a_glob_star_spans_any_run_of_characters(): void
    {
        $zone = Zone::create(['name' => ['en' => 'Star'], 'enabled' => true]);
        $zone->rules()->create(['kind' => 'postal_glob', 'value' => '1*9']);

        $matcher = new ZoneMatcher;

        $this->assertSame('Star', $matcher->match(new Location(country: 'UA', postal: '109'))?->name['en']);
        $this->assertSame('Star', (new ZoneMatcher)->match(new Location(country: 'UA', postal: '123459'))?->name['en']);
    }

    /** @param array<string, mixed> $covers */
    protected function form(array $covers): array
    {
        return $covers + ['names' => ['en' => 'Test zone'], 'position' => 0, 'enabled' => '1'];
    }

    protected function latestZone(): Zone
    {
        return Zone::with('rules')->latest('id')->firstOrFail();
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'zone-composition@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'zone-composition@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
