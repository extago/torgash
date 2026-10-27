<?php

namespace Tests\Feature\Cdek;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Cdek\Models\Point;
use Torgash\Cdek\Models\Sync;
use Torgash\Cdek\Sync\CitySync;
use Torgash\Cdek\Sync\PointSync;

/**
 * Keeping the carrier's directory: rows read the way the live API writes them
 * (fields copied from a real `deliverypoints` answer), points that close leave
 * the picker, and a failed refresh keeps what we had.
 */
class PointSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $settings = app(SettingsManager::class);
        $settings->set('torgash.cdek', 'account', 'acc');
        $settings->set('torgash.cdek', 'secure_password', 'sec');
        $settings->set('torgash.cdek', 'test_mode', false);
        $settings->set('torgash.cdek', 'countries', 'RU');
    }

    public function test_a_citys_points_are_stored_as_the_api_writes_them_and_closed_ones_leave(): void
    {
        DB::table('m_cdek_points')->insert(['code' => 'GONE1', 'city_code' => 270, 'synced_at' => now()->subDay()]);

        $this->fake(['*/deliverypoints*' => Http::response([$this->row('NSK23')])]);

        $count = app(PointSync::class)->refresh(270);

        $this->assertSame(1, $count);
        $this->assertNull(Point::find('GONE1'), 'a point the carrier no longer lists must leave the picker');

        $point = Point::find('NSK23');
        $this->assertSame(270, $point->city_code);
        $this->assertSame('мрн Горский, 53', $point->address);
        $this->assertSame('+79628421718', $point->phone);
        $this->assertSame(30.0, $point->weight_max);
        $this->assertSame('10:00–20:00', array_values($point->openingHours())[0]);
        $this->assertFalse(Sync::stale('points:270', new \DateInterval('PT1H')));
    }

    public function test_a_failed_refresh_keeps_the_rows_and_backs_off(): void
    {
        DB::table('m_cdek_points')->insert(['code' => 'KEEP1', 'city_code' => 270, 'synced_at' => now()->subDays(3)]);

        $this->fake(['*/deliverypoints*' => Http::response(['errors' => [['code' => 'x', 'message' => 'down']]], 500)]);

        app(PointSync::class)->refresh(270);

        $this->assertNotNull(Point::find('KEEP1'), 'day-old points are a better checkout than none');
        $this->assertFalse(Sync::stale('points:270:failed', new \DateInterval('PT1M')));
        $this->assertFalse(app(PointSync::class)->pending(270), 'we have rows, so nothing is pending');
    }

    public function test_the_whole_country_in_one_pass_marks_every_city_fresh(): void
    {
        DB::table('m_cdek_points')->insert(['code' => 'OLD1', 'city_code' => 999, 'synced_at' => now()->subDay()]);

        $this->fake(['*/deliverypoints*' => Http::response([$this->row('NSK23'), $this->row('MSK1', 44)])]);

        $result = app(PointSync::class)->all();

        $this->assertSame(['points' => 2, 'cities' => 2], $result);
        $this->assertNull(Point::find('OLD1'));
        $this->assertFalse(Sync::stale('points:44', new \DateInterval('PT1H')));
        $this->assertFalse(Sync::stale('points:270', new \DateInterval('PT1H')));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'country_code=RU'));
    }

    public function test_the_city_directory_skips_codes_that_address_nothing(): void
    {
        $this->fake(['*/location/cities*' => Http::response([
            ['code' => -1, 'city' => 'Иннополис', 'country_code' => 'RU', 'region' => 'Татарстан'],
            ['code' => 270, 'city' => 'Новосибирск', 'country_code' => 'RU', 'region' => 'Новосибирская область',
                'sub_region' => 'городской округ Новосибирск', 'latitude' => 55.03, 'longitude' => 82.92],
        ])]);

        $this->assertSame(['cities' => 1, 'countries' => ['RU']], app(CitySync::class)->run());
        $this->assertSame('Новосибирская область', DB::table('m_cdek_cities')->where('code', 270)->value('region'));
    }

    protected function fake(array $routes): void
    {
        Http::fake(['*/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600])] + $routes);
    }

    /** A `deliverypoints` row as the test server returned it (2026-10-21). */
    protected function row(string $code, int $city = 270): array
    {
        return [
            'code' => $code,
            'name' => $code.', Новосибирск, мрн Горский',
            'nearest_metro_station' => 'Ст. м. Студенческая',
            'work_time' => 'Пн-Пт 10:00-20:00',
            'phones' => [['number' => '+79628421718']],
            'type' => 'PVZ',
            'owner_code' => 'CDEK',
            'is_handout' => true,
            'allowed_cod' => true,
            'work_time_list' => array_map(fn ($day) => ['day' => $day, 'time' => '10:00/20:00'], range(1, 7)),
            'weight_min' => 0.0,
            'weight_max' => 30.0,
            'location' => [
                'country_code' => 'RU', 'city_code' => $city, 'city' => 'Новосибирск', 'postal_code' => '630032',
                'longitude' => 82.899826, 'latitude' => 54.9936104,
                'address' => 'мрн Горский, 53',
                'address_full' => '630032, Россия, Новосибирская область, Новосибирск, мрн Горский, 53',
            ],
        ];
    }
}
