<?php

namespace App\Core\Privacy;

use App\Models\User;

/**
 * Whose personal data is being exported or erased (PLAN §49): an account, and
 * the contacts that name the same person where no account is attached — a
 * guest's orders and enquiries are filed under an email or a phone, not a user.
 */
class Subject
{
    /**
     * @param  list<string>  $emails  lower-cased
     * @param  list<string>  $phones  digits only
     */
    public function __construct(
        public readonly ?User $user = null,
        public readonly array $emails = [],
        public readonly array $phones = [],
    ) {}

    public static function of(?User $user, ?string $email = null, ?string $phone = null): self
    {
        $emails = array_filter([$user?->email, $email]);
        $phones = array_filter([$user?->phone, $phone]);

        return new self(
            $user,
            array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim((string) $e)), $emails))),
            array_values(array_unique(array_filter(array_map(fn ($p) => preg_replace('/\D/', '', (string) $p), $phones)))),
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->user && $this->emails === [] && $this->phones === [];
    }

    /**
     * Rows of $query that belong to this person: the account's own, or a row
     * with one of the contacts in the named columns. Phones compare by their
     * last nine digits — "+380 67 123-45-67" and "067 1234567" are one number.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function scope($query, ?string $userColumn = 'user_id', ?string $emailColumn = 'email', ?string $phoneColumn = 'phone')
    {
        return $query->where(function ($q) use ($userColumn, $emailColumn, $phoneColumn) {
            $any = false;

            if ($userColumn && $this->user) {
                $q->orWhere($userColumn, $this->user->id);
                $any = true;
            }

            if ($emailColumn && $this->emails) {
                // Column names are the caller's constants, never input.
                $q->orWhereRaw('lower('.$emailColumn.') in ('.implode(',', array_fill(0, count($this->emails), '?')).')', $this->emails);
                $any = true;
            }

            if ($phoneColumn) {
                foreach ($this->phones as $phone) {
                    if (strlen($phone) >= 7) {
                        $q->orWhereRaw("right(regexp_replace(coalesce({$phoneColumn}, ''), '\\D', '', 'g'), 9) = ?", [substr($phone, -9)]);
                        $any = true;
                    }
                }
            }

            // Nothing to match on matches nothing — never everything.
            if (! $any) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}
