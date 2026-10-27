<?php

namespace Torgash\CommerceMl\Import;

use App\Core\Support\Units;
use Torgash\CommerceMl\Xml\Node;

/**
 * Which 1C field fills which of ours — a setting, not a decision in code.
 *
 * Half of CommerceML is a fixed vocabulary and half is an open bag. The fixed
 * half still offers choices: a name can come from `Наименование` or from
 * `Полное наименование`, an article number from `Артикул`, `Код` or `Штрихкод`,
 * and which is right depends on how the shop's accountant fills 1C in.
 *
 * The open half is `ЗначенияРеквизитов`: arbitrary named attributes, and no two
 * 1C configurations name them alike — "Вес", "Вес, кг", "ВесБрутто", "Масса".
 * Guessing a canonical spelling means silently importing no weights at all, so
 * the shop types the names it uses, several per field, and the last one
 * actually present wins.
 */
class FieldMap
{
    public function name(Node $product): ?string
    {
        return $product->text(setting('torgash.commerceml', 'field_name', 'name') === 'full_name'
            ? 'full_name'
            : 'name');
    }

    public function sku(Node $product): ?string
    {
        return $product->text(match (setting('torgash.commerceml', 'field_sku', 'article')) {
            'code' => 'code',
            'barcode' => 'barcode',
            default => 'article',
        });
    }

    /**
     * The description, or null when the shop said not to import one.
     *
     * Deliberately allows "do not import": a 1C description is an accountant's
     * note far more often than it is storefront copy, and a nightly exchange
     * that overwrites the copywriter is worse than one that leaves the field
     * alone.
     */
    public function description(Node $product): ?string
    {
        $source = (string) setting('torgash.commerceml', 'field_description', 'html_description');

        if ($source === '') {
            return null;
        }

        // 1C often sends both, with the plain one as a fallback for readers that
        // cannot render markup. Falling through gives a shop with only the
        // plain field something rather than nothing.
        return $product->text($source)
            ?? ($source === 'html_description' ? $product->text('description') : null);
    }

    /**
     * Weight in WHOLE GRAMS, which is the only thing the database stores.
     *
     * 1C writes kilograms, so the number is converted at the edge — the same
     * rule the admin form follows, and the reason `units()` exists. Null is
     * kept as null: "not measured" is a different answer from 0, and the
     * shipping engine acts on the difference.
     */
    public function weight(Node $product): ?int
    {
        $value = $this->attribute($product, 'attr_weight');

        return $value === null ? null : app(Units::class)->weightToStorage($value, 'kg');
    }

    /**
     * The box in WHOLE MILLIMETRES.
     *
     * @return array{length: ?int, width: ?int, height: ?int}
     */
    public function box(Node $product): array
    {
        return [
            'length' => $this->millimetres($product, 'attr_length'),
            'width' => $this->millimetres($product, 'attr_width'),
            'height' => $this->millimetres($product, 'attr_height'),
        ];
    }

    /** Is this field one the shop only wants filled when the product is new? */
    public function onlyForNew(string $field): bool
    {
        return in_array($field, (array) setting('torgash.commerceml', 'fields_new_only', []), true);
    }

    /**
     * The value of the last of the named attributes that is actually present.
     *
     * Last rather than first on purpose: the shop types the names in the order
     * it thinks of them, and the specific one ("Вес, кг") is written after the
     * general one ("Вес") far more often than before it.
     *
     * Public and untyped because the open bag carries more than numbers: an
     * order coming back from 1C reports its shipment as a date in exactly the
     * same "whatever this installation calls it" way, and there is no second
     * version of this rule.
     */
    public function pick(Node $node, string $settingKey): ?string
    {
        $names = preg_split('~\R~', (string) setting('torgash.commerceml', $settingKey, '')) ?: [];
        $bag = $node->attributes();
        $found = null;

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '' || ! array_key_exists($name, $bag)) {
                continue;
            }

            $raw = trim($bag[$name]);

            if ($raw !== '') {
                $found = $raw;
            }
        }

        return $found;
    }

    protected function millimetres(Node $product, string $key): ?int
    {
        $value = $this->attribute($product, $key);

        return $value === null ? null : app(Units::class)->lengthToStorage($value, 'cm');
    }

    protected function attribute(Node $product, string $settingKey): ?float
    {
        $raw = $this->pick($product, $settingKey);

        // Same comma-and-spaces problem as everywhere else in this format.
        return $raw === null ? null : (float) str_replace([' ', "\u{a0}", ','], ['', '', '.'], $raw);
    }
}
