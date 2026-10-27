<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\Import\Columns;
use Torgash\Exim\Import\ImportFile;
use Torgash\Exim\Import\XmlFile;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\ImportDraft;

/**
 * A supplier's YML feed goes in through the same screens as a CSV (PLAN §58):
 * an `<offer>` is a row, its tags the columns, its `<param>`s characteristics
 * named by the column, and the feed's own category tree becomes paths.
 */
class XmlImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function yml(string $encoding = 'UTF-8'): string
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="ENCODING"?>
<yml_catalog date="2026-10-26 12:00">
  <shop>
    <name>Supplier</name>
    <categories>
      <category id="1">Телефоны йети</category>
      <category id="2" parentId="1">Смартфоны йети</category>
    </categories>
    <offers>
      <offer id="Y-1" available="true">
        <price>1299.50</price>
        <oldprice>1499</oldprice>
        <categoryId>2</categoryId>
        <picture>https://img.example/y1.png</picture>
        <picture>https://img.example/y2.png</picture>
        <name>Йетифон 9</name>
        <vendor>Йети</vendor>
        <vendorCode>YETI-9</vendorCode>
        <description><![CDATA[<p>Снежный <b>телефон</b></p>]]></description>
        <param name="Цвет йети">Белый</param>
        <delivery-options><option cost="0" days="1"/></delivery-options>
      </offer>
      <offer id="Y-2" available="false">
        <price>999</price>
        <categoryId>1</categoryId>
        <name>Йетифон 8</name>
        <vendor>Йети</vendor>
        <vendorCode>YETI-8</vendorCode>
        <param name="Цвет йети">Серый</param>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

        $xml = str_replace('ENCODING', $encoding, $xml);

        return $encoding === 'UTF-8' ? $xml : mb_convert_encoding($xml, $encoding, 'UTF-8');
    }

    protected function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yml').'.yml';
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    public function test_a_feed_is_read_as_a_table_and_its_columns_map_themselves(): void
    {
        $file = ImportFile::read($this->file($this->yml('windows-1251')));

        $this->assertInstanceOf(XmlFile::class, $file);
        $this->assertSame('offer', $file->record, 'the offers, not the params inside them');
        $this->assertSame(2, $file->count());

        $header = $file->header();
        $row = array_combine($header, $file->sample(1)[0]);
        $this->assertSame('Y-1', $row['@id']);
        $this->assertSame('Йетифон 9', $row['name'], 'declared windows-1251, read as UTF-8');
        $this->assertSame('Телефоны йети///Смартфоны йети', $row['category'], 'the feed\'s tree as a path');
        $this->assertSame('https://img.example/y1.png, https://img.example/y2.png', $row['picture']);
        $this->assertSame('Белый', $row['param [Цвет йети]']);
        $this->assertSame('<p>Снежный <b>телефон</b></p>', $row['description']);
        $this->assertSame('0', $row['delivery-options/option@cost']);
        $this->assertNotContains('delivery-options/option', $header, 'an empty tag with attributes is its attributes');

        $mapping = array_combine($header, Columns::guess($header, array_values(app(ImportRunner::class)->fields('product'))));
        $this->assertSame('sku', $mapping['vendorCode']);
        $this->assertSame('price_units', $mapping['price']);
        $this->assertSame('list_price_units', $mapping['oldprice']);
        $this->assertSame('category', $mapping['category']);
        $this->assertSame('brand', $mapping['vendor']);
        $this->assertSame('images', $mapping['picture']);
        $this->assertSame('feature_column', $mapping['param [Цвет йети]']);
        $this->assertSame('name_'.content_base_locale(), $mapping['name']);
    }

    public function test_a_yml_feed_imports_through_the_screens(): void
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        Http::fake(['img.example/*' => Http::response(ob_get_clean(), 200, ['Content-Type' => 'image/png'])]);

        $upload = $this->post('http://localhost/admin/exim/import/upload', [
            'entity' => 'product',
            'file' => UploadedFile::fake()->createWithContent('supplier.yml', $this->yml()),
        ])->assertRedirect();
        $token = basename(dirname(parse_url($upload->headers->get('Location'), PHP_URL_PATH)));
        $this->assertStringEndsWith('.yml', ImportDraft::where('token', $token)->value('path'));

        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk()
            ->assertSee('Йетифон 9')
            ->assertSee('&lt;offer&gt;', false)
            ->assertDontSee('Semicolon (;)');

        $draft = ImportDraft::where('token', $token)->firstOrFail();
        $this->post('http://localhost/admin/exim/import/preview', [
            'token' => $token, 'map' => $draft->mapping, 'xml_record' => 'offer', 'create_new' => 1,
            'images_mode' => 'empty', 'category_delimiter' => '///',
        ])->assertOk()->assertSee('2 will be created');

        $this->post('http://localhost/admin/exim/import/commit', ['token' => $token])->assertRedirect();

        $base = content_base_locale();
        $nine = Product::where('sku', 'YETI-9')->firstOrFail();
        $this->assertSame('Йетифон 9', $nine->name[$base]);
        $this->assertSame(129950, (int) $nine->price);
        $this->assertSame(149900, (int) $nine->list_price);
        $this->assertSame('Йети', $nine->brandValue()?->value[$base]);
        $this->assertCount(2, $nine->getMedia('gallery'));

        $leaf = Category::whereRaw("name->>? = 'Смартфоны йети'", [$base])->sole();
        $this->assertSame('Телефоны йети', $leaf->parent->name[$base]);
        $this->assertSame([$leaf->id], $nine->categories()->pluck('m_catalog_categories.id')->all());

        $colour = Feature::whereRaw("name->>? = 'Цвет йети'", [$base])->sole();
        $this->assertSame('Белый', $nine->featureAssignments()->where('feature_id', $colour->id)->first()?->display($base));
    }
}
