<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'bool'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
