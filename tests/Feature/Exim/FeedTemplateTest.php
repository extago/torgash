<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Models\EximProfile;
use Torgash\Exim\Template\FeedTemplate;
use Torgash\Exim\Template\Presets;
use Torgash\Exim\Template\TemplateException;

/**
 * An export profile's own template — a marketplace feed (PLAN §58): lists
 * inside the rows, conditions, filters; and the ready Yandex Market template
 * producing a well-formed YML of the shop.
 */
class FeedTemplateTest extends TestCase
{
    public function test_sections_lists_conditions_and_filters(): void
    {
        $template = new FeedTemplate(implode("\n", [
            '<o id="{{ item.id }}">',
            '    {{#item.pictures}}',
            '    <p>{{ . }}</p>',
            '    {{/item.pictures}}',
            '    {{#item.old}}<old>{{ item.old | money }}</old>{{/item.old}}{{^item.old}}<none/>{{/item.old}}',
            '    {{#item.params}}<param name="{{ name }}">{{ value }}</param>{{/item.params}}',
            '    <d>{{ item.text | strip_tags | squish | truncate:9 | cdata }}</d>',
            '    <w>{{ item.weight | divide:1000 }}</w><e>{{ item.missing | default:"—" }}</e>',
            '</o>',
        ]));

        $xml = $template->render(['item' => [
            'id' => 7, 'pictures' => ['a.jpg', 'b&c.jpg'], 'old' => 129050,
            'params' => [['name' => 'Цвет "ночь"', 'value' => 'чёрный']],
            'text' => "<p>Hello   <b>big</b>\n world</p>", 'weight' => 171,
        ]]);

        $this->assertSame(implode("\n", [
            '<o id="7">',
            '    <p>a.jpg</p>',
            '    <p>b&amp;c.jpg</p>',
            '    <old>1290.50</old>',
            '    <param name="Цвет &quot;ночь&quot;">чёрный</param>',
            '    <d><![CDATA[Hello big]]></d>',
            '    <w>0.171</w><e>—</e>',
            '</o>',
        ]), $xml);

        $this->assertStringContainsString('<none/>', $template->render(['item' => ['old' => null]]));
    }

    public function test_a_mistake_names_its_line(): void
    {
        foreach ([
            "a\n{{#rows}}\nb" => 'never closed',
            "{{#a}}\n{{/b}}" => 'closes',
            '{{ item.x | shout }}' => 'no filter',
            '{{ item x }}' => 'is not a name',
        ] as $source => $words) {
            try {
                new FeedTemplate($source);
                $this->fail('Parsed: '.$source);
            } catch (TemplateException $e) {
                $this->assertStringContainsString($words, $e->getMessage());
            }
        }
    }

    public function test_the_yandex_market_template_writes_a_well_formed_feed_of_the_shop(): void
    {
        Storage::fake('local');
        $this->actingAs(User::role('admin')->firstOrFail());
        $base = content_base_locale();

        $phones = Category::create(['name' => [$base => 'Квокфоны'], 'slug' => 'quokkaphones-feed', 'status' => 'active']);
        $product = Product::create([
            'sku' => 'FEED-1', 'name' => [$base => 'Квокфон «Про» & Co'], 'slug' => 'quokkaphone-feed',
            'short_description' => [$base => 'Коротко <b>и ясно</b>'],
            'price' => 71990_00, 'list_price' => 79990_00, 'stock' => 3, 'status' => 'active', 'weight' => 171,
        ]);
        $product->categories()->attach($phones->id);
        $memory = Feature::create(['name' => [$base => 'Память квокки'], 'input_type' => 'select']);
        $value = FeatureValue::create(['feature_id' => $memory->id, 'value' => [$base => '1 ТБ'], 'sort' => 1]);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $memory->id, 'feature_value_id' => $value->id]);

        $preset = collect(Presets::all())->firstWhere('key', 'yandex-market');
        $profile = EximProfile::create([
            'type' => 'export', 'entity' => 'product', 'format' => 'xml', 'mode' => 'template',
            'name' => ['en' => 'Market'], 'template_body' => $preset['body'], 'enabled' => true,
        ]);

        $yml = Storage::disk('local')->get(app(ExportRunner::class)->run($profile));

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $yml, 'the comment line leaves nothing');
        $doc = simplexml_load_string($yml);
        $this->assertNotFalse($doc, 'well-formed: '.substr($yml, 0, 400));

        $offer = $doc->xpath('//offer[@id="'.$product->id.'"]')[0];
        $this->assertSame('Квокфон «Про» & Co', (string) $offer->name);
        $this->assertSame('71990', (string) $offer->price);
        $this->assertSame('79990', (string) $offer->oldprice);
        $this->assertSame('true', (string) $offer['available']);
        $this->assertSame((string) $phones->id, (string) $offer->categoryId);
        $this->assertSame('Коротко <b>и ясно</b>', trim((string) $offer->description));
        $this->assertSame('1 ТБ', (string) $offer->xpath('param[@name="Память квокки"]')[0]);
        $this->assertSame('0.171', (string) $offer->weight);
        $this->assertNotEmpty($doc->xpath('//categories/category[@id="'.$phones->id.'"]'));
        $this->assertSame(shop_currency(), (string) $offer->currencyId);
    }

    public function test_a_feed_lists_the_products_own_rows_after_the_shops_and_never_one_name_twice(): void
    {
        Storage::fake('local');
        $base = content_base_locale();

        // The row called like the characteristic is the longer wording of the
        // same value: the page may print both, a feed must not.
        $product = Product::create([
            'sku' => 'FEED-OWN', 'name' => [$base => 'Квокфон'], 'slug' => 'quokkaphone-own-rows',
            'price' => 100_00, 'stock' => 1, 'status' => 'active',
            'features' => ['Гарантия' => '2 года', 'память квокки' => '1 ТБ, два слота', 'Комплект' => ['кабель', 'чехол']],
            'features_layout' => [['type' => 'section', 'label' => 'Прочее'], ['key' => 'Комплект'], ['key' => 'Гарантия']],
        ]);
        $memory = Feature::create(['name' => [$base => 'Память квокки'], 'input_type' => 'select']);
        $value = FeatureValue::create(['feature_id' => $memory->id, 'value' => [$base => '1 ТБ'], 'sort' => 1]);
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $memory->id, 'feature_value_id' => $value->id]);

        $profile = EximProfile::create([
            'type' => 'export', 'entity' => 'product', 'format' => 'xml', 'mode' => 'template', 'name' => ['en' => 'Params'],
            'template_body' => "<offers>\n{{#rows}}\n<offer id=\"{{ item.id }}\">{{#item.params}}<param name=\"{{ name }}\" own=\"{{#own}}yes{{/own}}{{^own}}no{{/own}}\">{{ value }}</param>{{/item.params}}</offer>\n{{/rows}}\n</offers>",
            'enabled' => true,
        ]);

        $doc = simplexml_load_string(Storage::disk('local')->get(app(ExportRunner::class)->run($profile)));
        $params = collect($doc->xpath('//offer[@id="'.$product->id.'"]/param'))
            ->map(fn ($param) => [(string) $param['name'], (string) $param, (string) $param['own']])
            ->all();

        $this->assertSame([
            ['Память квокки', '1 ТБ', 'no'],
            ['Комплект', 'кабель, чехол', 'yes'],
            ['Гарантия', '2 года', 'yes'],
        ], $params);
    }
}
