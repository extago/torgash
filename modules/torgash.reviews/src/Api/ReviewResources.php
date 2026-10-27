<?php

namespace Torgash\Reviews\Api;

use Illuminate\Support\Facades\Validator;
use Torgash\Api\ApiResources;
use Torgash\Media\Gallery;
use Torgash\Reviews\Models\Review;
use Torgash\Reviews\Moderate;
use Torgash\Reviews\Photos;
use Torgash\Reviews\PostReply;

/**
 * Reviews over the API — read them, moderate them, answer them (PLAN §32.3).
 *
 * The reason this is worth having is written down in §32.2: the owner's plan
 * is for an AI agent to read reviews and decide what they are worth. An agent
 * needs the whole review — the prose, the per-criterion scores, the photographs
 * and the thread — and it needs to be able to act on one without imitating what
 * the moderation screen does.
 *
 * Which is why the two acts are shaped as they are. **The status goes through
 * `update`, but `update` calls `Moderate`**, not the column: publishing or
 * rejecting a review changes the product's average in both directions, and a
 * caller that set `status` would leave the card on the product page quoting a
 * number nothing adds up to. **Answering is an ACTION**, for the same reason an
 * order's status is one in the API: a reply is not a field — it is a row with a
 * staff mark, an email to whoever wrote the review, and an announcement.
 *
 * No `lookup`: a review has no key another system knew it by. What it does have
 * is `external_id` — for a catalogue of reviews arriving from another engine —
 * and that is a filter here and the import's match key in the exim schema.
 */
