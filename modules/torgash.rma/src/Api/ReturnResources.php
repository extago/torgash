<?php

namespace Torgash\Rma\Api;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Api\ApiResources;
use Torgash\Rma\Handling;
use Torgash\Rma\Models\ReturnRequest;
use Torgash\Rma\State;

/**
 * Returns over the API.
 *
 * There is no `update`, and that is the whole design in one absence. Every move
 * a return makes has consequences outside its own row — receiving goods credits
 * warehouses, refunding moves the payment state through checkout, an exchange
 * places a real order — so each is an ACTION calling exactly the method the
 * admin card calls. A client PATCHing `state` to "received" would have set a
 * string and moved nothing, and it would have looked like it worked: the same
 * argument by which an order's status is an action and not a column.
 *
 * That is also what makes this an honest place to hang an agent: it cannot get
 * past a rule the screen enforces, because there is only the one set of rules.
 */
class ReturnResources
{
    public function register(): void
    {
        ApiResources::register('admin.returns', [
            'label' => 'Returns',
            'description' => 'Returns and exchanges. The conversation about one is an enquiry and is read through that resource; this is the goods, the money and the acts the shop performs on them.',
            'permission' => 'rma.manage',
            'query' => fn () => ReturnRequest::query()->with(['enquiry', 'order']),
            'sortable' => ['id', 'created_at', 'updated_at', 'received_at', 'completed_at'],
            'filters' => [
                'state' => [
                    'description' => 'requested | approved | declined | received | completed.',
                    'apply' => fn ($q, $v) => $q->where('state', $v),
                ],
                'resolution' => [
                    'description' => 'refund | exchange — what the customer asked for.',
                    'apply' => fn ($q, $v) => $q->where('resolution', $v),
                ],
                'order_id' => ['apply' => fn ($q, $v) => $q->where('order_id', (int) $v)],
                'open' => [
                    'description' => '1 for the returns still to be dealt with — everything that is neither declined nor completed. This is the queue.',
                    'apply' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOL) ? $q->open() : $q,
                ],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'serialize' => fn (ReturnRequest $r) => $this->serialize($r),
            'schema' => $this->schema(),
            'actions' => [
                'approve' => [
                    'description' => 'Say yes. An optional note is posted into the ticket as the shop\'s reply, which is what emails the customer — a state nobody is told about has told nobody anything.',
                    'body' => ['note' => ['type' => 'string|null', 'description' => 'A reply to the customer, posted into the ticket.']],
                    'apply' => fn (ReturnRequest $r, array $data) => $this->act($r, 'approve', $data),
                ],
                'decline' => [
                    'description' => 'Say no. The reason belongs in the note, where the customer can read it.',
                    'body' => ['note' => ['type' => 'string|null']],
                    'apply' => fn (ReturnRequest $r, array $data) => $this->act($r, 'decline', $data),
                ],
                'receive' => [
                    'description' => 'The parcel is here — which is when stock moves, not when the return was approved. `disposition` is restock or writeoff, and a warranty item is written off: it goes back to the manufacturer or into the bin, and stock must not gain one. `warehouse_id` is where it actually arrived; omit it and the units go back to the warehouses that shipped them.',
                    'body' => [
                        'disposition' => ['type' => 'string', 'description' => 'restock | writeoff', 'example' => 'restock'],
                        'warehouse_id' => ['type' => 'integer|null'],
                        'note' => ['type' => 'string|null'],
                    ],
                    'apply' => function (ReturnRequest $return, array $data) {
                        $validated = Validator::make($data, [
                            'disposition' => ['required', Rule::in([ReturnRequest::RESTOCK, ReturnRequest::WRITEOFF])],
                            'warehouse_id' => ['nullable', 'integer'],
                            'note' => ['nullable', 'string', 'max:5000'],
                        ])->validate();

                        $this->guard($return, 'receive');

                        app(Handling::class)->receive(
                            $return,
                            $validated['disposition'],
                            $validated['warehouse_id'] ?? null,
                            auth()->user(),
                            $validated['note'] ?? null,
                        );
                    },
                ],
                'refund' => [
                    'description' => 'Give the money back, in minor units of the shop\'s own currency. Where a gateway took it, it is asked to return it; a payment that is only on hold is RELEASED rather than transferred, because paying a customer money that never left their card costs the shop twice. Where no gateway took it, the refund is recorded and a person makes the transfer.',
                    'body' => [
                        'amount' => ['type' => 'integer', 'description' => 'Minor units of the shop\'s books.', 'example' => 39920],
                        'note' => ['type' => 'string|null'],
                    ],
                    'apply' => function (ReturnRequest $return, array $data) {
                        $validated = Validator::make($data, [
                            'amount' => ['required', 'integer', 'min:1'],
                            'note' => ['nullable', 'string', 'max:5000'],
                        ])->validate();

                        $this->guard($return, 'refund');

                        app(Handling::class)->refund(
                            $return,
                            $validated['amount'],
                            auth()->user(),
                            $validated['note'] ?? null,
                        );
                    },
                ],
                'exchange' => [
                    'description' => 'Place the replacement order — a NEW order for the same goods to the same address, linked to the old one, never an edit of it. It is priced from today\'s catalogue, so `surcharge` records what is left to settle (none | due | owed) as a decision rather than a payment: the engine will not invent one.',
                    'body' => [
                        'surcharge' => ['type' => 'string', 'description' => 'none | due | owed', 'example' => 'none'],
                        'note' => ['type' => 'string|null'],
                    ],
                    'apply' => function (ReturnRequest $return, array $data) {
                        $validated = Validator::make($data, [
                            'surcharge' => ['required', Rule::in(array_keys(ReturnRequest::surcharges()))],
                            'note' => ['nullable', 'string', 'max:5000'],
                        ])->validate();

                        $this->guard($return, 'exchange');

                        app(Handling::class)->exchange(
                            $return,
                            $validated['surcharge'],
                            auth()->user(),
                            $validated['note'] ?? null,
                        );
                    },
                ],
            ],
        ]);
    }

