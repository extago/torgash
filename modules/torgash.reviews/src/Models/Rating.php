<?php

namespace Torgash\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One criterion's score inside one review.
 *
 * Kept even when the criterion itself is deleted (no foreign key): the person
 * did give that score, and a shop tidying its list of criteria must not
 * silently rewrite what people said. It stops being printed, which is the
 * whole of what deleting a criterion should mean.
 */
class Rating extends Model
{
    protected $table = 'm_reviews_ratings';

    public $timestamps = false;

    protected $fillable = ['review_id', 'criterion_id', 'value'];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Review score';
    }

    protected function casts(): array
    {
        return ['review_id' => 'int', 'criterion_id' => 'int', 'value' => 'int'];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class, 'criterion_id');
    }
}
