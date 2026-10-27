<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Label;

/** The labels list: one heading, searched in every language, narrowed by status, switched and acted on in bulk. */
class LabelListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function label(string $admin, array $name, bool $enabled = true): Label
    {
        return Label::create(['name' => $name, 'name_for_admin' => $admin, 'color' => 'primary', 'enabled' => $enabled]);
    }

    public function test_the_page_is_headed_once(): void
    {
        $html = $this->get('http://localhost/admin/catalog/labels')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h[12][^>]*>\s*(Labels|Метки)\s*<\/h[12]>/u', $html));
    }

    public function test_search_reads_every_language_and_the_admin_name(): void
    {
        $this->label('LL Autumn sale', ['en' => 'Sale', 'ru' => 'Осенняя распродажа']);
        $this->label('LL Novelty', ['en' => 'New']);

        $this->get('http://localhost/admin/catalog/labels?q=осенняя')->assertOk()
            ->assertSee('LL Autumn sale')->assertDontSee('LL Novelty');

        $this->get('http://localhost/admin/catalog/labels?q=ll+novelty')->assertOk()
            ->assertSee('LL Novelty')->assertDontSee('LL Autumn sale');
    }

    public function test_the_list_narrows_by_status(): void
    {
        $this->label('LL On one', ['en' => 'On']);
        $this->label('LL Off one', ['en' => 'Off'], enabled: false);

        $this->get('http://localhost/admin/catalog/labels?status=disabled&q=LL')->assertOk()
            ->assertSee('LL Off one')->assertDontSee('LL On one');
    }

    public function test_the_form_saves_back_to_itself_unless_asked_to_close(): void
    {
        $label = $this->label('LL Form', ['en' => 'Hit']);
        $url = 'http://localhost/admin/catalog/labels/'.$label->id;
        $fields = [
            'name' => 'Hit', 'name_for_admin' => 'LL Form', 'style' => 'text', 'color' => 'error',
            'position_list' => 'top-right', 'position_detail' => 'top-left', 'enabled' => '1',
            'match' => 'all', 'rules' => [['field' => 'stock', 'operator' => 'lte', 'value' => '3']],
        ];

        $this->get($url.'/edit')->assertOk()
            ->assertSee('form="label-delete"', false)
            ->assertSee('name="after_save"', false);

        $this->put($url, $fields)->assertRedirect(route('admin.catalog.labels.edit', $label));
        $label->refresh();
        $this->assertSame(['error', 'top-right'], [$label->color, $label->position_list]);
        $this->assertSame('stock', $label->conditions['rules'][0]['field'] ?? null);

        $this->put($url, $fields + ['after_save' => 'close'])->assertRedirect(route('admin.catalog.labels.index'));

        $this->post('http://localhost/admin/catalog/labels', ['name_for_admin' => 'LL New one'] + $fields)
            ->assertRedirect(route('admin.catalog.labels.edit', Label::where('name_for_admin', 'LL New one')->firstOrFail()));
    }

    public function test_a_row_switches_in_place_and_several_switch_at_once(): void
    {
        $a = $this->label('LL Bulk A', ['en' => 'A']);
        $b = $this->label('LL Bulk B', ['en' => 'B']);

        $this->patch('http://localhost/admin/catalog/labels/'.$a->id.'/status', ['enabled' => 0])
            ->assertOk()->assertSee('id="label-status-'.$a->id.'"', false);
        $this->assertFalse($a->fresh()->enabled);

        $this->post('http://localhost/admin/catalog/labels/bulk', ['action' => 'enable', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertTrue($a->fresh()->enabled);

        $this->post('http://localhost/admin/catalog/labels/bulk', ['action' => 'delete', 'ids' => [$b->id]])->assertRedirect();
        $this->assertNull(Label::find($b->id));

        $this->post('http://localhost/admin/catalog/labels/bulk', ['action' => 'purge', 'ids' => [$a->id]])->assertSessionHasErrors('action');
    }
}
