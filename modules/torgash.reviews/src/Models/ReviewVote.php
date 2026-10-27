<?php

namespace Torgash\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reader's answer to "was this review helpful?".
 *
 * A row rather than only a counter, because the counter cannot say WHO: it is
 * the row that stops one person voting fifty times, and that lets them change
 * their mind or take it back. The counter beside it on the review exists for
 * the other question — "most helpful first" over a paginated list is an ORDER
 * BY on a column.
 */
class ReviewVote extends Model
{
    protected $table = 'm_reviews_votes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'review_id' => 'int',
            'user_id' => 'int',
            'helpful' => 'bool',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class, 'review_id');
    }
}
