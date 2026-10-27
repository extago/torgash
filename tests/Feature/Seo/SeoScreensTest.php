<?php

namespace Tests\Feature\Seo;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Seo\Models\Template;

/**
 * The SEO screens in the admin's common shape: templates searched in their
 * text and tabbed by kind of page, switched in place and several at once, a
 * copy that arrives off, a form that stays open on Save and previews the
 * UNSAVED text on a real page — and the audit, tabbed by what is still empty.
 */
class SeoScreensTest extends TestCase
{
    protected User $admin;

    protected Category $category;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        session(['admin_content_locale' => 'en']);

        $this->category = Category::create(['name' => ['en' => 'Screens Laptops'], 'slug' => 'screens-laptops', 'enabled' => true]);
        $this->product = Product::create([
            'sku' => 'SEO-SCR-1', 'name' => ['en' => 'Screens Thin Laptop'], 'slug' => 'screens-thin-laptop',
            'price' => 100_00, 'stock' => 2, 'status' => 'active',
        ]);
        $this->product->categories()->attach($this->category->id);
    }

    protected function template(string $subject, string $title, array $attributes = []): Template
    {
        return Template::create($attributes + [
            'subject' => $subject, 'scope' => 'global', 'mode' => Template::MODE_FILL_EMPTY,
            'enabled' => true, 'title' => ['en' => $title],
        ]);
    }

    public function test_search_reads_the_texts_and_tabs_narrow_by_kind(): void
    {
        $this->template('product', 'Buy {product} ZZTOP');
        $this->template('category', 'All {category} here');

        $this->actingAs($this->admin)->get('http://localhost/admin/seo?q=ZZTOP')
            ->assertOk()->assertSee('Buy {product} ZZTOP')->assertDontSee('All {category} here');
        $this->get('http://localhost/admin/seo?subject=category')
            ->assertSee('All {category} here')->assertDontSee('Buy {product} ZZTOP');
    }

    public function test_a_category_scope_is_named_in_the_list(): void
    {
        $this->template('product', 'Scoped', ['scope' => 'category:'.$this->category->id]);

        $this->actingAs($this->admin)->get('http://localhost/admin/seo')->assertSee('Screens Laptops');
    }

    public function test_status_in_place_bulk_and_a_copy_that_is_off(): void
    {
        $one = $this->template('product', 'One');
        $two = $this->template('product', 'Two');

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/seo/{$one->id}/status", ['enabled' => 0], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('seo-template-status-'.$one->id, false);
        $this->assertFalse($one->fresh()->enabled);

        $this->post("http://localhost/admin/seo/{$two->id}/duplicate")->assertRedirect();
        $copy = Template::where('id', '>', $two->id)->latest('id')->firstOrFail();
        $this->assertFalse($copy->enabled);
        $this->assertSame(['en' => 'Two'], $copy->title);

        $this->post('http://localhost/admin/seo/bulk', ['action' => 'delete', 'ids' => [$two->id, $copy->id]])->assertRedirect();
        $this->assertNull(Template::find($two->id));
        $this->assertNotNull(Template::find($one->id));
    }

    public function test_save_stays_on_the_form_and_close_goes_back(): void
    {
        $template = $this->template('product', 'Stay');
        $fields = ['subject' => 'product', 'scope' => 'global', 'mode' => 'fill_empty', 'title' => 'Stay here', 'enabled' => 1];

        $this->actingAs($this->admin)->put("http://localhost/admin/seo/{$template->id}", $fields)
            ->assertRedirect(route('admin.seo.edit', $template));
        $this->put("http://localhost/admin/seo/{$template->id}", $fields + ['after_save' => 'close'])
            ->assertRedirect(route('admin.seo.index'));
    }

    public function test_the_preview_prints_the_unsaved_text_on_a_page_it_covers(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/seo/preview', [
            'subject' => 'product',
            'scope' => 'category:'.$this->category->id,
            'mode' => 'fill_empty',
            'title' => '{product} in {category|lower}',
        ])->assertOk()->assertSee('Screens Thin Laptop in screens laptops');
    }

    public function test_the_audit_counts_what_is_empty_and_its_search_keeps_the_kind(): void
    {
        $this->product->update(['meta_title' => ['en' => 'Typed title']]);

        $response = $this->actingAs($this->admin)
            ->get('http://localhost/admin/seo/audit?subject=product&filled=filled&q=Typed');
        $response->assertOk()->assertSee('Typed title');

        $counts = $response->viewData('counts');
        $this->assertSame(1, $counts['']);
        $this->assertSame(0, $counts['empty']);
        $this->assertSame(1, $counts['filled']);
    }
}
