<?php

namespace Torgash\Rma\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Checkout\Models\Order;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;
use Torgash\Rma\Handling;
use Torgash\Rma\Models\ReturnRequest;
use Torgash\Rma\RequestReturn;
use Torgash\Rma\State;

/**
 * The buttons on the return card — and nothing else.
 *
 * There is no index and no show: a return is a ticket, it is read in the one
 * inbox the shop already reads, and a second list of the same rows filtered
 * differently is exactly the second inbox §29.2 refuses. Every action here
 * comes back to the enquiry screen the manager pressed it on.
 *
 * Each one validates what the FORM may say and then hands the decision to
 * Handling, which is where the act lives — the API's actions call the same
 * methods, so a rule enforced here and not there would be no rule at all. What
 * is validated here is only the shape of the request; whether the return is in
 * a state to be moved is the model's `allows()`, asked in one place for both.
 */
class ReturnsController extends Controller
{
    public function __construct(protected Handling $handling) {}

    /**
     * The shop drawing up a return on a ticket that started as something else.
     *
     * The owner's case: somebody writes in with a question about their order,
     * it turns out the thing is faulty, and the shop is the one who decides it
     * is coming back. Everything already said stays in that one conversation,
     * which is the whole reason this is not "create a new return".
     *
     * The order is named by NUMBER rather than picked from a list: a manager
     * reading a ticket has the number in front of them, and a select of every
     * order in the shop is not a control. It defaults to the ticket's own
     * subject, so the ordinary case is already filled in.
     */
    public function create(Request $request, Enquiry $enquiry, RequestReturn $returns)
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:64'],
            'resolution' => ['required', Rule::in(array_keys(ReturnRequest::resolutions()))],
            'lines' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $order = Order::where('order_number', trim($validated['order_number']))->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'order_number' => __('No order with that number.'),
            ]);
        }

        $returns->attach($enquiry, $order, $validated['lines'], $validated['resolution']);

        // The customer is told in the thread they are already in, not by a
        // state appearing on a page they may never open.
        if (($note = trim((string) ($validated['note'] ?? ''))) !== '') {
            app(PostMessage::class)->fromStaff($enquiry, $request->user(), $note);
        }

        return back()->with('status', __('Return drawn up on this ticket.'));
    }

    public function approve(Request $request, ReturnRequest $return)
    {
        $this->allow($return, 'approve');

        $this->handling->approve($return, $request->user(), $this->note($request));

        return back()->with('status', __('Return approved.'));
    }

    public function decline(Request $request, ReturnRequest $return)
    {
        $this->allow($return, 'decline');

        $this->handling->decline($return, $request->user(), $this->note($request));

        return back()->with('status', __('Return declined.'));
    }

    public function receive(Request $request, ReturnRequest $return)
    {
        $this->allow($return, 'receive');

        $validated = $request->validate([
            'disposition' => ['required', Rule::in([ReturnRequest::RESTOCK, ReturnRequest::WRITEOFF])],
            'warehouse_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->handling->receive(
            $return,
            $validated['disposition'],
            $validated['warehouse_id'] ?? null,
            $request->user(),
            $validated['note'] ?? null,
        );

        return back()->with('status', $validated['disposition'] === ReturnRequest::RESTOCK
            ? __('Received and back in stock.')
            : __('Received and written off.'));
    }

    public function refund(Request $request, ReturnRequest $return)
    {
        $this->allow($return, 'refund');

        $validated = $request->validate([
            // In the shop's own currency, as every typed amount in the admin is
            // — the switcher beside the language pickers is read-only and every
            // INPUT is in the stored currency.
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $summary = $this->handling->refund(
            $return,
            (int) round(((float) $validated['amount']) * 100),
            $request->user(),
            $validated['note'] ?? null,
        );

        return back()->with('status', $summary);
    }

    public function exchange(Request $request, ReturnRequest $return)
    {
        $this->allow($return, 'exchange');

        $validated = $request->validate([
            'surcharge' => ['required', Rule::in(array_keys(ReturnRequest::surcharges()))],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $order = $this->handling->exchange(
            $return,
            $validated['surcharge'],
            $request->user(),
            $validated['note'] ?? null,
        );

        return redirect()
            ->route('admin.orders.show', $order->order_number)
            ->with('status', __('Exchange order :number created. Check its delivery and its total before telling the customer.', [
                'number' => $order->order_number,
            ]));
    }

    /**
     * A finished return can still be re-opened, because a manager will
     * sometimes be wrong — the same admission the enquiry screen makes about
     * closing a thread.
     *
     * It moves nothing else: stock that went back stays back and a refund that
     * was paid stays paid, because those are facts about the past and not a
     * state to be toggled. What re-opening does is put the row back where the
     * remaining buttons are.
     */
    public function reopen(ReturnRequest $return)
    {
        $return->update(['state' => $return->received_at ? State::RECEIVED : State::REQUESTED]);

        return back()->with('status', __('Return reopened.'));
    }

    /** A move the return is not in a state for is refused, not quietly ignored. */
    protected function allow(ReturnRequest $return, string $action): void
    {
        abort_unless(in_array($action, $return->allows(), true), 403);
    }

    protected function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:5000']])['note'] ?? null;
    }
}
