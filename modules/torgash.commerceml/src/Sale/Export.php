<?php

namespace Torgash\CommerceMl\Sale;

use Torgash\CommerceMl\Xml\Writer;

/**
 * The answer to `type=sale&mode=query`: the orders, as one CommerceML file.
 *
 * The whole batch in one response, because that is what the protocol's sale
 * half is — 1C asks once, reads the file, and says `success`. There is no
 * "progress" on this side and no place to put an offset: the slicing exists
 * for uploads, and the number of orders a shop places between two exchanges is
 * not the number of products in its catalogue.
 *
 * What keeps that honest is the limit: at most one batch per call, and an
 * exchange that hits the ceiling simply carries the rest next time, because
 * nothing is marked as taken until 1C says so.
 */
class Export
{
    public function __construct(protected Outbox $outbox) {}

    /** @return array{xml: string, count: int} */
    public function document(): array
    {
        $rows = $this->outbox->due($this->batch());

        $xml = new Writer;
        $xml->open('commerce_information', [
            'schema_version' => '2.05',
            'created_at' => now()->format('Y-m-d'),
        ]);

        foreach ($rows as $row) {
            $xml->raw($row['xml']);
        }

        $xml->close();

        // Recorded as offered, not as delivered. The confirmation is a separate
        // request from 1C, and an exchange that dies between the two must send
        // the same orders again rather than believe they arrived.
        $this->outbox->offered($rows);

        return ['xml' => $xml->toString(), 'count' => $rows->count()];
    }

    protected function batch(): int
    {
        return max(1, (int) setting('torgash.commerceml', 'batch_size', 200));
    }
}
