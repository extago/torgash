<?php

namespace App\Core\Access;

use App\Core\Storefronts\LocaleResolver;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A member of staff looking at the storefront through a customer's eyes
 * (PLAN §70): to see what the customer is complaining of, or to place an
 * order for them over the phone.
 *
 * There is no key and no link. The button on the customer's card writes, into
 * the manager's own session, whom they are looking at; for the length of one
 * storefront request {@see ImpersonateCustomer} hands the guard that customer.
 * The session's identity is never touched, so there is no `Login` event (which
 * would pull a guest's basket and list into the account and move its language)
 * and the admin in the next tab is still the manager. Leaving is forgetting
 * one session key.
 *
 * Asked again on every request, never trusted from the moment of entering: a
 * manager who has lost the right, a customer who has been deleted or made
 * staff, and the view ends by itself on the next page.
 *
 * What is the customer's alone stays theirs, and the direction matters. A page
 * may be looked at unless its owner says otherwise
 * (`core.impersonation.closed`); a request that CHANGES something is refused
 * unless its owner says a manager may (`core.impersonation.writes`). A module
 * written next year then cannot subscribe somebody, or write a review in their
 * name, because nobody thought to forbid it. `core.impersonation.own` lists
 * the routes that stay the manager's whatever they are looking at: their own
 * second factor. All three are lists of route names, without the locale.
 *
 * One per request (`scoped()`, forgotten by PerRequest): it says who is who
 * for this request and for no other.
 */
class Impersonation
{
    /** `['manager' => id, 'customer' => id]` in the manager's session. */
    public const SESSION = 'impersonation';

    /** Flashed when something was refused: the strip says so on the next page. */
    public const REFUSED = 'impersonation_refused';

    public const PERMISSION = 'customers.impersonate';

    protected ?User $manager = null;

    protected ?User $customer = null;

    /** The button: from now on this session's storefront is that customer's. */
    public function start(Request $request, User $manager, User $customer): void
    {
        $this->stop($request);

        $request->session()->put(self::SESSION, [
            'manager' => $manager->getKey(),
            'customer' => $customer->getKey(),
        ]);

        hook_action('core.impersonation.started', ['manager' => $manager, 'customer' => $customer]);
    }

    /**
     * The middleware's half: this request is the customer's, if it still may
     * be. False leaves the request the manager's own.
     */
    public function enter(Request $request): bool
    {
        if ($this->routeIn($request, 'own')) {
            return false;
        }

        $state = (array) $request->session()->get(self::SESSION);

        $this->manager = $request->user();
        $this->customer = User::find($state['customer'] ?? null);

        if (! $this->stillAllowed($state)) {
            $this->stop($request);

            return false;
        }

        // Not login(): that writes the session's identity and announces a
        // sign-in. This lasts as long as the request does.
        Auth::setUser($this->customer);

        hook_action('core.impersonation.entered', ['customer' => $this->customer]);

        return true;
    }

    /** @param array<string, mixed> $state */
    protected function stillAllowed(array $state): bool
    {
        return $this->manager !== null
            && $this->customer !== null
            && $this->manager->getKey() === ($state['manager'] ?? null)
            && ! $this->customer->isStaff()
            && $this->manager->can('admin.access')
            && $this->manager->can(self::PERMISSION);
    }

    /**
     * Forget it. The answer is the customer it was, if there was one and they
     * still exist.
     */
    public function stop(Request $request): ?User
    {
        $state = $request->session()->pull(self::SESSION);

        if (! is_array($state)) {
            return null;
        }

        $customer = $this->customer ?? User::find($state['customer'] ?? null);

        // While `manager()` still answers: that is who the journal names.
        hook_action('core.impersonation.stopped', [
            'manager' => $this->manager ?? $request->user(),
            'customer' => $customer,
            'customer_id' => (int) ($state['customer'] ?? 0),
        ]);

        if ($this->manager) {
            Auth::setUser($this->manager);
        }

        $this->manager = null;
        $this->customer = null;

        return $customer;
    }

    /** Stop, and go back to where the manager came from. */
    public function leave(Request $request): RedirectResponse
    {
        $manager = $this->manager ?? $request->user();
        $customer = $this->stop($request);

        return $customer && $manager?->can('customers.manage')
            ? redirect()->route('admin.customers.edit', $customer)
            : redirect()->route('admin.dashboard');
    }

    /** Is this request a manager looking through a customer's eyes? */
    public function active(): bool
    {
        return $this->manager !== null;
    }

    /** The real person behind this request, while they are looking as somebody else. */
    public function manager(): ?User
    {
        return $this->manager;
    }

    public function customer(): ?User
    {
        return $this->customer;
    }

    /** A page its owner closed, or a change nobody opened. */
    public function refuses(Request $request): bool
    {
        return $request->isMethodSafe()
            ? $this->routeIn($request, 'closed')
            : ! $this->routeIn($request, 'writes');
    }

    /** The route's name as its owner wrote it, without the locale's copy prefix. */
    public function routeName(Request $request): string
    {
        $route = $request->route();
        $name = (string) $route?->getName();
        $prefix = LocaleResolver::prefixOf($route);

        return $prefix === null ? $name : substr($name, strlen($prefix) + 1);
    }

    protected function routeIn(Request $request, string $list): bool
    {
        return Str::is((array) hook_filter('core.impersonation.'.$list, []), $this->routeName($request));
    }
}
