<?php

namespace Torgash\Rma;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Checkout\Models\Order;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Submit;
use Torgash\Rma\Models\ReturnRequest;

/**
 * The one place a return is created — the same law as Submit for an enquiry and
 * PlaceOrder for an order.
 *
 * A return is TWO rows and they are written together: the ticket (an enquiry,
 * so the shop answers it in the one inbox it already reads) and the return
 * itself (the lines, which an enquiry's answers cannot hold — see the
 * migration). Neither is useful without the other, so neither is created
 * without the other.
 */
class RequestReturn
{
    public function __construct(protected Submit $submit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Request $request, Order $order): ?ReturnRequest
    {
        if ($refusal = Eligibility::refusal($order)) {
            throw ValidationException::withMessages(['enquiry' => $refusal]);
        }

        $lines = $this->lines((array) $request->input('lines', []), $order);

        // Through the enquiries funnel, so the spam guard, the label snapshot,
        // the customer ref, the shop's notification and the automations trigger
        // are the ones every other enquiry gets. The subject is set here rather
        // than read from the form: what a return is about is the order it was
        // asked from, and a POST must not be able to name another one.
        $enquiry = $this->submit->handle($request, Kind::RETURN, [
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'page_url' => $order->customerUrl(),
        ]);

        // The honeypot was hit: answered as a success, written nowhere. No
        // ticket, so no return either.
        if (! $enquiry) {
            return null;
        }

        return ReturnRequest::create([
            'enquiry_id' => $enquiry->id,
            'order_id' => $order->id,
            'resolution' => $enquiry->answer('resolution') ?: ReturnRequest::REFUND,
            'lines' => $lines,
        ]);
    }

    /**
     * The MANAGER drawing up a return against a ticket that already exists.
     *
     * The owner's case, and it is the ordinary one rather than an edge: somebody
     * writes in with a question about their order, it turns out the thing is
     * faulty, and the shop is the one who says "send it back". The conversation
     * has already happened — inventing a second ticket for it would split the
     * thread in half and file the customer's own words under the wrong one.
     *
     * So this writes the return row alone and converts the ticket it hangs on:
     * a question that ended as a return IS a return, and the inbox's `return`
     * filter has to find it. Nothing the person wrote is touched — the answers
     * and the labels they were asked under are a snapshot on the row, so the
     * conversation still reads exactly as it did.
     *
     * The shop's OFFER rules are deliberately not applied here. "Which statuses,
     * within how many days, only if we recorded it as paid" answer the question
     * "when do we show a customer the button" — a manager taking something back
     * outside that window is exactly what a shop keeps people for, and refusing
     * them would only mean the return happens in a spreadsheet instead. What
     * does still bind is the arithmetic: `lines()` bounds every quantity by what
     * is actually left of the order, because the same unit cannot come back
     * twice however senior the person asking.
     *
     * @param  array<int|string, mixed>  $wanted  product id => qty (or ['qty' => n])
     *
     * @throws ValidationException
     */
    public function attach(Enquiry $enquiry, Order $order, array $wanted, string $resolution): ReturnRequest
    {
        if (ReturnRequest::where('enquiry_id', $enquiry->id)->exists()) {
            throw ValidationException::withMessages([
                'lines' => __('This ticket already has a return on it.'),
            ]);
        }

        $lines = $this->lines($wanted, $order);

        $enquiry->kind = Kind::RETURN;

        // A ticket that came in through the general contact form is about
        // nothing in particular until now. Setting the subject here is not the
        // thing §29.2 forbids — that rule is about a FORM naming its own
        // subject, and this is a signed-in manager with `rma.manage` saying
        // which order they are looking at.
        $enquiry->subject_type = 'order';
        $enquiry->subject_id = $order->id;
        $enquiry->save();

        return ReturnRequest::create([
            'enquiry_id' => $enquiry->id,
            'order_id' => $order->id,
            'resolution' => $resolution,
            'lines' => $lines,
        ]);
    }

    /**
     * What is being sent back, checked against what is actually left of the
     * order — never taken from the form as given.
     *
     * A quantity is bounded by what remains after earlier returns, and a
     * product that is not on this order is not a line at all: otherwise a
     * hand-built POST refunds a product the customer never bought, at a price
     * it names itself. The price comes from the ORDER's frozen line, which is
     * what the customer actually paid.
     *
     * Shared by both ways in — the customer's form and the manager's — because
     * a rule that only one of them obeys is not a rule.
     *
     * @param  array<int|string, mixed>  $wanted  product id => qty (or ['qty' => n])
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    protected function lines(array $wanted, Order $order): array
    {
        $lines = [];

        foreach (Eligibility::remaining($order) as $line) {
            $id = (int) $line['product_id'];
            $qty = (int) ($wanted[$id]['qty'] ?? $wanted[$id] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $qty = min($qty, (int) $line['qty']);
            $unit = (int) ($line['unit_price'] ?? 0);

            $lines[] = [
                'product_id' => $id,
                'sku' => $line['sku'] ?? null,
                'name' => $line['name'] ?? null,
                'qty' => $qty,
                'unit_price' => $unit,
                // Recomputed rather than sliced off the order's line total: a
                // partial return of a line owes its own share, and the order's
                // total is for the whole of it.
                'line_total' => $unit * $qty,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'enquiry' => __('Choose what you are sending back.'),
            ]);
        }

        return $lines;
    }
}
