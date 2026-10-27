<?php

namespace Torgash\Locations\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    use HasTranslations;

    protected $table = 'm_locations_states';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Region';
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'bool', 'edited' => 'bool'];
    }

    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }
}
