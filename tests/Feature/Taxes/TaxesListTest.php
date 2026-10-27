<?php

namespace Tests\Feature\Taxes;

use App\Models\User;
use Tests\TestCase;
use Torgash\Taxes\Models\Tax;

/** The taxes list: searched, switched in place, several at once; the form stays open after a save. */
class TaxesListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function tax(array $attributes = []): Tax
    {
        return Tax::create($attributes + [
            'code' => 't'.uniqid(),
            'name' => ['en' => 'Some tax'],
            'rate_type' => Tax::PERCENT,
            'rate_percent' => 20,
            'enabled' => true,
        ]);
    }

    public function test_search_by_code_and_status_tabs(): void
    {
        $this->tax(['code' => 'zebra-vat', 'name' => ['en' => 'Zebra VAT'], 'enabled' => false]);
        $this->tax(['code' => 'other', 'name' => ['en' => 'Other duty']]);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/taxes?q=zebra&status=disabled')
            ->assertOk()->assertSee('Zebra VAT')->assertDontSee('Other duty');

        $this->get('http://localhost/admin/taxes?q=zebra&status=enabled')
            ->assertOk()->assertDontSee('Zebra VAT');
    }

    public function test_status_in_place_and_in_bulk(): void
    {
        $a = $this->tax();
        $b = $this->tax();

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/taxes/{$a->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('tax-status-'.$a->id, false);
        $this->assertFalse($a->refresh()->enabled);

        $this->post('http://localhost/admin/taxes/bulk', ['action' => 'disable', 'ids' => [$b->id]]);
        $this->assertFalse($b->refresh()->enabled);

        $this->post('http://localhost/admin/taxes/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]]);
        $this->assertNull($a->fresh());
        $this->assertNull($b->fresh());
    }

    public function test_saving_stays_on_the_form_and_save_and_close_leaves_it(): void
    {
        $tax = $this->tax(['code' => 'stay']);
        $form = ['code' => 'stay', 'name' => 'Some tax', 'rate_type' => Tax::PERCENT, 'rate_percent' => 7, 'price_includes_tax' => '0', 'enabled' => 1];

        $this->actingAs($this->admin)
            ->put("http://localhost/admin/taxes/{$tax->id}", $form)
            ->assertRedirect(route('admin.taxes.edit', $tax));
        $this->assertFalse($tax->refresh()->price_includes_tax);

        $this->put("http://localhost/admin/taxes/{$tax->id}", $form + ['after_save' => 'close'])
            ->assertRedirect(route('admin.taxes.index'));
    }
}
