<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\PriceTypeMarkupRule;

/** The price types list, its form with the markup rules on it, and the rule form. */
class PriceTypeScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function priceType(string $name, bool $enabled = true): PriceType
    {
        return PriceType::create(['name' => ['en' => $name], 'enabled' => $enabled, 'adjustment_percent' => -10]);
    }

    public function test_the_list_is_headed_once_searched_and_narrowed(): void
    {
        $this->priceType('PT Dealer');
        $this->priceType('PT Club', enabled: false);

        $html = $this->get('http://localhost/admin/catalog/price-types')->assertOk()->getContent();
        // The heading's text sits in a span, with the ⓘ hint beside it.
        $this->assertSame(1, preg_match_all('/<h[12][^>]*>\s*(?:<span>)?\s*(Price types|Типы цен)\s*(?:<\/span>|<\/h[12]>)/u', $html));

        $this->get('http://localhost/admin/catalog/price-types?q=dealer')->assertOk()
            ->assertSee('PT Dealer')->assertDontSee('PT Club');
        $this->get('http://localhost/admin/catalog/price-types?status=disabled&q=PT')->assertOk()
            ->assertSee('PT Club')->assertDontSee('PT Dealer');
    }

    public function test_a_row_switches_in_place(): void
    {
        $type = $this->priceType('PT Switch');

        $this->patch('http://localhost/admin/catalog/price-types/'.$type->id.'/status', ['enabled' => 0])
            ->assertOk()->assertSee('id="price-type-status-'.$type->id.'"', false);

        $this->assertFalse($type->fresh()->enabled);
    }

    public function test_the_form_stays_open_and_carries_its_rules(): void
    {
        $type = $this->priceType('PT Form');
        $rule = $type->markupRules()->create([
            'name' => ['en' => 'PT Displays'], 'markup_type' => 'percent', 'markup_value' => 3500,
            'rounding_mode' => 'nearest', 'round_to' => 10000, 'round_direction' => 'up', 'sort' => 0, 'enabled' => true,
        ]);

        $this->get('http://localhost/admin/catalog/price-types/'.$type->id.'/edit')->assertOk()
            ->assertSee('PT Displays')
            ->assertSee('form="rule-delete-'.$rule->id.'"', false)
            ->assertSee('form="price-type-delete"', false);

        $this->put('http://localhost/admin/catalog/price-types/'.$type->id, ['name' => 'PT Form', 'enabled' => '1'])
            ->assertRedirect(route('admin.catalog.price-types.edit', $type));
        $this->put('http://localhost/admin/catalog/price-types/'.$type->id, ['name' => 'PT Form', 'after_save' => 'close'])
            ->assertRedirect(route('admin.catalog.price-types.index'));

        // The old rules page is the form's card now.
        $this->get('http://localhost/admin/catalog/price-types/'.$type->id.'/rules')
            ->assertRedirect(route('admin.catalog.price-types.edit', $type).'#rules');
    }

    /** One "round to" box: the hidden mode's copy used to be submitted too, and to win. */
    public function test_a_rule_keeps_the_step_it_was_given(): void
    {
        $type = $this->priceType('PT Rules');

        $this->post('http://localhost/admin/catalog/price-types/'.$type->id.'/rules', [
            'markup_type' => 'percent', 'markup_value' => '35',
            'rounding_mode' => 'nearest', 'round_to' => '100', 'round_direction' => 'up', 'enabled' => '1',
        ])->assertRedirect();

        $rule = PriceTypeMarkupRule::where('price_type_id', $type->id)->firstOrFail();
        $this->assertSame([10000, 'up', null], [$rule->round_to, $rule->round_direction, $rule->psychological_offset]);
        $this->assertSame(140000, $rule->apply(100000), 'cost 1000.00 + 35% = 1350.00, up to the next 100');

        $html = $this->get('http://localhost/admin/catalog/price-types/'.$type->id.'/rules/'.$rule->id.'/edit')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'name="round_to"'));
    }
}
