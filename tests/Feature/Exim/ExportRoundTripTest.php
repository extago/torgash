<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Exim\ProductImport;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\EximProfile;

/**
 * The columns an import reads from another system's file are WRITTEN by the
 * export too, the same way (PLAN §58): exported, changed in a spreadsheet,
 * imported back — and nothing is created twice, no photo downloaded again.
 */
class ExportRoundTripTest extends TestCase
{
    protected const COLUMNS = ['sku', 'price_units', 'category', 'brand', 'characteristics', 'images'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt').'.csv';
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    protected function export(array $fields): string
    {
        $profile = EximProfile::create([
            'type' => 'export', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema',
            'name' => ['en' => 'Round trip'], 'fields' => $fields, 'enabled' => true,
        ]);

        return Storage::disk('local')->get(app(ExportRunner::class)->run($profile));
    }

    public function test_an_export_comes_back_through_the_import_unchanged_and_then_changed(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        Http::fake(['img.example/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $runner = app(ImportRunner::class);
        $runner->commit('product', $runner->rowsOf($this->file(
            "Product code;Product name;Category;Price;Brand;Features;Images\n"
            ."RT-1;Квокфон;Телефоны квокки///Смартфоны квокки;\"1 299,50\";Квокка;\"Память квокки: S[128 ГБ]; Вес квокки: O[171]\";https://img.example/a.png https://img.example/b.png\n"
        ), 'product'), null, ['images_mode' => 'missing']);

        $product = Product::where('sku', 'RT-1')->sole();
        $this->assertCount(2, $product->getMedia('gallery'));
        $categories = Category::count();
        $features = Feature::count();

        $csv = $this->export(self::COLUMNS);
        $this->assertStringContainsString('1299.50', $csv);
        $this->assertStringContainsString('Телефоны квокки///Смартфоны квокки', $csv);
        $this->assertStringContainsString('Квокка', $csv);
        $this->assertStringContainsString('Память квокки: S[128 ГБ]', $csv);
        $this->assertStringContainsString('Вес квокки: O[171]', $csv);
        $this->assertStringContainsString($product->getMedia('gallery')->first()->getUrl(), $csv);

        // Every field still exports what it did: the new columns are asked for, not imposed.
        $this->assertStringNotContainsString('Category (path)', $this->export([]));

        // Back as it went out: nothing new, no photo fetched again.
        Http::fake(['*' => Http::response('', 500)]);
        $runner->commit('product', $runner->rowsOf($this->file($csv), 'product'), null, ['images_mode' => 'missing']);
        $this->assertSame($categories, Category::count());
        $this->assertSame($features, Feature::count());
        $this->assertCount(2, $product->fresh()->getMedia('gallery'));
        $this->assertSame(129950, $product->fresh()->price);

        // Changed in the spreadsheet: the change, and only it, lands.
        $runner->commit('product', $runner->rowsOf($this->file(str_replace('1299.50', '1399', $csv)), 'product'), null, ['images_mode' => 'missing']);
        $this->assertSame(139900, $product->fresh()->price);
        $this->assertSame(['Телефоны квокки///Смартфоны квокки'], $product->fresh()->categories->map(fn (Category $c) => ProductImport::pathOf($c))->all());
    }
}
