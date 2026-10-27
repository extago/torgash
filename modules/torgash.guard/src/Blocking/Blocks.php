<?php

namespace Torgash\Guard\Blocking;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Torgash\Guard\Models\Block;

/**
 * The last part of the guard, and the only one that can do harm (PLAN §25.2/4).
 *
 * Everything else in this module WATCHES. This one acts, and the cost of it
 * acting wrongly is money and a long diagnosis: a shop whose 1C exchange has
 * silently stopped, or whose payment callbacks are being answered 429 by its
 * own watchman, does not look like a blocking bug from the inside. It looks
 * like the bank being down. Hence three rules that are not settings.
 *
 * **One: a request that proved who it is, is never touched.** Twice over, and
 * neither half is a list of paths anybody has to maintain:
 *
 * - The check runs in the `web` group ONLY. Everything credentialed in this
 *   engine is deliberately registered outside it — `/commerceml` and
 *   `/1c_exchange.php`, the payment gateways' callbacks, `/api/v2/*` — because
 *   a machine has no session, no CSRF token and no locale prefix. So they are
 *   out of reach of this by the SHAPE of the route table rather than by a list
 *   of exceptions, and a gateway module written next year is exempt without
 *   ever having heard of this one.
 * - Inside `web`, an authenticated request passes. A session cookie IS a
 *   credential: the manager who is signed in when their office address gets
 *   blocked keeps working, and so does the customer in the middle of checkout.
 *
 * The honest limit of that second half: an abusive visitor who registers an
 * account is no longer anonymous. That is a suspended ACCOUNT, which is a
 * different feature — a block is about traffic with no name on it, and
 * pretending otherwise would make rule one a lie.
 *
 * **Two: it watches before it acts.** `blocking_mode` is `watch` out of the
 * box: everything is recorded exactly as it would be, the row says what it
 * would have refused and counts the requests it would have refused — and
 * nobody is refused anything. A shop that switches this on without reading
 * that screen first has no idea what its own traffic looks like, and neither
 * do we.
 *
 * **Three: the way out is a CONSOLE command.** `php artisan guard:unblock`.
 * Locking yourself out of the admin with a module that lives in the admin is
 * one wrong number away, and the screen inside cannot be the answer to not
 * being able to reach the screen.
 *
 * **What is blocked is a CLIENT, not a string** (`subject()`). For IPv4 that
 * is the address, written one way. For IPv6 it is the /64 network: a provider
 * hands one household, one phone or one server a whole /64 — eighteen
 * quintillion addresses — so a bot guessing passwords from a fresh address
 * each time never fills any one address's counter, and a block on one address
 * is a block on nothing. Counting, blocking, looking up, releasing and the
 * allow-list all go through `subject()`, so two spellings of one IPv6 address
 * (`2001:db8::1`, `2001:0db8:0:0::1`) are one row, and an IPv4 address arriving
 * dressed as IPv6 (`::ffff:203.0.113.9`) is the IPv4 one.
 *
 * And a fourth thing, which is not a rule but a limit worth saying out loud:
 * PHP does not stop a flood. A real one dies at Caddy, or upstream of it, long
 * before a framework boots. What this stops is a password being guessed on
 * /admin, a form being abused and a scanner working through token strings —
 * slow, patient, small-volume things, which are exactly the ones nobody
 * notices.
 */
class Blocks
{
    public const OFF = 'off';

    public const WATCH = 'watch';

    public const ON = 'on';

    /**
     * The active addresses, cached as one value.
     *
     * Every request in the `web` group asks this question, so the answer must
     * not be a query against a table that grows under attack — and on the shop
     * that eventually moves its cache to redis or apcu it becomes free, which a
     * database read never would. Sixty seconds, and forgotten outright on every
     * write, so a release is immediate and only an expiry can lag.
     */
    public const CACHE_KEY = 'guard:blocks';

    protected const CACHE_SECONDS = 60;

    /** The part of an IPv6 address one client holds (see the class docblock). */
    public const IPV6_PREFIX = \App\Core\Support\ClientAddress::IPV6_PREFIX;

    /** A block can lengthen, but never past this: a week is not an accident. */
    protected const MAX_MINUTES = 10080;

    public function mode(): string
    {
        $mode = (string) setting('torgash.guard', 'blocking_mode', self::WATCH);

        return in_array($mode, [self::OFF, self::WATCH, self::ON], true) ? $mode : self::WATCH;
    }

