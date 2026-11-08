<?php

namespace Tests\Feature\Checkout;

use App\Core\Hooks\HookBus;
use App\Core\Settings\SettingsManager;
use Illuminate\Http\Request;
use Tests\TestCase;
use Torgash\Checkout\Shipping\LocationStore;
use Torgash\Locations\Models\Place;

/**
 * A town picked with no country (the "your town" window, a popular-town
 * button) is in one country, and the checkout's country box says which —
 * it used to read "Select a country" over a chosen Dnipro.
 */
class CheckoutCountryTest extends TestCase
{
    public function test_a_remembered_town_answers_the_country(): void
    {
        $town = Place::create(['country' => 'UA', 'name' => ['en' => 'Dniprotown'], 'type' => 'settlement', 'enabled' => true]);
        $store = app(LocationStore::class);

        session(['checkout_location' => ['place_id' => $town->id, 'town' => 'Dniprotown', 'postal' => null, 'country' => null]]);
        $this->assertSame('UA', $store->country());

        // A quote with the box still empty keeps the town's country for the next page.
        $store->fromRequest(Request::create('/', 'POST', ['place_id' => $town->id, 'city' => 'Dniprotown']));
        $this->assertSame('UA', session('checkout_location')['country']);

        session(['checkout_location' => ['place_id' => null, 'country' => null]]);
        $this->assertNull($store->country(), 'no town, no answer');
    }

    /** Several countries served: the shop's own is chosen, until a town may say otherwise. */
    public function test_the_box_starts_on_the_shops_country(): void
    {
        app(HookBus::class)->listen('checkout.location.countries', fn () => [
            ['code' => 'PL', 'name' => 'Poland'],
            ['code' => 'UA', 'name' => 'Ukraine'],
        ], 99);
        $settings = app(SettingsManager::class);
        $field = fn (array $data = []) => view('torgash.shipping::partials.town-field', $data)->render();

        $settings->set('core', 'default_country', 'UA');
        $this->assertStringContainsString('<option value="UA" selected>', $field());
        $this->assertStringNotContainsString(' selected>', $field(['town' => 'Warsaw']), 'a town may be in the other one');
        $this->assertStringContainsString('<option value="PL" selected>', $field(['country' => 'PL']));

        $settings->set('core', 'default_country', 'DE');
        $this->assertStringNotContainsString(' selected>', $field(), 'a country the shop does not serve is not offered');
    }
}
