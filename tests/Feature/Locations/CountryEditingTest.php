<?php

namespace Tests\Feature\Locations;

use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\PackImporter;

/**
 * A country is mostly a set of names, and the pack only carries the languages
 * it was built with. A shop that adds a language has to be able to write the
 * missing ones itself — and keep them when the world list is imported again.
 */
class CountryEditingTest extends TestCase
{
    public function test_a_translation_added_by_hand_survives_a_re_import(): void
    {
        $this->importCountries();

        $country = Country::where('code', 'UA')->firstOrFail();
        $country->update([
            'name' => $country->name + ['pl' => 'Ukraina'],
            'edited' => true,
        ]);

        $this->importCountries();

        $this->assertSame('Ukraina', $country->fresh()->name['pl'], 'the pack wiped a language the shop wrote itself');
    }

    public function test_a_name_the_shop_corrected_is_not_reverted(): void
    {
        $this->importCountries();

        $country = Country::where('code', 'UA')->firstOrFail();
        $country->update(['name' => ['en' => 'Ukraine (we ship here)'], 'edited' => true]);

        $this->importCountries();

        $this->assertSame('Ukraine (we ship here)', $country->fresh()->name['en']);
    }

    public function test_an_edited_country_still_gets_facts_it_was_missing(): void
    {
        $country = Country::create(['code' => 'UA', 'name' => ['en' => 'Ukraine'], 'edited' => true]);

        $this->importCountries();

        // Nothing was overwritten, but the empty column was filled: the shop
        // edited a name, not the phone prefix, and should still get one.
        $this->assertSame('380', $country->fresh()->phone);
    }

    public function test_an_untouched_country_is_refreshed_wholesale(): void
    {
        $this->importCountries();

        Country::where('code', 'UA')->update(['name' => ['en' => 'Stale']]);

        $this->importCountries();

        $this->assertSame('Ukraine', Country::where('code', 'UA')->value('name')['en']);
    }

    public function test_removing_a_country_keeps_its_places_but_stops_offering_them(): void
    {
        $this->importCountries();
        Place::create(['country' => 'UA', 'name' => ['en' => 'Kyiv'], 'geonameid' => 1, 'source' => 'import']);

        $this->assertSame(1, Place::available()->count());

        Country::where('code', 'UA')->delete();

        $this->assertSame(1, Place::where('country', 'UA')->count(), 'removing a country deleted its places');
        $this->assertSame(0, Place::available()->count(), 'a place of an unlisted country was still offered');
    }

    protected function importCountries(): void
    {
        $dir = storage_path('app/testing-countries');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = "{$dir}/countries.csv";

        file_put_contents(
            $file,
            "code;code3;numeric;continent;currency;phone;postal_format;postal_regex;name_en;name_uk;geonameid\n"
            ."UA;UKR;804;EU;UAH;380;#####;^(\d{5})$;Ukraine;Україна;690791\n",
        );

        app(PackImporter::class)->importCountries($file);

        unlink($file);
    }
}
