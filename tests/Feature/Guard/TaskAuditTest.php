<?php

namespace Tests\Feature\Guard;

use App\Core\Settings\SettingsManager;
use App\Core\Tasks\CurrentTask;
use App\Models\BackgroundTask;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Guard\Models\AuditEntry;
use Torgash\Sku\Renumber;

/**
 * A background task somebody started is THEIR act in the trail — the queue
 * worker has no request and no signed-in user, and until this a renumbering
 * of the whole shop left nothing behind but "task done".
 */
class TaskAuditTest extends TestCase
{
    public function test_a_task_writes_the_trail_as_the_person_who_started_it(): void
    {
        $admin = User::factory()->create(['name' => 'Starter']);
        app(SettingsManager::class)->set('torgash.sku', 'on_create', 'off');
        app(SettingsManager::class)->set('torgash.sku', 'template', 'AUD-{n:4}');
        $product = Product::create([
            'sku' => 'OLD-AUD', 'name' => ['en' => 'Audited'], 'slug' => 'task-audit-'.uniqid(),
            'price' => 100, 'stock' => 1, 'status' => 'active',
        ]);

        $job = new Renumber('restart');
        $job->taskId = BackgroundTask::create([
            'name' => $job->label(), 'module' => 'torgash.sku', 'status' => BackgroundTask::QUEUED, 'user_id' => $admin->id,
        ])->id;
        $job->handle();

        $entry = AuditEntry::where('entity_type', Product::class)->where('entity_id', (string) $product->id)
            ->where('action', 'updated')->latest('id')->first();

        $this->assertNotNull($entry, 'the renumbering left no trail');
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('Starter', $entry->user_name);
        $this->assertSame($job->taskId, $entry->task_id);
        $this->assertSame('OLD-AUD', $entry->changes['sku']['from'], 'old → new, never the parking');
        $this->assertStringStartsWith('AUD-', $entry->changes['sku']['to']);
        $this->assertNull(app(CurrentTask::class)->task(), 'left behind for the next job');

        $this->actingAs($admin->assignRole('admin'))
            ->get('http://localhost/admin/guard/audit?task='.$job->taskId)
            ->assertOk()->assertSee('OLD-AUD');
    }

    public function test_a_task_nobody_started_writes_nothing(): void
    {
        $job = new Renumber('restart');
        $job->taskId = BackgroundTask::create([
            'name' => $job->label(), 'module' => 'torgash.sku', 'status' => BackgroundTask::QUEUED,
        ])->id;
        $before = AuditEntry::count();

        $job->handle();

        $this->assertSame($before, AuditEntry::count());
    }

    public function test_everything_a_task_did_is_put_back_swaps_included(): void
    {
        $admin = User::factory()->create(['name' => 'Starter'])->assignRole('admin');
        app(SettingsManager::class)->set('torgash.sku', 'on_create', 'off');
        app(SettingsManager::class)->set('torgash.sku', 'template', 'AUD-{n:4}');
        Product::query()->update(['sku' => null]);

        // Old SKUs in a different order from the one the renumbering will
        // give out: every product wants back a SKU another one now holds.
        $made = [];
        foreach (['AUD-0003', 'AUD-0001', 'AUD-0002'] as $i => $old) {
            $made[] = Product::create([
                'sku' => $old, 'name' => ['en' => 'Swap '.$i], 'slug' => 'task-swap-'.$i.'-'.uniqid(),
                'price' => 100, 'stock' => 1, 'status' => 'active',
            ]);
        }

        $this->actingAs($admin);
        $job = new Renumber('restart');
        $job->taskId = BackgroundTask::create([
            'name' => $job->label(), 'module' => 'torgash.sku', 'status' => BackgroundTask::QUEUED, 'user_id' => $admin->id,
        ])->id;
        $job->handle();

        $this->assertSame(['AUD-0001', 'AUD-0002', 'AUD-0003'], array_map(fn ($p) => $p->fresh()->sku, $made));

        // Somebody edits the third one afterwards: that edit is theirs and stays.
        $made[2]->fresh()->update(['sku' => 'BY-HAND']);

        $this->post('http://localhost/admin/guard/audit/task/'.$job->taskId.'/revert')
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('AUD-0003', $made[0]->fresh()->sku);
        $this->assertSame('AUD-0001', $made[1]->fresh()->sku);
        $this->assertSame('BY-HAND', $made[2]->fresh()->sku, 'changed since — left alone');

        $revert = BackgroundTask::where('name', 'Put back what a background task did')->latest('id')->firstOrFail();
        $this->assertSame(BackgroundTask::DONE, $revert->status);
        $counts = $revert->result;
        $this->assertSame(2, $counts['reverted']);
        $this->assertSame(1, $counts['changed_since']);
        $this->assertSame(0, $counts['taken']);
        $this->assertSame(0, Product::where('sku', 'like', '~~%')->count(), 'nothing left parked');

        // The revert is in the trail too — as its own task, by the person who asked.
        $this->assertTrue(\Torgash\Guard\Models\AuditEntry::where('task_id', $revert->id)->where('user_id', $admin->id)->exists());
    }

    public function test_a_value_somebody_else_took_stays_where_it_is(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        app(SettingsManager::class)->set('torgash.sku', 'on_create', 'off');
        app(SettingsManager::class)->set('torgash.sku', 'template', 'TKN-{n:4}');
        Product::query()->update(['sku' => null]);
        $product = Product::create([
            'sku' => 'TAKEN-OLD', 'name' => ['en' => 'Taken'], 'slug' => 'task-taken-'.uniqid(),
            'price' => 100, 'stock' => 1, 'status' => 'active',
        ]);

        $this->actingAs($admin);
        $job = new Renumber('restart');
        $job->taskId = BackgroundTask::create([
            'name' => $job->label(), 'module' => 'torgash.sku', 'status' => BackgroundTask::QUEUED, 'user_id' => $admin->id,
        ])->id;
        $job->handle();

        // Not one of the task's rows: its SKU is not the task's to move.
        $other = Product::create([
            'sku' => 'TAKEN-OLD', 'name' => ['en' => 'Other'], 'slug' => 'task-other-'.uniqid(),
            'price' => 100, 'stock' => 1, 'status' => 'active',
        ]);

        $this->post('http://localhost/admin/guard/audit/task/'.$job->taskId.'/revert')->assertRedirect();

        $this->assertSame('TKN-0001', $product->fresh()->sku);
        $this->assertSame('TAKEN-OLD', $other->fresh()->sku);
        $counts = BackgroundTask::where('name', 'Put back what a background task did')->latest('id')->firstOrFail()->result;
        $this->assertSame(1, $counts['taken']);
        $this->assertSame(0, Product::where('sku', 'like', '~~%')->count());
    }
}
