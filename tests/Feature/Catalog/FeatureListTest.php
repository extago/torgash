<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;

/** The characteristics list: searched, narrowed by purpose, acted on in bulk. */
class FeatureListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function feature(string $name, string $purpose = 'filter', ?string $ru = null): Feature
    {
        return Feature::create(['name' => array_filter(['en' => $name, 'ru' => $ru]), 'purpose' => $purpose, 'input_type' => 'select', 'sort' => 0]);
    }

    public function test_search_reads_every_language_and_the_code(): void
    {
        $this->feature('FL Screen size', ru: 'ФЛ Диагональ');
        $this->feature('FL Battery');

        $this->get('http://localhost/admin/catalog/features?q=Диагональ')->assertOk()
            ->assertSee('FL Screen size')->assertDontSee('FL Battery');

        $this->get('http://localhost/admin/catalog/features?q=FL_BATTERY')->assertOk()
            ->assertSee('FL Battery')->assertDontSee('FL Screen size');
    }

    public function test_the_list_narrows_by_purpose(): void
    {
        $this->feature('FL Plain one');
        $this->feature('FL Axis one', 'variation');

        $this->get('http://localhost/admin/catalog/features?purpose=variation&q=FL+')->assertOk()
            ->assertSee('FL Axis one')->assertDontSee('FL Plain one');
    }

    public function test_bulk_changes_the_purpose_and_deletes(): void
    {
        $a = $this->feature('FL Bulk A');
        $b = $this->feature('FL Bulk B');
        $c = $this->feature('FL Bulk C');

        $this->post('http://localhost/admin/catalog/features/bulk', ['action' => 'variation', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertSame(['variation', 'variation', 'filter'], [$a->fresh()->purpose, $b->fresh()->purpose, $c->fresh()->purpose]);

        $this->post('http://localhost/admin/catalog/features/bulk', ['action' => 'delete', 'ids' => [$c->id]])->assertRedirect();
        $this->assertNull(Feature::find($c->id));

        // Not a bulk act: a brand's values are pages decided one at a time.
        $this->post('http://localhost/admin/catalog/features/bulk', ['action' => 'brand', 'ids' => [$a->id]])->assertSessionHasErrors('action');
    }
}
