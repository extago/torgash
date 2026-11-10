<?php

namespace App\Core\Access;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * No other account holds this address, in any capitals. What
 * `Rule::unique('users', 'email')` compares is the text as typed, which would
 * let a capital through to the database's own refusal: a 500 instead of a
 * message. Compared with `lower()` on both sides, as the unique index is.
 */
final class EmailIsFree implements ValidationRule
{
    public function __construct(private readonly ?int $except = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = User::query()
            ->whereRaw('lower(email) = lower(?)', [trim((string) $value)])
            ->when($this->except, fn ($query) => $query->whereKeyNot($this->except))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
