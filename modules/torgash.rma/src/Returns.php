<?php

namespace Torgash\Rma;

use Illuminate\Support\Collection;
use Torgash\Rma\Models\ReturnRequest;

/**
 * "Is this enquiry a return, and where has it got to?" — asked once per row of
 * the inbox, answered in one query for the whole page.
 *
 * A registered singleton and never a static property, for the reason the whole
 * project gives: a static outlives the request under a persistent worker and
 * would answer the next visitor with this one's rows. And a listener that wants
 * it resolves it inside itself (`app(Returns::class)`) rather than capturing it
 * at boot, because the listener outlives the request too.
 *
 * Priming is optional, exactly as StockAllocator's is: unprimed it simply asks
 * per row and is correct, just slower. The list announces its set and gets one
 * query; the enquiry screen asks about one row and gets one query either way.
 */
class Returns
{
    /** @var array<int, ReturnRequest|null> keyed by enquiry id */
    protected array $memo = [];

    /** @param iterable<int> $enquiryIds */
    public function prime(iterable $enquiryIds): void
    {
        $wanted = collect($enquiryIds)->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => array_key_exists($id, $this->memo))
            ->unique()
            ->values();

        if ($wanted->isEmpty()) {
            return;
        }

        $found = ReturnRequest::whereIn('enquiry_id', $wanted)->get()->keyBy('enquiry_id');

        foreach ($wanted as $id) {
            // Misses are memoised too: an enquiry that is not a return is the
            // common case, and asking again for every one of them would undo
            // the point of priming.
            $this->memo[$id] = $found->get($id);
        }
    }

    public function forEnquiry(int $enquiryId): ?ReturnRequest
    {
        if (! array_key_exists($enquiryId, $this->memo)) {
            $this->memo[$enquiryId] = ReturnRequest::where('enquiry_id', $enquiryId)->first();
        }

        return $this->memo[$enquiryId];
    }

    /** @param Collection<int, object>|iterable<object> $enquiries */
    public function primeFrom(iterable $enquiries): void
    {
        $this->prime(collect($enquiries)->pluck('id')->filter());
    }
}
