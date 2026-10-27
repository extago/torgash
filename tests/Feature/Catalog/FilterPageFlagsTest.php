<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Filters\FilterPages;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Product;

/**
 * A yes/no filter as part of a published ADDRESS — "laptops in stock".
 *
 * The registry that owns those filters is §104's; what this adds is that a
 * filter page may carry their keys, so a module registering "here today" can be
 * published as a page without the catalogue learning its name. The properties
 * worth pinning are the ones where that could go quietly wrong: the page must
 * really narrow, a listing that ticks the same box must canonicalise onto it,
 * the box must show as ticked so there is a way off the page, and a key nobody
 * answers for must narrow to NOTHING rather than widen the page into its whole
 * category.
 */
class FilterPageFlagsTest extends TestCase
{
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['en' => 'Flag pages'], 'slug' => 'flag-pages', 'enabled' => true,
        ]);

        app(SettingsManager::class)->set('torgash.catalog', 'filter_flags', ['in_stock']);
        app(Flags::class)->flush();
        app(FilterPages::class)->flush();
    }

    public function test_a_page_of_a_system_filter_narrows_like_the_box_does(): void
    {
        $this->product('FPF-1', 'Has stock', stock: 4);
        $this->product('FPF-2', 'None left', stock: 0);

        $page = $this->page(['in_stock'], 'in-stock');

        $this->get('http://localhost'.$page->path())->assertOk()
            ->assertSee('Has stock')
            ->assertDontSee('None left');
    }

    /** The line the whole table exists for, now reachable through a flag. */
    public function test_a_listing_with_the_box_ticked_canonicalises_onto_the_page(): void
    {
        $this->product('FPF-3', 'Has stock', stock: 4);

        $page = $this->page(['in_stock'], 'in-stock');

        $this->get('http://localhost/catalog/flag-pages?in_stock=1')->assertOk()
            ->assertSee('rel="canonical" href="http://localhost'.$page->path().'"', false);

        // ...and a listing ticking nothing stays on the category, as before.
        $this->get('http://localhost/catalog/flag-pages')->assertOk()
            ->assertSee('rel="canonical" href="http://localhost/catalog/flag-pages"', false);
    }

    /** The box shows ticked at that address — which is what makes it a way off. */
    public function test_the_pages_own_flag_is_ticked_in_the_panel(): void
    {
        $this->product('FPF-4', 'Has stock', stock: 4);
        $this->product('FPF-5', 'None left', stock: 0);

        $page = $this->page(['in_stock'], 'in-stock');

        $body = $this->get('http://localhost'.$page->path())->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="in_stock"[^>]*checked/',
            $body,
            'the flag the address applies has to show as ticked, or there is no way off the page',
        );
    }

    /**
     * A key whose module has gone narrows to nothing. Widening the page into
     * its category would leave a heading promising a narrower set over a grid
     * showing everything — the one answer that misleads a visitor and a crawler
     * at once.
     */
    public function test_a_flag_nobody_answers_for_shows_nothing(): void
    {
        $this->product('FPF-6', 'Has stock', stock: 4);

        $page = $this->page(['here_today'], 'here-today');

        $this->get('http://localhost'.$page->path())->assertOk()->assertDontSee('Has stock');
    }

    /** Order is not identity: the same two filters are the same page. */
    public function test_the_flags_are_part_of_the_fingerprint_and_are_sorted(): void
    {
        $one = FilterPage::fingerprintFor($this->category->id, [], null, ['on_sale', 'in_stock']);
        $two = FilterPage::fingerprintFor($this->category->id, [], null, ['in_stock', 'on_sale']);

        $this->assertSame($one, $two);
        $this->assertNotSame($one, FilterPage::fingerprintFor($this->category->id, [], null, ['in_stock']));

        // ...and a page with no flags hashes exactly as it did before they
        // existed, which is what lets the UNIQUE column stay as it is.
        $this->assertSame(
            $this->category->id.'|',
            FilterPage::fingerprintFor($this->category->id, [], null, []),
        );
    }

    /** The admin can write one, and the picker is scoped to the category. */
    public function test_the_admin_form_offers_the_registered_filters(): void
    {
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/catalog/filter-pages/create')->assertOk()
            ->assertSee('name="flags[]"', false)
            ->assertSee('value="in_stock"', false);

        // The picker on its own, which is what the category select swaps in.
        $this->get('http://localhost/admin/catalog/filter-pages/values?category_id='.$this->category->id)
            ->assertOk();
    }

    public function test_the_form_writes_a_flag_only_page(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/catalog/filter-pages', [
            'category_id' => $this->category->id,
            'name' => 'In stock',
            'flags' => ['in_stock', 'nonsense'],
            'enabled' => '1',
            'in_sitemap' => '1',
        ])->assertRedirect();

        $page = FilterPage::where('category_id', $this->category->id)->firstOrFail();

        // The made-up key is dropped on the way in: a page is refused the right
        // to publish an address nobody can answer for.
        $this->assertSame(['in_stock'], $page->flagSet());
    }

    /** @param list<string> $flags */
    protected function page(array $flags, string $slug): FilterPage
    {
        $page = FilterPage::create([
            'category_id' => $this->category->id,
            'slug' => $slug,
            'values' => [],
            'flags' => $flags,
            'fingerprint' => FilterPage::fingerprintFor($this->category->id, [], null, $flags),
            'enabled' => true,
            'in_sitemap' => true,
        ]);

        app(FilterPages::class)->flush();

        return $page;
    }

    protected function product(string $sku, string $name, int $stock = 4): Product
    {
        $product = Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => 100_00, 'stock' => $stock, 'status' => 'active',
        ]);

        $product->categories()->attach($this->category->id);

        return $product;
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
