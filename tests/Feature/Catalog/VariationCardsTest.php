<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\VariationCards;

/**
 * The clothing scenario (Support\VariationCards, Product::contentFrom()):
 * boots in two colours and two heights — each a card of its own — in three
 * sizes and two insoles chosen on the card. Twelve products per colour and
 * height, four cards in the catalogue; a size with nothing of its own shows
 * its card's photos, description and characteristics.
 */
class VariationCardsTest extends TestCase
{
    use MakesVariationAxes;

    protected Category $category;

    /** @var array<string, Product> by "colour-height-size-insole" */
    protected array $boots = [];

    protected function setUp(): void
    {
        parent::setUp();

        [, $colours] = $this->variationAxis('VC Colour', ['Black', 'Olive']);
        [, $heights] = $this->variationAxis('VC Height', ['Low', 'High']);
        [$size, $sizes] = $this->variationAxis('VC Size', ['40', '42', '44']);
        [$insole, $insoles] = $this->variationAxis('VC Insole', ['Standard', 'Orthopedic']);
        $size->update(['variation_role' => Feature::ROLE_CHOICE]);
        $insole->update(['variation_role' => Feature::ROLE_CHOICE]);

        $this->category = Category::create(['name' => ['en' => 'VC Boots'], 'slug' => 'vc-boots', 'enabled' => true]);

        foreach (['Black', 'Olive'] as $c) {
            foreach (['Low', 'High'] as $h) {
                $main = null;
                foreach (['42', '40', '44'] as $s) {
                    foreach (['Standard', 'Orthopedic'] as $i) {
                        $key = "{$c}-{$h}-{$s}-{$i}";
                        $product = Product::create([
                            'sku' => 'VC-'.strtoupper($key), 'name' => ['en' => "Boots {$c} {$h}"], 'slug' => strtolower('vc-'.$key),
                            'price' => 100_00, 'stock' => 5, 'status' => 'active', 'variation_group' => 'vc-boots',
                            'description' => $main ? null : ['en' => "<p>The {$c} {$h} description.</p>"],
                            'content_source_id' => $main?->id,
                        ]);
                        $product->categories()->attach($this->category->id);
                        foreach ([$colours[$c], $heights[$h], $sizes[$s], $insoles[$i]] as $value) {
                            $this->answer($product, $value);
                        }
                        $main ??= $product;
                        $this->boots[$key] = $product;
                    }
                }
            }
        }
    }

    /** @return list<string> the product slugs the listing links to, once each */
    protected function cards(string $url): array
    {
        preg_match_all('#/product/(vc-[a-z0-9-]+)#', $this->get($url)->assertOk()->getContent(), $m);

        return array_values(array_unique($m[1]));
    }

    public function test_the_catalogue_shows_one_card_per_colour_and_height(): void
    {
        $cards = $this->cards('http://localhost/catalog/vc-boots');

        sort($cards);
        $this->assertSame(['vc-black-high-42-standard', 'vc-black-low-42-standard', 'vc-olive-high-42-standard', 'vc-olive-low-42-standard'], $cards);

        $this->withHeader('X-Catalog-Count', '1')->get('http://localhost/catalog/vc-boots')->assertSee('Show 4 products');
    }

    public function test_a_sold_out_size_gives_its_place_to_one_in_stock(): void
    {
        $this->boots['Black-Low-42-Standard']->update(['stock' => 0]);

        $this->assertContains('vc-black-low-42-orthopedic', $this->cards('http://localhost/catalog/vc-boots'));
        $this->assertNotContains('vc-black-low-42-standard', $this->cards('http://localhost/catalog/vc-boots'));
    }

    public function test_a_filter_on_a_size_opens_every_card_at_that_size(): void
    {
        $size44 = ProductFeature::where('product_id', $this->boots['Black-Low-44-Orthopedic']->id)
            ->whereHas('feature', fn ($q) => $q->where('variation_role', Feature::ROLE_CHOICE))
            ->get()->first(fn ($pf) => $pf->featureValue->value['en'] === '44');
        $ortho = ProductFeature::where('product_id', $this->boots['Black-Low-44-Orthopedic']->id)
            ->get()->first(fn ($pf) => $pf->featureValue->value['en'] === 'Orthopedic');

        $cards = $this->cards('http://localhost/catalog/vc-boots?feature['.$size44->feature_id.'][]='.$size44->feature_value_id
            .'&feature['.$ortho->feature_id.'][]='.$ortho->feature_value_id);

        sort($cards);
        $this->assertSame(['vc-black-high-44-orthopedic', 'vc-black-low-44-orthopedic', 'vc-olive-high-44-orthopedic', 'vc-olive-low-44-orthopedic'], $cards);
    }

    public function test_without_a_characteristic_chosen_on_the_card_nothing_collapses(): void
    {
        Feature::where('variation_role', Feature::ROLE_CHOICE)->update(['variation_role' => Feature::ROLE_CARD]);

        $this->assertFalse(app(VariationCards::class)->inUse());
        $this->assertCount(24, Product::query()->where('variation_group', 'vc-boots')->get());
        $this->assertGreaterThan(4, count($this->cards('http://localhost/catalog/vc-boots')));
    }

    public function test_search_answers_one_card_per_colour_and_height(): void
    {
        $ids = array_map(fn (Product $p) => $p->id, array_values($this->boots));

        $this->assertCount(4, app(VariationCards::class)->collapseIds($ids));
        $this->assertSame($this->boots['Black-Low-42-Standard']->id, app(VariationCards::class)->collapseIds($ids)[0], 'the first card keeps its place');
    }

