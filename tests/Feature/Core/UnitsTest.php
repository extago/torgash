<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Units;
use Tests\TestCase;

/**
 * What the admin types against what the database stores.
 *
 * Pinned because the whole point of the setting is that it changes ONLY the
 * form: the day a stored gram starts meaning a pound, every shipping band and
 * every carrier request in the shop is quietly wrong, and nothing errors.
 */
class UnitsTest extends TestCase
{
    public function test_kilograms_in_grams_out(): void
    {
        $units = $this->units('kg');

        $this->assertSame(1500, $units->weightToStorage('1.5'));
        $this->assertSame('1.5', $units->weightToForm(1500));
    }

    public function test_the_setting_changes_the_field_and_nothing_else(): void
    {
        // The same stored row, read by three shops with different settings.
        $this->assertSame('453', $this->units('g')->weightToForm(453));
        $this->assertSame('0.453', $this->units('kg')->weightToForm(453));
        $this->assertSame('2', $this->units('lb')->weightToForm(907));
        // ...and typed back the way it was read.
        $this->assertSame(907, $this->units('lb')->weightToStorage('2'));
    }

    public function test_a_comma_decimal_is_accepted_because_half_the_world_types_one(): void
    {
        $this->assertSame(650, $this->units('kg')->weightToStorage('0,65'));
    }

    public function test_an_empty_field_is_not_measured_rather_than_zero(): void
    {
        $units = $this->units('kg');

        $this->assertNull($units->weightToStorage(''));
        $this->assertNull($units->weightToStorage(null));
        $this->assertSame('', $units->weightToForm(null));
        // Zero typed on purpose is still zero — only an empty field is unknown.
        $this->assertSame(0, $units->weightToStorage('0'));
    }

    public function test_lengths_are_stored_in_whole_millimetres(): void
    {
        $this->assertSame(200, $this->units(length: 'cm')->lengthToStorage('20'));
        $this->assertSame(254, $this->units(length: 'in')->lengthToStorage('10'));
        $this->assertSame(7, $this->units(length: 'mm')->lengthToStorage('7'));
    }

    public function test_a_box_reads_as_one_line_only_when_every_side_is_known(): void
    {
        $units = $this->units(length: 'cm');

        $this->assertSame('20 × 15 × 10 cm', $units->formatBox(200, 150, 100));
        $this->assertNull($units->formatBox(200, 150, null));
    }

    public function test_an_unknown_unit_code_falls_back_instead_of_breaking_the_form(): void
    {
        $this->assertSame('kg', $this->units('stones')->weightUnit());
    }

    protected function units(?string $weight = null, ?string $length = null): Units
    {
        $settings = app(SettingsManager::class);
        $settings->set('core', 'weight_unit', $weight ?? 'kg');
        $settings->set('core', 'length_unit', $length ?? 'cm');

        // A fresh instance per call: the real one memoises for the request,
        // which is exactly what a test changing the setting must not inherit.
        return new Units;
    }
}
