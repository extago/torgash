<?php

namespace App\Core\Support;

use App\Models\Currency;

/**
 * A PRICE PER UNIT is kept finer than an AMOUNT (PLAN §65).
 *
 * An amount — a line's total, an order's, a delivery, a tax, a payment, a
 * refund — is in the currency's minor units, 1/100, as it has always been:
 * a bank takes cents and nothing finer. A price per unit is in PRICE UNITS,
 * 1/10^P of the currency, P being the core setting `price_places` (2, 3 or
 * 4). A product that costs a rouble in a shop keeping dollars costs $0.012,
 * and with P = 2 it was sold at $0.01: a thousand of them for $10 instead of
 * $12. With P = 4 it is 120 price units, and a thousand of them come to
 * round(120 000 / 100) = 1200 cents.
 *
 * P = 2 is the default and then a price unit IS a minor unit: every method
 * here is the identity (or the old helper it stands for), so a shop that
 * never touches the setting runs exactly the code it ran before.
 *
 * Every conversion of a written number is done in integers — "0.0125" is
 * parsed digit by digit, never through a float, so 0.0125 is 125 and not
 * 124.99999. Rounding is arithmetic, half away from zero, everywhere (PHP's
 * `round`, Postgres' `round(numeric)`), which is what an accounting system on
 * the other end of an exchange expects.
 *
 * Stateless on purpose: the setting is read through `setting()`, whose memo
 * is the settings manager's, so nothing here outlives a request.
 */
class PriceScale
{
    public const SETTING = 'price_places';

    public const MIN = 2;

    public const MAX = 4;

    /** How many places a price per unit is kept, typed and shown with. */
    public function places(): int
    {
        return max(self::MIN, min(self::MAX, (int) setting('core', self::SETTING, self::MIN)));
    }

    /** Price units in one major unit: 100 for two places, 10 000 for four. */
    public function perMajor(?int $places = null): int
    {
        return 10 ** ($places ?? $this->places());
    }

    /** Price units in one minor unit: 1 for two places, 100 for four. */
    public function perMinor(?int $places = null): int
    {
        return 10 ** (($places ?? $this->places()) - 2);
    }

    /**
     * The smallest step a WORKED-OUT price per unit is rounded to (a group
     * discount, a markup, a converted price). With two places it is what the
     * books currency shows — the rule `round_money()` keeps for amounts, so a
     * shop in whole hryvnias still gets whole prices. With more places the
     * shop asked for the precision, and it is the price unit itself.
     */
    public function step(): int
    {
        return $this->places() > self::MIN ? 1 : app(Currencies::class)->step();
    }

    /** A worked-out price per unit, rounded to that step. */
    public function round(float|int $units): int
    {
        $step = $this->step();

        return (int) (round($units / $step) * $step);
    }

    /**
     * An AMOUNT from price units: a line's quantity times its price, or the
     * price of a part. Rounded the way every amount is (`round_money`), once,
     * on the product of the multiplication — never per unit.
     */
    public function amount(float|int $units): int
    {
        return round_money($units / $this->perMinor());
    }

    /** Minor units → price units (an amount read as a price: exact). */
    public function fromMinor(int $minor): int
    {
        return $minor * $this->perMinor();
    }

    /** Price units → minor units, to the nearest cent, without the books' step. */
    public function toMinor(float|int $units): int
    {
        return (int) round($units / $this->perMinor());
    }

    /** Price units → a major-unit float, for arithmetic only — never to print. */
    public function toMajor(float|int $units): float
    {
        return $units / $this->perMajor();
    }

    /**
     * A typed price — "1 299,5", "0.0125", 420.5 — in price units, rounded to
     * the places the shop keeps; null for an empty field. Null too for
     * something that is not a number, so a form can say so rather than store
     * zero.
     */
    public function fromMajor(mixed $major, ?int $places = null): ?int
    {
        return self::parse($major, $places ?? $this->places());
    }

