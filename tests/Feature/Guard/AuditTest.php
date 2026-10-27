<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Models\CustomerGroup;
use App\Models\User;
use Tests\TestCase;
use Torgash\Guard\Audit\Auditor;
use Torgash\Guard\Models\AuditEntry;

/**
 * The second part of the guard: who changed what.
 *
 * What is pinned here is not "a row appears" — it is the four ways a trail
 * stops being one. It records the wrong things (the storefront's traffic, a
 * timestamp somebody's save touched), it records too much of them (a whole
 * description, an unbounded bulk edit), it records what must never be written
 * down twice (a gateway's secret), and it stops recording quietly.
 */
class AuditTest extends TestCase
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

    /** An admin request that changes one thing, with nothing else in the way. */
    protected function inAdmin(callable $work): void
    {
        $this->actingAs($this->admin());

        // A real request through the admin, because that is what arms the
        // auditor — everything below would record nothing from a bare unit
        // test, and that is the feature rather than a limitation of the test.
        $this->app['router']->get('/admin/_audit_probe', function () use ($work) {
            $work();

            return 'ok';
        })->middleware('web');

        $this->get('http://localhost/admin/_audit_probe')->assertOk();
    }

    // ------------------------------------------------------------- what it is

    public function test_a_change_in_the_admin_is_recorded_with_who_and_what(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);

        $this->inAdmin(fn () => CustomerGroup::find($group->id)->update(['discount_percent' => 12]));

        $entry = AuditEntry::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertSame(CustomerGroup::class, $entry->entity_type);
        $this->assertSame((string) $group->id, $entry->entity_id);
        $this->assertSame(5, $entry->changes['discount_percent']['from']);
        $this->assertSame(12, $entry->changes['discount_percent']['to']);
        $this->assertSame($this->admin()->id, $entry->user_id);
        $this->assertSame($this->admin()->name, $entry->user_name);
    }

    /**
     * The one thing a trail is for that nothing else in the engine does: what
     * was in the row that no longer exists.
     */
    public function test_a_deletion_keeps_a_copy_of_what_was_deleted(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);

        $this->inAdmin(fn () => CustomerGroup::find($group->id)->delete());

        $entry = AuditEntry::where('action', 'deleted')->latest('id')->firstOrFail();

        $this->assertSame(5, $entry->changes['discount_percent']['from']);
        $this->assertSame('Wholesale', $entry->entity_title);
    }

    /**
     * Not "always on". The storefront writes on every visit, and an audit of
     * that is a copy of the shop's traffic nobody will ever read.
     */
    public function test_nothing_outside_the_admin_is_recorded(): void
    {
        $this->actingAs($this->admin());

        $this->app['router']->get('/_audit_shop_probe', function () {
            CustomerGroup::create(['name' => ['en' => 'Shop side'], 'discount_percent' => 1]);

            return 'ok';
        })->middleware('web');

        $this->get('http://localhost/_audit_shop_probe')->assertOk();

        $this->assertSame(0, AuditEntry::count());
    }

    /** Neither is a queue worker or an artisan command: nobody clicked. */
    public function test_nothing_outside_a_request_is_recorded(): void
    {
        CustomerGroup::create(['name' => ['en' => 'Console'], 'discount_percent' => 2]);

        $this->assertSame(0, AuditEntry::count());
    }

    /**
     * A save that touched only a timestamp is not a change anybody asked
     * about, and a list full of them is a list nobody reads.
     */
    public function test_a_save_that_changed_nothing_is_not_an_entry(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);

        $this->inAdmin(function () use ($group) {
            $fresh = CustomerGroup::find($group->id);
            $fresh->touch();
            $fresh->save();
        });

        $this->assertSame(0, AuditEntry::where('action', 'updated')->count());
    }

    // --------------------------------------------------------------- the rules

    /**
     * The rule with the highest cost of being wrong. A gateway's credentials
     * live in a plain jsonb column called `config`; masking only by the outer
     * field name would have written the secret into a table any manager with
     * one permission can read.
     */
    public function test_a_credential_is_recorded_as_changed_and_never_as_a_value(): void
    {
        $auditor = new class extends Auditor
        {
            public function values(array $changes): array
            {
                return $this->clean($changes);
            }
        };

        $cleaned = $auditor->values([
            'secret_key' => ['from' => 'old-key', 'to' => 'new-key'],
            'config' => ['to' => ['merchant' => 'shop-1', 'secret' => 'live_abc123']],
        ]);

        $this->assertSame('••••••', $cleaned['secret_key']['to']);
        $this->assertStringNotContainsString('old-key', json_encode($cleaned));

        // The field is still there, and so is everything about it that is not
        // the credential itself.
        $this->assertStringContainsString('shop-1', $cleaned['config']['to']);
        $this->assertStringNotContainsString('live_abc123', $cleaned['config']['to']);
    }

    public function test_a_long_value_is_cut_rather_than_stored_whole(): void
    {
        $long = str_repeat('а', 5000);
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);

        $this->inAdmin(fn () => CustomerGroup::find($group->id)->update(['name' => ['en' => $long]]));

        $entry = AuditEntry::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertLessThan(400, mb_strlen((string) $entry->changes['name']['to']));
    }

    /**
     * One click can legitimately change thousands of rows. The trail is allowed
     * to stop — it is not allowed to stop silently, because a trail with a hole
     * in it and no note about the hole is worse than one that says "from here
     * on I was not looking".
     */
    public function test_a_runaway_action_is_capped_and_says_so(): void
    {
        config(['guard.audit.max_per_request' => 3]);

        $this->inAdmin(function () {
            foreach (range(1, 10) as $n) {
                CustomerGroup::create(['name' => ['en' => 'G'.$n], 'discount_percent' => $n]);
            }
        });

        $this->assertSame(3, AuditEntry::where('action', 'created')->count());
        $this->assertSame(1, AuditEntry::where('action', 'truncated')->count());
    }

    public function test_an_ignored_table_is_not_recorded(): void
    {
        config(['guard.audit.ignore' => ['customer_groups']]);

        $this->inAdmin(fn () => CustomerGroup::create(['name' => ['en' => 'Quiet'], 'discount_percent' => 3]));

        $this->assertSame(0, AuditEntry::count());
    }

    /** A table its owner declares to be a log already is not logged twice. */
    public function test_a_table_a_module_calls_a_log_is_not_recorded(): void
    {
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'customer_groups']);

        $this->inAdmin(fn () => CustomerGroup::create(['name' => ['en' => 'Declared'], 'discount_percent' => 3]));

        $this->assertSame(0, AuditEntry::count());
    }

    /** Neighbouring saves of one row by one person in one minute read as one line. */
    public function test_one_act_saved_in_several_steps_is_one_line_on_the_screen(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Steps'], 'discount_percent' => 1]);

        $this->inAdmin(function () use ($group) {
            CustomerGroup::find($group->id)->update(['discount_percent' => 2]);
            CustomerGroup::find($group->id)->update(['discount_percent' => 3]);
        });

        $this->assertSame(2, AuditEntry::count());

        $this->actingAs($this->admin())->get('http://localhost/admin/guard/audit')->assertOk()
            ->assertSee('× 2')
            ->assertSeeInOrder(['2', '3']);
    }

    public function test_switching_it_off_stops_it(): void
    {
        config(['guard.audit.enabled' => false]);

        $this->inAdmin(fn () => CustomerGroup::create(['name' => ['en' => 'Off'], 'discount_percent' => 4]));

        $this->assertSame(0, AuditEntry::count());
    }

    // ------------------------------------------- the things that are not rows

    /**
     * Settings live in a plain table with no Eloquent under it, and they are
     * the second half of what an audit is for: "who turned that off in March".
     */
    public function test_a_setting_change_is_recorded_through_the_core_hook(): void
    {
        $this->inAdmin(fn () => app(SettingsManager::class)->set('torgash.guard', 'errors_level', 'warning'));

        $entry = AuditEntry::where('entity_type', 'setting')->latest('id')->firstOrFail();

        $this->assertSame('torgash.guard.errors_level', $entry->entity_id);
        $this->assertSame('warning', $entry->changes['errors_level']['to']);
    }

    /**
     * And only when it MOVED: the settings screen saves every field on the
     * page, so a hook that fires for the forty that did not change is a hook
     * nobody can listen to.
     */
    public function test_saving_a_setting_to_the_value_it_already_had_is_not_a_change(): void
    {
        app(SettingsManager::class)->set('torgash.guard', 'errors_level', 'warning');

        $this->inAdmin(fn () => app(SettingsManager::class)->set('torgash.guard', 'errors_level', 'warning'));

        $this->assertSame(0, AuditEntry::where('entity_type', 'setting')->count());
    }

    // ------------------------------------------------------------- the screen

    public function test_the_screen_shows_the_trail_and_can_be_narrowed_to_one_row(): void
    {
        $group = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 5]);

        $this->inAdmin(fn () => CustomerGroup::find($group->id)->update(['discount_percent' => 12]));

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/guard/audit')
            ->assertOk()
            // The kind of thing in words, not the PHP class.
            ->assertSee('Customer group')
            ->assertSee('12');

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/guard/audit?'.http_build_query([
                'type' => CustomerGroup::class,
                'id' => $group->id,
            ]))
            ->assertOk()
            ->assertSee('12');
    }
}
