<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * A product's own characteristics in another language: the base words stay
 * the rows (and what every lookup reads), the form in another content language
 * writes only their words in it, and the page shows them — the base words
 * wherever nothing was typed.
 */
class FeatureTranslationsTest extends TestCase
{
    public function test_another_language_writes_only_its_words_and_the_page_reads_them(): void
    {
        $product = Product::create([
            'sku' => 'FT-1', 'name' => ['en' => 'Gateway', 'ru' => 'Шлюз'], 'slug' => 'ft-gateway',
            'price' => 100_00, 'stock' => 1, 'status' => 'active',
            'features' => ['Weight' => '7.85 kg', 'Display' => 'Touchscreen'],
            'features_layout' => [['type' => 'section', 'label' => 'Build'], ['type' => 'feature', 'key' => 'Weight'], ['type' => 'feature', 'key' => 'Display']],
        ]);

        $this->actingAs(User::role('admin')->firstOrFail())->withSession(['admin_content_locale' => 'ru'])
            ->put('http://localhost/admin/catalog/products/'.$product->id, [
                'sku' => 'FT-1', 'slug' => 'ft-gateway', 'name' => 'Шлюз', 'price' => '100', 'stock' => '1', 'status' => 'active',
                // What the base-language editor would post — ignored in another language.
                'features' => json_encode(['Hacked' => 'x']),
                'features_words' => json_encode(['s' => ['Build' => 'Корпус'], 'k' => ['Weight' => 'Вес'], 'v' => ['Weight' => '7,85 кг', 'Display' => '  ']]),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(['Weight' => '7.85 kg', 'Display' => 'Touchscreen'], $product->features, 'the rows are untouched');
        $this->assertEquals(['s' => ['Build' => 'Корпус'], 'k' => ['Weight' => 'Вес'], 'v' => ['Weight' => '7,85 кг']], $product->features_translations['ru'], 'a blank value is no translation');

        $ru = $this->get('http://localhost/ru/product/ft-gateway')->assertOk()->getContent();
        $this->assertStringContainsString('Корпус', $ru);
        $this->assertStringContainsString('7,85 кг', $ru);
        $this->assertStringContainsString('Touchscreen', $ru, 'no word typed: the base one shows');

        $en = $this->get('http://localhost/product/ft-gateway')->assertOk()->getContent();
        $this->assertStringContainsString('7.85 kg', $en);
        $this->assertStringNotContainsString('Корпус', $en);

        // The editor in the base language edits the base words, untranslated.
        $rows = $product->featureRows(null, true, false);
        $this->assertSame('Weight', $rows[1]['label']);
    }
}