    /** Approve and decline are the same shape; only the verb differs. */
    protected function act(ReturnRequest $return, string $action, array $data): void
    {
        $validated = Validator::make($data, ['note' => ['nullable', 'string', 'max:5000']])->validate();

        $this->guard($return, $action);

        app(Handling::class)->{$action}($return, auth()->user(), $validated['note'] ?? null);
    }

    /**
     * The model's own answer to "what may happen to this next", asked here as
     * the screen asks it. One list, so the API can never allow what the card
     * refuses — and 422 rather than 403, because this is about the state of the
     * row and not about who is calling.
     */
    protected function guard(ReturnRequest $return, string $action): void
    {
        if (! in_array($action, $return->allows(), true)) {
            throw ValidationException::withMessages([
                'state' => __('A return in the state ":state" cannot be :action.', [
                    'state' => State::label($return->state),
                    'action' => $action,
                ]),
            ]);
        }
    }

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        return [
            'id' => 'integer',
            'enquiry_id' => ['type' => 'integer', 'description' => 'The ticket the conversation about this return lives on — read it through the enquiries resource.'],
            'order_id' => 'integer',
            'order_number' => 'string|null',
            'resolution' => ['type' => 'string', 'description' => 'refund | exchange — what the customer asked for, which is not necessarily what the shop did.'],
            'state' => ['type' => 'string', 'description' => 'requested | approved | declined | received | completed — where the goods and the money are, deliberately not the ticket\'s conversation status.'],
            'lines' => ['type' => 'array', 'description' => 'What is being sent back, frozen off the order at the price the customer actually paid.', 'of' => [
                'product_id' => 'integer', 'sku' => 'string|null', 'name' => 'string|null',
                'qty' => 'integer', 'unit_price' => 'integer', 'line_total' => 'integer',
            ]],
            'lines_total' => ['type' => 'integer', 'description' => 'What those goods came to, in minor units of the shop\'s books. The starting point for a refund, not the answer.'],
            'disposition' => ['type' => 'string|null', 'description' => 'restock | writeoff — decided at receipt, because what is in the parcel is only known once it is opened.'],
            'warehouse_id' => ['type' => 'integer|null', 'description' => 'Where the parcel arrived, when that is not where it shipped from.'],
            'refund_amount' => ['type' => 'integer|null', 'description' => 'Minor units. Not derivable from the lines: a shop may keep the postage or agree something else.'],
            'exchange_order_id' => 'integer|null',
            'surcharge' => ['type' => 'string|null', 'description' => 'none | due | owed — what is left to settle after an exchange, recorded as a decision and never charged.'],
            'received_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'completed_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'allows' => ['type' => 'array', 'description' => 'The actions this return can take right now — the same list the admin card draws its buttons from.'],
            'created_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
        ];
    }

    /** @return array<string, mixed> */
    protected function serialize(ReturnRequest $return): array
    {
        return [
            'id' => $return->id,
            'enquiry_id' => $return->enquiry_id,
            'order_id' => $return->order_id,
            'order_number' => $return->order?->order_number,
            'resolution' => $return->resolution,
            'state' => $return->state,
            'lines' => $return->lines,
            'lines_total' => $return->linesTotal(),
            'disposition' => $return->disposition,
            'warehouse_id' => $return->warehouse_id,
            'refund_amount' => $return->refund_amount,
            'exchange_order_id' => $return->exchange_order_id,
            'surcharge' => $return->surcharge,
            'received_at' => $return->received_at?->toIso8601String(),
            'completed_at' => $return->completed_at?->toIso8601String(),
            'allows' => $return->allows(),
            'created_at' => $return->created_at?->toIso8601String(),
            'updated_at' => $return->updated_at?->toIso8601String(),
        ];
    }
}
