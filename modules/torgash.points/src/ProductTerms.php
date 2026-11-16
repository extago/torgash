<?php

namespace Torgash\Points;

use Illuminate\Support\Facades\DB;

/**
 * A product's OWN answers about points (PLAN §74) — what it earns, whether
 * points pay for it, its price in points — written from every door through
 * one place: the product form's tab, the import, the bulk dialog. A product
 * that answers nothing has no row.
 *
 * The same answers have a SPELLING for a spreadsheet cell, read and written
 * here so an export goes through a spreadsheet and back:
 *
 *   earning   ""  as the rules say    "x2"  the shop's rate ×2    "5%"  of the price
 *             "500"  per piece        "none"
 *   paying    ""  as the rules say    "yes"    "no"    "30%"  up to its own share
 */
class ProductTerms
{
    protected const YES = ['yes', 'y', '1', 'true', 'да', 'так', 'allowed'];

    protected const NO = ['no', 'n', '0', 'false', 'нет', 'ні', 'none', '-', 'forbidden'];

    /** @var array<int, object> rows of the window below, by product */
    protected array $rows = [];

    /** @var array{0: int, 1: int}|null the ids the memo has an answer for */
    protected ?array $window = null;

    /**
     * The product's row, or null. An export asks product after product in
     * the order of their ids, so the rows are read a stretch at a time —
     * one query per five hundred products, not one per cell.
     */
    public function row(int $productId): ?object
    {
        if ($this->window === null || $productId < $this->window[0] || $productId > $this->window[1]) {
            $rows = DB::table('m_points_products')->where('product_id', '>=', $productId)->orderBy('product_id')->limit(500)->get();
            $this->rows = $rows->keyBy('product_id')->all();
            $this->window = [$productId, $rows->count() < 500 ? PHP_INT_MAX : (int) $rows->last()->product_id];
        }

        return $this->rows[$productId] ?? null;
    }

    /**
     * Write what is NAMED and keep the rest: `earn` => [mode, value],
     * `spend` => [mode, percent], `price` => points or null.
     *
     * @param  array{earn?: array{0: string, 1: float|int|null}, spend?: array{0: string, 1: int|null}, price?: int|null}  $changes
     */
    public function write(int $productId, array $changes): void
    {
        $table = DB::table('m_points_products');
        $row = $table->where('product_id', $productId)->first();
        $values = [
            'points_price' => $row->points_price ?? null,
            'earn_mode' => $row->earn_mode ?? 'default',
            'earn_value' => $row->earn_value ?? null,
            'spend_mode' => $row->spend_mode ?? 'default',
            'spend_percent' => $row->spend_percent ?? null,
        ];

        if (array_key_exists('earn', $changes)) {
            [$mode, $value] = $changes['earn'];
            $mode = in_array($mode, Rules::EARN_MODES, true) && ($mode === 'none' || is_numeric($value)) ? $mode : 'default';
            $values['earn_mode'] = $mode;
            $values['earn_value'] = in_array($mode, ['default', 'none'], true) ? null : max(0, min(9999999, (float) $value));
        }

        if (array_key_exists('spend', $changes)) {
            [$mode, $percent] = $changes['spend'];
            $mode = in_array($mode, Rules::SPEND_MODES, true) && ($mode !== 'limit' || is_numeric($percent)) ? $mode : 'default';
            $values['spend_mode'] = $mode;
            $values['spend_percent'] = $mode === 'limit' ? max(0, min(100, (int) $percent)) : null;
        }

        if (array_key_exists('price', $changes)) {
            $values['points_price'] = (int) $changes['price'] > 0 ? (int) $changes['price'] : null;
        }

        $this->window = null;

        if ($values['points_price'] === null && $values['earn_mode'] === 'default' && $values['spend_mode'] === 'default') {
            $table->where('product_id', $productId)->delete();

            return;
        }

        $row
            ? $table->where('product_id', $productId)->update($values + ['updated_at' => now()])
            : $table->insert($values + ['product_id' => $productId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * A cell → [mode, value], or null for something that is not an answer.
     *
     * @return array{0: string, 1: float|null}|null
     */
    public static function parseEarn(mixed $cell): ?array
    {
        $text = mb_strtolower(trim((string) $cell));
        $number = fn (string $digits) => is_numeric($digits = str_replace([' ', ','], ['', '.'], $digits)) && (float) $digits >= 0 ? (float) $digits : null;

        if ($text === '') {
            return ['default', null];
        }

        if (in_array($text, self::NO, true)) {
            return ['none', null];
        }

        if (preg_match('/^[x×*х]\s*(.+)$/u', $text, $m)) {
            return ($value = $number($m[1])) === null ? null : ['multiplier', $value];
        }

        if (str_ends_with($text, '%')) {
            return ($value = $number(rtrim($text, '% '))) === null ? null : ['percent', $value];
        }

        $value = $number(ltrim($text, '+'));

        return $value === null ? null : ($value > 0 ? ['fixed', $value] : ['none', null]);
    }

    /**
     * @return array{0: string, 1: int|null}|null
     */
    public static function parsePay(mixed $cell): ?array
    {
        $text = mb_strtolower(trim((string) $cell));

        return match (true) {
            $text === '' => ['default', null],
            in_array($text, self::YES, true) => ['allowed', null],
            in_array($text, self::NO, true) => ['forbidden', null],
            (bool) preg_match('/^(\d{1,3})\s*%$/', $text, $m) && (int) $m[1] <= 100 => ['limit', (int) $m[1]],
            default => null,
        };
    }

    public static function spellEarn(?object $row): string
    {
        $value = rtrim(rtrim(number_format((float) ($row->earn_value ?? 0), 2, '.', ''), '0'), '.');

        return match ($row->earn_mode ?? 'default') {
            'multiplier' => 'x'.$value,
            'percent' => $value.'%',
            'fixed' => $value,
            'none' => 'none',
            default => '',
        };
    }

    public static function spellPay(?object $row): string
    {
        return match ($row->spend_mode ?? 'default') {
            'allowed' => 'yes',
            'forbidden' => 'no',
            'limit' => (int) $row->spend_percent.'%',
            default => '',
        };
    }
}
