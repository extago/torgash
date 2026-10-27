<?php

namespace Torgash\Guard\TwoFactor;

use App\Models\User;

/**
 * Who is offered a second factor, and of whom it is demanded.
 *
 * The owner's question, answered as three global settings per audience rather
 * than as one: **off** (the feature does not exist for these people — no page,
 * no menu item, nothing to switch on), **offered** (they may turn it on
 * themselves, and it is demanded of the groups the shop ticked), **required**
 * (of every one of them).
 *
 * The reason the group list hangs off "offered" instead of being a fourth
 * option is that the two would otherwise contradict each other on screen: a
 * shop that has chosen "required of everybody" and also ticked two groups has
 * said something that cannot be read one way. So the list is only ever read in
 * the middle mode, where it means "…and of these, it is not optional" — and
 * "required" means what the word says.
 *
 * Staff and customers are separate axes with the same shape, and their defaults
 * differ on purpose. Staff are offered it out of the box: they hold the shop's
 * catalogue, its orders and its customers' addresses, and one guessed password
 * is the whole of it. Customers are off out of the box, because an account area
 * that grows a security page nobody asked for is a support question, and the
 * shop can turn it on the day it wants to.
 *
 * Requiring it never locks anybody out. A person who must have one and has none
 * is sent to the enrolment page and nowhere else, which is the same journey
 * they would have taken voluntarily — see `RequireTwoFactor`. The thing that
 * CAN lock a shop out is a lost phone, and the answer to that is the recovery
 * codes plus `php artisan guard:two-factor`.
 */
class Requirement
{
    /** Not on offer at all: no page, no menu item, no demand. */
    public const OFF = 'off';

    /** On offer, and demanded of the groups the shop named. */
    public const OFFERED = 'offered';

    /** Demanded of everybody in this audience. */
    public const REQUIRED = 'required';

    /**
     * Is this person staff? "Has at least one role" — never "has the admin
     * role", which is this project's standing rule (the first manager created
     * would otherwise land in the customer list).
     *
     * The RELATION rather than `roles()->exists()`, and that is about cost: this
     * is asked by a middleware on every request in the `web` group, and the
     * relation is loaded once and then cached on the user object the auth guard
     * is already holding for this request. A fresh `exists()` query per call
     * would be several per page. Not memoised in this singleton either — a
     * singleton outlives the request under a persistent worker, and a cached
     * "is staff" would then answer for whoever came before.
     */
    public function isStaff(User $user): bool
    {
        return $user->roles->isNotEmpty();
    }

    /** What the shop has decided about this person's audience. */
    public function mode(User $user): string
    {
        $staff = $this->isStaff($user);
        $mode = (string) setting(
            'torgash.guard',
            $staff ? 'two_factor_staff' : 'two_factor_customers',
            $staff ? self::OFFERED : self::OFF,
        );

        return in_array($mode, [self::OFF, self::OFFERED, self::REQUIRED], true) ? $mode : self::OFF;
    }

    /** Is there a security page for this person at all? */
    public function offered(User $user): bool
    {
        return $this->mode($user) !== self::OFF;
    }

    /** Must they have one before they can do anything else? */
    public function required(User $user): bool
    {
        return match ($this->mode($user)) {
            self::REQUIRED => true,
            self::OFFERED => $this->inNamedGroup($user),
            default => false,
        };
    }

    /**
     * The groups the shop singled out — permission groups for staff, customer
     * groups for everybody else. Two different lists because they are two
     * different questions: "the people who can edit prices" and "the wholesale
     * accounts with a credit line" are both worth demanding this of, and
     * neither is expressible in the other's vocabulary.
     */
    protected function inNamedGroup(User $user): bool
    {
        if ($this->isStaff($user)) {
            $named = array_map('strval', (array) setting('torgash.guard', 'two_factor_staff_groups', []));

            return $named !== [] && $user->roles->contains(fn ($role) => in_array((string) $role->id, $named, true));
        }

        $named = array_map('strval', (array) setting('torgash.guard', 'two_factor_customer_groups', []));

        return $named !== [] && in_array((string) $user->customer_group_id, $named, true);
    }
}
