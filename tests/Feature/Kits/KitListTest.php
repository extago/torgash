<?php

namespace Tests\Feature\Kits;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Kits\Models\Kit;

/** The kits list: searched, narrowed, switched in place and in bulk; a kit's dates show in its status. */
class KitListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function kit(string $admin, ?Product $main = null, array $extra = []): Kit
    {
        return Kit::create($extra + [
            'name' => ['en' => $admin], 'admin_name' => $admin, 'placement' => 'before_details', 'layout' => 'grid',
            'type' => 'extended', 'enabled' => true, 'sort' => 0, 'rules_match' => 'all', 'main_product_id' => $main?->id,
        ]);
    }

    public function test_search_tabs_and_the_status_words(): void
    {
        $phone = Product::create(['sku' => 'KL-1', 'name' => ['en' => 'KL Phone'], 'slug' => 'kl-phone', 'price' => 100, 'status' => 'active']);
        $this->kit('KL Glass set', $phone);
        $this->kit('KL Shared set');
        $this->kit('KL Future set', null, ['starts_at' => now()->addWeek()]);

        $this->get('http://localhost/admin/kits?q=KL-1')->assertOk()
            ->assertSee('KL Glass set')->assertDontSee('KL Shared set');

        $this->get('http://localhost/admin/kits?whose=shared&q=KL')->assertOk()
            ->assertSee('KL Shared set')->assertDontSee('KL Glass set');

        // Switched on but not started: the list must not call it "on offer".
        $this->get('http://localhost/admin/kits?q=future')->assertOk()->assertSee('Not started yet');
    }

    public function test_bulk_and_in_place_switching_and_save_and_close(): void
    {
        $a = $this->kit('KL Bulk A');
        $b = $this->kit('KL Bulk B');

        $this->patch('http://localhost/admin/kits/'.$a->id.'/status', ['enabled' => 0])
            ->assertOk()->assertSee('id="kit-status-'.$a->id.'"', false);
        $this->assertFalse($a->fresh()->enabled);

        $this->post('http://localhost/admin/kits/bulk', ['action' => 'enable', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertTrue($a->fresh()->enabled);

        $this->put('http://localhost/admin/kits/'.$a->id, [
            'name' => 'KL Bulk A', 'admin_name' => 'KL Bulk A', 'placement' => 'before_details', 'layout' => 'grid',
            'type' => 'extended', 'enabled' => '1', 'after_save' => 'close',
        ])->assertRedirect(route('admin.kits.index'));

        $this->from('http://localhost/admin/kits?q=KL')->delete('http://localhost/admin/kits/'.$b->id)
            ->assertRedirect('http://localhost/admin/kits?q=KL');
        $this->assertNull(Kit::find($b->id));
    }
}
