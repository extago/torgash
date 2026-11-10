<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Destinations;
use App\Core\Backups\Names;
use App\Core\Backups\PlanSchedule;
use App\Core\Backups\Retention;
use App\Models\BackupDestination;
use App\Models\BackupPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Backup plans (PLAN §67): a schedule of several moments, names from a
 * template, "how many to keep" per storage — and the screens that edit them.
 */
class BackupPlansTest extends TestCase
{
    public function test_the_schedule_is_a_list_of_moments_in_cron(): void
    {
        $this->assertSame('0 12 * * *', PlanSchedule::cron(['kind' => 'daily', 'at' => '12:00']));
        $this->assertSame('5 */6 * * *', PlanSchedule::cron(['kind' => 'hourly', 'every' => 6, 'minute' => 5]));
        $this->assertSame('40 3 * * 1,4', PlanSchedule::cron(['kind' => 'weekly', 'days' => [4, 1], 'at' => '03:40']));
        $this->assertSame('0 2 1 * *', PlanSchedule::cron(['kind' => 'monthly', 'day' => 1, 'at' => '02:00']));
        $this->assertSame('30 2 * * 1-5', PlanSchedule::cron(['kind' => 'cron', 'expression' => '30 2 * * 1-5']));
        $this->assertNull(PlanSchedule::cron(['kind' => 'daily', 'at' => '25:00']));
        $this->assertNull(PlanSchedule::cron(['kind' => 'cron', 'expression' => 'every day']));

        [$kept, $errors] = PlanSchedule::clean([
            ['kind' => 'daily', 'at' => '12:00'],
            ['kind' => 'daily', 'at' => '00:00'],
            ['kind' => 'weekly', 'days' => [], 'at' => '03:00'],
        ]);
        $this->assertCount(2, $kept);
        $this->assertCount(1, $errors);

        // Noon and midnight, in the shop's clock.
        $next = PlanSchedule::next($kept, 3, Carbon::parse('2026-11-02 10:00', config('app.timezone')));
        $this->assertSame(['2026-11-02 12:00', '2026-11-03 00:00', '2026-11-03 12:00'], array_map(fn ($d) => $d->format('Y-m-d H:i'), $next));
    }

    public function test_names_come_from_the_template_and_must_be_unique_in_time(): void
    {
        $plan = new BackupPlan(['code' => 'db']);
        $at = Carbon::parse('2026-11-02 12:00:05');

        $this->assertSame('shop-db-2026-11-02-120005.zip', Names::file('shop-{plan}-{date}-{time}', $plan, $at, 'zip'));
        $this->assertSame('db/2026/11', Names::folder('{plan}/{Y}/../{m}', $plan, $at));
        $this->assertNotNull(Names::nameError('{plan}-{date}'));
        $this->assertNotNull(Names::nameError('a/{date}-{time}'));
        $this->assertNotNull(Names::nameError('{date}-{time}-{nope}'));
        $this->assertNull(Names::nameError('{shop}-{date}-{time}'));
    }

    public function test_keeping_counts_days_weeks_and_months_and_never_the_newest_or_a_pinned_one(): void
    {
        $now = Carbon::parse('2026-11-02 12:00');
        $copies = [];
        // One a day for 60 days, two on the newest day.
        for ($i = 0; $i < 60; $i++) {
            $copies[] = ['id' => $i + 1, 'at' => $now->copy()->subDays($i)->setTime(3, 0), 'bytes' => 100, 'pinned' => $i === 50];
        }
        $copies[] = ['id' => 100, 'at' => $now->copy()->setTime(11, 0), 'bytes' => 100, 'pinned' => false];

        $expired = Retention::expired($copies, ['daily' => 7, 'weekly' => 4, 'monthly' => 3], $now);
        $kept = array_diff(array_column($copies, 'id'), $expired);

        $this->assertContains(100, $kept, 'the newest');
        $this->assertNotContains(1, $kept, 'the same day\'s older one is not a second daily');
        $this->assertContains(51, $kept, 'the pinned one');
        $this->assertLessThan(20, count($kept));

        // Last N, then a cap in size.
        $this->assertCount(57, Retention::expired($copies, ['last' => 3], $now), 'the newest 3 and the pinned one');
        $this->assertSame([], Retention::expired($copies, [], $now), 'no rule keeps all');
        $this->assertCount(59, Retention::expired(array_map(fn ($c) => ['pinned' => false] + $c, $copies), ['last' => 10, 'gigabytes' => 0.0000002], $now));
    }

