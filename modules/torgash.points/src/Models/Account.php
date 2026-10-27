<?php

namespace Torgash\Points\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer's points balance — the sum of their ledger, kept to be listed and sorted. */
class Account extends Model
{
    protected $table = 'm_points_accounts';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance' => 'int'];
    }
}
