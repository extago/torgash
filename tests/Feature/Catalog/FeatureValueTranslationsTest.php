<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\ShortDescription;

/**
 * A global characteristic's TYPED value in another language: the base word
 * stays in `value_text` (what filters and lookups read), the form in another
 * content language writes only that language's word, and every reader shows
 * it — the base word wherever none was typed.
 */
class FeatureValueTranslationsTest extends TestCase
{
    public function test_another_language_writes_only_its_word_and_the_page_reads_it(): void
    {
        $warranty = Feature::create([
            'name' => ['en' => 'Warranty', 'ru' => 'Гарантия'], 'code' => 'fvt_warranty', 'purpose' => 'info',
            'input_type' => 'text', 'in_short_description' => true, 'sort' => 1,
        ]);
        $voltage = Feature::create([
            'name' => ['en' => 'Voltage'], 'code' => 'fvt_voltage', 'purpose' => 'info', 'input_type' => 'number', 'sort' => 2,
        ]);
        $product = Product::create([
            'sku' => 'FVT-1', 'name' => ['en' => 'Kettle', 'ru' => 'Чайник'], 'slug' => 'fvt-kettle',
            'price' => 100_00, 'stock' => 1, 'status' => 'active',
        ]);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $warranty->id, 'value_text' => '24 months']);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $voltage->id, 'value_text' => '220']);

        $save = fn (string $word) => $this->actingAs(User::role('admin')->firstOrFail())->withSession(['admin_content_locale' => 'ru'])
            ->put('http://localhost/admin/catalog/products/'.$product->id, [
                'sku' => 'FVT-1', 'slug' => 'fvt-kettle', 'name' => 'Чайник', 'price' => '100', 'stock' => '1', 'status' => 'active',
                // A number is the same in every language and saves from any of them.
                'global_features' => [$voltage->id => '230'],
                'global_features_words' => [$warranty->id => $word],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs(User::role('admin')->firstOrFail())->withSession(['admin_content_locale' => 'ru'])
            ->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')->assertOk()
            ->assertSee('name="global_features_words['.$warranty->id.']"', false)
            ->assertSee('placeholder="24 months"', false)
            ->assertSee('name="global_features['.$voltage->id.']"', false);

        $save('24 месяца');

        $assignment = $product->featureAssignments()->where('feature_id', $warranty->id)->firstOrFail();
        $this->assertSame('24 months', $assignment->value_text, 'the base word is untouched');
        $this->assertSame(['ru' => '24 месяца'], $assignment->value_translations);
        $this->assertSame('230', $product->featureAssignments()->where('feature_id', $voltage->id)->value('value_text'));

        $this->get('http://localhost/ru/product/fvt-kettle')->assertOk()->assertSee('24 месяца');
        $this->get('http://localhost/product/fvt-kettle')->assertOk()->assertSee('24 months')->assertDontSee('24 месяца');

        app()->setLocale('ru');
        $this->assertStringContainsString('24 месяца', app(ShortDescription::class)->for($product->fresh()));
        app()->setLocale('en');

        // Blank removes the word, never the value.
        $save('  ');
        $assignment->refresh();
        $this->assertSame('24 months', $assignment->value_text);
        $this->assertNull($assignment->value_translations);
        $this->assertSame('24 months', $assignment->display('ru'), 'no word typed: the base one shows');
    }
}
