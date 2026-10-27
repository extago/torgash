<?php

namespace Torgash\Reviews\Exim;

use Illuminate\Support\Carbon;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Aggregates;
use Torgash\Reviews\Models\Review;

/**
 * Reviews in a spreadsheet (PLAN §32.3).
 *
 * The export is the obvious half: a shop wants its reviews in a file for a
 * marketplace feed, for a report, or to read them somewhere other than a
 * screen thirty at a time.
 *
 * The IMPORT is the half worth explaining, because it is the one a shop needs
 * exactly once and cannot do without: a catalogue moving here from another
 * engine brings its reviews with it, and a product page that arrives with no
 * reviews after years of collecting them looks like a shop nobody buys from.
 * So the row is matched on `external_id` — what the OTHER system calls it
 * (§58's rule) — which is what makes the import repeatable rather than a
 * one-shot that duplicates everything on a second run.
 *
 * Three things the import deliberately does NOT do:
 *
 * - **It writes no votes.** "Fourteen people found this helpful" is a claim
 *   about this shop's readers, and importing the number would invent them.
 * - **It cannot make a review verified.** That mark means "this person had
 *   bought it HERE when they wrote this", worked out at submission from this
 *   shop's own orders. A file saying so would be the shop marking its own
 *   homework, and the badge is the thing a reader believes most.
 * - **It carries no photographs.** They are files, not cells; a review
 *   arriving without its pictures is a review, and a column of URLs that the
 *   importer fetched would be a downloader nobody asked for.
 *
 * The average is recomputed per product at the end rather than per row: an
 * import of ten thousand reviews would otherwise recount a product once per
 * review it has.
 */
class ReviewSchema
{
    public function register(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + ['review' => 'Review']);

        hook_listen('exim.query.review', function ($v, array $ctx) {
            // Reviews proper, never the shop's own replies: they share the two
            // columns a review is found by, and a file listing them as peers
            // would read as the shop reviewing itself.
            $query = Review::query()->reviews()->with(['product', 'user']);

            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->published();
            }

            return $query;
        });

        hook_listen('exim.match.review', function ($v, array $ctx) {
            $external = trim((string) ($ctx['row']['external_id'] ?? ''));
            $existing = $external === '' ? null : Review::where('external_id', $external)->first();

            return ['model' => $existing ?? new Review, 'matchedBy' => $existing ? 'external_id' : null];
        });

        hook_listen('exim.schema.review', fn (array $fields) => [...$fields, ...$this->fields()]);

        // Every product the FILE was about, counted once, after the whole
        // import rather than per row: a `saved` listener would recount a
        // popular product once per review it has. Read from the rows the hook
        // hands over, never from an accumulator — a static would outlive an
        // import that failed half way and recount somebody else's products on
        // the next one (the rule this engine states about per-request memos).
        hook_listen('exim.imported', function (array $context) {
            if (($context['entity'] ?? null) !== 'review') {
                return;
            }

            $skus = collect($context['rows'] ?? [])
                ->map(fn ($entry) => trim((string) ($entry['row']['product_sku'] ?? '')))
                ->filter()
                ->unique();

            if ($skus->isEmpty()) {
                return;
            }

            foreach (Product::whereIn('sku', $skus->all())->pluck('id') as $productId) {
                app(Aggregates::class)->recalculate((int) $productId);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    protected function fields(): array
    {
        return [
            // Matched on FIRST and written here, which is what lets an import
            // be run twice without doubling the shop's reviews.
            ['key' => 'external_id', 'label' => 'External ID', 'get' => fn (Review $r) => $r->external_id, 'set' => fn (Review $r, $v) => $r->external_id = (trim((string) $v) ?: null)],
            // By SKU, like everything else that travels. A review of a product
            // this shop does not have is a row with nothing to attach to, so
            // an unknown SKU leaves it where it was rather than filing it
            // under whatever sorted first.
            ['key' => 'product_sku', 'label' => 'Product SKU', 'get' => fn (Review $r) => $r->product?->sku, 'set' => function (Review $r, $v) {
                $id = Product::where('sku', trim((string) $v))->value('id');

                if ($id) {
                    $r->product_id = $id;
                }
            }],
            ['key' => 'author_name', 'label' => 'Author', 'get' => fn (Review $r) => $r->author_name, 'set' => fn (Review $r, $v) => $r->author_name = (trim((string) $v) ?: __('Customer'))],
            // The account, when there is one — by EMAIL, because an id from
            // another system means nothing here. A review whose author has no
            // account in this shop keeps their name and loses the link, which
            // is exactly what an imported review is.
            ['key' => 'author_email', 'label' => 'Author email', 'get' => fn (Review $r) => $r->user?->email, 'set' => function (Review $r, $v) {
                $email = trim((string) $v);

                if ($email !== '') {
                    $r->user_id = \App\Models\User::where('email', $email)->value('id') ?? $r->user_id;
                }
            }],
            ['key' => 'rating', 'label' => 'Rating (1-5)', 'get' => fn (Review $r) => $r->rating, 'set' => fn (Review $r, $v) => $r->rating = ($v === '' || $v === null ? null : max(1, min(5, (float) $v)))],
            ['key' => 'recommend', 'label' => 'Recommends', 'get' => fn (Review $r) => $r->recommend, 'set' => fn (Review $r, $v) => $r->recommend = in_array($v, Review::RECOMMEND, true) ? $v : null],
            ['key' => 'body', 'label' => 'Review', 'get' => fn (Review $r) => $r->body, 'set' => fn (Review $r, $v) => $r->body = (trim((string) $v) ?: null)],
            ['key' => 'pros', 'label' => 'Pros', 'get' => fn (Review $r) => $r->pros, 'set' => fn (Review $r, $v) => $r->pros = (trim((string) $v) ?: null)],
            ['key' => 'cons', 'label' => 'Cons', 'get' => fn (Review $r) => $r->cons, 'set' => fn (Review $r, $v) => $r->cons = (trim((string) $v) ?: null)],
            ['key' => 'status', 'label' => 'Status', 'get' => fn (Review $r) => $r->status, 'set' => fn (Review $r, $v) => $r->status = in_array($v, Review::STATUSES, true) ? $v : Review::PENDING],
            // Export only, all three. `verified` is this shop's own finding
            // about its own orders; the votes are its own readers. A file
            // could only assert them, and the badge a reader believes most is
            // the one that must never be assertable.
            ['key' => 'verified', 'label' => 'Verified purchase (export only)', 'get' => fn (Review $r) => $r->verified, 'set' => null],
            ['key' => 'helpful_yes', 'label' => 'Found helpful (export only)', 'get' => fn (Review $r) => $r->helpful_yes, 'set' => null],
            ['key' => 'helpful_no', 'label' => 'Found unhelpful (export only)', 'get' => fn (Review $r) => $r->helpful_no, 'set' => null],
            // When it was written, which for an imported review is the one
            // thing a file has that this shop cannot work out. Empty leaves it
            // alone: a new row gets now, an existing one keeps what it had —
            // the same rule the product schema's `created_at` follows.
            ['key' => 'created_at', 'label' => 'Written at', 'get' => fn (Review $r) => $r->created_at?->toDateTimeString(), 'set' => function (Review $r, $v) {
                if (trim((string) $v) !== '') {
                    $r->created_at = Carbon::parse($v);
                }
            }],
            ['key' => 'locale', 'label' => 'Language', 'get' => fn (Review $r) => $r->locale, 'set' => fn (Review $r, $v) => $r->locale = (trim((string) $v) ?: null)],
        ];
    }
}