    /**
     * Something happened that has no legitimate reading. Count it, and block
     * the address if it has now done enough of them.
     *
     * ONE counter per address rather than one per kind of signal, and one
     * threshold on the settings screen rather than four. A shop owner can
     * reason about "ten refusals in ten minutes"; nobody can reason about four
     * numbers whose interactions they have to imagine. What kind they were is
     * kept — it is what the row has to say — but it is not what decides.
     */
    public function report(string $signal, ?Request $request = null): ?Block
    {
        if ($this->mode() === self::OFF) {
            return null;
        }

        $request ??= request();
        $ip = (string) ($request?->ip() ?? '');

        // No address means no client: a console command, a queue worker, a test
        // with nothing in REMOTE_ADDR. There is nobody here to block.
        if ($ip === '' || $this->exempt($request) || $this->allowed($ip)) {
            return null;
        }

        // Counted per CLIENT: every address of one IPv6 /64 fills one counter.
        $ip = $this->subject($ip);

        $window = max(1, (int) setting('torgash.guard', 'block_window', 10)) * 60;
        RateLimiter::hit($this->key($ip, $signal), $window);

        $counts = [];
        $total = 0;

        foreach (Signals::COUNTED as $kind) {
            $count = RateLimiter::attempts($this->key($ip, $kind));

            if ($count > 0) {
                $counts[$kind] = $count;
                $total += $count;
            }
        }

        if ($total < max(1, (int) setting('torgash.guard', 'block_threshold', 10))) {
            return null;
        }

        return $this->block($ip, $signal, ['signals' => $counts]);
    }

    /**
     * Write the block down — and apply it, if the shop has said to and the
     * address is one it is safe to be wrong about.
     *
     * A private or reserved address is never blocked AUTOMATICALLY, and the
     * reason is not politeness. A shop behind a proxy that has not been added
     * to `trustedProxies` sees every single visitor as 127.0.0.1, or as one
     * address on the local network — and the first automatic block would then
     * shut the whole shop. Recording it and refusing to act on it is how that
     * misconfiguration announces itself instead: as a row on the screen that
     * says why. By hand it is allowed, because then somebody has looked.
     */
    public function block(string $ip, string $signal, array $options = []): ?Block
    {
        if ($this->allowed($ip)) {
            return null;
        }

        $ip = $this->subject($ip);

        $byHand = ($options['by_hand'] ?? false) === true;
        $existing = Block::where('ip', $ip)->first();

        // Already standing: nothing about it changes except what it has been
        // caught doing since. Re-blocking an address that is already blocked
        // would count a strike for every extra password it tries.
        if ($existing && $existing->isActive() && ! $byHand) {
            $existing->forceFill([
                'reason' => $signal,
                'signals' => $options['signals'] ?? $existing->signals,
                'last_seen_at' => now(),
            ])->save();

            $this->flush();

            return $existing;
        }

        $strikes = $existing ? $existing->strikes + 1 : 1;
        $routable = $this->routable($ip);
        $mode = ($byHand || ($this->mode() === self::ON && $routable))
            ? Block::ENFORCED
            : Block::OBSERVED;

        $minutes = array_key_exists('minutes', $options)
            ? $options['minutes']
            : $this->minutes($strikes);

        $block = Block::updateOrCreate(['ip' => $ip], [
            'mode' => $mode,
            'reason' => $signal,
            'signals' => $options['signals'] ?? null,
            'strikes' => $strikes,
            'hits' => 0,
            // Stored in English and translated when PRINTED: the row is written
            // by whichever request tripped it, in that visitor's language, and
            // read by an admin in theirs.
            'note' => $options['note'] ?? ($routable ? null : 'A private or reserved address — the shop may be behind a proxy it has not been told to trust.'),
            'user_id' => $options['user_id'] ?? null,
            'user_name' => $options['user_name'] ?? null,
            'until' => $minutes === null ? null : now()->addMinutes((int) $minutes),
            'released_at' => null,
            'last_seen_at' => now(),
        ]);

        $this->flush();

        return $block;
    }

    /**
     * How long, in minutes. The shop's own number, multiplied by how many times
     * this address has been back — coming back after being let in is the
     * clearest evidence there is that the first block was right — and never
     * past a week, because an automatic block that outlives the attack is
     * indistinguishable from a customer who cannot reach the shop.
     */
    public function minutes(int $strikes): int
    {
        $base = max(1, (int) setting('torgash.guard', 'block_minutes', 60));

        return (int) min($base * max(1, $strikes), self::MAX_MINUTES);
    }

    /** Let one address back in. Returns how many rows that was (0 or 1). */
    public function release(string $ip): int
    {
        // Any address of the network lets the network in: whoever runs the
        // console command has the address the visitor wrote to them, not the
        // row's /64.
        $ip = $this->subject($ip);

        $released = Block::where('ip', $ip)->active()->update([
            'released_at' => now(),
            'updated_at' => now(),
        ]);

        // The counters too, or the next refusal puts them straight back: the
        // window that earned the block is still full of what earned it.
        foreach (Signals::COUNTED as $kind) {
            RateLimiter::clear($this->key($ip, $kind));
        }

        $this->flush();

        return $released;
    }

