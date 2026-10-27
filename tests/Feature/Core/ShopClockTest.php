<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\ShopClock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The shop's time zone is the application's clock. A shop that chose Moscow
 * before that was so kept running on UTC — its backups and orders printed
 * three hours early (the first live shop, 2026-10-22) — and re-saving the same
 * zone announced nothing, so nothing would ever have fixed it.
 */
class ShopClockTest extends TestCase
{
    protected string $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = (string) config('app.timezone');
    }

    protected function tearDown(): void
    {
        config(['app.timezone' => $this->zone]);
        date_default_timezone_set($this->zone);
        DB::statement('set time zone '.DB::getPdo()->quote($this->zone));

        parent::tearDown();
    }

    public function test_a_zone_chosen_before_the_upgrade_becomes_the_clock(): void
    {
        config(['app.timezone' => 'UTC']);
        DB::table('settings')->updateOrInsert(
            ['module' => 'core', 'key' => 'timezone', 'storefront_id' => null],
            ['value' => json_encode('Europe/Moscow')],
        );
        app(SettingsManager::class)->flush();

        ShopClock::reconcile();

        $this->assertSame('Europe/Moscow', config('app.timezone'));
        $this->assertSame('Europe/Moscow', date_default_timezone_get());
        $this->assertSame('Europe/Moscow', DB::selectOne('show timezone')->TimeZone, 'the database\'s own now() too');
    }

    public function test_a_shop_on_utc_by_choice_is_left_alone(): void
    {
        config(['app.timezone' => 'UTC']);
        app(SettingsManager::class)->set('core', 'timezone', '');

        ShopClock::reconcile();

        $this->assertSame('UTC', config('app.timezone'));
    }
}
