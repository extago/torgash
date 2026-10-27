<?php

namespace App\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    use HasTranslations;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'is_default' => 'bool', 'discount_percent' => 'int'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
