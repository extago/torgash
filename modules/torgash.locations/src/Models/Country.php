<?php

namespace Torgash\Locations\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasTranslations;

    protected $table = 'm_locations_countries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'bool', 'edited' => 'bool'];
    }

    /** Joined by ISO code rather than by id — see the migration's docblock. */
    public function states(): HasMany
    {
        return $this->hasMany(State::class, 'country', 'code');
    }

    public function places(): HasMany
    {
        return $this->hasMany(Place::class, 'country', 'code');
    }
}
