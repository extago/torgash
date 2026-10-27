<?php

namespace Tests\Feature\Core;

use App\Core\Seo\RedirectManager;
use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Automatic redirects nobody uses are forgotten; the shop's own never are. */
class RedirectPruneTest extends TestCase
{
    protected function row(string $from, ?string $source, ?string $lastHit, string $created): void
    {
        DB::table('redirects')->insert(['from_path' => $from, 'to_path' => '/new', 'source' => $source,
            'last_hit_at' => $lastHit, 'created_at' => $created, 'updated_at' => $created]);
    }

    public function test_only_unused_automatic_redirects_are_forgotten(): void
    {
        $old = now()->subMonths(13)->toDateTimeString();
        $this->row('/rp-auto-stale', 'auto', null, $old);
        $this->row('/rp-auto-visited', 'auto', now()->subMonth()->toDateTimeString(), $old);
        $this->row('/rp-auto-young', 'auto', null, now()->subMonths(2)->toDateTimeString());
        $this->row('/rp-manual', 'manual', null, $old);
        $this->row('/rp-import', 'import', null, $old);
        $this->row('/rp-legacy', null, null, $old);

        $this->assertSame(1, app(RedirectManager::class)->prune());
        $this->assertFalse(DB::table('redirects')->where('from_path', '/rp-auto-stale')->exists());
        $this->assertSame(5, DB::table('redirects')->where('from_path', 'like', '/rp-%')->count());

        app(SettingsManager::class)->set('core', 'redirect_forget_months', 0);
        $this->row('/rp-auto-stale2', 'auto', null, $old);
        $this->assertSame(0, app(RedirectManager::class)->prune());
    }

    public function test_keep_for_ever_makes_an_automatic_redirect_the_shops_own(): void
    {
        $this->row('/rp-keep', 'auto', null, now()->subMonths(13)->toDateTimeString());
        $id = DB::table('redirects')->where('from_path', '/rp-keep')->value('id');

        $this->actingAs(\App\Models\User::role('admin')->firstOrFail())->post(route('admin.redirects.bulk'), ['action' => 'keep', 'ids' => [$id]])->assertRedirect();

        $this->assertSame('manual', DB::table('redirects')->where('id', $id)->value('source'));
        $this->assertSame(0, app(RedirectManager::class)->prune());
    }
}
