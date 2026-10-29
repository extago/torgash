<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureGroup;

/**
 * Characteristic groups have a screen of their own (PLAN §63.10): named in
 * every content language one language per save, ordered by a drag, deleted
 * without taking their characteristics along.
 */
class FeatureGroupsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_the_list_names_groups_and_counts_their_characteristics(): void
    {
        $group = FeatureGroup::create(['name' => ['en' => 'FG Display', 'ru' => 'FG Экран'], 'sort' => 10]);
        Feature::create(['code' => 'fg_size', 'name' => ['en' => 'FG Size'], 'purpose' => 'filter', 'input_type' => 'text', 'shown_in' => ['specs'], 'group_id' => $group->id]);

        $this->actingAs($this->admin)->get('http://localhost/admin/catalog/feature-groups')
            ->assertOk()->assertSee('FG Display')->assertSee('Also filled in: RU');

        $this->actingAs($this->admin)->get('http://localhost/admin/catalog/features')
            ->assertOk()->assertSee(route('admin.catalog.feature-groups.index'));
    }

    public function test_a_group_is_named_one_language_per_save_and_keeps_the_others(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/catalog/feature-groups', ['name' => 'FG Camera'])
            ->assertRedirect();
        $group = FeatureGroup::whereRaw("name->>'en' = ?", ['FG Camera'])->firstOrFail();

        $group->update(['name' => ['en' => 'FG Camera', 'ru' => 'FG Камера']]);

        $this->actingAs($this->admin)->put('http://localhost/admin/catalog/feature-groups/'.$group->id, ['name' => 'FG Cameras'])
            ->assertRedirect(route('admin.catalog.feature-groups.edit', $group));

        $this->assertSame(['en' => 'FG Cameras', 'ru' => 'FG Камера'], $group->fresh()->name, 'the other language is kept');

        $this->actingAs($this->admin)->post('http://localhost/admin/catalog/feature-groups', ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_the_order_is_the_one_dragged(): void
    {
        $a = FeatureGroup::create(['name' => ['en' => 'FG A'], 'sort' => 10]);
        $b = FeatureGroup::create(['name' => ['en' => 'FG B'], 'sort' => 20]);

        $this->actingAs($this->admin)->postJson('http://localhost/admin/catalog/feature-groups/sort', ['ids' => [$b->id, $a->id]])
            ->assertNoContent();

        $this->assertTrue($b->fresh()->sort < $a->fresh()->sort);
    }

    public function test_deleting_a_group_leaves_its_characteristics_ungrouped(): void
    {
        $group = FeatureGroup::create(['name' => ['en' => 'FG Gone'], 'sort' => 10]);
        $feature = Feature::create(['code' => 'fg_stays', 'name' => ['en' => 'FG Stays'], 'purpose' => 'filter', 'input_type' => 'text', 'shown_in' => ['specs'], 'group_id' => $group->id]);

        $this->actingAs($this->admin)->delete('http://localhost/admin/catalog/feature-groups/'.$group->id)
            ->assertRedirect(route('admin.catalog.feature-groups.index'));

        $this->assertNull(FeatureGroup::find($group->id));
        $this->assertNull($feature->fresh()->group_id);
    }
}
