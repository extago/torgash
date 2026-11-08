<?php

namespace Torgash\Enquiries;

use App\Core\Support\ClientFacts as CoreClientFacts;

/**
 * What an enquiry writes down about the browser it was sent from: the same
 * facts as an order, under the shop's own ticks for enquiries. Captured by
 * `Submit` and nowhere else; a later message in the thread adds nothing.
 */
class ClientFacts extends CoreClientFacts
{
    protected function ticked(): array
    {
        return (array) setting('torgash.enquiries', 'client_facts', self::DEFAULTS);
    }
}
