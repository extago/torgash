<?php

namespace Tests\Feature\Seo;

use App\Core\Seo\OpeningHours;
use App\Models\Storefront;
use Tests\TestCase;

/**
 * The storefront's opening hours, typed as people write them, read into
 * schema.org's openingHoursSpecification on the shop's node — and left
 * alone, node and all, when the line does not read as days and times.
 */
class OpeningHoursTest extends TestCase
{
    public function test_lines_people_write_read_as_days_and_times(): void
    {
        $daily = OpeningHours::parse('Ежедневно 10:00–20:00');
        $this->assertCount(1, $daily);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], $daily[0]['dayOfWeek']);
        $this->assertSame(['10:00', '20:00'], [$daily[0]['opens'], $daily[0]['closes']]);

        $week = OpeningHours::parse('Пн–Пт 9:00–18:00, Сб 10-16');
        $this->assertCount(2, $week);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $week[0]['dayOfWeek']);
        $this->assertSame(['Saturday'], $week[1]['dayOfWeek']);
        $this->assertSame(['10:00', '16:00'], [$week[1]['opens'], $week[1]['closes']]);

        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], OpeningHours::parse('Mon-Fri 9-18')[0]['dayOfWeek']);
        $this->assertSame(['Saturday', 'Sunday'], OpeningHours::parse('Сб и Вс 11:00–17:00')[0]['dayOfWeek']);
        $this->assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], OpeningHours::parse('Щодня 9:00-21:00')[0]['dayOfWeek']);

        $always = OpeningHours::parse('Круглосуточно');
        $this->assertSame(['00:00', '23:59'], [$always[0]['opens'], $always[0]['closes']]);
        $this->assertCount(7, $always[0]['dayOfWeek']);
    }

    public function test_a_line_that_does_not_read_yields_nothing(): void
    {
        $this->assertSame([], OpeningHours::parse(''));
        $this->assertSame([], OpeningHours::parse('Звоните, договоримся'));
        $this->assertSame([], OpeningHours::parse('Пн–Пт 9:00–18:00, кроме праздников'), 'one unreadable piece drops the whole line');
        $this->assertSame([], OpeningHours::parse('Пн–Пт 25:00–18:00'));
    }

    public function test_the_shop_node_carries_the_hours_only_when_they_read(): void
    {
        $storefront = Storefront::query()->where('enabled', true)->where('is_default', true)->firstOrFail();
        $contacts = (array) $storefront->contacts;

        $storefront->update(['contacts' => ['hours' => 'Ежедневно 10:00–20:00'] + $contacts]);
        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('"openingHoursSpecification"', $html);
        $this->assertStringContainsString('"@type":["Organization","LocalBusiness"]', $html);

        $storefront->update(['contacts' => ['hours' => 'Звоните, договоримся'] + $contacts]);
        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('openingHoursSpecification', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
    }
}
