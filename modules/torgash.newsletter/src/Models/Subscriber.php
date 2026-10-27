<?php

namespace Torgash\Newsletter\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscriber extends Model
{
    protected $table = 'm_newsletter_subscribers';

    protected $guarded = [];

    public const PENDING = 'pending';

    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    public const SOURCES = ['block', 'cart', 'checkout', 'account', 'import', 'admin'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSubscribed(): bool
    {
        return $this->status === self::SUBSCRIBED;
    }
}
