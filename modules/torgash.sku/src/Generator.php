<?php

namespace Torgash\Sku;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * A template in, a SKU nobody has out.
 *
 * The template is text as it is with tokens in braces — readable where
 * the usual "every letter is a random letter" was not, and a SKU a person can
 * dictate over the phone is the point of having one:
 *
 *   {n} {n:6}            a running number (zero-padded to 6) — per TEMPLATE
 *   {id}                 the product's own number
 *   {category} {category:3}  the category's address in capitals (first 3)
 *   {brand} {brand:3}    the brand value's code
 *   {year} {yy}          this year, four digits or two
 *   {rand:4}             random capitals and digits (no O/0, I/1)
 *   {digits:4}           random digits
 *   {ean13}              a whole EAN-13 with its check digit, prefix 2 —
 *                        the range GS1 leaves for a shop's own goods: good
 *                        for its scanner and its labels, not for a market
 *                        place, which wants a GS1 member's number.
 *
 * A token with nothing to say (no category, no brand) prints nothing, and
 * the separators it leaves doubled or hanging are tidied away.
 */
class Generator
{
    public const TOKEN = '/\{(n|id|category|brand|year|yy|rand|digits|ean13)(?::(\d{1,2}))?\}/';

    /** Readable random characters: no O/0 or I/1 to confuse on a label. */
    protected const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Tries before giving up on a template that keeps producing taken SKUs. */
    protected const ATTEMPTS = 25;

    /**
     * A free SKU for the product by the template, or null when the template
     * cannot make one (empty, or every try was somebody else's).
     */
    public function make(string $template, Product $product, ?Category $category = null): ?string
    {
        $template = trim($template);

        if ($template === '') {
            return null;
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $sku = $this->render($template, $product, $category);

            if ($sku === '') {
                return null;
            }

            if (! Product::skuTaken($sku, $product->exists ? $product->getKey() : null)) {
                return $sku;
            }

            // A template with nothing that changes between tries (no number,
            // nothing random) makes the same SKU every time: say so once.
            if (! $this->varies($template)) {
                return null;
            }
        }

        return null;
    }

    /** One rendering. Running numbers advance even when the result turns out taken. */
    public function render(string $template, Product $product, ?Category $category = null): string
    {
        $sku = preg_replace_callback(self::TOKEN, function (array $m) use ($template, $product, $category) {
            $size = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

            return match ($m[1]) {
                'n' => str_pad((string) $this->next($template), $size ?? 1, '0', STR_PAD_LEFT),
                'id' => (string) $product->getKey(),
                'category' => $this->cut($category ? Str::upper((string) $category->slug) : '', $size),
                'brand' => $this->cut(Str::upper((string) ($product->exists ? $product->brandValue()?->code : '')), $size),
                'year' => now()->format('Y'),
                'yy' => now()->format('y'),
                'rand' => $this->random(self::ALPHABET, $size ?? 4),
                'digits' => $this->random('0123456789', $size ?? 4),
                'ean13' => self::ean13('2'.str_pad((string) $this->next('{ean13}'), 11, '0', STR_PAD_LEFT)),
            };
        }, $template);

        // What an empty token leaves behind: "SKU--0001", "-0001", "SKU-".
        $sku = preg_replace('/([-_.\/ ])\1+/', '$1', (string) $sku);

        return mb_substr(trim((string) $sku, "-_./ \t"), 0, 190);
    }

    /** The twelve digits with their check digit — the standard's weights 1, 3, 1, 3… */
    public static function ean13(string $twelve): string
    {
        $twelve = substr(str_pad(preg_replace('/\D/', '', $twelve), 12, '0', STR_PAD_LEFT), -12);
        $sum = 0;

        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $twelve.((10 - $sum % 10) % 10);
    }

    /** Does the template say anything that changes from one SKU to the next? */
    public function varies(string $template): bool
    {
        return preg_match('/\{(n|id|rand|digits|ean13)(?::\d{1,2})?\}/', $template) === 1;
    }

    /** Is every brace in the template a token we know? (The settings screen asks.) */
    public static function unknownTokens(string $template): array
    {
        preg_match_all('/\{[^}]*\}/', $template, $all);

        return array_values(array_filter($all[0], fn ($token) => preg_match(self::TOKEN, $token) !== 1));
    }

    /**
     * The template's next running number — one statement, so two products
     * created at the same moment never draw the same one.
     */
    protected function next(string $template): int
    {
        return (int) DB::selectOne(
            'INSERT INTO m_sku_counters (template, value) VALUES (?, 1)
             ON CONFLICT (template) DO UPDATE SET value = m_sku_counters.value + 1
             RETURNING value',
            [mb_substr($template, 0, 190)],
        )->value;
    }

    protected function cut(string $value, ?int $size): string
    {
        $value = preg_replace('/[^A-Z0-9]+/', '', $value);

        return $size ? substr($value, 0, $size) : $value;
    }

    protected function random(string $alphabet, int $size): string
    {
        $out = '';

        for ($i = 0; $i < max(1, $size); $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
