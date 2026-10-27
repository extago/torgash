<?php

namespace Tests\Feature\Exim;

use App\Models\Language;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductPriceTypeOverride;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\EximProfile;

/**
 * What an export writes is DECLARED once and expanded here: a text in every
 * language of the shop, a price of its own for every price type — and a
 * template that is filled in, never run.
 */
class EximColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Language::updateOrCreate(['code' => 'uk'], ['name' => 'Ukrainian', 'native_name' => 'Українська', 'enabled' => true, 'sort' => 5]);
    }

    public function test_a_text_is_a_column_per_language_and_the_profile_can_narrow_them(): void
    {
        $product = Product::create(['sku' => 'COL-LANG', 'slug' => 'col-lang', 'price' => 1000,
            'name' => ['en' => 'Kettle', 'ru' => 'Чайник', 'uk' => 'Чайник UA']]);

        $all = $this->export(['sku', 'name']);
        $this->assertStringContainsString('Name (UK)', $all);
        $this->assertStringContainsString('Чайник UA', $all);

        $narrow = $this->export(['sku', 'name'], ['uk']);
        $this->assertStringContainsString('Name (UK)', $narrow);
        $this->assertStringNotContainsString('Name (EN)', $narrow);

        // A profile saved when the column was called name_ru still writes it.
        $this->assertStringContainsString('Чайник', $this->export(['sku', 'name_ru']));

        // And a file in a third language imports into that language alone.
        $this->import("sku,name_uk\nCOL-LANG,Електрочайник\n");
        $this->assertSame(['en' => 'Kettle', 'ru' => 'Чайник', 'uk' => 'Електрочайник'], $product->fresh()->name);
    }

    public function test_a_price_types_own_price_goes_out_and_comes_back_and_a_blank_returns_it_to_the_formula(): void
    {
        $type = PriceType::create(['code' => 'DEALER', 'name' => ['en' => 'Dealer'], 'adjustment_percent' => -10, 'enabled' => true]);
        $product = Product::create(['sku' => 'COL-PT', 'slug' => 'col-pt', 'price' => 10000, 'name' => ['en' => 'Pump']]);

        $this->import("sku,price_type:DEALER\nCOL-PT,7500\n");
        $this->assertSame(7500, $product->fresh()->priceForType($type));
        $this->assertStringContainsString('7500', $this->export(['sku', 'price_type:DEALER']));

        $this->import("sku,price_type:DEALER\nCOL-PT,\n");
        $this->assertFalse(ProductPriceTypeOverride::where('product_id', $product->id)->exists());
        $this->assertSame(9000, $product->fresh()->priceForType($type));
    }

    public function test_a_template_is_filled_in_and_escaped_never_run(): void
    {
        Product::create(['sku' => 'COL-TPL', 'slug' => 'col-tpl', 'price' => 500, 'name' => ['en' => 'Tom & "Jerry"', 'ru' => 'Том']]);

        $profile = EximProfile::create([
            'type' => 'export', 'entity' => 'product', 'format' => 'xml', 'mode' => 'template', 'enabled' => true,
            'name' => ['en' => 'tpl'], 'fields' => ['sku', 'name'],
            'template_body' => "<offers>{{#rows}}<o sku=\"{{ item.sku }}\">{{ item.name.en }}|{{ item.name.ru }}</o>{{/rows}}</offers>@php echo 'RAN'; @endphp{{ \$profile }}",
        ]);

        $out = Storage::disk('local')->get(app(ExportRunner::class)->run($profile));

        $this->assertStringContainsString('<o sku="COL-TPL">Tom &amp; &quot;Jerry&quot;|Том</o>', $out);
        $this->assertStringNotContainsString('RAN', str_replace("echo 'RAN'", '', $out));
        $this->assertStringContainsString("@php echo 'RAN'; @endphp", $out);
    }

    public function test_the_form_offers_one_row_of_languages_and_saves_the_choice(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $profile = EximProfile::create(['type' => 'export', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema',
            'name' => ['en' => 'Langs'], 'public_token' => bin2hex(random_bytes(16)), 'enabled' => true]);

        $this->get("http://localhost/admin/exim/profiles/{$profile->id}/edit")->assertOk()
            ->assertSee('name="locales[]"', false)
            ->assertSee('value="name"', false)
            ->assertDontSee('value="name_ru"', false);

        $this->put("http://localhost/admin/exim/profiles/{$profile->id}", ['name' => 'Langs', 'entity' => 'product', 'format' => 'csv',
            'mode' => 'schema', 'fields' => ['sku', 'name'], 'locales' => ['uk']])->assertSessionHasNoErrors();
        $this->assertSame(['uk'], $profile->fresh()->locales);
    }

    protected function export(array $fields, ?array $locales = null): string
    {
        $profile = EximProfile::create([
            'type' => 'export', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema', 'enabled' => true,
            'name' => ['en' => 'cols'], 'fields' => $fields, 'locales' => $locales, 'filters' => ['enabled_only' => false],
        ]);

        return Storage::disk('local')->get(app(ExportRunner::class)->run($profile));
    }

    protected function import(string $csv): void
    {
        $path = tempnam(sys_get_temp_dir(), 'exim').'.csv';
        file_put_contents($path, $csv);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $runner = app(ImportRunner::class);
        $runner->commit('product', $runner->rowsOf($path, 'product'));
    }
}
