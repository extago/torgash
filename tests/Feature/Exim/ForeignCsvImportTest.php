<?php

namespace Tests\Feature\Exim;

use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Catalog\Exim\ProductImport;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\Import\Columns;
use Torgash\Exim\Import\CsvFile;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\ImportPreset;

/**
 * Another engine's product file goes in the way the owner's did on the live shop
 * (PLAN §58): semicolons, categories as paths, a brand, photo links, the
 * characteristics cell and "VAR Feature N [Axis]" columns — matched without
 * anybody naming a column, photos fetched by a task of their own.
 */
class ForeignCsvImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function csv(): string
    {
        $rows = [
            ['Product code', 'Language', 'Category', 'Price', 'Quantity', 'Product name', 'Description', 'Images', 'Features', 'Brand', 'VAR Feature 2 [Цвет защёлки]', 'Status'],
            ['CSC-1', 'ru', 'Телефоны///Смартфоны квокки', '1 299,50', '7', 'Квокфон 15', "Первая строка\n\nВторая", 'https://img.example/a.png,https://img.example/b.png', 'Память квокки: S[128 ГБ]; Вес квокки: O[171]', 'Квокка', 'Синий', 'A'],
            ['CSC-2', 'ru', 'Телефоны///Смартфоны квокки', '1 399,50', '0', 'Квокфон 15 красный', '<p>HTML</p>', 'https://img.example/c.png', '[{"type":"section","label":"Экран"},{"key":"Диагональ","value":"6,1\""}]', 'Квокка', 'Красный', 'H'],
        ];

        return implode("\n", array_map(fn ($row) => implode(';', array_map(fn ($cell) => '"'.str_replace('"', '""', $cell).'"', $row)), $rows))."\n";
    }

    protected function fakeImages(): void
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        Http::fake(['img.example/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    }

    public function test_the_file_is_read_as_written_and_its_columns_map_themselves(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csc').'.csv';
        // Windows-1251, as Excel in a Russian Windows saves "CSV".
        file_put_contents($path, mb_convert_encoding($this->csv(), 'Windows-1251', 'UTF-8'));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $file = CsvFile::open($path);
        $this->assertSame(';', $file->delimiter);
        $this->assertSame('Windows-1251', $file->encoding);
        $this->assertSame('Квокфон 15', $file->sample(1)[0][5]);

        $mapping = Columns::guess($file->header(), array_values(app(ImportRunner::class)->fields('product')));

        $this->assertSame([
            'sku', '', 'category', 'price_units', 'stock', 'name_'.content_base_locale(), 'description_'.content_base_locale(),
            'images', 'characteristics', 'brand', 'variation_column', 'status',
        ], $mapping);
    }

    public function test_another_engines_file_imports_products_categories_characteristics_and_photos(): void
    {
        $this->fakeImages();

        $upload = $this->post('http://localhost/admin/exim/import/upload', [
            'entity' => 'product',
            'file' => UploadedFile::fake()->createWithContent('products.csv', $this->csv()),
        ]);
        $token = basename(dirname(parse_url($upload->headers->get('Location'), PHP_URL_PATH)));

        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk()
            ->assertSee('Квокфон 15')
            ->assertSee('Semicolon (;)');

        $file = CsvFile::open($this->localCopyOf($token));
        $mapping = Columns::guess($file->header(), array_values(app(ImportRunner::class)->fields('product')));

        $this->post('http://localhost/admin/exim/import/preview', [
            'token' => $token, 'map' => $mapping, 'create_new' => 1, 'images_mode' => 'empty',
            'category_delimiter' => '///', 'preset_name' => 'Quokka export',
        ])->assertOk()->assertSee('2 will be created');

        // The queue is synchronous in the suite: the import and its photos run here.
        $this->post('http://localhost/admin/exim/import/commit', ['token' => $token])->assertRedirect();

        $one = Product::where('sku', 'CSC-1')->firstOrFail();
        $two = Product::where('sku', 'CSC-2')->firstOrFail();
        $base = content_base_locale();

        $this->assertSame('Квокфон 15', $one->name[$base]);
        $this->assertSame(129950, (int) $one->price, 'the price as a person writes it');
        $this->assertSame(7, (int) $one->stock);
        $this->assertSame(Product::STATUS_HIDDEN, $two->status);
        $this->assertNotSame('', (string) $one->slug);
        $this->assertSame('<p>Первая строка</p><p>Вторая</p>', $one->description[$base]);
        $this->assertSame('<p>HTML</p>', $two->description[$base]);

        // One category tree, created once for both rows.
        $leaf = Category::whereRaw("name->>? = 'Смартфоны квокки'", [$base])->sole();
        $this->assertSame('Телефоны', $leaf->parent->name[$base]);
        $this->assertSame([$leaf->id], $one->categories()->pluck('m_catalog_categories.id')->all());
        $this->assertSame([$leaf->id], $two->categories()->pluck('m_catalog_categories.id')->all());

        // Brand, the typed characteristics cell and the variation axis.
        $this->assertSame('Квокка', $one->fresh()->brandValue()?->value[$base]);
        $memory = Feature::whereRaw("name->>? = 'Память квокки'", [$base])->sole();
        $this->assertSame('128 ГБ', $one->featureAssignments()->where('feature_id', $memory->id)->sole()->featureValue->value[$base]);
        $weight = Feature::whereRaw("name->>? = 'Вес квокки'", [$base])->sole();
        $this->assertSame('number', $weight->input_type);
        $this->assertSame('171', $one->featureAssignments()->where('feature_id', $weight->id)->sole()->value_text);
        $axis = Feature::whereRaw("name->>? = 'Цвет защёлки'", [$base])->sole();
        $this->assertSame(Feature::PURPOSE_VARIATION, $axis->purpose);
        $this->assertSame('Красный', $two->featureAssignments()->where('feature_id', $axis->id)->sole()->featureValue->value[$base]);

        // The JSON table became the product's own specification rows.
        $this->assertSame(['Диагональ' => '6,1"'], $two->features);
        $this->assertSame('section', $two->features_layout[0]['type']);

        // Photos: a task of their own, every link fetched once, in order.
        $this->assertSame(['https://img.example/a.png', 'https://img.example/b.png'], $one->getMedia('gallery')->map->getCustomProperty('source_url')->all());
        $this->assertCount(1, $two->getMedia('gallery'));
        $task = BackgroundTask::where('name', 'Import images')->latest('id')->firstOrFail();
        $this->assertSame(BackgroundTask::DONE, $task->status);
        $this->assertSame(3, (int) $task->result['images']);

        $import = BackgroundTask::where('name', 'Import')->latest('id')->firstOrFail();
        $this->assertSame(2, (int) $import->result['created'], 'the counts reach the task (they used to read 0)');

        // The way it was read is kept for the next file with these columns.
        $preset = ImportPreset::where('name', 'Quokka export')->sole();
        $this->assertSame('category', $preset->mapping['Category']);
        $this->assertSame($preset->id, ImportPreset::forHeader('product', $file->header())?->id);
    }

    public function test_a_second_run_downloads_nothing_again(): void
    {
        $this->fakeImages();
        $path = tempnam(sys_get_temp_dir(), 'csc').'.csv';
        file_put_contents($path, $this->csv());
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $runner = app(ImportRunner::class);
        $runner->commit('product', $runner->rowsOf($path, 'product'), null, ['images_mode' => 'missing']);
        $runner->commit('product', $runner->rowsOf($path, 'product'), null, ['images_mode' => 'missing']);

        $this->assertCount(2, Product::where('sku', 'CSC-1')->sole()->getMedia('gallery'));
        $this->assertSame(1, Category::whereRaw("name->>? = 'Смартфоны квокки'", [content_base_locale()])->count());
        Http::assertSentCount(3);
    }

    public function test_a_price_list_does_not_invent_products(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csc').'.csv';
        file_put_contents($path, "Product code;Price\nNOBODY-HAS-THIS;10\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $runner = app(ImportRunner::class);
        $counts = $runner->commit('product', $runner->rowsOf($path, 'product'), null, ['create_new' => false]);

        $this->assertSame(1, $counts['skipped']);
        $this->assertFalse(Product::where('sku', 'NOBODY-HAS-THIS')->exists());
    }

    public function test_a_typed_characteristics_cell_and_links_are_parsed(): void
    {
        $parsed = ProductImport::parseFeaturesCell('(Main) Brand: E[Apple]; Colours: M[Red///Blue]; Note: T[a\; b]');

        $this->assertSame([
            ['name' => 'Brand', 'type' => 'E', 'values' => ['Apple']],
            ['name' => 'Colours', 'type' => 'M', 'values' => ['Red', 'Blue']],
            ['name' => 'Note', 'type' => 'T', 'values' => ['a; b']],
        ], $parsed);

        $this->assertSame(['https://x.example/1.jpg', 'https://x.example/2.jpg'], ProductImport::imageLinks('https://x.example/1.jpg///https://x.example/2.jpg')['valid']);
        $this->assertSame(['https://old.example/img/detailed/a.jpg'], ProductImport::imageLinks('detailed/a.jpg', 'https://old.example/img/')['valid']);
        $this->assertSame(['detailed/a.jpg'], ProductImport::imageLinks('detailed/a.jpg')['invalid'], 'a file name with no address to put in front');
        $this->assertSame(1299.5, ProductImport::number('1 299,50'));
        $this->assertSame(1299.5, ProductImport::number('1,299.50'));
        $this->assertSame(1299.0, ProductImport::number('1299'));
    }

    /** The upload as the import job will read it. */
    protected function localCopyOf(string $token): string
    {
        $local = app(\App\Core\Files\FileStorage::class)->localCopy('private', 'exim/imports/'.$token.'.csv');
        $this->beforeApplicationDestroyed(fn () => @unlink($local));

        return $local;
    }
}
