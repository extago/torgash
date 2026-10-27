<?php

namespace Torgash\Enquiries\Api;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Spatie\MediaLibrary\HasMedia;
use Torgash\Api\ApiResources;
use Torgash\Enquiries\Attachments;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;
use Torgash\Enquiries\PostMessage;
use Torgash\Enquiries\Status;

/**
 * The inbox over the API — read it, answer it, dispose of it.
 *
 * No `lookup`: unlike a product's sku or an order's number, an enquiry has no
 * key another system would have known it by before this API told it the id.
 *
 * Disposing of one stays a plain `update`: status, manager and notes are a
 * single field-set, not a transition with side effects. ANSWERING is an action,
 * for exactly the reason an order's status is one — a reply is not a column. It
 * ends the wait, moves a new enquiry into the queue, gives it an owner and
 * emails the person, and `Torgash\Enquiries\PostMessage` is where all of that
 * lives; so a CRM (or an agent) answering through this does what the manager's
 * own box does, rather than a convincing imitation of it.
 */
class EnquiryResources
{
    public function register(): void
    {
        ApiResources::register('admin.enquiries', [
            'label' => 'Enquiries',
            'description' => 'Everything a visitor asked — a callback, a product question, a message. Read in full; dispositioned by the one act the admin screen offers (status, manager, notes), never by rewriting the answers themselves.',
            'permission' => 'enquiries.manage',
            // The thread comes with the row: anything reading an enquiry wants
            // what was said, and eager-loading is what keeps a page of them one
            // query rather than one per row.
            'query' => fn () => Enquiry::query()->with(['manager', 'user', 'messages', 'lastMessage']),
            'sortable' => ['id', 'created_at', 'updated_at', 'answered_at'],
            'filters' => [
                // A LIST, comma-separated, because the question a queue asks
                // is "anything not finished" — the same set the inbox opens on,
                // and one value cannot say it.
                'status' => [
                    'description' => 'new | in_progress | done | rejected. Several at once, comma-separated: status=new,in_progress.',
                    'apply' => fn ($q, $v) => $q->whereIn('status', array_filter(array_map('trim', explode(',', (string) $v)))),
                ],
                'kind' => ['apply' => fn ($q, $v) => $q->where('kind', $v)],
                'subject_type' => ['apply' => fn ($q, $v) => $q->where('subject_type', $v)],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
                // The one question a client that ANSWERS enquiries actually
                // asks. Not status=new: the shop replying leaves an enquiry in
                // progress, and a customer writing back to a finished one
                // reopens it — either way the queue is "who spoke last".
                'awaiting_reply' => [
                    'description' => '1 for the open enquiries the shop owes an answer to: nothing said yet, or the customer spoke last. This is the queue, not status=new.',
                    'apply' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOL)
                        ? $q->awaitingReply()
                        : $q,
                ],
            ],
            'serialize' => fn (Enquiry $e) => $this->enquiry($e),
            'writable' => [
                'status' => ['type' => 'string', 'description' => 'One of the fixed statuses — new, in_progress, done, rejected.'],
                'manager_id' => ['type' => 'integer|null', 'description' => 'Who is dealing with it.'],
                'notes' => ['type' => 'string|null'],
            ],
            'schema' => $this->schema(),
            'actions' => [
                'reply' => [
                    'description' => 'Answer the person who asked — the same code the box on the enquiry screen runs. It stamps when the wait ended, moves a new enquiry into progress, gives an ownerless one to whoever answered, and emails the customer. Writing a message row directly would have done none of that.',
                    'body' => [
                        'body' => ['type' => 'string', 'description' => 'The answer, as the customer will read it. Plain text.', 'example' => 'We have it back in stock — shall we hold one for you?'],
                    ],
                    'apply' => function (Enquiry $enquiry, array $data) {
                        $validated = Validator::make($data, [
                            'body' => ['required', 'string', 'max:5000'],
                        ])->validate();

                        // The token's own user, exactly as the screen uses the
                        // logged-in one: a call IS somebody, so the answer is
                        // signed with a name rather than left anonymous.
                        abort_unless($staff = auth()->user(), 403);

                        app(PostMessage::class)->fromStaff($enquiry, $staff, $validated['body']);
                    },
                ],
                'close' => [
                    'description' => 'End the conversation: the enquiry is marked answered and locked, and after that neither the customer nor the shop can add to it. Reopening is a deliberate act on the enquiry screen — which is the point, since what this prevents is somebody resurrecting a year-old thread on a context nobody remembers.',
                    'body' => [],
                    'apply' => function (Enquiry $enquiry) {
                        $enquiry->status = Status::DONE;
                        $enquiry->answered_at ??= now();
                        $enquiry->locked_at = now();
                        $enquiry->save();
                    },
                ],
            ],
            // Same act the admin form performs in one save — see
            // EnquiriesController::update() — including the answered_at rule:
            // stamped by the FIRST move into a finished state, never moved by
            // a later correction.
            'update' => function (Enquiry $enquiry, array $data) {
                $enquiry->fill(array_intersect_key($data, array_flip(['status', 'manager_id', 'notes'])));

                if (! Status::isOpen($enquiry->status) && ! $enquiry->answered_at) {
                    $enquiry->answered_at = now();
                }

                $enquiry->save();
            },
        ]);
    }

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        return [
            'id' => 'integer',
            'kind' => ['type' => 'string', 'description' => 'callback | product_question | back_in_stock | message, or a kind another module registered.'],
            'kind_label' => 'string',
            'status' => ['type' => 'string', 'description' => 'new | in_progress | done | rejected — a fixed vocabulary, moved through this resource’s own `update`, never a pipeline of the shop’s own.'],
            'name' => 'string|null',
            'phone' => 'string|null',
            'email' => 'string|null',
            'subject' => ['type' => 'object|null', 'description' => 'What the enquiry is about, when it is about anything.', 'of' => [
                'type' => 'string', 'id' => 'integer', 'label' => 'string', 'url' => 'string|null',
            ]],
            'answers' => ['type' => 'array', 'description' => 'What was asked and answered, under the labels the form showed at the time — a renamed field does not change how an old enquiry reads.', 'of' => [
                'key' => 'string', 'label' => 'string', 'value' => 'string',
            ]],
            'cart' => ['type' => 'array|null', 'description' => 'What was in the basket at the moment of asking, frozen.'],
            'customer_ref' => ['type' => 'string|null', 'description' => 'The same reference an order from this person carries, so a CRM can treat the two as one counterparty.'],
            'user_id' => 'integer|null',
            'locale' => 'string|null',
            'manager' => ['type' => 'object|null', 'of' => ['id' => 'integer', 'name' => 'string']],
            'notes' => 'string|null',
            'answered_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'awaiting_reply' => ['type' => 'boolean', 'description' => 'The shop owes an answer: the enquiry is open, and either nothing has been said yet or the customer spoke last.'],
            'can_be_answered' => ['type' => 'boolean', 'description' => 'Whether a reply can reach the person: they left an email or have an account. False for a phone-only callback — a reply is then only a line in the call log the shop keeps for itself.'],
            'locked' => ['type' => 'boolean', 'description' => 'The conversation is over: nobody, the shop included, can add to it. Set by the `close` action, and by rejecting an enquiry.'],
            'attachments' => ['type' => 'array', 'description' => 'Photographs sent with the enquiry itself. Each URL is signed and expires with nothing — the files are not on a public disk, and the signature is what authorises reading one.', 'of' => [
                'id' => 'integer', 'name' => 'string', 'url' => 'string',
            ]],
            'messages' => ['type' => 'array', 'description' => 'The conversation, oldest first. The enquiry itself is its opening — what the person typed is in `answers` and is not repeated here as the first message.', 'of' => [
                'id' => 'integer', 'author' => 'string', 'author_name' => 'string', 'body' => 'string',
                'attachments' => 'array', 'created_at' => 'string',
            ]],
            'created_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
        ];
    }

    /**
     * Photographs on a row, as SIGNED links.
     *
     * Not a path and not a public URL: attachments live off the public disk on
     * purpose (somebody's kitchen table is not a catalogue photo), so the only
     * way to hand one to a caller is a link that authorises itself. The same
     * link works for a person, which is what makes it useful in a CRM.
     *
     * @return list<array<string, mixed>>
     */
    protected function files(HasMedia $owner): array
    {
        return Attachments::of($owner)->map(fn ($media) => [
            'id' => $media->id,
            'name' => $media->name,
            'url' => URL::signedRoute('enquiries.file', ['media' => $media->id]),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    protected function enquiry(Enquiry $enquiry): array
    {
        $subject = $enquiry->subject();

        return [
            'id' => $enquiry->id,
            'kind' => $enquiry->kind,
            'kind_label' => $enquiry->kindLabel(),
            'status' => $enquiry->status,
            'name' => $enquiry->name,
            'phone' => $enquiry->phone,
            'email' => $enquiry->email,
            'subject' => $subject ? [
                'type' => $enquiry->subject_type,
                'id' => $enquiry->subject_id,
                'label' => $subject['label'],
                'url' => $subject['url'],
            ] : null,
            'answers' => $enquiry->answers(),
            'cart' => $enquiry->cart,
            'customer_ref' => $enquiry->customer_ref,
            'user_id' => $enquiry->user_id,
            'locale' => $enquiry->locale,
            'manager' => $enquiry->manager ? ['id' => $enquiry->manager->id, 'name' => $enquiry->manager->name] : null,
            'notes' => $enquiry->notes,
            'answered_at' => $enquiry->answered_at?->toIso8601String(),
            'awaiting_reply' => $enquiry->isAwaitingReply(),
            'can_be_answered' => $enquiry->canBeAnswered(),
            'locked' => $enquiry->isLocked(),
            'attachments' => $this->files($enquiry),
            // 'shop' / 'customer' rather than the column's boolean: a name is
            // what a reader of the payload understands without a legend.
            'messages' => $enquiry->messages->map(fn (Message $m) => [
                'id' => $m->id,
                'author' => $m->is_staff ? 'shop' : 'customer',
                'author_name' => $m->authorName(),
                'body' => $m->body,
                'attachments' => $this->files($m),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
            'created_at' => $enquiry->created_at?->toIso8601String(),
            'updated_at' => $enquiry->updated_at?->toIso8601String(),
        ];
    }
}
