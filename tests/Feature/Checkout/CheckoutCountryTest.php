<?php

namespace Tests\Feature\Checkout;

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
}