class ReviewResources
{
    public function register(): void
    {
        ApiResources::register('admin.reviews', [
            'label' => 'Reviews',
            'description' => 'What customers said about the products, with their scores, photographs and the shop’s answers. Moderated through the same code the moderation screen runs, so publishing one really does move the product’s average.',
            'permission' => 'reviews.moderate',
            'query' => fn () => Review::query()->with(['product', 'user', 'ratings.criterion', 'replies', 'media']),
            'sortable' => ['id', 'created_at', 'updated_at', 'rating', 'helpful_yes'],
            'filters' => [
                'status' => [
                    'description' => 'pending | published | rejected. Several at once, comma-separated.',
                    'apply' => fn ($q, $v) => $q->whereIn('status', array_filter(array_map('trim', explode(',', (string) $v)))),
                ],
                'product_id' => ['apply' => fn ($q, $v) => $q->where('product_id', (int) $v)],
                'verified' => [
                    'description' => '1 for reviews by somebody who had bought the product.',
                    'apply' => fn ($q, $v) => $q->where('verified', filter_var($v, FILTER_VALIDATE_BOOL)),
                ],
                'external_id' => ['apply' => fn ($q, $v) => $q->where('external_id', $v)],
                // Replies are rows of the same table and share the two columns
                // a review is found by. They are LISTED by default rather than
                // hidden, because a moderation queue has to see them: a
                // customer's answer waits in the same queue a review does, and
                // a listing that quietly dropped it would leave it unpublished
                // for ever. A client that wants the reviews alone says so —
                // and each review carries its own answers in `replies`
                // whichever way this is set.
                //
                // Stated as a value rather than a flag, because a flag cannot
                // express a default: a filter runs only when it is passed, so
                // "off by default" would have meant "off only when somebody
                // asks for it off".
                'only' => [
                    'description' => 'reviews — the reviews alone, without the answers under them. replies — only the answers. Omit for both, which is what a moderation queue wants.',
                    'apply' => fn ($q, $v) => match ((string) $v) {
                        'reviews' => $q->reviews(),
                        'replies' => $q->whereNotNull('parent_id'),
                        default => $q,
                    },
                ],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'serialize' => fn (Review $r) => $this->review($r),
            'schema' => $this->schema(),
            'writable' => [
                'status' => ['type' => 'string', 'description' => 'pending | published | rejected. Applied through the module’s own Moderate, so the product’s average follows.'],
                'moderation_note' => ['type' => 'string|null', 'description' => 'The moderator’s own record of why. Left alone when absent.'],
            ],
            'update' => function (Review $review, array $data) {
                $validated = Validator::make($data, [
                    'status' => ['nullable', 'in:'.implode(',', Review::STATUSES)],
                    'moderation_note' => ['nullable', 'string', 'max:1000'],
                ])->validate();

                app(Moderate::class)->decide(
                    $review,
                    $validated['status'] ?? $review->status,
                    $validated['moderation_note'] ?? null,
                );
            },
            'actions' => [
                'reply' => [
                    'description' => 'Answer a review in public, as the shop — the same code the moderation screen runs. It badges the answer as the shop’s, publishes the review it answers (half a conversation on a page is worse than either half) and emails the person who wrote it.',
                    'body' => [
                        'body' => ['type' => 'string', 'description' => 'The answer, as everyone will read it. Plain text.', 'example' => 'Thank you — we have changed the packaging since.'],
                    ],
                    'apply' => function (Review $review, array $data) {
                        $validated = Validator::make($data, [
                            'body' => ['required', 'string', 'max:5000'],
                        ])->validate();

                        // The token's own user, exactly as the screen uses the
                        // signed-in one: a call IS somebody, so the answer is
                        // signed rather than left anonymous.
                        abort_unless($staff = auth()->user(), 403);

                        app(PostReply::class)->fromShop($review, $staff, $validated['body']);

                        if ($review->status !== Review::PUBLISHED) {
                            app(Moderate::class)->decide($review, Review::PUBLISHED);
                        }
                    },
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    protected function review(Review $review): array
    {
        return [
            'id' => (int) $review->id,
            'external_id' => $review->external_id,
            'product_id' => (int) $review->product_id,
            'product_sku' => $review->product?->sku,
            'parent_id' => $review->parent_id ? (int) $review->parent_id : null,
            'status' => $review->status,
            'author_name' => $review->author_name,
            'user_id' => $review->user_id ? (int) $review->user_id : null,
            'is_staff' => (bool) $review->is_staff,
            'verified' => (bool) $review->verified,
            'rating' => $review->rating === null ? null : (float) $review->rating,
            'recommend' => $review->recommend,
            'body' => $review->body,
            'pros' => $review->pros,
            'cons' => $review->cons,
            // By the criterion's CODE, never its id: a code means the same
            // thing in a shop the caller also talks to, and an id does not.
            'criteria' => $review->ratings
                ->filter(fn ($score) => $score->criterion)
                ->mapWithKeys(fn ($score) => [$score->criterion->code => (int) $score->value])
                ->all(),
            'helpful_yes' => (int) $review->helpful_yes,
            'helpful_no' => (int) $review->helpful_no,
            // Plain URLs, unlike an enquiry's attachments: a review photograph
            // is published under the review on the product page, so there is
            // nothing for a signature to authorise.
            'photos' => Photos::of($review)
                ->map(fn ($photo) => ['id' => (int) $photo->id, 'url' => Gallery::thumbUrl($photo, 1200)])
                ->values()->all(),
            'replies' => $review->replies->map(fn (Review $reply) => [
                'id' => (int) $reply->id,
                'author_name' => $reply->author_name,
                'is_staff' => (bool) $reply->is_staff,
                'body' => $reply->body,
                'created_at' => $reply->created_at?->toIso8601String(),
            ])->values()->all(),
            'moderation_note' => $review->moderation_note,
            'locale' => $review->locale,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        return [
            'id' => 'integer',
            'external_id' => ['type' => 'string|null', 'description' => 'What the system this review came from calls it. Null for one written here.'],
            'product_id' => 'integer',
            'product_sku' => 'string|null',
            'parent_id' => ['type' => 'integer|null', 'description' => 'Set on the shop’s own answers, which are rows of the same kind. Absent from the listing unless `replies=1`.'],
            'status' => ['type' => 'string', 'description' => 'pending | published | rejected. Only a published review counts towards the product’s average.'],
            'author_name' => ['type' => 'string', 'description' => 'A snapshot taken when it was written: a renamed customer does not silently re-sign what they said.'],
            'user_id' => 'integer|null',
            'is_staff' => 'boolean',
            'verified' => ['type' => 'boolean', 'description' => 'They had bought it when they wrote this. Frozen — a later cancellation does not retract the fact.'],
            'rating' => ['type' => 'number|null', 'description' => '1 to 5. Computed from the per-criterion scores when the shop rates by criteria.'],
            'recommend' => ['type' => 'string|null', 'description' => 'yes | neutral | no.'],
            'body' => 'string|null',
            'pros' => 'string|null',
            'cons' => 'string|null',
            'criteria' => ['type' => 'object', 'description' => 'Score per criterion, keyed by the criterion’s CODE — 1 to 5.'],
            'helpful_yes' => ['type' => 'integer', 'description' => 'How many readers said this review helped them.'],
            'helpful_no' => 'integer',
            'photos' => ['type' => 'array', 'description' => 'Photographs sent with the review. Public URLs: they are published under the review anyway.', 'of' => [
                'id' => 'integer', 'url' => 'string',
            ]],
            'replies' => ['type' => 'array', 'description' => 'The public thread under this review, oldest first.', 'of' => [
                'id' => 'integer', 'author_name' => 'string', 'is_staff' => 'boolean', 'body' => 'string', 'created_at' => 'string',
            ]],
            'moderation_note' => 'string|null',
            'locale' => 'string|null',
            'created_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
        ];
    }
}
