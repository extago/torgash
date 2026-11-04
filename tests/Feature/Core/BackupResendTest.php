<?php

namespace Tests\Feature\Core;

use App\Core\Backups\Backups;
use App\Models\BackupCopy;
use App\Models\BackupDestination;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A storage that refuses a backup (PLAN §67.11): tried once more on the
 * spot, and later sent again from the copy that was stored — without the
 * backup being made anew. The storage here is a folder on the server whose
 * path is taken by a FILE: the storage cannot be opened until it is removed.
 */
class BackupResendTest extends TestCase
{
    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'torgash-resend-'.uniqid();
        File::ensureDirectoryExists($this->work.'/modules/acme.thing');
        file_put_contents($this->work.'/modules/acme.thing/manifest.json', '{"id":"acme.thing"}');
        File::ensureDirectoryExists($this->work.'/themes');
        config(['torgash.modules_path' => $this->work.'/modules', 'torgash.themes_path' => $this->work.'/themes']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);

        parent::tearDown();
    }

    public function test_a_storage_that_refused_is_tried_once_more_and_later_sent_again_from_the_copy_kept(): void
    {
        foreach (['zip', 'folder'] as $format) {
            $wall = $this->work.'/wall-'.$format;
            file_put_contents($wall, 'a file where the folder should be');
            $shelf = BackupDestination::create(['name' => 'Shelf '.$format, 'driver' => 'folder', 'path_template' => '', 'settings' => ['path' => $wall]]);
            $plan = $this->plan($format, $shelf);

            $backups = app(Backups::class);
            $backups->retryPause = 0;
            $said = [];
            $run = $backups->runNow($plan, 'console', null, function (string $message) use (&$said) {
                $said[] = $message;
            });

            $this->assertSame(BackupRun::PARTIAL, $run->status, $format.': '.$run->error);
            $this->assertContains(':storage did not take the file (:error) — trying once more', $said, $format.': a second attempt before giving up');
            $failed = $run->copies()->where('status', BackupCopy::FAILED)->first();
            $this->assertNotNull($failed, $format);
            $this->assertSame($shelf->id, $failed->destination_id);
            $this->assertNotEmpty($failed->error);
            $this->assertSame(1, $run->storedCopies()->count(), $format.': the server\'s copy is there');

            // The wall comes down; nothing is made again, the kept copy is sent.
            unlink($wall);
            $copy = $backups->resend($failed);

            $this->assertSame(BackupCopy::STORED, $copy->status, $format);
            $this->assertNull($copy->error);
            $this->assertSame(BackupRun::DONE, $run->fresh()->status, $format);
            $this->assertNull($run->fresh()->error);
            $format === 'folder'
                ? $this->assertFileExists($wall.'/'.$run->name.'/manifest.json', $format)
                : $this->assertFileExists($wall.'/'.$run->name, $format);
            $this->assertSame(2, $run->storedCopies()->count(), $format);
        }
    }

    public function test_the_list_offers_send_again_only_beside_a_copy_not_stored_and_runs_it(): void
    {
        $wall = $this->work.'/wall';
        file_put_contents($wall, 'x');
        $shelf = BackupDestination::create(['name' => 'Shelf', 'driver' => 'folder', 'path_template' => '', 'settings' => ['path' => $wall]]);
        $backups = app(Backups::class);
        $backups->retryPause = 0;
        $run = $backups->runNow($this->plan('zip', $shelf));
        $failed = $run->copies()->where('status', BackupCopy::FAILED)->firstOrFail();
        $stored = $run->storedCopies()->firstOrFail();

        $admin = User::where('email', 'admin@torgash.local')->firstOrFail();
        $this->actingAs($admin)->get('http://localhost/admin/system/backups')
            ->assertOk()->assertSee('send again')->assertSee(route('admin.system.backups.resend', $failed), false);

        $this->actingAs($admin)->post(route('admin.system.backups.resend', $stored))->assertNotFound();

        unlink($wall);
        // The queue is sync under test: the task runs on the spot.
        $this->actingAs($admin)->post(route('admin.system.backups.resend', $failed))->assertRedirect();

        $this->assertSame(BackupCopy::STORED, $failed->fresh()->status);
        $this->assertSame(BackupRun::DONE, $run->fresh()->status);
        $this->assertFileExists($wall.'/'.$run->name);
        $this->actingAs($admin)->get('http://localhost/admin/system/backups')->assertOk()->assertDontSee('send again');
    }

    public function test_an_s3_storage_keeps_its_attempts_within_bounds(): void
    {
        $destinations = app(\App\Core\Backups\Destinations::class);
        $input = ['bucket' => 'b', 'endpoint' => 'https://s3.example.com', 'key' => 'k', 'secret' => 's'];

        [$settings] = $destinations->fromInput('s3', $input);
        $this->assertSame(\App\Core\Backups\Destinations::ATTEMPTS, $settings['attempts'], 'blank: the default');

        [$settings] = $destinations->fromInput('s3', $input + ['attempts' => '30']);
        $this->assertSame(30, $settings['attempts']);

        [$settings] = $destinations->fromInput('s3', $input + ['attempts' => '900']);
        $this->assertSame(100, $settings['attempts'], 'never more than a hundred');

        $destination = new BackupDestination(['driver' => 's3', 'settings' => ['attempts' => 0]]);
        $this->assertSame(\App\Core\Backups\Destinations::ATTEMPTS, \App\Core\Backups\Destinations::attempts($destination), 'zero is the default, not no attempt');
    }

    protected function plan(string $format, BackupDestination $second): BackupPlan
    {
        static $n = 0;
        $n++;

        $plan = new BackupPlan([
            'name' => 'Resend '.$n, 'code' => 'resend'.$n, 'contents' => ['code'], 'schedule' => [],
            'name_template' => 'r'.$n.'-{date}-{time}',
        ]);
        $plan->format = $format;
        $plan->options = ['level' => 'fast'];
        $plan->save();
        $plan->destinations()->attach(app(Backups::class)->systemDestination()->id, ['keep' => json_encode(['last' => 5])]);
        $plan->destinations()->attach($second->id, ['keep' => json_encode(['last' => 5])]);

        return $plan;
    }
}