    public function test_a_size_shows_what_it_has_none_of_from_its_source(): void
    {
        $size = $this->boots['Olive-High-44-Orthopedic']->fresh();

        $this->assertSame('<p>The Olive High description.</p>', $size->t('description'));
        $this->assertSame($this->boots['Olive-High-42-Standard']->id, $size->contentFrom()?->id);

        // Its own text wins; the source is never written to.
        $size->update(['description' => ['en' => '<p>Mine.</p>']]);
        $this->assertSame('<p>Mine.</p>', $size->fresh()->t('description'));
        $this->assertNull($size->fresh()->getRawOriginal('short_description'));

        // Photos: the source's, while the size has none.
        $path = tempnam(sys_get_temp_dir(), 'vc').'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->boots['Olive-High-42-Standard']->addMedia($path)->toMediaCollection('gallery');
        $this->assertCount(1, $size->fresh()->galleryImages());
        $this->assertNotNull($size->fresh()->primaryImageUrl(400));
        $this->assertCount(0, $size->fresh()->getMedia('gallery'), 'nothing was copied');
    }

    public function test_without_a_named_source_a_size_finds_its_cards_product_with_photos(): void
    {
        $size = $this->boots['Black-High-40-Standard'];
        $size->update(['content_source_id' => null]);

        $this->assertNull($size->fresh()->contentFrom(), 'no member of its card has photos yet');

        $path = tempnam(sys_get_temp_dir(), 'vc').'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        copy($path, $path.'.png');
        $this->boots['Black-High-42-Standard']->addMedia($path)->toMediaCollection('gallery');
        $this->boots['Olive-High-42-Standard']->addMedia($path.'.png')->toMediaCollection('gallery');

        $this->assertSame($this->boots['Black-High-42-Standard']->id, $size->fresh()->contentFrom()?->id, 'of its own colour and height, not another card');
    }

    public function test_the_admin_sets_the_role_and_the_source(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $size = $this->boots['Black-Low-44-Standard'];

        $this->actingAs($admin)->get(route('admin.catalog.products.edit', $size))->assertOk()
            ->assertSee('name="content_source_id"', false);

        $feature = Feature::where('variation_role', Feature::ROLE_CHOICE)->firstOrFail();
        $this->actingAs($admin)->get(route('admin.catalog.features.edit', $feature))->assertOk()
            ->assertSee('name="variation_role"', false);
    }

    /**
     * A size is the card to a search engine (2026-11-07): its page names the
     * card's oldest member as canonical, and the sitemap lists that one only.
     */
    public function test_a_size_names_its_cards_head_as_canonical(): void
    {
        $head = $this->boots['Black-Low-42-Standard'];

        $this->get('http://localhost/product/vc-black-low-44-orthopedic')->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('catalog.product', $head->slug).'"', false);

        // The head names itself; a typed canonical still wins.
        $this->get('http://localhost/product/vc-black-low-42-standard')->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/product/vc-black-low-42-standard"', false);
        $this->boots['Black-Low-40-Standard']->update(['canonical_url' => 'https://example.com/own']);
        $this->get('http://localhost/product/vc-black-low-40-standard')->assertOk()
            ->assertSee('<link rel="canonical" href="https://example.com/own"', false);

        // A head that is switched off hands the place to the next oldest.
        $head->update(['status' => 'disabled']);
        $this->get('http://localhost/product/vc-black-low-44-orthopedic')->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('catalog.product', 'vc-black-low-42-orthopedic').'"', false);
    }

    public function test_the_sitemap_lists_one_address_per_card(): void
    {
        preg_match_all('#/product/(vc-[a-z0-9-]+)</loc>#', $this->get('http://localhost/sitemap.xml')->assertOk()->getContent(), $m);
        $listed = $m[1];
        sort($listed);

        $this->assertSame(['vc-black-high-42-standard', 'vc-black-low-42-standard', 'vc-olive-high-42-standard', 'vc-olive-low-42-standard'], $listed);
    }

    /** Sizes of one card share a title by design; two CARDS sharing one is still a finding. */
    public function test_a_title_is_a_duplicate_only_between_cards(): void
    {
        $titles = fn () => collect(((new \Torgash\Catalog\Invariants\CatalogInvariants)->register()['catalog.titles_are_distinct']['check'])(50))->pluck('title');

        $this->assertNotContains('Boots Black Low', $titles());

        foreach ($this->boots as $key => $boot) {
            if (str_starts_with($key, 'Olive-Low-')) {
                $boot->update(['name' => ['en' => 'Boots Black Low']]);
            }
        }

        $this->assertSame(1, $titles()->filter(fn ($t) => $t === 'Boots Black Low')->count());
    }

    /** What is compared is what the page prints: a template that tells the colours apart ends the finding. */
    public function test_an_seo_template_that_parts_the_titles_ends_the_finding(): void
    {
        foreach ($this->boots as $key => $boot) {
            if (str_starts_with($key, 'Olive-Low-')) {
                $boot->update(['name' => ['en' => 'Boots Black Low']]);
            }
        }

        \Torgash\Seo\Models\Template::create([
            'subject' => 'product', 'scope' => 'global', 'mode' => 'override', 'enabled' => true,
            'title' => ['en' => '{product_name} {product_sku}'],
        ]);
        app(\Torgash\Seo\Templates::class)->flush();

        $titles = collect(((new \Torgash\Catalog\Invariants\CatalogInvariants)->register()['catalog.titles_are_distinct']['check'])(50))->pluck('title');
        $this->assertFalse($titles->contains(fn ($t) => str_starts_with((string) $t, 'Boots Black Low')));
    }
}
