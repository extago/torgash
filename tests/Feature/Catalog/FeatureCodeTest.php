<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\PriceType;

/**
 * Every characteristic, value and price type has a code, whoever wrote it.
 *
 * It used to be the admin FORM that filled a blank one in, so a row created by
 * an import, by 1C, by a seeder or over the API kept a NULL — and a NULL code
 * is a row nothing can match on: `exim.match.*` asks about one column, a
 * readable filtered address is written in codes, and CommerceML's links are
 * keyed by them. The rule lives on the model now
 * (Torgash\Catalog\Support\AssignsCode) and the column is NOT NULL.
 */
class FeatureCodeTest extends TestCase
{
    /** The hole: a row written anywhere but the form. */
    public function test_a_characteristic_created_outside_the_form_is_given_a_code(): void
    {
        $feature = Feature::create(['name' => ['en' => 'Screen size'], 'purpose' => 'filter', 'input_type' => 'select']);

        $this->assertSame('SCREEN-SIZE', $feature->code);
    }

    /**
     * A Russian-only shop, which is the ordinary case and not an edge one.
     *
     * `Str::slug()` transliterates with a generic map unless it is told which
     * alphabet it is reading; the generic one answers CVET for Цвет where the
     * Russian one answers TSVET. Either beats what the old code did to a shop
     * that fills in no English at all — read the empty `en` name, find nothing,
     * and name every characteristic ITEM, ITEM-2, ITEM-3.
     */
    public function test_a_cyrillic_name_makes_a_readable_code(): void
    {
        $feature = Feature::create(['name' => ['ru' => 'Цвет'], 'purpose' => 'filter', 'input_type' => 'select']);

        $this->assertSame('TSVET', $feature->code);
    }

    /** The base language is preferred where both are filled in. */
    public function test_the_base_language_names_it(): void
    {
        $feature = Feature::create([
            'name' => ['en' => 'Colour', 'ru' => 'Цвет'], 'purpose' => 'filter', 'input_type' => 'select',
        ]);

        $this->assertSame('COLOUR', $feature->code);
    }

    /**
     * A name nothing can be made of is named after its own id.
     *
     * Deliberately readable: a person matching columns in a spreadsheet can
     * look up row 11, and could do nothing at all with "ITEM-7" — which is
     * what the old fallback produced.
     */
    public function test_a_name_that_slugs_to_nothing_falls_back_to_the_id(): void
    {
        $feature = Feature::create(['name' => ['en' => '—'], 'purpose' => 'filter', 'input_type' => 'select']);

        $this->assertSame('FEATURE-'.$feature->id, $feature->code);
        $this->assertSame('FEATURE-'.$feature->id, $feature->fresh()->code, 'the placeholder reached the database');
    }

    /** Two characteristics of the same name are two rows, so two codes. */
    public function test_a_collision_gets_a_suffix(): void
    {
        $first = Feature::create(['name' => ['en' => 'Material'], 'purpose' => 'filter', 'input_type' => 'select']);
        $second = Feature::create(['name' => ['en' => 'Material'], 'purpose' => 'filter', 'input_type' => 'select']);

        $this->assertSame('MATERIAL', $first->code);
        $this->assertSame('MATERIAL-2', $second->code);
    }

    /** A code the shop typed is its name and is never rewritten — a rename included. */
    public function test_a_typed_code_survives_a_rename(): void
    {
        $feature = Feature::create([
            'name' => ['en' => 'Material'], 'code' => 'MAT', 'purpose' => 'filter', 'input_type' => 'select',
        ]);

        $feature->update(['name' => ['en' => 'Surface material']]);

        $this->assertSame('MAT', $feature->fresh()->code);
    }

    /** A value is unique within its characteristic, not across the table. */
    public function test_the_same_value_code_may_exist_under_two_characteristics(): void
    {
        $one = Feature::create(['name' => ['en' => 'Colour'], 'purpose' => 'filter', 'input_type' => 'select']);
        $two = Feature::create(['name' => ['en' => 'Frame colour'], 'purpose' => 'filter', 'input_type' => 'select']);

        $a = FeatureValue::create(['feature_id' => $one->id, 'value' => ['en' => 'White']]);
        $b = FeatureValue::create(['feature_id' => $two->id, 'value' => ['en' => 'White']]);

        $this->assertSame('WHITE', $a->code);
        $this->assertSame('WHITE', $b->code, 'a value code was made unique across the table instead of within its characteristic');
    }

    /** A copy is a new row and needs a name of its own. */
    public function test_a_copy_is_given_its_own_code(): void
    {
        $feature = Feature::create(['name' => ['en' => 'Material'], 'purpose' => 'filter', 'input_type' => 'select']);

        $copy = $feature->replicate();
        $copy->save();

        $this->assertSame('MATERIAL-2', $copy->code);
    }

    /** The same rule, on the other table that carries a code. */
    public function test_a_price_type_is_given_a_code_too(): void
    {
        $type = PriceType::create(['name' => ['ru' => 'Оптовая']]);

        $this->assertNotSame('', (string) $type->code);
        $this->assertSame('OPTOVAYA', $type->code);
    }

    /** The form still refuses a code somebody else holds, rather than quietly renaming it. */
    public function test_the_form_refuses_a_taken_code(): void
    {
        Feature::create(['name' => ['en' => 'Material'], 'code' => 'TAKEN', 'purpose' => 'filter', 'input_type' => 'select']);
        $other = Feature::create(['name' => ['en' => 'Finish'], 'purpose' => 'filter', 'input_type' => 'select']);

        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->put('http://localhost/admin/catalog/features/'.$other->id, [
            'name' => 'Finish',
            'code' => 'TAKEN',
            'purpose' => 'filter',
            'input_type' => 'select',
            'shown_in_set' => '1',
            'shown_in' => ['filters'],
            'values_json' => '[]',
        ])->assertSessionHasErrors('code');
    }
}
