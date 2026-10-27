<?php

namespace Torgash\Reviews\Models;

use Illuminate\Database\Eloquent\Model;

/** Which categories a criterion is asked in — see Criterion::categories(). */
class CriterionCategory extends Model
{
    protected $table = 'm_reviews_criterion_category';

    public $timestamps = false;

    protected $fillable = ['criterion_id', 'category_id'];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Review criterion category';
    }

    protected function casts(): array
    {
        return ['criterion_id' => 'int', 'category_id' => 'int'];
    }
}
