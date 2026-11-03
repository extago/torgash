<?php

namespace Tests\Feature\Exim;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Exim\ProductSelection;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Models\EximProfile;

/**
 * Which products an export takes (PLAN §59.4, after the big engines' feed export):
 * statuses, price and stock, orderable now, a description, a photo, stop
 * words, categories with subtrees, labels, characteristics with operators,
 * SKUs taken as well or never — and the profile form that writes them.
 */
class ProductSelectionTest extends TestCase
{
    use MakesVariationAxes;

    protected Category $phones;

    protected Category $android;

    protected Category $cases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phones = Category::create(['name' => ['en' => 'Phones'], 'slug' => 'ps-phones', 'enabled' => true]);
        $this->android = Category::create(['name' => ['en' => 'Android'], 'slug' => 'ps-android', 'enabled' => true, 'parent_id' => $this->phones->id]);
        $this->cases = Category::create(['name' => ['en' => 'Cases'], 'slug' => 'ps-cases', 'enabled' => true]);
    }

    protected function product(string $sku, array $attributes = [], ?Category $category = null): Product
    {
        $product = Product::create($attributes + [
            'sku' => $sku, 'name' => ['en' => $sku], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => 5, 'status' => 'active', 'availability_status' => 'in_stock',
        ]);
        if ($category) {
            $product->categories()->attach($category->id);
        }

        return $product;
    }

    /** @return list<string> */
    protected function taken(array $input): array
    {
        return ProductSelection::apply(Product::query()->where('sku', 'like', 'PS-%'), ProductSelection::fromInput($input))
            ->orderBy('sku')->pluck('sku')->all();
    }

    public function test_statuses_price_stock_description_and_photo_narrow(): void
    {
        $this->product('PS-A', ['price' => 50_00, 'stock' => 0, 'availability_status' => 'in_stock']);
        $this->product('PS-B', ['price' => 150_00, 'stock' => 3, 'description' => ['en' => 'Told about'], 'status' => 'hidden']);
        $this->product('PS-C', ['price' => 250_00, 'stock' => 12, 'short_description' => ['ru' => 'Коротко'], 'status' => 'disabled']);
        $this->product('PS-D', ['price' => 350_00, 'stock' => 0, 'availability_status' => 'preorder', 'description' => ['en' => '  ']]);

        $this->assertSame(['PS-A', 'PS-D'], $this->taken([]), 'active alone by default');
        $this->assertSame(['PS-A', 'PS-B', 'PS-D'], $this->taken(['statuses' => ['active', 'hidden']]));
        $this->assertSame(['PS-B', 'PS-C'], $this->taken(['statuses' => ['active', 'hidden', 'disabled'], 'price_min' => '100', 'price_max' => '300']));
        $this->assertSame(['PS-B', 'PS-C'], $this->taken(['statuses' => ['active', 'hidden', 'disabled'], 'stock_min' => '1']));
        $this->assertSame(['PS-C'], $this->taken(['statuses' => ['active', 'hidden', 'disabled'], 'stock_min' => '4', 'stock_max' => '20']));
        $this->assertSame(['PS-B', 'PS-C'], $this->taken(['statuses' => ['active', 'hidden', 'disabled'], 'with_description' => '1']), 'blank text is no description');
        // Orderable now: a tracked status needs stock, a preorder does not.
        $this->assertSame(['PS-D'], $this->taken(['in_stock_only' => '1']));
        // By the availability status itself, whatever the stock says.
        $this->assertSame(['PS-D'], $this->taken(['availability' => ['preorder']]));
        $this->assertSame(['PS-A', 'PS-D'], $this->taken(['availability' => ['preorder', 'in_stock']]));
        $this->assertSame(['PS-A', 'PS-D'], $this->taken(['availability' => ['no_such_status']]), 'an unknown status is dropped, not applied');

        // A photo: of its own, or by address while the shop allows those.
        $this->product('PS-E', ['image_url' => 'https://img.example/e.jpg']);
        Storage::fake('public');
        $own = $this->product('PS-F');
        $path = tempnam(sys_get_temp_dir(), 'ps').'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $own->addMedia($path)->toMediaCollection('gallery');

        $this->assertSame(['PS-F'], $this->taken(['with_image' => '1']));
        app(SettingsManager::class)->set('torgash.catalog', 'external_images', true);
        $this->assertSame(['PS-E', 'PS-F'], $this->taken(['with_image' => '1']));
    }

    public function test_categories_labels_skus_and_stop_words(): void
    {
        $a = $this->product('PS-A', [], $this->phones);
        $b = $this->product('PS-B', ['name' => ['en' => 'Refurbished phone', 'ru' => 'Телефон']], $this->android);
        $c = $this->product('PS-C', ['description' => ['ru' => 'Витринный образец']], $this->cases);
        $this->product('PS-D');
        $label = Label::create(['name' => ['en' => 'Sale'], 'name_for_admin' => 'PS Sale', 'color' => 'primary', 'enabled' => true]);
        $a->labels()->attach($label->id);

        $this->assertSame(['PS-A'], $this->taken(['categories' => [$this->phones->id]]));
        $this->assertSame(['PS-A', 'PS-B'], $this->taken(['categories' => [$this->phones->id], 'categories_subtree' => '1']));
        $this->assertSame(['PS-A', 'PS-D'], $this->taken(['categories_exclude' => [$this->android->id, $this->cases->id]]));
        $this->assertSame(['PS-C', 'PS-D'], $this->taken(['categories_exclude' => [$this->phones->id], 'categories_exclude_subtree' => '1']));
        $this->assertSame(['PS-A'], $this->taken(['labels' => [$label->id]]));

        // SKUs: as well as the within (or), only those within it (and), never.
        $this->assertSame(['PS-A', 'PS-C'], $this->taken(['categories' => [$this->phones->id], 'skus_include' => 'PS-C, PS-X']));
        $this->assertSame([], $this->taken(['categories' => [$this->phones->id], 'skus_include' => 'PS-C', 'include_operator' => 'and']));
        $this->assertSame(['PS-B', 'PS-D'], $this->taken(['skus_exclude' => "PS-A\nPS-C"]));

        // Stop words: any language, only in the ticked fields, case-insensitive.
        $this->assertSame(['PS-A', 'PS-C', 'PS-D'], $this->taken(['stop_words' => 'refurbished', 'stop_fields' => ['name']]));
        $this->assertSame(['PS-A', 'PS-D'], $this->taken(['stop_words' => 'REFURBISHED, витринный', 'stop_fields' => ['name', 'description']]));
        $this->assertSame(['PS-A', 'PS-B', 'PS-C', 'PS-D'], $this->taken(['stop_words' => 'витринный', 'stop_fields' => ['name']]), 'a word in a field not ticked does not count');
        $this->product('PS-E', ['name' => ['en' => '100% cotton']]);
        $this->assertSame(['PS-A', 'PS-B', 'PS-C', 'PS-D'], $this->taken(['stop_words' => '100%', 'stop_fields' => ['name']]), 'a percent sign is a letter, not a wildcard');
        $this->assertSame(['PS-A', 'PS-B', 'PS-C', 'PS-D', 'PS-E'], $this->taken(['stop_words' => '1_0%', 'stop_fields' => ['name']]));
    }

    public function test_characteristics_compare_as_text_or_as_numbers_in_any_language(): void
    {
        [$memory, $sizes] = $this->variationAxis('Memory', ['64 GB', '128 GB', '256 GB'], 'filter');
        [$colour, $colours] = $this->variationAxis('Colour', ['Black', 'Blue'], 'filter');
        $colours['Black']->update(['value' => ['en' => 'Black', 'ru' => 'Чёрный']]);
        $a = $this->product('PS-A');
        $b = $this->product('PS-B');
        $c = $this->product('PS-C');
        $this->product('PS-D');
        $this->answer($a, $sizes['64 GB']);
        $this->answer($b, $sizes['128 GB']);
        $this->answer($c, $sizes['256 GB']);
        $this->answer($a, $colours['Black']);
        $this->answer($b, $colours['Blue']);
        ProductFeature::create(['product_id' => $c->id, 'feature_id' => $colour->id, 'value_text' => 'Graphite']);

        $take = fn (array $features, string $operator = 'and') => $this->taken(['features' => $features, 'features_operator' => $operator]);

        $this->assertSame(['PS-A'], $take([['feature' => $colour->id, 'op' => 'eq', 'value' => 'чёрный']]), 'any language, any case');
        $this->assertSame(['PS-B', 'PS-C', 'PS-D'], $take([['feature' => $colour->id, 'op' => 'neq', 'value' => 'Black']]), 'a product without the characteristic is "not black"');
        $this->assertSame(['PS-A', 'PS-B'], $take([['feature' => $colour->id, 'op' => 'in', 'value' => 'Blue, Black']]));
        $this->assertSame(['PS-C', 'PS-D'], $take([['feature' => $colour->id, 'op' => 'not_in', 'value' => 'Blue, Black']]));
        $this->assertSame(['PS-C'], $take([['feature' => $colour->id, 'op' => 'contains', 'value' => 'graph']]), 'the row\'s own text counts too');
        $this->assertSame(['PS-B', 'PS-C'], $take([['feature' => $memory->id, 'op' => 'gt', 'value' => '64']]), '"128 GB" reads as 128');
        $this->assertSame(['PS-A', 'PS-B'], $take([['feature' => $memory->id, 'op' => 'lte', 'value' => '128']]));
        $this->assertSame(['PS-A', 'PS-B', 'PS-C'], $take([['feature' => $memory->id, 'op' => 'not_empty', 'value' => '']]));
        $this->assertSame(['PS-D'], $take([['feature' => $memory->id, 'op' => 'empty', 'value' => '']]));

        $both = [['feature' => $memory->id, 'op' => 'gte', 'value' => '128'], ['feature' => $colour->id, 'op' => 'eq', 'value' => 'Blue']];
        $this->assertSame(['PS-B'], $take($both));
        $this->assertSame(['PS-B', 'PS-C'], $take($both, 'or'));

        // Garbage in the input is dropped, not refused.
        $this->assertSame([], ProductSelection::fromInput(['features' => [['feature' => '0', 'op' => 'eq', 'value' => 'x'], ['feature' => $colour->id, 'op' => 'teleport']]])['features']);
    }

    public function test_the_profile_form_writes_the_selection_and_the_export_reads_it(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());
        $this->product('PS-A', ['price' => 50_00], $this->phones);
        $this->product('PS-B', ['price' => 500_00], $this->phones);
        $this->product('PS-C', ['price' => 500_00], $this->cases);

        $this->get('http://localhost/admin/exim/profiles/create')->assertOk()->assertSee(__('Which products'))->assertSee('— Android');

        $this->post('http://localhost/admin/exim/profiles', [
            'name' => 'Phones over 100', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema', 'fields' => ['sku'],
            'statuses' => ['active', 'hidden'], 'price_min' => '100', 'categories' => [$this->phones->id], 'categories_subtree' => '1',
            'stop_words' => 'demo', 'stop_fields' => ['name', 'seo'], 'features' => [['feature' => '', 'op' => 'eq', 'value' => '']],
            'skus_exclude' => 'PS-Z',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $profile = EximProfile::query()->where('name->en', 'Phones over 100')->firstOrFail();
        $this->assertSame(['active', 'hidden'], $profile->filters['statuses']);
        $this->assertFalse($profile->filters['enabled_only']);
        $this->assertEquals(100, $profile->filters['price_min']);
        $this->assertSame([$this->phones->id], $profile->filters['categories']);
        $this->assertSame(['demo'], $profile->filters['stop_words']);
        $this->assertSame(['name', 'seo'], $profile->filters['stop_fields']);
        $this->assertSame([], $profile->filters['features']);
        $this->assertSame(['PS-Z'], $profile->filters['skus_exclude']);

        $this->get("http://localhost/admin/exim/profiles/{$profile->id}/edit")->assertOk()
            ->assertSee(trans_choice(':count product matches now|:count products match now', 1, ['count' => 1]))
            ->assertSee('PS-Z');

        Storage::fake('local');
        $csv = Storage::disk('local')->get(app(ExportRunner::class)->run($profile));
        $this->assertStringContainsString('PS-B', $csv);
        $this->assertStringNotContainsString('PS-A', $csv);
        $this->assertStringNotContainsString('PS-C', $csv);

        // A profile from before the selection existed still means "active only".
        $old = EximProfile::create(['type' => 'export', 'name' => ['en' => 'Old'], 'entity' => 'product', 'format' => 'csv',
            'mode' => 'schema', 'public_token' => bin2hex(random_bytes(16)), 'enabled' => true, 'filters' => ['enabled_only' => true], 'fields' => ['sku']]);
        $this->product('PS-H', ['status' => 'hidden']);
        $csv = Storage::disk('local')->get(app(ExportRunner::class)->run($old));
        $this->assertStringContainsString('PS-A', $csv);
        $this->assertStringNotContainsString('PS-H', $csv);
    }
}
