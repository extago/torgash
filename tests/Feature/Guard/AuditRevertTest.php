<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Models\CustomerGroup;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Guard\Audit\Auditor;
use Torgash\Guard\Audit\Revert;
use Torgash\Guard\Models\AuditEntry;
use Torgash\Pages\Models\Page;

/**
 * "Put back as it was" (Torgash\Guard\Audit\Revert): through the model's
 * own save, field by field, and never over what the trail did not keep
 * whole, what changed again since, or a kind its owner did not offer.
 */
class AuditRevertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['guard.audit.enabled' => true, 'guard.audit.ignore' => [], 'guard.audit.max_per_request' => 200]);
        AuditEntry::query()->delete();
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    /** A change made in the admin, the way the trail records one. */
    protected function changeInAdmin(callable $work): AuditEntry
    {
        $this->actingAs($this->admin());
        $this->app['router']->get('/admin/_revert_probe', function () use ($work) {
            $work();

            return 'ok';
        })->middleware('web');

        $this->get('http://localhost/admin/_revert_probe')->assertOk();

        return AuditEntry::where('action', 'updated')->latest('id')->firstOrFail();
    }

    protected function page(): Page
    {
        return Page::create(['title' => ['en' => 'Delivery'], 'slug' => 'delivery-'.uniqid(), 'body' => ['en' => '<p>Old text</p>'], 'enabled' => true]);
    }

    public function test_a_page_change_is_put_back_through_the_model(): void
    {
        $page = $this->page();
        $entry = $this->changeInAdmin(fn () => Page::find($page->id)->update(['title' => ['en' => 'Shipping, broken']]));

        $this->post('http://localhost/admin/guard/audit/'.$entry->id.'/revert')->assertRedirect()->assertSessionHas('status');

        $this->assertSame('Delivery', $page->fresh()->title['en']);
        $this->assertSame(2, AuditEntry::where('entity_type', Page::class)->where('action', 'updated')->count(), 'the revert is itself in the trail');
    }

    public function test_a_field_changed_again_since_is_left_alone(): void
    {
        $page = $this->page();
        $entry = $this->changeInAdmin(fn () => Page::find($page->id)->update(['title' => ['en' => 'Second']]));
        Page::find($page->id)->update(['title' => ['en' => 'Third']]);

        $result = app(Revert::class)->apply($entry->fresh());

        $this->assertSame([], $result['reverted']);
        $this->assertSame(['title' => 'changed_since'], $result['skipped']);
        $this->assertSame('Third', $page->fresh()->title['en']);
    }

    public function test_a_value_the_trail_kept_only_in_part_is_not_put_back(): void
    {
        $page = $this->page();
        $long = '<p>'.str_repeat('Long text. ', 60).'</p>';
        Page::whereKey($page->id)->update(['body' => json_encode(['en' => $long])]);

        $entry = $this->changeInAdmin(fn () => Page::find($page->id)->update(['body' => ['en' => '<p>Short</p>']]));

        $this->assertFalse(Revert::whole($entry->changes['body']['from']));
        $this->assertFalse(app(Revert::class)->offered($entry), 'nothing else changed, so there is no button');
    }

    public function test_a_kind_its_owner_did_not_offer_gets_no_button(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);
        $entry = $this->changeInAdmin(fn () => CustomerGroup::find($group->id)->update(['discount_percent' => 12]));

        $this->assertFalse(app(Revert::class)->offered($entry));

        $this->post('http://localhost/admin/guard/audit/'.$entry->id.'/revert')->assertSessionHas('error');
        $this->assertSame(12, $group->fresh()->discount_percent);
    }

    public function test_a_setting_is_put_back(): void
    {
        $settings = app(SettingsManager::class);
        $settings->set('core', 'weight_unit', 'g');

        $entry = $this->changeInAdmin(fn () => app(SettingsManager::class)->set('core', 'weight_unit', 'kg'));

        $this->assertSame('setting', $entry->entity_type);

        $result = app(Revert::class)->apply($entry);

        $this->assertSame(['weight_unit'], $result['reverted']);
        $this->assertSame('g', app(SettingsManager::class)->get('core', 'weight_unit'));
    }

    public function test_it_takes_a_permission_of_its_own(): void
    {
        $page = $this->page();
        $entry = $this->changeInAdmin(fn () => Page::find($page->id)->update(['title' => ['en' => 'Changed']]));

        $role = Role::findOrCreate('auditor-only');
        $role->givePermissionTo(['admin.access', 'guard.manage']);
        $reader = User::factory()->create();
        $reader->assignRole($role);

        $this->actingAs($reader)->get('http://localhost/admin/guard/audit')->assertOk()->assertDontSee('Put back as it was');
        $this->actingAs($reader)->post('http://localhost/admin/guard/audit/'.$entry->id.'/revert')->assertForbidden();
        $this->assertSame('Changed', $page->fresh()->title['en']);
    }

    public function test_the_mask_and_the_cut_are_told_apart_from_a_value(): void
    {
        $this->assertFalse(Revert::whole(Auditor::MASK));
        $this->assertFalse(Revert::whole(str_repeat('a', Auditor::MAX_VALUE).'…'));
        $this->assertTrue(Revert::whole('short…'));
        $this->assertTrue(Revert::whole(12));
        $this->assertTrue(Revert::whole(null));
    }
}
