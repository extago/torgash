<?php

namespace App\Core\Support;

/**
 * Units of measure: what an admin types and reads, against what the database
 * stores.
 *
 * Storage is canonical and never negotiable — WHOLE GRAMS and WHOLE
 * MILLIMETRES, the same major/minor split money already uses. The setting
 * changes only the form field, the label beside a number and the payload a
 * carrier is handed; nothing downstream has to ask which unit a stored row is
 * in, because there is only ever one answer.
 *
 * It is a core setting rather than a shipping one because the data is core
 * catalogue data: a product has a weight whether or not anything ships. That
 * is also where shop engines usually keep it (in the general settings), and
 * it is what lets `torgash.catalog` render
 * its own dimensions card without depending on `torgash.shipping`.
 *
 * Registered as a request-scoped singleton — the settings read must not become
 * one query per product row, and a static would outlive the request under a
 * persistent worker.
 */
class Units
{
    /** Weight units: how many grams one of them is, and how finely it is typed. */
    public const WEIGHT = [
        'g' => ['grams' => 1.0, 'decimals' => 0, 'label' => 'g'],
        'kg' => ['grams' => 1000.0, 'decimals' => 3, 'label' => 'kg'],
        'lb' => ['grams' => 453.59237, 'decimals' => 3, 'label' => 'lb'],
    ];

    /** Length units: how many millimetres one of them is. */
    public const LENGTH = [
        'mm' => ['mm' => 1.0, 'decimals' => 0, 'label' => 'mm'],
        'cm' => ['mm' => 10.0, 'decimals' => 1, 'label' => 'cm'],
        'in' => ['mm' => 25.4, 'decimals' => 2, 'label' => 'in'],
    ];

    protected ?string $weight = null;

    protected ?string $length = null;

    /** The units are settings: read again by the next job (PerRequest::flushPerJob). */
    public function flush(): void
    {
        $this->weight = $this->length = null;
    }

    public function weightUnit(): string
    {
        return $this->weight ??= $this->pick(setting('core', 'weight_unit'), self::WEIGHT, 'kg');
    }

    public function lengthUnit(): string
    {
        return $this->length ??= $this->pick(setting('core', 'length_unit'), self::LENGTH, 'cm');
    }

    /** The symbol to put beside a field or a number, translated. */
    public function weightLabel(): string
    {
        return __(self::WEIGHT[$this->weightUnit()]['label']);
    }

    public function lengthLabel(): string
    {
        return __(self::LENGTH[$this->lengthUnit()]['label']);
    }

    /** What a number input's `step` should be, so grams are not typed to three decimals. */
    public function weightStep(): string
    {
        return $this->step(self::WEIGHT[$this->weightUnit()]['decimals']);
    }

    public function lengthStep(): string
    {
        return $this->step(self::LENGTH[$this->lengthUnit()]['decimals']);
    }

    /** Grams → the field value. Null (never measured) stays empty, not "0". */
    public function weightToForm(?int $grams): string
    {
        return $this->toForm($grams, self::WEIGHT[$this->weightUnit()]['grams'], self::WEIGHT[$this->weightUnit()]['decimals']);
    }

    public function lengthToForm(?int $mm): string
    {
        return $this->toForm($mm, self::LENGTH[$this->lengthUnit()]['mm'], self::LENGTH[$this->lengthUnit()]['decimals']);
    }

    /** The field value → grams. An empty field is "not measured", which is null. */
    public function weightToStorage(mixed $value, ?string $unit = null): ?int
    {
        return $this->toStorage($value, self::WEIGHT[$unit ?? $this->weightUnit()]['grams'] ?? null);
    }

    public function lengthToStorage(mixed $value, ?string $unit = null): ?int
    {
        return $this->toStorage($value, self::LENGTH[$unit ?? $this->lengthUnit()]['mm'] ?? null);
    }

    /** "1.5 kg" for reading, not for typing back in. */
    public function formatWeight(?int $grams): string
    {
        $value = $this->weightToForm($grams);

        return $value === '' ? '—' : $value.' '.$this->weightLabel();
    }

    public function formatLength(?int $mm): string
    {
        $value = $this->lengthToForm($mm);

        return $value === '' ? '—' : $value.' '.$this->lengthLabel();
    }

    /** "20 × 15 × 10 cm", or null when the box is not fully measured. */
    public function formatBox(?int $length, ?int $width, ?int $height): ?string
    {
        if ($length === null || $width === null || $height === null) {
            return null;
        }

        return implode(' × ', [
            $this->lengthToForm($length),
            $this->lengthToForm($width),
            $this->lengthToForm($height),
        ]).' '.$this->lengthLabel();
    }

    /** @param  array<string, array<string, mixed>>  $table */
    protected function pick(mixed $value, array $table, string $fallback): string
    {
        return is_string($value) && isset($table[$value]) ? $value : $fallback;
    }

    protected function step(int $decimals): string
    {
        return $decimals === 0 ? '1' : '0.'.str_repeat('0', $decimals - 1).'1';
    }

    protected function toForm(?int $canonical, float $per, int $decimals): string
    {
        if ($canonical === null) {
            return '';
        }

        $value = number_format($canonical / $per, $decimals, '.', '');

        // Trailing zeros are noise in a field the admin edits by hand: 1.500
        // reads as precision that was never entered.
        return $decimals > 0 ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value;
    }

    protected function toStorage(mixed $value, ?float $per): ?int
    {
        if ($per === null || $value === null || $value === '' || ! is_numeric(str_replace(',', '.', (string) $value))) {
            return null;
        }

        return (int) round((float) str_replace(',', '.', (string) $value) * $per);
    }
}
