<?php

namespace Tests\Feature\Enquiries;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use App\Core\Mail\EventMail;
use Torgash\Enquiries\Mail\EnquiryLetters;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Enquiries\Jobs\NotifyRestocked;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Status;

/**
 * "Tell me when it is back" — the promise the product page makes, and until now
 * the one it did not keep: the enquiry was stored and nothing ever fired when
 * the goods actually arrived.
 *
 * Two halves are pinned here. The catalogue announces a product BECOMING
 * buyable again, for every path that can move it — a save, a raw warehouse
 * write, a status edit — and only as a crossing. And this module answers the
 * people who asked, in the thread, in their own language.
 */
class BackInStockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.enquiries', 'notify_email', 'manager@shop.test');
    }

    /** The whole point, end to end: stock arrives, the person is told. */
    public function test_the_customer_is_told_when_the_goods_arrive(): void
    {
        Mail::fake();

        $product = $this->product('BIS-1', stock: 0);
        $enquiry = $this->waitingFor($product);

        // The queue is sync here, so saving the product runs the job — which
        // is the path a real shop takes too, just with a worker in between.
        $product->update(['stock' => 7]);

        $message = $enquiry->fresh()->messages()->latest('id')->first();

        $this->assertNotNull($message, 'nothing was written into the thread');
        $this->assertTrue($message->is_staff);
        $this->assertStringContainsString('BIS-1 thing', $message->body);

        // The email is the answer for a guest — they have no account to come
        // back to.
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED);

        // Promise kept, so the thread is finished — and `answered_at` is
        // stamped, because the wait genuinely ended.
        $this->assertSame(Status::DONE, $enquiry->fresh()->status);
        $this->assertNotNull($enquiry->fresh()->answered_at);
    }

    /**
     * A restock is a CROSSING. A product that was already buyable has not come
     * back from anywhere, and a shop editing prices all morning must not mail
     * its waiting list every time it saves.
     */
    public function test_nothing_is_announced_for_a_product_that_was_never_away(): void
    {
        Bus::fake();

        $product = $this->product('BIS-2', stock: 5);
        $this->waitingFor($product);

        $product->update(['stock' => 9]);

        Bus::assertNotDispatched(NotifyRestocked::class);
    }

    /** ...and the crossing is announced once, whichever way the number moved. */
    public function test_a_save_that_brings_it_back_announces_once(): void
    {
        Bus::fake();

        $product = $this->product('BIS-3', stock: 0);

        $product->update(['stock' => 3]);

        Bus::assertDispatchedTimes(NotifyRestocked::class, 1);
    }

    /**
     * A status edit is a return too: "discontinued" back to "in stock" is the
     * same news to the person waiting, and it moves no number at all.
     */
    public function test_a_status_edit_counts_as_coming_back(): void
    {
        Bus::fake();

        $product = $this->product('BIS-4', stock: 4, availability: 'discontinued');

        $product->update(['availability_status' => 'in_stock']);

        Bus::assertDispatchedTimes(NotifyRestocked::class, 1);
    }

    /**
     * The warehouse path, which is the one that actually matters: a delivery is
     * booked in, the aggregate is written with a raw query, and before this
     * that was the single stock change nothing downstream could hear.
     */
    public function test_a_warehouse_write_announces_it_too(): void
    {
        Bus::fake();

        $product = $this->product('BIS-5', stock: 0);

        Product::syncStockTo($product->id, 12);

        Bus::assertDispatchedTimes(NotifyRestocked::class, 1);
        $this->assertSame(12, $product->fresh()->stock);
    }

    /**
     * ...and the same path down to zero runs the shop's own automatic
     * transition, which only the other stock path used to do.
     */
    public function test_a_warehouse_write_to_zero_closes_the_sale(): void
    {
        $product = $this->product('BIS-6', stock: 4);

        Product::syncStockTo($product->id, 0);

        $this->assertSame('out_of_stock', $product->fresh()->availability_status);
    }

    /**
     * A phone number and no address: the news goes into the thread, but the
     * row stays in the queue. Nothing has been said to anybody yet — only a
     * person can ring, and closing it would hide that from the inbox.
     */
    public function test_a_thread_with_no_email_stays_in_the_queue(): void
    {
        Mail::fake();

        $product = $this->product('BIS-7', stock: 0);
        $enquiry = $this->waitingFor($product, ['email' => null]);

        $product->update(['stock' => 2]);

        Mail::assertNotQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED);

        $this->assertNotNull($enquiry->fresh()->messages()->latest('id')->first());
        $this->assertContains($enquiry->fresh()->status, Status::OPEN);
    }

    /**
     * The queue runs behind the shop, and the last one can sell out while the
     * job waits — telling somebody to come and buy a disabled button is worse
     * than saying nothing.
     */
    public function test_a_product_that_sold_out_again_before_the_worker_ran_is_not_announced(): void
    {
        Mail::fake();
        // Held back on purpose: this is the job running LATE, which is what a
        // real worker does — everywhere else in this file the sync queue runs
        // it at once.
        Bus::fake();

        $product = $this->product('BIS-8', stock: 0);
        $enquiry = $this->waitingFor($product);

        $product->update(['stock' => 1]);
        $product->update(['stock' => 0]);

        (new NotifyRestocked($product->id))->handle(app(\Torgash\Enquiries\PostMessage::class));

        Mail::assertNotQueued(EventMail::class, fn (EventMail $mail) => $mail->event === EnquiryLetters::REPLIED);
        $this->assertSame(0, $enquiry->fresh()->messages()->count());
    }

    /** A finished (or rejected, or locked) thread is not written into again. */
    public function test_a_closed_thread_is_left_alone(): void
    {
        Mail::fake();

        $product = $this->product('BIS-9', stock: 0);
        $enquiry = $this->waitingFor($product, ['status' => Status::DONE]);

        $product->update(['stock' => 5]);

        $this->assertSame(0, $enquiry->fresh()->messages()->count());
    }

    protected function waitingFor(Product $product, array $overrides = []): Enquiry
    {
        return Enquiry::create(array_merge([
            'kind' => 'back_in_stock',
            'name' => 'Waiting buyer',
            'email' => 'buyer@example.com',
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'locale' => 'en',
            'status' => Status::NEW,
        ], $overrides));
    }

    protected function product(string $sku, int $stock, string $availability = 'in_stock'): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => $sku.' thing'],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => $stock,
            'status' => 'active',
            'availability_status' => $availability,
        ]);
    }
}
