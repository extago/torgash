<?php

namespace Tests\Feature\Locations;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\Locations\TownGuess;

/**
 * "Is your town …?" (setting `town_prompt`): asked once a visit of a visitor
 * with no town, never stored until they say yes, silent when nothing knows a
 * town to offer.
 */
class TownPromptTest extends TestCase
{
    protected Place $kyiv;

    protected function setUp(): void
    {
        parent::setUp();

        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);
        $region = State::create(['country' => 'UA', 'code' => '30', 'name' => ['en' => 'Kyiv region'], 'enabled' => true]);
        // A smaller namesake that merely CONTAINS the name, and the city itself.
        Place::create(['country' => 'UA', 'state_id' => $region->id, 'name' => ['en' => 'Kyivska Sloboda'], 'population' => 4_000_000, 'source' => 'import']);
        $this->kyiv = Place::create(['country' => 'UA', 'state_id' => $region->id, 'name' => ['en' => 'Kyiv', 'uk' => 'Київ'],
            'population' => 3_000_000, 'latitude' => 50.45, 'longitude' => 30.52, 'source' => 'import']);
    }

    protected function mode(string $mode, string $town = ''): void
    {
        $settings = app(SettingsManager::class);
        $settings->set('torgash.locations', 'town_prompt', $mode);
        $settings->set('torgash.locations', 'town_prompt_default', $town);
    }

    public function test_off_by_default_and_without_a_town_to_offer(): void
    {
        $this->get('http://localhost/')->assertOk()->assertDontSee('torgash-town-prompt');

        $this->mode('default');
        $this->get('http://localhost/')->assertOk()->assertDontSee('torgash-town-prompt');
    }

    public function test_the_shops_town_is_offered_once_a_visit_and_yes_remembers_it(): void
    {
        $this->mode('default', 'київ');
        $this->assertSame($this->kyiv->id, app(TownGuess::class)->shopTown()?->id);

        $this->get('http://localhost/')->assertOk()
            ->assertSee('torgash-town-prompt')
            ->assertSee('name="place_id" value="'.$this->kyiv->id.'"', false);

        // Asked: the next page does not ask again.
        $this->get('http://localhost/')->assertOk()->assertDontSee('torgash-town-prompt');

        $this->post('http://localhost/location', ['place_id' => $this->kyiv->id])->assertRedirect();
        $this->assertSame($this->kyiv->id, (int) hook_filter('storefront.visitor_place', null));
    }

    public function test_a_visitor_who_chose_a_town_is_not_asked(): void
    {
        $this->mode('default', 'Kyiv');
        session(['checkout_location' => ['place_id' => $this->kyiv->id, 'postal' => null, 'town' => null, 'country' => 'UA']]);

        $this->get('http://localhost/')->assertOk()->assertDontSee('torgash-town-prompt');
    }

    public function test_the_browser_mode_asks_even_with_no_town_of_the_shop(): void
    {
        $this->mode('browser');

        $this->get('http://localhost/')->assertOk()
            ->assertSee('data-mode="browser"', false)
            ->assertSee('name="place_id" value=""', false);

        $this->getJson('http://localhost/places/nearest?lat=50.46&lng=30.5')->assertJson(['id' => $this->kyiv->id, 'name' => 'Kyiv']);
    }

    public function test_by_ip_without_a_database_falls_back_to_the_shops_town(): void
    {
        $this->mode('ip', 'Kyiv');
        app(SettingsManager::class)->set('torgash.locations', 'geoip_database', 'data/geo/no-such-file.mmdb');

        $this->assertNull(app(TownGuess::class)->byIp('8.8.8.8'));
        $this->assertSame($this->kyiv->id, app(TownGuess::class)->guess('8.8.8.8')?->id);
    }
}
