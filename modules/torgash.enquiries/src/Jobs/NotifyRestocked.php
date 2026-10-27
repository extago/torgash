<?php

namespace Torgash\Enquiries\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Torgash\Catalog\Models\Product;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;
use Torgash\Enquiries\Status;

/**
 * Keeping the promise the product page made: "tell me when it is back".
 *
 * Until now this kind of enquiry was collected and nothing more — the row sat
 * in the inbox and somebody had to notice that a delivery had arrived, which
 * is precisely the thing a person cannot do reliably across a catalogue. The
 * catalogue now says when a product becomes buyable again
 * (`catalog.product.restocked`), and this is what that means for the people
 * who asked.
 *
 * QUEUED, because one import can restock five hundred products and each of
 * them may have a queue of people behind it: a thousand messages written
 * inside the request that uploaded a price list is how an import times out.
 *
 * Told through the ordinary conversation — `PostMessage`, the one place a
 * message is written — rather than a mail of its own. That is what puts the
 * answer in the thread the customer can read and reply to, sends it in THEIR
 * language, and keeps this module's promise that nobody is talking into a
 * void. A thread with no email address gets the message and stays OPEN: there
 * is a phone number on it, and only a person can ring.
 */
class NotifyRestocked implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $productId) {}

    public function handle(PostMessage $messages): void
    {
        if (! $product = Product::find($this->productId)) {
            return;
        }

        // Still buyable when the worker actually gets here? A queue runs
        // behind the shop, and the last one can sell out in between —
        // announcing a return that has already ended would send people to a
        // disabled button.
        if (! $product->isOrderable()) {
            return;
        }

        $waiting = Enquiry::where('kind', 'back_in_stock')
            ->where('subject_type', 'product')
            ->where('subject_id', $this->productId)
            ->whereIn('status', Status::OPEN)
            ->whereNull('locked_at')
            ->get();

        foreach ($waiting as $enquiry) {
            $this->tell($messages, $enquiry, $product);
        }
    }

    protected function tell(PostMessage $messages, Enquiry $enquiry, Product $product): void
    {
        $locale = $enquiry->locale ?: content_base_locale();

        $body = trans('The product you asked about is back in stock: :product', [
            'product' => $product->t('name', $locale) ?: $product->sku,
        ], $locale);

        if ($product->slug) {
            $body .= "\n".url('/product/'.$product->slug);
        }

        $messages->fromShop($enquiry, $body);

        // The question is answered only if the answer actually went anywhere.
        // With an address, this thread is finished — the shop promised one
        // thing and has just done it. With a phone number only, nothing has
        // been said to anybody yet, so the row stays in the queue with the
        // news written in it for whoever picks up the phone.
        if (trim((string) $enquiry->email) !== '') {
            $enquiry->answered_at ??= now();
            $enquiry->status = Status::DONE;
            $enquiry->save();
        }
    }
}
