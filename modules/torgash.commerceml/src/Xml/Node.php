<?php

namespace Torgash\CommerceMl\Xml;

use SimpleXMLElement;

/**
 * One element of a CommerceML document, asked about in English.
 *
 * `$node->text('article')` rather than `(string) $x->{'Артикул'}` — the Russian
 * spelling lives in Tags and nowhere else, and a reader of an importer can see
 * what is being taken without knowing the format.
 *
 * Deliberately thin and deliberately forgiving: a CommerceML file in the wild is
 * missing half of what the specification allows, and an importer that throws on
 * an absent element is an importer that stops on the third product of somebody's
 * real catalogue. Absent is null, and the caller decides whether that matters.
 */
class Node
{
    public function __construct(public readonly SimpleXMLElement $xml) {}

    public static function of(SimpleXMLElement $xml): self
    {
        return new self($xml);
    }

    /** Is there such a child at all? Distinct from "is it empty". */
    public function has(string $key): bool
    {
        return isset($this->xml->{Tags::of($key)});
    }

    /** The child's text, or null when it is absent or blank. */
    public function text(string $key): ?string
    {
        if (! $this->has($key)) {
            return null;
        }

        $value = trim((string) $this->xml->{Tags::of($key)});

        return $value === '' ? null : $value;
    }

    public function int(string $key): ?int
    {
        $value = $this->text($key);

        return $value === null ? null : (int) round($this->number($key));
    }

    /**
     * A number as 1C writes it, which is not always as PHP reads it: a comma
     * for the decimal point and spaces inside the thousands are both common,
     * and `(float) "1 234,50"` is 1.0.
     */
    public function float(string $key): ?float
    {
        $value = $this->text($key);

        return $value === null ? null : $this->number($key);
    }

    protected function number(string $key): float
    {
        $value = (string) $this->text($key);

        return (float) str_replace([' ', "\u{a0}", ','], ['', '', '.'], $value);
    }

    /** An attribute of this element (`Статус="Удален"`). */
    public function attr(string $key): ?string
    {
        $value = $this->xml->attributes()->{Tags::of($key)} ?? null;

        return $value === null ? null : (string) $value;
    }

    /** One child, wrapped. */
    public function child(string $key): ?self
    {
        return $this->has($key) ? new self($this->xml->{Tags::of($key)}) : null;
    }

    /**
     * Every child of that name, wrapped.
     *
     * @return array<int, self>
     */
    public function all(string $key): array
    {
        if (! $this->has($key)) {
            return [];
        }

        $nodes = [];

        foreach ($this->xml->{Tags::of($key)} as $child) {
            $nodes[] = new self($child);
        }

        return $nodes;
    }

    /**
     * Children of a wrapper element — `Группы/Группа`, `Товары/Товар`.
     *
     * @return array<int, self>
     */
    public function each(string $wrapper, string $item): array
    {
        return $this->child($wrapper)?->all($item) ?? [];
    }

    /** Every child's text (a product carries several `Картинка` elements). */
    public function texts(string $key): array
    {
        return array_values(array_filter(array_map(
            fn (self $node) => trim((string) $node->xml) ?: null,
            $this->all($key),
        )));
    }

    /**
     * The open bag: `ЗначенияРеквизитов` as name => value.
     *
     * This is where 1C puts everything the format has no element for — weight,
     * dimensions, a promo line, whatever the accountant added — and the names
     * are whatever that installation calls them. Hence a map, and hence the
     * shop typing the names it wants rather than the module guessing.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $values = [];

        foreach ($this->each('attributes', 'attribute') as $attribute) {
            $name = $attribute->text('name');

            if ($name !== null) {
                $values[$name] = (string) $attribute->text('value');
            }
        }

        return $values;
    }
}
