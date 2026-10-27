<?php

namespace Torgash\ShopReviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One criterion's score in one review. */
class Rating extends Model
{
    public $timestamps = false;

    protected $table = 'm_shopreviews_ratings';

    protected $fillable = ['review_id', 'criterion_id', 'value'];

    protected function casts(): array
    {
        return ['review_id' => 'int', 'criterion_id' => 'int', 'value' => 'int'];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class, 'criterion_id');
    }
}
