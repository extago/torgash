<?php

namespace Torgash\Reviews\Invariants;

use Illuminate\Support\Facades\DB;
use Torgash\Reviews\Group;
use Torgash\Reviews\Models\Review;

/**
 * What must be true about the marks, said by the module that keeps them.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the guard
 * being installed.
 *
 * Both statements are about a number stored beside the rows it is the sum of —
 * the same family as `warehouses.total_matches_warehouses` and
 * `promotions.usage_within_limit`, and there for the same reason: a
 * denormalised counter is only ever as right as the last code path that
 * remembered to move it, and the one that forgot does not announce itself.
 *
 * The consequence is not cosmetic. The average is what a product card shows,
 * what "best rated first" sorts by and what goes out as `aggregateRating` in
 * JSON-LD — so a stale number is a claim the shop makes to a search engine
 * about a product, with the reviews to disprove it sitting one click away.
 *
 * Both are ONE query each with a limit, because this runs over the whole
 * catalogue on a timer.
 */
class ReviewInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'reviews.aggregate_matches_reviews' => [
                'label' => 'A product\'s stored mark is the reviews it actually has',
                'explain' => 'The average and the number of reviews are kept in a row of their own, recomputed whenever a review is published, rejected or deleted. This one no longer agrees with the published reviews behind it — a card, a "best rated" listing and the structured data a search engine reads are all showing a number the reviews do not support. "Recount the averages" on the reviews screen puts it right.',
                // A union of both sides, because drift has two shapes: a row
                // whose count is stale, and a row for a product whose last
                // published review has gone (recalculate() deletes the row, so
                // one left behind means something deleted rows without it).
                // What a product SHOULD carry is its reading's — its own
                // reviews, or its variation group's where they are shared
                // (Group) — so the reviews are counted per reading `k`.
                'check' => fn (int $limit) => collect(DB::select(<<<'SQL'
                    WITH scope AS (
                        SELECT p.id AS product_id, p.sku,
                               CASE WHEN ? AND btrim(COALESCE(p.variation_group, '')) <> ''
                                    THEN 'g:' || p.variation_group ELSE 'p:' || p.id END AS k
                          FROM m_catalog_products p
                    ), counted AS (
                        SELECT s.k,
                               count(*) AS n,
                               avg(r.rating) FILTER (WHERE r.rating IS NOT NULL) AS avg_rating
                          FROM m_reviews_reviews r
                          JOIN scope s ON s.product_id = r.product_id
                         WHERE r.parent_id IS NULL AND r.status = ?
                         GROUP BY s.k
                    )
                    SELECT s.product_id,
                           s.sku,
                           COALESCE(a.reviews_count, 0) AS stored_count,
                           COALESCE(c.n, 0)             AS actual_count,
                           COALESCE(a.rating, 0)        AS stored_rating,
                           COALESCE(ROUND(c.avg_rating, 1), 0) AS actual_rating
                      FROM scope s
                      LEFT JOIN counted c ON c.k = s.k
                      LEFT JOIN m_reviews_aggregates a ON a.product_id = s.product_id
                     WHERE (a.product_id IS NOT NULL OR c.n IS NOT NULL)
                       AND (COALESCE(a.reviews_count, 0) <> COALESCE(c.n, 0)
                        -- A tolerance, not equality: the column is one decimal
                        -- place and the average is not, so 4.25 stored as 4.3
                        -- is agreement and not drift.
                        OR abs(COALESCE(a.rating, 0) - COALESCE(ROUND(c.avg_rating, 1), 0)) > 0.05)
                     ORDER BY s.product_id
                     LIMIT ?
                SQL, [Group::shared(), Review::PUBLISHED, $limit]))
                    ->map(fn ($row) => [
                        'id' => (string) $row->product_id,
                        'title' => $row->sku ?: '#'.$row->product_id,
                        'detail' => [
                            'Stored :storedCount review(s) at :storedRating; the reviews say :actualCount at :actualRating.',
                            [
                                'storedCount' => $row->stored_count,
                                'storedRating' => $row->stored_rating,
                                'actualCount' => $row->actual_count,
                                'actualRating' => $row->actual_rating,
                            ],
                        ],
                        'url' => route('admin.reviews.index', ['status' => Review::PUBLISHED]),
                    ]),
            ],

            'reviews.vote_counts_match' => [
                'severity' => 'warning',
                'label' => '"Was this helpful" counts the votes that were cast',
                'explain' => 'A vote is a row (so one person cannot vote fifty times and can change their mind) and the counter beside the review is what "most helpful first" orders by, since a subquery per row is what a paginated list cannot afford. They have drifted, so the order of the list is not the order of the votes. Most often an import that wrote the counters directly — those are this shop\'s own readers\' findings and are never imported.',
                'check' => fn (int $limit) => collect(DB::select(<<<'SQL'
                    SELECT r.id,
                           r.product_id,
                           r.helpful_yes,
                           r.helpful_no,
                           COALESCE(v.yes, 0) AS actual_yes,
                           COALESCE(v.no, 0)  AS actual_no
                      FROM m_reviews_reviews r
                      LEFT JOIN (
                            SELECT review_id,
                                   count(*) FILTER (WHERE helpful)     AS yes,
                                   count(*) FILTER (WHERE NOT helpful) AS no
                              FROM m_reviews_votes
                             GROUP BY review_id
                           ) v ON v.review_id = r.id
                     WHERE r.helpful_yes <> COALESCE(v.yes, 0)
                        OR r.helpful_no  <> COALESCE(v.no, 0)
                     ORDER BY r.id
                     LIMIT ?
                SQL, [$limit]))
                    ->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => '#'.$row->id,
                        'detail' => [
                            'Counter says :storedYes/:storedNo, the votes are :actualYes/:actualNo.',
                            [
                                'storedYes' => $row->helpful_yes,
                                'storedNo' => $row->helpful_no,
                                'actualYes' => $row->actual_yes,
                                'actualNo' => $row->actual_no,
                            ],
                        ],
                        'url' => route('admin.reviews.index', ['status' => Review::PUBLISHED]),
                    ]),
            ],
        ];
    }
}