    /** Whether a typed price has more places than the shop keeps ("0.01255" at four). */
    public function tooPrecise(mixed $major): bool
    {
        $digits = self::fraction($major);

        return $digits !== null && strlen(rtrim($digits, '0')) > $this->places();
    }

    /**
     * A validation rule for a typed price per unit: refuses more places than
     * the shop keeps, so "0.01255" is an error to fix and not a price quietly
     * rounded to 0.0126.
     */
    public function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if ($this->tooPrecise($value)) {
                $fail(__('A price may have at most :places places after the point.', ['places' => $this->places()]));
            }
        };
    }

    /**
     * A validation rule for an AMOUNT typed or sent in major units — a refund,
     * a card's value: a number with at most two places ("399.20").
     */
    public static function amountRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $digits = self::fraction($value);

            if ($digits === null) {
                $fail(__('An amount must be a number such as 399.20.'));
            } elseif (strlen(rtrim($digits, '0')) > 2) {
                $fail(__('An amount may have at most two places after the point.'));
            }
        };
    }

    /** An amount in major units ("399.20", 399.2) → minor units; null for no number. */
    public static function minorFrom(mixed $major): ?int
    {
        return self::parse($major, 2);
    }

    /**
     * Price units as a decimal string with exactly P places — "0.0120",
     * "199990.0000": what the API answers with and what a form is filled
     * with. Integer arithmetic, so no float ever decides a digit.
     */
    public function decimal(int $units, ?int $places = null): string
    {
        return self::digits($units, $places ?? $this->places());
    }

    /** The same, trimmed to the places the value needs, never below `$min`. */
    public function trimmed(int $units, int $min = 2): string
    {
        $places = $this->places();
        $text = $this->decimal($units, $places);

        if ($places <= $min) {
            return $text;
        }

        [$whole, $fraction] = explode('.', $text);
        $fraction = rtrim($fraction, '0');

        return $whole.'.'.str_pad($fraction, $min, '0');
    }

    /**
     * A price per unit as the shop writes it, in `$code` (the visitor's
     * currency by default).
     *
     * With two places this IS `format_price()`, byte for byte. With more, the
     * currency's own places are the minimum and further places are shown only
     * while they mean something: in the books currency exactly as stored
     * ($0.012, but $999.00); in another one, converted, until rounding loses
     * less than half a percent of the price — otherwise a dollar price shown
     * in hryvnias would print the rate's noise (41 958.4183 ₴).
     */
    public function format(int $units, ?string $code = null): string
    {
        $code ??= current_currency();
        $places = $this->places();

        if ($places === self::MIN) {
            return format_price($units, $code);
        }

        $currency = app(Currencies::class)->find($code);

        if ($currency === null) {
            return $this->trimmed($units).' '.$code;
        }

        return $this->formatAt($units, $currency, (float) $currency->rate ?: 1.0, $code === shop_currency());
    }

    /**
     * The same at a GIVEN rate — an order prints its lines at the rate it was
     * agreed at, not today's. `$exact` = the books currency, every kept place
     * that is not zero shown.
     */
    public function formatAt(int $units, Currency $currency, float $rate, bool $exact): string
    {
        $places = $this->places();
        $major = $units / $this->perMajor($places) * $rate;

        if ($places === self::MIN) {
            return $currency->format($major);
        }

        return $currency->format($major, $this->shownPlaces($major, $currency, $exact, $places));
    }

    /**
     * A price per unit as a MACHINE reads it, in `$code`: a dot, no thousands,
     * no symbol — "1200", "0.012", "41916.46" — with the same places
     * `format()` would print. For schema.org, feeds, llms pages.
     */
    public function plain(int $units, ?string $code = null): string
    {
        $code ??= shop_currency();
        $places = $this->places();
        $currency = app(Currencies::class)->find($code);

        if ($currency === null) {
            return $this->trimmed($units);
        }

        $rate = (float) $currency->rate ?: 1.0;
        $major = $units / $this->perMajor($places) * $rate;
        $shown = $places === self::MIN
            ? max(0, (int) $currency->decimals)
            : $this->shownPlaces($major, $currency, $code === shop_currency(), $places);

        return number_format($major, $shown, '.', '');
    }

    /**
     * How many places `format()` prints: the currency's own, and further only
     * while they carry the value. At least two once past the currency's own
     * places, so a shop in whole hryvnias writes 0,50 ₴ and not 0,5 ₴.
     */
    protected function shownPlaces(float $major, Currency $currency, bool $exact, int $places): int
    {
        $own = max(0, (int) $currency->decimals);
        $shown = $own;

        if ($exact) {
            while ($shown < $places && round($major, $shown) != round($major, $places)) {
                $shown++;
            }
        } else {
            while ($shown < $places && $major != 0.0 && abs(round($major, $shown) - $major) / abs($major) > 0.005) {
                $shown++;
            }
        }

        return $shown > $own ? max($shown, min(2, $places)) : $shown;
    }

    // ------------------------------------------------------------- integers

    /**
     * "0.0125" → 125 at four places, "1 299,50" → 129950 at two; more places
     * than kept are rounded half away from zero on the digits themselves.
     */
    public static function parse(mixed $major, int $places): ?int
    {
        $text = self::normalise($major);

        if ($text === null) {
            return null;
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-+');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        $kept = substr(str_pad($fraction, $places, '0'), 0, $places);
        $units = (int) ($whole === '' ? '0' : $whole) * (10 ** $places) + (int) ($kept === '' ? '0' : $kept);

        // The first dropped digit decides: 5 or more rounds away from zero.
        if (strlen($fraction) > $places && (int) $fraction[$places] >= 5) {
            $units++;
        }

        return $negative ? -$units : $units;
    }

    /** `$units` at `$places` as a decimal string, sign kept, no thousands. */
    public static function digits(int $units, int $places): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        $base = 10 ** $places;

        $whole = intdiv($units, $base);

        if ($places === 0) {
            return $sign.$whole;
        }

        return $sign.$whole.'.'.str_pad((string) ($units % $base), $places, '0', STR_PAD_LEFT);
    }

    /** The fraction's digits as typed ("0125" for "0.0125"), or null for no number. */
    protected static function fraction(mixed $major): ?string
    {
        $text = self::normalise($major);

        if ($text === null) {
            return null;
        }

        return explode('.', $text, 2)[1] ?? '';
    }

    /**
     * A typed number as plain digits with one dot, or null. Accepts what a
     * person types — spaces and no-break spaces as thousands, a comma as the
     * decimal mark — and what a program sends: an int, a float (written out
     * without an exponent first), a numeric string.
     */
    protected static function normalise(mixed $major): ?string
    {
        if ($major === null || $major === '' || is_bool($major) || is_array($major)) {
            return null;
        }

        if (is_int($major)) {
            return (string) $major;
        }

        if (is_float($major)) {
            if (! is_finite($major)) {
                return null;
            }

            // Fifteen significant digits is what a double actually holds:
            // 420.5 stays 420.5, 0.1 + 0.2 stays 0.3. `%g` may answer with
            // an exponent (1.0E-5); then the number is written out in full.
            $text = sprintf('%.15G', $major);

            if (stripos($text, 'E') !== false) {
                $text = rtrim(rtrim(sprintf('%.20F', $major), '0'), '.');
            }

            return $text === '' || $text === '-' || $text === '-0' ? '0' : $text;
        }

        $text = str_replace(["\u{00A0}", "\u{202F}", ' ', "'"], '', trim((string) $major));

        // A comma is the decimal mark when there is no dot; with both, the
        // comma is a thousands separator ("1,299.50").
        $text = str_contains($text, '.') ? str_replace(',', '', $text) : str_replace(',', '.', $text);

        return preg_match('/^[-+]?(\d+(\.\d*)?|\.\d+)$/', $text) ? rtrim($text, '.') : null;
    }
}