    /** @return int how many addresses were let back in */
    public function releaseAll(): int
    {
        $addresses = Block::active()->pluck('ip');

        foreach ($addresses as $ip) {
            $this->release((string) $ip);
        }

        return $addresses->count();
    }

    /** Apply one that was only observed — the button beside a watched row. */
    public function enforce(Block $block, ?int $minutes = null): Block
    {
        $block->forceFill([
            'mode' => Block::ENFORCED,
            'released_at' => null,
            'until' => $minutes === null
                ? ($block->until && $block->until->isFuture() ? $block->until : now()->addMinutes($this->minutes($block->strikes)))
                : now()->addMinutes($minutes),
        ])->save();

        $this->flush();

        return $block;
    }

    /**
     * The block standing against this request, if any — observed ones included,
     * because watch mode has to count what it would have refused.
     */
    public function forRequest(Request $request): ?Block
    {
        $ip = $this->subject((string) ($request->ip() ?? ''));

        if ($ip === '' || ! isset($this->active()[$ip])) {
            return null;
        }

        $block = Block::where('ip', $ip)->first();

        return $block && $block->isActive() ? $block : null;
    }

    /**
     * One more request from an address the shop has already decided about.
     *
     * A bare UPDATE rather than a model save: no events, so the audit trail
     * does not fill up with the guard noticing things, and no hydration. The
     * request being counted has already paid for a full framework boot — one
     * indexed update is not what makes it expensive.
     */
    public function seen(Block $block): void
    {
        Block::whereKey($block->getKey())->update([
            'hits' => DB::raw('hits + 1'),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Rule one, in one method: a request that presented valid credentials is
     * never touched. See the class docblock for its other half, which is the
     * shape of the route table rather than any code here.
     */
    public function exempt(?Request $request): bool
    {
        return $request === null || auth()->check();
    }

    /**
     * The shop's own list of addresses and networks that are never blocked.
     *
     * A single IPv6 address on the list covers its /64, the same client the
     * blocking is about: allowing the office's one address and then blocking
     * the network it sits in would block the office.
     */
    public function allowed(string $ip): bool
    {
        foreach (preg_split('/[\s,]+/', (string) setting('torgash.guard', 'block_allow', '')) ?: [] as $entry) {
            $entry = trim($entry);

            if ($entry !== '' && $this->matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** A public address: one it is safe for the guard to be wrong about. */
    public function routable(string $ip): bool
    {
        return (bool) filter_var($this->address($ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Who an address belongs to, written the one way every row and every
     * counter is keyed by: an IPv4 address as itself, an IPv6 one as its /64
     * (`2001:db8:1:2::/64`), an IPv4-mapped IPv6 one as the IPv4 inside it.
     * A subject passes through unchanged, so it is safe to ask twice; what is
     * not an address at all comes back as given, trimmed.
     */
    public function subject(string $ip): string
    {
        // Core's, since core's own limits count clients the same way.
        return \App\Core\Support\ClientAddress::subject($ip);
    }

    /** Whether a subject stands for a whole network rather than one address. */
    public function isNetwork(string $subject): bool
    {
        return str_contains($subject, '/');
    }

    /** The address a subject starts at — itself for a single address. */
    protected function address(string $subject): string
    {
        return $this->isNetwork($subject) ? strstr($subject, '/', true) : $subject;
    }

    /**
     * @return array<string, array{mode: string, until: int|null}>
     */
    public function active(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => Block::active()
            ->get(['ip', 'mode', 'until'])
            ->mapWithKeys(fn (Block $block) => [$block->ip => [
                'mode' => $block->mode,
                'until' => $block->until?->getTimestamp(),
            ]])
            ->all());
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** An address, or a CIDR network the address is inside. IPv4 and IPv6. */
    protected function matches(string $ip, string $entry): bool
    {
        // One address: the same client, whatever spelling either side arrived in.
        if (! str_contains($entry, '/')) {
            return $this->subject($ip) === $this->subject($entry);
        }

        $ip = $this->address($this->subject($ip));

        [$subnet, $bits] = explode('/', $entry, 2);
        $address = @inet_pton($ip);
        $network = @inet_pton($subnet);
        $bits = (int) $bits;

        if ($address === false || $network === false || strlen($address) !== strlen($network) || $bits < 0) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($bytes > strlen($address) || strncmp($address, $network, $bytes) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return ($address[$bytes] & $mask) === ($network[$bytes] & $mask);
    }

    protected function key(string $ip, string $signal): string
    {
        return 'guard:signal:'.$signal.':'.$this->subject($ip);
    }
}
