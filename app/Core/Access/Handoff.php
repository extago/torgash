<?php

namespace App\Core\Access;

use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Carrying a member of staff's sign-in to another storefront's address.
 *
 * A session belongs to the host it was made on, so an admin page that has to
 * live on a storefront's OWN address — the theme configurator, whose preview
 * frame and draft must share an origin with it — cannot just be linked to
 * from the admin on the main domain: there the person is a stranger. Framing
 * the other address instead does not help either: a frame from another site
 * gets no cookies, so the draft would be lost on the first link inside it.
 *
 * So the admin issues a ticket and the browser carries it there, top-level:
 * - one use (`Cache::pull`), a minute to live, kept under its hash;
 * - issued by POST only — a GET would be fetched by the link prefetcher on
 *   its own and the ticket spent before anybody clicked;
 * - good only on a domain of the storefront it was issued for, and only for
 *   the page it was issued for (never a destination from the address);
 * - for staff only, re-checked when it is taken.
 *
 * What a session has PROVED beyond the password (a second factor) is a
 * module's, not core's: `core.auth.handoff` lets it add session values to
 * the ticket, and `core.auth.handed_over` tells it the ticket was used.
 */
class Handoff
{
    public const TTL = 60;

    /** The address that signs the user in on the storefront's domain and opens $path there. */
    public function issue(User $user, Storefront $storefront, string $path): ?string
    {
        if (! $user->isStaff() || ($base = $storefront->url('/')) === null) {
            return null;
        }

        $token = Str::random(48);

        Cache::put($this->key($token), [
            'user' => $user->getKey(),
            'storefront' => $storefront->getKey(),
            'path' => '/'.ltrim($path, '/'),
            'session' => (array) hook_filter('core.auth.handoff', [], ['user' => $user]),
        ], self::TTL);

        return rtrim($base, '/').route('admin.handoff', ['token' => $token], false);
    }

    /**
     * The ticket's contents, spent; null when it is unknown, used, expired,
     * brought to another host or issued to somebody who is no longer staff.
     *
     * @return array{user: User, storefront: Storefront, path: string, session: array<string, mixed>}|null
     */
    public function take(Request $request, string $token): ?array
    {
        $ticket = Cache::pull($this->key($token));

        if (! is_array($ticket)) {
            return null;
        }

        $storefront = Storefront::find($ticket['storefront'] ?? 0);
        $user = User::find($ticket['user'] ?? 0);

        if (! $storefront || ! $user || ! $user->isStaff()
            || ! in_array(strtolower($request->getHost()), array_map('strtolower', $storefront->domains ?? []), true)) {
            return null;
        }

        return ['user' => $user, 'storefront' => $storefront, 'path' => (string) $ticket['path'], 'session' => (array) ($ticket['session'] ?? [])];
    }

    /**
     * Is this address the storefront's — the one it draws? That includes the
     * default storefront on any host nobody else claims (localhost, an IP).
     */
    public static function isHome(Request $request, Storefront $storefront): bool
    {
        return (bool) Storefront::resolveByHost($request->getHost())?->is($storefront);
    }

    protected function key(string $token): string
    {
        return 'core.handoff.'.hash('sha256', $token);
    }
}