    public function test_a_plan_is_made_on_the_screen_with_two_moments_and_two_storages(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $server = BackupDestination::where('system', true)->firstOrFail();
        $other = BackupDestination::create(['name' => 'Second disk', 'driver' => 'disk', 'settings' => ['disk' => 'private', 'root' => 'backups']]);

        $this->actingAs($admin)->post('http://localhost/admin/system/backup-plans', [
            'name' => 'Whole site',
            'contents' => ['database', 'files', 'code'],
            'enabled' => 1,
            'name_template' => '{shop}-{plan}-{date}-{time}',
            'schedule' => [['kind' => 'daily', 'at' => '12:00'], ['kind' => 'daily', 'at' => '00:00']],
            'destinations' => [
                $server->id => ['on' => 1, 'keep' => ['last' => 3]],
                $other->id => ['on' => 1, 'keep' => ['daily' => 7, 'monthly' => 12]],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $plan = BackupPlan::where('name', 'Whole site')->firstOrFail();
        $this->assertSame('whole-site', $plan->code);
        $this->assertCount(2, $plan->schedule);
        $this->assertSame(['database', 'files', 'code'], $plan->contents);
        $this->assertEqualsCanonicalizing([$server->id, $other->id], $plan->destinations->pluck('id')->all());

        $this->get('http://localhost/admin/system/backup-plans')->assertOk()->assertSee('Whole site')->assertSee(__('Every day at :time', ['time' => '12:00']));
        $this->get('http://localhost/admin/system/backup-plans/'.$plan->id.'/edit')->assertOk();

        // A name without the time, no storage: refused, in words.
        $this->post('http://localhost/admin/system/backup-plans', [
            'name' => 'Bad', 'contents' => ['database'], 'name_template' => '{plan}-{date}', 'destinations' => [],
        ])->assertSessionHasErrors(['name_template', 'destinations']);
    }

    public function test_a_storage_keeps_its_secrets_encrypted_and_a_blank_field_keeps_them(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->post('http://localhost/admin/system/backup-destinations', [
            'name' => 'Bucket', 'driver' => 's3', 'path_template' => '{plan}',
            'settings' => ['bucket' => 'shop-backups', 'endpoint' => 'https://s3.example.com', 'key' => 'AKIA1', 'secret' => 'very-secret'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $bucket = BackupDestination::where('name', 'Bucket')->firstOrFail();
        $this->assertStringNotContainsString('very-secret', (string) $bucket->getRawOriginal('secrets'));
        $this->assertSame('very-secret', $bucket->secret('secret'));

        $this->put('http://localhost/admin/system/backup-destinations/'.$bucket->id, [
            'name' => 'Bucket', 'driver' => 's3', 'path_template' => '',
            'settings' => ['bucket' => 'shop-backups', 'endpoint' => 'https://s3.example.com', 'key' => '', 'secret' => ''],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('very-secret', $bucket->refresh()->secret('secret'));
        $this->get('http://localhost/admin/system/backup-destinations/'.$bucket->id.'/edit')->assertOk()->assertDontSee('very-secret');
        $this->get('http://localhost/admin/system/backup-destinations')->assertOk()->assertSee('Bucket');
    }

    public function test_a_folder_inside_the_public_one_is_refused_and_the_built_in_one_stays(): void
    {
        $this->assertNotNull(app(Destinations::class)->folderError(public_path('backups')));
        $this->assertNotNull(app(Destinations::class)->folderError('relative/path'));
        $this->assertNull(app(Destinations::class)->folderError(sys_get_temp_dir()));

        // A way round each of them, for a folder not made yet.
        $d = DIRECTORY_SEPARATOR;
        foreach ([
            storage_path("..{$d}public{$d}new-backups"),
            storage_path("app{$d}public{$d}new-backups"),
            base_path("modules{$d}torgash.hello{$d}assets{$d}new-backups"),
            base_path("themes{$d}default{$d}assets{$d}new-backups"),
            storage_path("no-such{$d}..{$d}..{$d}public{$d}new-backups"),
        ] as $served) {
            $this->assertNotNull(app(Destinations::class)->folderError($served), $served);
        }

        $this->assertNull(app(Destinations::class)->folderError(storage_path("app{$d}private{$d}new-backups")), 'the private disk is nobody\'s to download');

        $admin = User::role('admin')->firstOrFail();
        $server = BackupDestination::where('system', true)->firstOrFail();

        $this->actingAs($admin)->delete('http://localhost/admin/system/backup-destinations/'.$server->id)->assertSessionHasErrors();
        $this->assertTrue($server->exists());

        $folder = BackupDestination::create(['name' => 'Spare', 'driver' => 'folder', 'settings' => ['path' => sys_get_temp_dir()]]);
        $this->post('http://localhost/admin/system/backup-destinations/'.$folder->id.'/test')->assertSessionHas('status');
    }
}
