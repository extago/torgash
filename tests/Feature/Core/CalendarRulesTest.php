<?php

namespace Tests\Feature\Core;

use App\Core\Display\DisplayRules;
use App\Core\Display\Periods;
use App\Core\Settings\SettingsManager;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "When" in display rules (PLAN §51): days of the year — every year, over New
 * Year, or one year's date for a holiday that moves — days of the week and
 * hours of the day, all on the SHOP's clock, not the server's.
 */
class CalendarRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_yearly_period_runs_over_new_year_and_a_dated_one_is_that_year_only(): void
    {
        $holidays = [['from' => '25.12', 'to' => '08.01'], ['from' => '08.03', 'to' => '08.03']];

        $this->assertTrue(Periods::contains($holidays, 'date', Carbon::parse('2026-12-31')));
        $this->assertTrue(Periods::contains($holidays, 'date', Carbon::parse('2027-01-08')));
        $this->assertTrue(Periods::contains($holidays, 'date', Carbon::parse('2027-03-08')));
        $this->assertFalse(Periods::contains($holidays, 'date', Carbon::parse('2027-01-09')));
        $this->assertFalse(Periods::contains($holidays, 'date', Carbon::parse('2027-12-24')));

        $easter = [['from' => '10.04.2026', 'to' => '13.04.2026']];
        $this->assertTrue(Periods::contains($easter, 'date', Carbon::parse('2026-04-12')));
        $this->assertFalse(Periods::contains($easter, 'date', Carbon::parse('2027-04-12')), 'a dated period is not yearly');

        $evening = [['from' => '22:00', 'to' => '02:00']];
        $this->assertTrue(Periods::contains($evening, 'time', Carbon::parse('2026-10-07 23:30')));
        $this->assertTrue(Periods::contains($evening, 'time', Carbon::parse('2026-10-08 01:59')));
        $this->assertFalse(Periods::contains($evening, 'time', Carbon::parse('2026-10-08 12:00')));
    }

    public function test_what_is_typed_is_kept_only_when_it_is_a_period(): void
    {
        $this->assertSame([
            ['from' => '05.03', 'to' => '05.03'],
            ['from' => '25.12', 'to' => '08.01'],
            ['from' => '29.02', 'to' => '29.02'],
        ], Periods::normalise([
            ['from' => '5.3', 'to' => ''],          // one day
            ['from' => '25.12', 'to' => '8.1'],
            ['from' => '29.02'],                    // a real yearly date
            ['from' => '31.02', 'to' => '01.03'],   // no such day
            ['from' => '01.05', 'to' => '1.5.2026'], // yearly at one end, dated at the other
            ['from' => '01.06', 'to' => 'tomorrow'], // a typo is not "one day"
            ['from' => '', 'to' => '03.03'],
        ], 'date'));

        $this->assertSame([['from' => '08:00', 'to' => '21:30']], Periods::normalise([['from' => '8:00', 'to' => '21.30'], ['from' => '25:00']], 'time'));
    }

    public function test_the_rules_ask_the_shops_clock(): void
    {
        $rules = app(DisplayRules::class);
        app(SettingsManager::class)->set('core', 'timezone', 'Europe/Kyiv');

        // 22:30 UTC on 31 December is already 1 January, 00:30, in Kyiv.
        Carbon::setTestNow(Carbon::parse('2026-12-31 22:30:00', 'UTC'));

        $this->assertTrue($rules->passes([['kind' => 'calendar', 'operator' => 'in', 'value' => [['from' => '01.01', 'to' => '02.01']]]]));
        $this->assertTrue($rules->passes([['kind' => 'time_of_day', 'operator' => 'in', 'value' => [['from' => '00:00', 'to' => '01:00']]]]));
        // 1 January 2027 is a Friday.
        $this->assertTrue($rules->passes([['kind' => 'weekday', 'operator' => 'in', 'value' => ['5']]]));
        $this->assertFalse($rules->passes([['kind' => 'weekday', 'operator' => 'in', 'value' => ['6', '7']]]));
        $this->assertTrue($rules->passes([['kind' => 'calendar', 'operator' => 'not_in', 'value' => [['from' => '25.12', 'to' => '31.12']]]]));

        $this->assertSame([['kind' => 'calendar', 'operator' => 'in', 'value' => [['from' => '25.12', 'to' => '08.01']]]],
            $rules->normalise([['kind' => 'calendar', 'operator' => 'in', 'value' => [['from' => '25.12', 'to' => '08.01'], ['from' => 'x']]]]));
        $this->assertNull($rules->normalise([['kind' => 'calendar', 'operator' => 'in', 'value' => [['from' => 'x']]]]), 'nothing usable: the row is dropped');
        $this->assertSame('Calendar — is within 25.12–08.01, 08.03', $rules->describe(['kind' => 'calendar', 'operator' => 'in', 'value' => [['from' => '25.12', 'to' => '08.01'], ['from' => '08.03', 'to' => '08.03']]]));
    }
}
