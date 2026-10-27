<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'customer_group_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The language this person reads the shop in (`locale`, written by
     * App\Core\Storefronts\CustomerLocale when they switch the storefront's
     * language) — and so the one a letter addressed to them is written in.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale ?: null;
    }

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Account';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Not fillable on purpose: a phone is written by ContactChange
            // after its code came back, never from a form's own input.
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'admin_preferences' => 'array',
        ];
    }

    /**
     * `core.user.deleting` — a module whose rows point at an account WITHOUT a
     * foreign key (a review keeps `user_id` bare) lets go of them here. Rows
     * with one are the constraint's business. Fired for staff and customers
     * alike: deleting an account is one act whichever screen it came from.
     */
    protected static function booted(): void
    {
        static::deleting(fn (User $user) => hook_action('core.user.deleting', ['user' => $user]));
    }

    /**
     * Staff and customers share this table; a role is what tells them apart.
     * NOT "has the admin role" — that was true only while `admin` was the one
     * role that existed, and the first manager created would have shown up in
     * the customer list (and in the customer export) the day a second one did.
     *
     * The Administrators screen enforces the other half: a staff account is
     * created with at least one group, so it never lands in neither list.
     */
    public function scopeStaff($query)
    {
        return $query->whereHas('roles');
    }

    /**
     * The same line for one account. The loaded relation, not a query: the
     * permission gate has usually loaded the roles already, and a storefront
     * page may ask several times (a closed shop, safe mode, a failed block).
     */
    public function isStaff(): bool
    {
        return $this->roles->isNotEmpty();
    }

    public function scopeCustomers($query)
    {
        return $query->whereDoesntHave('roles');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderByDesc('is_default')->orderByDesc('id');
    }

    public function defaultAddress(): ?Address
    {
        return $this->addresses->firstWhere('is_default', true) ?? $this->addresses->first();
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }
}
