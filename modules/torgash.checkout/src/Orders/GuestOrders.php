<?php

namespace Torgash\Checkout\Orders;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;

/**
 * Orders placed WITHOUT signing in that belong to an account by their contact.
 *
 * An order gets its `user_id` only from a signed-in buyer; one placed as a
 * guest stays nobody's even when its email is the account's own — and the
 * customer opened their account to find their order missing. Two ways close
 * that gap:
 *
 *  - the account PROVES a contact (`core.user.contact_confirmed`, from the
 *    model, whatever the door): the guest orders carrying that email or that
 *    phone are attached at once. Only a confirmed contact: otherwise anyone
 *    could register with a stranger's address and read the stranger's orders,
 *    delivery address included;
 *  - a manager sees, on the customer's card, the guest orders that share ANY
 *    of the account's contacts (confirmed or not, the number left for calls
 *    too) and attaches the ones that are theirs — a human is the authority.
 *
 * A phone matches by its last nine digits, the way `CustomerRef` already
 * recognises one buyer across "+380 67 …" and "067…". The order's
 * `customer_ref` is NOT rewritten: it is frozen on purpose — an exchange has
 * already recorded the counterparty by it.
 */
class GuestOrders
{
    /**
     * Guest orders carrying one of these contacts.
     *
     * @param  list<string|null>  $emails
     * @param  list<string|null>  $phones
     */
    public function query(array $emails, array $phones): ?Builder
    {
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), $emails))));
        $phones = array_values(array_unique(array_filter(array_map(fn ($p) => $this->tail((string) $p), $phones))));

        if ($emails === [] && $phones === []) {
            return null;
        }

        return Order::query()
            ->whereNull('user_id')
            ->where(function (Builder $where) use ($emails, $phones) {
                if ($emails !== []) {
                    $where->orWhereIn(DB::raw('lower(email)'), $emails);
                }

                if ($phones !== []) {
                    $where->orWhereIn(DB::raw("right(regexp_replace(coalesce(phone, ''), '\\D', '', 'g'), 9)"), $phones);
                }
            });
    }

    /** What a manager is offered on the customer's card: every contact the account has. */
    public function candidates(User $user, int $limit = 20): Collection
    {
        return $this->query([$user->email], [$user->phone, $user->contact_phone])
            ?->latest()->limit($limit)->get() ?? collect();
    }

    /**
     * Attach the guest orders carrying the contacts this account has just
     * proved. Returns how many were attached.
     *
     * @param  list<string>  $kinds  'email' | 'phone'
     */
    public function attachConfirmed(User $user, array $kinds): int
    {
        // A manager's test order placed as a guest is not their purchase.
        if ($user->isStaff()) {
            return 0;
        }

        $query = $this->query(
            in_array('email', $kinds, true) && $user->email_verified_at ? [$user->email] : [],
            in_array('phone', $kinds, true) && $user->phone_verified_at ? [$user->phone] : [],
        );

        return $query ? $this->attach($user, $query->get(), null) : 0;
    }

    /**
     * Give these orders to the account, each with a line in its history.
     * An order that has an owner already is left alone — whatever was
     * offered a moment ago, it is not a guest's any more.
     *
     * @param  iterable<Order>  $orders
     */
    public function attach(User $user, iterable $orders, ?User $by): int
    {
        $count = 0;

        foreach ($orders as $order) {
            DB::transaction(function () use ($user, $order, $by, &$count) {
                $fresh = Order::query()->lockForUpdate()->find($order->getKey());

                if (! $fresh || $fresh->user_id !== null) {
                    return;
                }

                $fresh->forceFill(['user_id' => $user->getKey()])->save();
                OrderLog::record($fresh, $by
                    ? 'Attached to the account :account'
                    : 'Attached to the account :account: it confirmed this order\'s contact', ['account' => $user->email ?: ($user->phone ?: '#'.$user->getKey())], $by?->getKey());
                $count++;
            });
        }

        return $count;
    }

    protected function tail(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}
