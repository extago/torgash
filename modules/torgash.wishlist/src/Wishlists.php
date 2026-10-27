<?php

namespace Torgash\Wishlist;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Wishlist\Models\WishList;

/**
 * Whose list this is, and what is in it — the one place that knows.
 *
 * A signed-in visitor's list is the one row with their `user_id`; a guest's
 * is the row whose token their session holds, exactly as a basket is. Reading
 * never creates a row (the header counter asks on every page, and a row per
 * crawler is not a list); the first heart does. Signing in pours the guest's
 * list into the account's (`adopt()`), without duplicates, and the guest's row
 * goes — or, when the account has none yet, simply becomes the account's.
 *
 * A per-request singleton: `ids()` is asked by every heart on a page and must
 * be one query, not one per card.
 */
class Wishlists
{
    public const SESSION = 'wishlist_token';

    protected ?WishList $list = null;

    protected bool $looked = false;

    /** @var array<int, true>|null */
    protected ?array $ids = null;

    public function current(bool $create = false): ?WishList
    {
        if ($this->looked && ($this->list || ! $create)) {
            return $this->list;
        }

        $this->looked = true;
        $user = auth()->user();
        $token = session(self::SESSION);

        if ($user) {
            $this->list = WishList::where('user_id', $user->id)->first();

            // Signed in without passing through the login event this request
            // (remembered cookie, a session that started as a guest): the
            // guest's list still becomes theirs.
            if ($token && ($guest = WishList::where('token', $token)->whereNull('user_id')->first())) {
                $this->list = $this->merge($guest, $this->list, $user);
                session()->forget(self::SESSION);
            }
        } elseif ($token) {
            $this->list = WishList::where('token', $token)->whereNull('user_id')->first();
        }

        if (! $this->list && $create) {
            $this->list = WishList::create([
                'token' => WishList::newToken(),
                'user_id' => $user?->id,
                'last_active_at' => now(),
            ]);

            if (! $user) {
                session([self::SESSION => $this->list->token]);
            }
        }

        return $this->list;
    }

    /** @return array<int, true> product id => true */
    public function ids(): array
    {
        if ($this->ids === null) {
            $list = $this->current();
            $this->ids = $list
                ? DB::table('m_wishlist_items')->where('list_id', $list->id)->pluck('product_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all()
                : [];
        }

        return $this->ids;
    }

    public function has(Product|int $product): bool
    {
        return isset($this->ids()[is_int($product) ? $product : $product->id]);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /** Put it in or take it out; answers whether it is in now. */
    public function toggle(Product $product): bool
    {
        if ($this->has($product)) {
            $this->remove($product->id);

            return false;
        }

        $this->add($product);

        return true;
    }

    public function add(Product $product): void
    {
        $list = $this->current(create: true);

        DB::table('m_wishlist_items')->insertOrIgnore([
            'list_id' => $list->id,
            'product_id' => $product->id,
            'price_at_add' => $product->publicPrice(),
            'created_at' => now(),
        ]);

        $this->touch($list);
    }

    public function remove(int $productId): void
    {
        if ($list = $this->current()) {
            DB::table('m_wishlist_items')->where('list_id', $list->id)->where('product_id', $productId)->delete();
            $this->touch($list);
        }
    }

    public function clear(): void
    {
        if ($list = $this->current()) {
            DB::table('m_wishlist_items')->where('list_id', $list->id)->delete();
            $this->touch($list);
        }
    }

    /**
     * Somebody has just signed in: the list they built as a guest is theirs.
     * Called from the Login event, which fires for a new account too.
     */
    public function adopt(User $user): void
    {
        $token = session(self::SESSION);

        if ($token && ($guest = WishList::where('token', $token)->whereNull('user_id')->first())) {
            $this->merge($guest, WishList::where('user_id', $user->id)->first(), $user);
        }

        session()->forget(self::SESSION);
        $this->forget();
    }

    /** Forget what this request knew — the list changed under it. */
    public function forget(): void
    {
        $this->list = null;
        $this->looked = false;
        $this->ids = null;
    }

    protected function merge(WishList $guest, ?WishList $account, User $user): WishList
    {
        if (! $account) {
            $guest->forceFill(['user_id' => $user->id, 'last_active_at' => now()])->save();

            return $guest;
        }

        // Kept as the guest added them: the price they saw then is the price
        // a drop is measured from.
        DB::statement(
            'insert into m_wishlist_items (list_id, product_id, price_at_add, created_at)
             select ?, product_id, price_at_add, created_at from m_wishlist_items where list_id = ?
             on conflict (list_id, product_id) do nothing',
            [$account->id, $guest->id],
        );
        $guest->delete();
        $this->touch($account);

        return $account;
    }

    /**
     * The customer's consent to the two letters — theirs to give, so only
     * ever set from their own switch.
     */
    public function setNotify(bool $price, bool $stock): WishList
    {
        $list = $this->current(create: true);
        $list->forceFill(['notify_price' => $price, 'notify_stock' => $stock]);
        $this->touch($list);

        return $list;
    }

    /**
     * The address a list is shared by: issued when asked for, never before,
     * and replaced by a new one on `$renew` — the way to take back a link
     * that went further than intended.
     */
    public function share(bool $renew = false): WishList
    {
        $list = $this->current(create: true);

        if ($renew || ! $list->share_token) {
            $list->forceFill(['share_token' => WishList::newToken()])->save();
        }

        return $list;
    }

    /** Nobody reaches the list by a link any more. */
    public function unshare(): void
    {
        $this->current()?->forceFill(['share_token' => null])->save();
    }

    /**
     * Every write stamps WHO the list is kept for right now — the language
     * and the shop being browsed — because the letters about it are written
     * later, by a job with no request to ask (see the migration adding them).
     */
    protected function touch(WishList $list): void
    {
        $list->forceFill(array_filter([
            'last_active_at' => now(),
            'locale' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : app()->getLocale(),
            'storefront_id' => current_storefront()?->id,
        ], fn ($v) => $v !== null))->save();
        $this->ids = null;
    }
}
