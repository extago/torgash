<?php

namespace Torgash\Wishlist\Models;

use App\Models\Storefront;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One customer's (or one guest's) favourites. See the wishlist migrations. */
class WishList extends Model
{
    protected $table = 'm_wishlist_lists';

    protected $fillable = ['token', 'user_id', 'share_token', 'notify_price', 'notify_stock', 'last_active_at', 'locale', 'storefront_id'];

    protected $casts = [
        'notify_price' => 'boolean',
        'notify_stock' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Wishlist';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WishItem::class, 'list_id');
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }
}
