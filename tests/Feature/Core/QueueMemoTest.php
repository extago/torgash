<?php

namespace Tests\Feature\Core;

use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use App\Core\Support\Currencies;
use App\Models\Currency;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A queue worker runs for days, and before 2026-10-21 it served the settings
 * and rows it had read once: "Install the update" ran in a worker that had
 * read the update check before the owner pressed Check, saw no new version
 * and refused. Every job now starts with the memos emptied
 * (PerRequest::flushPerJob) — and with what is reset per request reset too.
 */
class QueueMemoTest extends TestCase
{
    protected function nextJob(): void
    {
        $job = new \Illuminate\Queue\Jobs\SyncJob(app(), json_encode(['uuid' => 'probe', 'displayName' => 'Probe', 'job' => 'Probe', 'data' => []]), 'database', 'default');

        event(new JobProcessing('database', $job));
    }

    public function test_a_setting_changed_behind_the_worker_reaches_its_next_job(): void
    {
        $settings = app(SettingsManager::class);
        $settings->set(CoreSettings::ID, 'update_check', ['updates' => []]);
        $this->assertSame([], setting(CoreSettings::ID, 'update_check')['updates']);

        // The admin's request writes it — another process, as far as the worker knows.
        DB::table('settings')->where(['module' => CoreSettings::ID, 'key' => 'update_check'])->whereNull('storefront_id')
            ->update(['value' => json_encode(['updates' => [['version' => '9.9.9']]])]);
        $this->assertSame([], setting(CoreSettings::ID, 'update_check')['updates'], 'within one job the memo holds');

        $this->nextJob();

        $this->assertSame('9.9.9', setting(CoreSettings::ID, 'update_check')['updates'][0]['version']);
    }

    public function test_the_defaults_registered_at_boot_survive_the_flush(): void
    {
        $this->nextJob();

        $this->assertNotNull(setting(CoreSettings::ID, 'weight_unit'), 'a default, not a stored value');
    }

    public function test_a_rate_changed_behind_the_worker_reaches_its_next_job(): void
    {
        $code = Currency::where('rate', '!=', 1)->value('code');
        $before = app(Currencies::class)->find($code)->rate;

        DB::table('currencies')->where('code', $code)->update(['rate' => 123.5]);
        $this->assertEquals($before, app(Currencies::class)->find($code)->rate);

        $this->nextJob();

        $this->assertEquals(123.5, app(Currencies::class)->find($code)->rate);
    }

    public function test_a_sync_job_inside_a_request_leaves_that_requests_state_alone(): void
    {
        $page = app(\App\Core\Seo\PageMeta::class);

        $job = new \Illuminate\Queue\Jobs\SyncJob(app(), json_encode(['uuid' => 'probe', 'displayName' => 'Probe', 'job' => 'Probe', 'data' => []]), 'sync', 'sync');
        event(new JobProcessing('sync', $job));

        $this->assertSame($page, app(\App\Core\Seo\PageMeta::class));
    }

    public function test_what_is_reset_per_request_is_reset_per_job(): void
    {
        $first = app(\App\Core\Seo\PageMeta::class);

        $this->nextJob();

        $this->assertNotSame($first, app(\App\Core\Seo\PageMeta::class));
    }
}
