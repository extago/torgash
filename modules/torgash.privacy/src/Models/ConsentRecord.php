<?php

namespace Torgash\Privacy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One consent given (PLAN §49): who, when, to what, under which words. */
class ConsentRecord extends Model
{
    protected $table = 'm_privacy_consents';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['categories' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
