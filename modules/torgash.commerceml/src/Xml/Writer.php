<?php

namespace Torgash\CommerceMl\Xml;

use XMLWriter;

/**
 * Writing CommerceML, in English, with the Russian spelling supplied by Tags.
 *
 * The mirror of Node: an exporter says `$xml->el('number', $order->order_number)`
 * and this is the only side of the module that knows the element is called
 * `Номер`. One vocabulary, read and written from the same table, so a tag that
 * is spelled wrong is spelled wrong in both directions and shows up at once.
 *
 * XMLWriter rather than string concatenation because 1C's own parser is strict
 * about exactly the things hand-built XML gets wrong — a customer's name with
 * an ampersand in it, a comment field with a stray `<`, the encoding declaration
 * — and an exchange that fails on one order in a thousand is the worst kind.
 */
class Writer
{
    protected XMLWriter $xml;

    /**
     * @param  bool  $declaration  false builds a FRAGMENT — one `<Документ>` on
     *                             its own, so that it can be hashed and compared
     *                             with what 1C was last given without the
     *                             envelope's timestamp changing every time.
     */
    public function __construct(protected bool $declaration = true)
    {
        $this->xml = new XMLWriter;
        $this->xml->openMemory();
        $this->xml->setIndent(true);
        $this->xml->setIndentString('  ');

        if ($declaration) {
            $this->xml->startDocument('1.0', 'UTF-8');
        }
    }

    /** Open an element by its English key. */
    public function open(string $key, array $attributes = []): static
    {
        $this->xml->startElement(Tags::of($key));

        foreach ($attributes as $name => $value) {
            $this->xml->writeAttribute(Tags::of($name), (string) $value);
        }

        return $this;
    }

    public function close(): static
    {
        $this->xml->endElement();

        return $this;
    }

    /**
     * A complete element with text, skipped entirely when there is nothing to
     * say. Absent and empty are different things to 1C — an empty `<Комментарий>`
     * overwrites the one in the document, a missing one leaves it — and "do not
     * write what you do not know" is the safer of the two everywhere.
     */
    public function el(string $key, string|int|float|null $value, array $attributes = []): static
    {
        if ($value === null || $value === '') {
            return $this;
        }

        $this->open($key, $attributes);
        $this->xml->text((string) $value);

        return $this->close();
    }

    /**
     * One entry of the open bag — `ЗначенияРеквизитов/ЗначениеРеквизита`.
     *
     * This is where everything the format has no element for goes: the payment
     * method, the shipping method, whether the order is paid. The NAME is what
     * the other side matches on, and every 1C configuration expects the names
     * its own exchange rules were written against, which is why the caller
     * passes the literal string rather than a key into Tags.
     */
    public function attribute(string $name, string|int|float|null $value): static
    {
        if ($value === null || $value === '') {
            return $this;
        }

        $this->open('attribute');
        $this->el('name', $name);
        $this->el('value', $value);

        return $this->close();
    }

    /**
     * Already-built XML, placed as it stands.
     *
     * Only ever given a fragment this class produced — that is how the envelope
     * is assembled from documents that were rendered (and hashed) one at a time.
     * Never given anything a person typed.
     */
    public function raw(string $xml): static
    {
        $this->xml->writeRaw("\n".$xml);

        return $this;
    }

    public function toString(): string
    {
        if ($this->declaration) {
            $this->xml->endDocument();
        }

        return $this->xml->outputMemory();
    }
}
