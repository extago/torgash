<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'bool', 'cases' => 'array'];
    }

    /** The per-request memo of every language's cases must not outlive a save. */
    protected static function booted(): void
    {
        static::saved(fn () => app(\App\Core\Support\GrammaticalCases::class)->flush());
        static::deleted(fn () => app(\App\Core\Support\GrammaticalCases::class)->flush());
        // ...nor the memo of the table itself (the storefront's pickers).
        static::saved(fn () => app(\App\Core\Support\Languages::class)->flush());
        static::deleted(fn () => app(\App\Core\Support\Languages::class)->flush());
    }
}
