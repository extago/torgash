<?php

namespace Torgash\Automations;

/**
 * Who made the change a rule was fired by — taken at the moment of the
 * change, because by the time the queued rule runs there is no request left
 * to ask: `{{ actor.name }}` is "Ivan", "the customer" or "the system".
 *
 * A staff member signed in is themselves. A request that carries a session
 * and no staff member is the customer's (a guest at checkout included). With
 * no session at all — a payment callback, a scheduled job, the console — it
 * is the system: a gateway confirming a payment is not the buyer.
 */
class Actor
{
    public const STAFF = 'staff';

    public const CUSTOMER = 'customer';

    public const SYSTEM = 'system';

    /** @return array{kind: string, name: string} */
    public static function now(): array
    {
        $user = auth()->user();

        if ($user && method_exists($user, 'isStaff') && $user->isStaff()) {
            return ['kind' => self::STAFF, 'name' => (string) $user->name];
        }

        if ($user || (! app()->runningInConsole() && request()->hasSession())) {
            return ['kind' => self::CUSTOMER, 'name' => __('the customer')];
        }

        return self::system();
    }

    /** @return array{kind: string, name: string} */
    public static function system(): array
    {
        return ['kind' => self::SYSTEM, 'name' => __('the system')];
    }
}
