<?php

namespace Torgash\CommerceMl\Import;

use Torgash\CommerceMl\Models\Exchange;
use Torgash\CommerceMl\Models\Link;
use Torgash\CommerceMl\Sale\OrderImport;
use Torgash\CommerceMl\Xml\Reader;

/**
 * One slice of an import, and where it stopped.
 *
 * The protocol asks for exactly this: do some, answer "progress", be asked
 * again. So the unit of work is a batch, the marker is a column, and no queue
 * worker is involved — a shop whose worker is not running would otherwise watch
 * an exchange say "in progress" for ever, and there is nothing here that a
 * background job would make better.
 *
 * The classifier is done whole on the first slice of `import.xml`. It is the
 * tree and the vocabulary, not the goods, and everything after it depends on it
 * having been read.
 */
class Runner
{
    public function __construct(
        protected ClassifierImport $classifier,
        protected ProductImport $products,
        protected OffersImport $offers,
        protected Matching $matching,
        protected OrderImport $orderImport,
    ) {}

    /**
     * @return array{done: bool, message: string}
     */
    public function slice(Exchange $exchange, string $file): array
    {
        // What the shop decided is re-read once per batch. It is memoised
        // within one — the same property is asked about by every product — but
        // never across, so an answer given on the matching screen halfway
        // through a long import takes effect at the next batch.
        $this->matching->flush();

        // A different file than last time is a new pass: 1C sends import.xml
        // and then offers.xml, and the offset belongs to the file, not to the
        // exchange.
        if ($exchange->file !== $file) {
            $exchange->forceFill(['file' => $file, 'offset' => 0])->save();
        }

        // Which half of the protocol this is was settled at checkauth — the two
        // types have their own conversations — so the file name decides nothing
        // here. It is worth saying because the catalogue half does the opposite:
        // there, `offers.xml` and `import.xml` arrive through one exchange.
        if ($exchange->kind === 'sale') {
            return $this->orders($exchange, $file);
        }

        return str_contains(strtolower($file), 'offers')
            ? $this->offers($exchange, $file)
            : $this->catalog($exchange, $file);
    }

    // ---------------------------------------------------------------- orders

    /**
     * Orders coming BACK from 1C: statuses, and the news that money arrived.
     *
     * Sliced like everything else, though it rarely needs to be — an exchange
     * carries the documents 1C has touched since the last one, which is tens,
     * not the whole order history.
     *
     * @return array{done: bool, message: string}
     */
    protected function orders(Exchange $exchange, string $file): array
    {
        $path = $exchange->file($file);
        $total = Reader::count($path, 'document');
        $batch = $this->batch();
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (Reader::stream($path, 'document', $exchange->offset, $batch) as $node) {
            $counts[$this->orderImport->run($node)]++;
        }

        return $this->advance($exchange, $counts, $total, $batch);
    }

    // --------------------------------------------------------------- catalog

    /** @return array{done: bool, message: string} */
    protected function catalog(Exchange $exchange, string $file): array
    {
        $path = $exchange->file($file);

        if ($exchange->offset === 0) {
            $document = Reader::document($path);

            if (! $document) {
                $exchange->finish('failed', __('The file is not valid XML.'));

                throw new \RuntimeException(__('The file is not valid XML.'));
            }

            foreach ($document->all('classifier') as $classifier) {
                $this->classifier->run($classifier);
            }
        }

        $total = Reader::count($path, 'product');
        $batch = $this->batch();
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (Reader::stream($path, 'product', $exchange->offset, $batch) as $node) {
            $counts[$this->products->run($node, $exchange)]++;
        }

        return $this->advance($exchange, $counts, $total, $batch);
    }

    // ---------------------------------------------------------------- offers

    /** @return array{done: bool, message: string} */
    protected function offers(Exchange $exchange, string $file): array
    {
        $path = $exchange->file($file);

        if ($exchange->offset === 0) {
            // The price-type vocabulary, before a single price is read: the
            // matching screen has to be able to ask about a type even on an
            // exchange that writes nothing at all.
            $document = Reader::document($path);

            foreach ($document?->all('offer_package') ?? [] as $package) {
                $this->offers->priceTypes($package);
            }

            // A document whose root IS the package (1C writes it both ways).
            if ($document && $document->has('price_types')) {
                $this->offers->priceTypes($document);
            }
        }

        $total = Reader::count($path, 'offer');
        $batch = $this->batch();
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (Reader::stream($path, 'offer', $exchange->offset, $batch) as $node) {
            $counts[$this->offers->run($node)]++;
        }

        return $this->advance($exchange, $counts, $total, $batch);
    }

    // --------------------------------------------------------------- helpers

    /**
     * Move the marker, and say where we are in words 1C will show a human.
     *
     * @param  array{created: int, updated: int, skipped: int}  $counts
     * @return array{done: bool, message: string}
     */
    protected function advance(Exchange $exchange, array $counts, int $total, int $batch): array
    {
        $offset = min($total, $exchange->offset + $batch);
        $done = $offset >= $total;

        $exchange->forceFill([
            'offset' => $offset,
            'created_count' => $exchange->created_count + $counts['created'],
            'updated_count' => $exchange->updated_count + $counts['updated'],
            'skipped_count' => $exchange->skipped_count + $counts['skipped'],
        ])->save();

        $message = __(':done of :total', ['done' => $offset, 'total' => $total]);

        if ($done) {
            $message = __('Created :created, updated :updated, skipped :skipped.', [
                'created' => $exchange->created_count,
                'updated' => $exchange->updated_count,
                'skipped' => $exchange->skipped_count,
            ]).$this->waiting();

            // Left open on purpose: 1C sends import.xml and then offers.xml
            // through the same conversation, and closing on the first success
            // would throw away the directory the second one needs.
            $exchange->note($message);
        }

        return ['done' => $done, 'message' => $message];
    }

    /**
     * The sentence that stops a shop wondering where its prices went.
     *
     * An unmatched price type means prices are being dropped, silently and
     * every night. Saying so in the message 1C prints is the only place the
     * person who ran the exchange will read it.
     */
    protected function waiting(): string
    {
        $pending = array_sum($this->matching->pending());

        return $pending === 0 ? '' : ' '.__(':count objects from 1C are not matched yet and are being skipped — see Admin → 1C exchange.', [
            'count' => $pending,
        ]);
    }

    protected function batch(): int
    {
        return max(1, (int) setting('torgash.commerceml', 'batch_size', 200));
    }
}
