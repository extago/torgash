<?php

namespace Tests\Feature\Options;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Options\Models\OptionGroup;

/** The option groups list: searched, narrowed by whose and by status, acted on in bulk and in place. */
class OptionGroupListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function group(string $admin, ?Product $product = null, bool $enabled = true): OptionGroup
    {
        return OptionGroup::create([
            'name' => ['en' => $admin], 'admin_name' => $admin, 'type' => 'select',
            'enabled' => $enabled, 'per_unit' => true, 'min_qty' => 1, 'sort' => 0,
            'rules_match' => 'all', 'product_id' => $product?->id,
        ]);
    }

    public function test_search_and_the_tabs_narrow_the_list(): void
    {
        $product = Product::create(['sku' => 'OGL-1', 'name' => ['en' => 'OGL Server'], 'slug' => 'ogl-server', 'price' => 100, 'status' => 'active']);
        $this->group('OGL Warranty');
        $this->group('OGL Processor', $product);
        $this->group('OGL Off', enabled: false);

        $this->get('http://localhost/admin/options/groups?q=warranty')->assertOk()
            ->assertSee('OGL Warranty')->assertDontSee('OGL Processor');

        // A product's own group is found by its product too.
        $this->get('http://localhost/admin/options/groups?q=OGL-1')->assertOk()
            ->assertSee('OGL Processor')->assertDontSee('OGL Warranty');

        $this->get('http://localhost/admin/options/groups?whose=product&q=OGL')->assertOk()
            ->assertSee('OGL Processor')->assertDontSee('OGL Warranty');

        $this->get('http://localhost/admin/options/groups?status=disabled&q=OGL')->assertOk()
            ->assertSee('OGL Off')->assertDontSee('OGL Warranty');

        // % typed in the box is a character, not a wildcard.
        $this->get('http://localhost/admin/options/groups?q=%25')->assertOk()->assertDontSee('OGL Warranty');
    }

    public function test_bulk_and_in_place_switching(): void
    {
        $a = $this->group('OGL Bulk A');
        $b = $this->group('OGL Bulk B');

        $this->patch('http://localhost/admin/options/groups/'.$a->id.'/status', ['enabled' => 0])
            ->assertOk()->assertSee('id="option-group-status-'.$a->id.'"', false);
        $this->assertFalse($a->fresh()->enabled);

        $this->post('http://localhost/admin/options/groups/bulk', ['action' => 'enable', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertTrue($a->fresh()->enabled);

        $this->post('http://localhost/admin/options/groups/bulk', ['action' => 'delete', 'ids' => [$b->id]])->assertRedirect();
        $this->assertNull(OptionGroup::find($b->id));
    }

    public function test_deleting_from_the_list_stays_on_the_list_and_the_form_closes_on_request(): void
    {
        $product = Product::create(['sku' => 'OGL-2', 'name' => ['en' => 'OGL Rack'], 'slug' => 'ogl-rack', 'price' => 100, 'status' => 'active']);
        $own = $this->group('OGL Rails', $product);

        $this->from('http://localhost/admin/options/groups?whose=product')
            ->delete('http://localhost/admin/options/groups/'.$own->id)
            ->assertRedirect('http://localhost/admin/options/groups?whose=product');

        $shared = $this->group('OGL Install');
        $this->put('http://localhost/admin/options/groups/'.$shared->id, [
            'name' => 'OGL Install', 'admin_name' => 'OGL Install', 'type' => 'select', 'enabled' => '1', 'after_save' => 'close',
        ])->assertRedirect(route('admin.options.groups.index'));
    }
}
