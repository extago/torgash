<?php

namespace App\Core\PageCache;

use App\Core\Settings\CoreSettings;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whole pages kept ready for guests (PLAN §61).
 *
 * A guest's page is drawn once and handed to the next guest as it is, with
 * the parts that are theirs alone — the basket in the header, the town, the
 * wishlist's heart — cut out of the copy and drawn by PHP for each visitor
 * (`personal()`, see Holes). No flicker and no second request from the
 * browser: the personal parts arrive in the same HTML they always did.
 *
 * What makes it safe is not the list of personal parts, which a module can
 * forget, but what happens when one is forgotten. Everything a visitor
 * carries — the session's keys, the cookies — is DECLARED by whoever owns it:
 *
 *  - harmless: it changes nothing on a page (the language already in the
 *    address, a "counted already" list);
 *  - personal: it is drawn only inside personal parts (the basket's token);
 *  - vary: the page is drawn differently for it, so each value gets its own
 *    copy (the currency, how a listing is laid out). EVERY copy is filed
 *    under every vary's value, whether the page read it or not: a memo
 *    filled before the drawing began would hide the reading, and the few
 *    values a vary has cost a few copies, never a wrong page.
 *
 * A visitor whose session holds anything undeclared is simply drawn the
 * page, and a copy is taken only from a visitor carrying nothing personal.
 * While a copy is being drawn the session is watched (WatchedStore): a page
 * that reads something personal outside a personal part, or writes the
 * session at all, is not kept — and the reason is shown on Admin → Cache and
 * sessions. A forgotten declaration costs speed, never a stranger's basket.
 *
 * Cookies cannot be watched (Symfony's bag is a final class), so they are
 * held to stricter rules instead. A visitor holding a cookie nobody declared
 * is drawn the page — only cookies the shop wrote count: a counter's own,
 * which the server cannot decrypt, is nothing to it. A cookie the page
 * varies by puts every copy under its value. A personal cookie keeps a copy
 * from being taken, and is read inside personal parts only: nothing would
 * notice a page that read it anywhere else.
 *
 * A copy is thrown away when what it was drawn from changes (Writes): any
 * write to a table not declared invisible on pages discards every copy at
 * once, after the transaction commits.
 */
class PageCache
{
    public const ENABLED = 'page_cache';

    public const MINUTES = 'page_cache_minutes';

    public const SKIP = 'page_cache_skip';

    /** Kept on the cache store: when everything was last thrown away, and why. */
    protected const LAST_FLUSH = 'page_cache.last_flush';

    /** ...and the latest reasons a page was not kept. */
    protected const REFUSALS = 'page_cache.refusals';

    /**
     * What a link from an advert or a newsletter carries. Such a page is
     * served from the copy of its clean address, but never copied itself:
     * the pagination and the language links would carry the tags along.
     */
    public const AD_PARAMS = ['gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'yclid', 'ysclid', 'ttclid', 'igshid',
        '_openstat', 'mc_cid', 'mc_eid', '_ga', '_gl', 'srsltid'];

    /** @var array<string, string> session path => harmless | personal | personal+copies | vary:<name> */
    protected array $session = [];

    /** @var array<string, string> cookie (or `prefix*`) => harmless | personal | personal+copies | vary:<name> */
    protected array $cookies = [];

    /** @var array<string, Closure> vary name => fn (Request): string */
    protected array $vary = [];

    /** @var array<string, string> page kind => label */
    protected array $kinds = [];

    /** @var array<string, callable> visit action => fn (array $data, Request $request) */
    protected array $visits = [];

    /** @var array<string, true|list<string>> table => true (all of it), or the columns that are */
    protected array $unseen = [];

    /** @var list<Closure(): ?DateTimeInterface> */
    protected array $clocks = [];

    /** The copy being drawn in this request, when one is. */
    protected ?Capture $current = null;

    public function __construct()
    {
        // The framework's own: the form token is put back fresh on every
        // page; the previous address is everybody's after their first page,
        // so its holder still gives copies, and a page that prints it ("back
        // to where you were") is not kept; the flash list is empty whenever a
        // page is served from here (a message to show is never served from a
        // copy); and "where to go after signing in" is read only by the
        // sign-in.
        $this->session += ['_token' => 'harmless', '_previous' => 'personal+copies', '_flash' => 'harmless', 'url.intended' => 'harmless'];
        $this->cookies += ['XSRF-TOKEN' => 'harmless', 'remember_*' => 'harmless'];
    }

    // ---------------------------------------------------------------- declarations

    /**
     * State that changes nothing on any page — having it, reading it.
     *
     * @param  list<string>  $session  keys, dotted for a key inside another
     * @param  list<string>  $cookies  names, `prefix*` for a family
     */
    public function harmless(array $session = [], array $cookies = []): static
    {
        return $this->declare('harmless', $session, $cookies);
    }

    /**
     * State that is drawn only inside personal parts. A visitor who has it is
     * served copies, and a page that reads it outside a personal part is not
     * kept. A copy is never taken from them either — whatever read it before
     * the page began (a middleware, a memo) would be in it — unless
     * `$copies`: say so of a flag only personal parts ever read ("asked
     * already"), and a visitor holding it still gives copies.
     *
     * @param  list<string>  $session
     * @param  list<string>  $cookies
     */
    public function personal(array $session = [], array $cookies = [], bool $copies = false): static
    {
        return $this->declare($copies ? 'personal+copies' : 'personal', $session, $cookies);
    }

    /** Is this role a personal one (either kind)? */
    public static function isPersonal(?string $role): bool
    {
        return $role !== null && str_starts_with($role, 'personal');
    }

    /**
     * State a page is drawn differently for: each value its own copy.
     *
     * `$resolve` answers what the pages WILL make of it, not what is stored —
     * the shop's default currency for a visitor who never chose one, a
     * listing's page size from the address before the cookie, the tax rates
     * that apply in the visitor's town rather than the town — so visitors
     * who see the same pages share their copies. It is asked for every
     * visitor, on every page: it must be cheap.
     *
     * @param  Closure(Request): (string|int|null)  $resolve
     * @param  list<string>  $session
     * @param  list<string>  $cookies
     */
    public function vary(string $name, Closure $resolve, array $session = [], array $cookies = []): static
    {
        $this->vary[$name] = $resolve;

        return $this->declare('vary:'.$name, $session, $cookies);
    }

    /** A kind of page the shop may choose not to keep (Settings → Store → Page cache). */
    public function kind(string $kind, string $label): static
    {
        $this->kinds[$kind] = $label;

        return $this;
    }

    /** @return array<string, string> kind => label */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /**
     * Something a page does for every visit, served from a copy or not — a
     * product's view counted, "viewed recently" updated. The page calls
     * `visit_action($name, $data)` where it used to do the work itself; a
     * copy keeps the call and makes it again for every visitor it serves.
     *
     * @param  callable(array<string, mixed>, Request): void  $action
     */
    public function onVisit(string $name, callable $action): static
    {
        $this->visits[$name] = $action;

        return $this;
    }

    /**
     * Tables no guest page is drawn from — a write to them throws nothing
     * away (orders, baskets, logs). With `$columns`, only those columns of
     * the table: an UPDATE that touches nothing else is invisible too.
     *
     * @param  list<string>  $columns
     */
    public function unseen(string|array $tables, array $columns = []): static
    {
        foreach ((array) $tables as $table) {
            if ($columns === []) {
                $this->unseen[$table] = true;
            } elseif (($this->unseen[$table] ?? null) !== true) {
                $this->unseen[$table] = array_values(array_unique([...(array) ($this->unseen[$table] ?? []), ...$columns]));
            }
        }

        return $this;
    }

    /** @return true|list<string>|null */
    public function unseenIn(string $table): true|array|null
    {
        return $this->unseen[$table] ?? null;
    }

    /**
     * Something pages may show changes by itself, with nothing written — a
     * sale starts, a banner's dates run out: `$next` answers when it next
     * does (or null, never), and no copy is kept past that moment. Asked
     * once for each copy, as it is kept.
     *
     * @param  Closure(): ?DateTimeInterface  $next
     */
    public function changes(Closure $next): static
    {
        $this->clocks[] = $next;

        return $this;
    }

    /**
     * For changes(): the next moment a row of `$table` comes on (its start)
     * or goes off (a second past its end, which the tables here count in).
     */
    public function nextDateIn(string $table, string $starts = 'starts_at', string $ends = 'ends_at'): ?Carbon
    {
        $at = DB::table($table)
            ->selectRaw("least(min({$starts}) filter (where {$starts} > now()), min({$ends}) filter (where {$ends} >= now()) + interval '1 second') as at")
            ->value('at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * The soonest moment anything registered with changes() changes.
     *
     * @throws Throwable when a module cannot say — a copy is then not kept
     */
    public function nextChange(): ?Carbon
    {
        $next = null;

        foreach ($this->clocks as $clock) {
            $at = $clock();

            if ($at !== null && ($next === null || $at < $next)) {
                $next = Carbon::instance($at);
            }
        }

        return $next;
    }

    /**
     * @param  list<string>  $session
     * @param  list<string>  $cookies
     */
    protected function declare(string $role, array $session, array $cookies): static
    {
        foreach ($session as $key) {
            $this->session[$key] = $role;
        }

        foreach ($cookies as $name) {
            $this->cookies[$name] = $role;
        }

        return $this;
    }

    // ---------------------------------------------------------------- reading the declarations

    /**
     * What a session key (or a key inside one) was declared as — by itself or
     * by a key it lives inside. Null: nobody declared it.
     */
    public function sessionRole(string $path): ?string
    {
        for ($at = $path; ; $at = substr($at, 0, $dot)) {
            if (isset($this->session[$at])) {
                return $this->session[$at];
            }

            if (($dot = strrpos($at, '.')) === false) {
                return null;
            }
        }
    }

    /**
     * The roles declared INSIDE a key — what reading the whole of `catalog`
     * reads when only `catalog.recently_viewed` was declared.
     *
     * @return list<string>
     */
    public function rolesUnder(string $path): array
    {
        $roles = [];

        foreach ($this->session as $key => $role) {
            if (str_starts_with($key, $path.'.')) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function cookieRole(string $name): ?string
    {
        if (isset($this->cookies[$name])) {
            return $this->cookies[$name];
        }

        foreach ($this->cookies as $declared => $role) {
            if (str_ends_with($declared, '*') && str_starts_with($name, substr($declared, 0, -1))) {
                return $role;
            }
        }

        // The session's own cookie is the session, watched as such.
        return $name === config('session.cookie') ? 'harmless' : null;
    }

    /**
     * The first key in a session that nobody declared, as a dotted path — or
     * null when every key is accounted for.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function undeclaredIn(array $values, string $prefix = ''): ?string
    {
        foreach ($values as $key => $value) {
            $path = $prefix.$key;

            if ($this->sessionRole($path) !== null) {
                continue;
            }

            if (is_array($value) && $this->rolesUnder($path) !== []) {
                if (($inner = $this->undeclaredIn($value, $path.'.')) !== null) {
                    return $inner;
                }

                continue;
            }

            return $path;
        }

        return null;
    }

    // ---------------------------------------------------------------- settings

    public function enabled(): bool
    {
        return (bool) setting(CoreSettings::ID, self::ENABLED, false);
    }

    public function minutes(): int
    {
        return max(1, (int) setting(CoreSettings::ID, self::MINUTES, 60));
    }

    public function skips(string $kind): bool
    {
        return in_array($kind, (array) setting(CoreSettings::ID, self::SKIP, []), true);
    }

    // ---------------------------------------------------------------- who is served

    /**
     * Why this request is not answered from a copy at all, or null when it
     * may be. Asked before anything else, in the order of what is cheapest.
     *
     * @param  list<string>  $params  the address's parameters the page reads
     */
    public function bypassReason(Request $request, array $params): ?string
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return 'not a GET';
        }

        // A piece of a page (htmx), an answer for a script, the filter
        // panel's count — each its own answer, and none of them a page.
        if ($request->hasHeader('HX-Request') || $request->ajax() || $request->hasHeader('X-Catalog-Count') || $request->expectsJson()) {
            return 'not a page';
        }

        foreach (array_keys($request->query()) as $param) {
            $param = (string) $param;

            if ($param === 'nocache') {
                return 'asked for a fresh page';
            }

            if (! in_array($param, $params, true) && ! $this->isAdParam($param)) {
                return 'parameter '.$param;
            }

            if (in_array($param, $params, true) && ! is_scalar($request->query($param))) {
                return 'parameter '.$param;
            }
        }

        if (auth()->check()) {
            return 'signed in';
        }

        $session = $request->hasSession() ? $request->session() : null;

        if (! $session) {
            return 'no session';
        }

        $values = $session->all();

        // A message to show (flash), or a form that came back with errors.
        if (($values['_flash']['old'] ?? []) !== [] || ($values['_flash']['new'] ?? []) !== []) {
            return 'a message to show';
        }

        if (isset($values['_old_input']) || isset($values['errors'])) {
            return 'a form came back';
        }

        if (($key = $this->undeclaredIn($values)) !== null) {
            return 'session holds '.$key;
        }

        // What the shop itself wrote: anything else failed to decrypt and is
        // null by now (EncryptCookies), and a page cannot read it.
        foreach ($request->cookies->all() as $name => $value) {
            if ($this->cookieRole((string) $name) === null && $this->written($value)) {
                return 'holds the cookie '.$name;
            }
        }

        return null;
    }

    protected function written(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn ($item) => $this->written($item)) !== [];
        }

        return $value !== null && $value !== '';
    }

    /**
     * Why a copy must not be TAKEN from this visitor, though they may be
     * served one: they carry something personal (whatever was cached about
     * them before the page began would end up in everybody's copy), or the
     * address carries an advert's tags.
     */
    public function takeRefusal(Request $request): ?string
    {
        if (! $request->isMethod('GET')) {
            return 'not a GET';
        }

        foreach (array_keys($request->query()) as $param) {
            if ($this->isAdParam((string) $param)) {
                return 'advert parameter '.$param;
            }
        }

        return $this->personalHeld($request);
    }

    /**
     * The personal key or cookie this visitor holds — one that keeps them
     * from giving copies (`copies` ones do not) — or null.
     *
     * Also why a part kept as drawn is not reused for them: what was worked
     * out about their state may sit in a memo that a part read without the
     * drawing ever seeing it (a module's per-request singleton). Drawn for
     * a visitor holding nothing personal, the part is right as drawn for
     * every visitor holding nothing personal either — whatever memos it read.
     */
    public function personalHeld(Request $request): ?string
    {
        foreach ($this->presentKeys($request->session()->all()) as $path) {
            if ($this->sessionRole($path) === 'personal') {
                return 'session holds '.$path;
            }
        }

        foreach (array_keys($request->cookies->all()) as $name) {
            if ($this->cookieRole((string) $name) === 'personal') {
                return 'cookie '.$name;
            }
        }

        return null;
    }

    /**
     * Every declared key a session actually holds, as dotted paths.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    protected function presentKeys(array $values, string $prefix = ''): array
    {
        $paths = [];

        foreach ($values as $key => $value) {
            $path = $prefix.$key;

            if ($this->sessionRole($path) !== null) {
                $paths[] = $path;
            } elseif (is_array($value) && $this->rolesUnder($path) !== []) {
                $paths = [...$paths, ...$this->presentKeys($value, $path.'.')];
            }
        }

        return $paths;
    }

    public function isAdParam(string $param): bool
    {
        return str_starts_with($param, 'utm_') || in_array($param, self::AD_PARAMS, true);
    }

    /**
     * The page's address as a copy is filed under: scheme, host, path and
     * the parameters the page reads, in a fixed order.
     *
     * @param  list<string>  $params
     */
    public function address(Request $request, array $params): string
    {
        $query = [];

        foreach ($params as $param) {
            $value = $request->query($param);

            if (is_scalar($value) && (string) $value !== '') {
                $query[$param] = (string) $value;
            }
        }

        ksort($query);

        return $request->getSchemeAndHttpHost().$request->getPathInfo().($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * This visitor's value of every declared vary.
     *
     * @return array<string, string>
     */
    public function varyValues(Request $request): array
    {
        $values = [];

        foreach ($this->vary as $name => $resolve) {
            $values[$name] = (string) $resolve($request);
        }

        ksort($values);

        return $values;
    }

    // ---------------------------------------------------------------- the copy being drawn

    public function current(): ?Capture
    {
        return $this->current;
    }

    public function begin(Capture $capture): void
    {
        $this->current = $capture;
    }

    public function end(): void
    {
        $this->current = null;
    }

    /**
     * True while a copy for every guest is being drawn, outside its personal
     * parts: what is drawn now is everybody's. A list that marks what the
     * visitor already has (the basket's tick on a card) leaves it unmarked
     * then, and a personal part puts the marks on.
     */
    public function drawing(): bool
    {
        return $this->current !== null && ! $this->current->inside();
    }

    /**
     * Reads something personal that a vary answers for: what `$read` reads
     * is not the page reading the visitor's own. Taxes read the visitor's
     * town, and every copy is filed under the tax rates that apply there —
     * one copy per set of rates, not per town. The vary's resolver must
     * answer exactly what the pages make of it.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public function under(string $vary, Closure $read): mixed
    {
        if (! $this->drawing()) {
            return $read();
        }

        if (! isset($this->vary[$vary])) {
            $this->current->refuse('reads under '.$vary.', which no module declared');

            return $read();
        }

        return $this->current->quietly($read);
    }

    /** Marks the page being drawn as showing these — `product:12`. */
    public function tag(string ...$tags): void
    {
        $this->current?->tag(...$tags);
    }

    /** The page being drawn changes by itself at this moment (a sale starts, a post comes out). */
    public function until(?DateTimeInterface $when): void
    {
        if ($when !== null) {
            $this->current?->until($when);
        }
    }

    /**
     * Does a visit action now and, when a copy is being drawn, keeps it for
     * every visitor that copy will serve. The data must be plain (it is
     * stored): numbers and strings, not models.
     *
     * @param  array<string, mixed>  $data
     */
    public function visit(string $name, array $data = []): void
    {
        $capture = $this->current;

        if ($capture === null) {
            $this->runVisit($name, $data);

            return;
        }

        $capture->visit(fn () => $this->runVisit($name, $data));
        $capture->visited($name, $data);
    }

    /** @param array<string, mixed> $data */
    public function runVisit(string $name, array $data): void
    {
        $action = $this->visits[$name] ?? null;

        if ($action === null) {
            throw new \InvalidArgumentException("No visit action [{$name}] is registered.");
        }

        $action($data, request());
    }

    // ---------------------------------------------------------------- storage

    /**
     * The copy for this address that this visitor may be served, or null.
     *
     * @param  array<string, string>  $vary
     * @return array{id: int, body: string, meta: array<string, mixed>}|null
     */
    public function find(string $address, array $vary): ?array
    {
        $row = DB::selectOne(
            'SELECT id, body, meta FROM page_cache
              WHERE url_hash = ? AND vary_hash = ? AND generation = (SELECT last_value FROM page_cache_generation)
                AND expires_at > now()',
            [sha1($address), sha1(json_encode((object) $vary))],
        );

        if ($row === null) {
            return null;
        }

        $body = is_resource($row->body) ? stream_get_contents($row->body) : (string) $row->body;

        return ['id' => (int) $row->id, 'body' => $body, 'meta' => json_decode($row->meta, true)];
    }

    /**
     * Keeps a copy — unless anything was thrown away since the drawing of
     * it began, which the same statement checks: a write that commits while
     * the page is being drawn may have been read half before and half after.
     *
     * @param  array<string, string>  $vary
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $tags
     */
    public function store(string $address, string $kind, array $vary, string $body, array $meta, array $tags, Carbon $expires, int $generation, int $changes): bool
    {
        $deflated = gzdeflate($body, 6);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $deflated);
        rewind($stream);

        $varyJson = json_encode((object) $vary);

        $stored = DB::affectingStatement(
            'INSERT INTO page_cache (url_hash, vary_hash, url, kind, vary, generation, body, meta, size, tags, created_at, expires_at)
             SELECT ?, ?, ?, ?, ?::jsonb, ?, ?, ?::jsonb, ?, ?::text[], now(), ?
              WHERE (SELECT last_value FROM page_cache_changes) = ?
             ON CONFLICT (url_hash, vary_hash) DO UPDATE SET
                url = excluded.url, kind = excluded.kind, generation = excluded.generation, body = excluded.body,
                meta = excluded.meta, size = excluded.size, tags = excluded.tags,
                created_at = excluded.created_at, expires_at = excluded.expires_at',
            [
                sha1($address), sha1($varyJson), mb_substr($address, 0, 2000), $kind, $varyJson, $generation,
                $stream, json_encode($meta), strlen($deflated), $this->textArray($tags), $expires, $changes,
            ],
        ) > 0;

        fclose($stream);

        return $stored;
    }

    /** @return array{generation: int, changes: int} */
    public function counters(): array
    {
        $row = DB::selectOne('SELECT (SELECT last_value FROM page_cache_generation) AS generation, (SELECT last_value FROM page_cache_changes) AS changes');

        return ['generation' => (int) $row->generation, 'changes' => (int) $row->changes];
    }

    /**
     * Throws away the copies that show any of these (`product:12`) — once the
     * transaction in progress commits, or now outside one.
     */
    public function discard(string ...$tags): void
    {
        if ($tags !== [] && $this->enabled()) {
            app(Writes::class)->pend(null, $tags);
        }
    }

    /** Throws every copy away — once the transaction in progress commits. */
    public function discardAll(string $why): void
    {
        if ($this->enabled()) {
            app(Writes::class)->pend($why, []);
        }
    }

    /** Throws every copy away, now. */
    public function flush(string $why): void
    {
        try {
            DB::select("SELECT nextval('page_cache_generation'), nextval('page_cache_changes')");
        } catch (Throwable) {
            // Before the table exists (the first migration of a new shop)
            // there is nothing to throw away.
            return;
        }

        Cache::forever(self::LAST_FLUSH, ['at' => time(), 'why' => mb_substr($why, 0, 300)]);
    }

    /**
     * Throws away the copies that show any of these (`product:12`). Counted
     * as a change FIRST: a copy whose drawing began before it is not kept,
     * and one kept just before is deleted here.
     *
     * @param  list<string>  $tags
     */
    public function forgetTagged(array $tags): void
    {
        if ($tags === []) {
            return;
        }

        try {
            DB::select("SELECT nextval('page_cache_changes')");
            DB::delete('DELETE FROM page_cache WHERE tags && ?::text[]', [$this->textArray($tags)]);
        } catch (Throwable) {
            return;
        }
    }

    public function forgetEntry(int $id): void
    {
        DB::delete('DELETE FROM page_cache WHERE id = ?', [$id]);
    }

    /** Copies past their time or of a generation thrown away. */
    public function prune(): int
    {
        return DB::delete('DELETE FROM page_cache WHERE expires_at < now() OR generation <> (SELECT last_value FROM page_cache_generation)');
    }

    /** @return array{entries: int, bytes: int} the copies that may still be served */
    public function stats(): array
    {
        $row = DB::selectOne('SELECT count(*) AS entries, coalesce(sum(size), 0) AS bytes FROM page_cache
            WHERE generation = (SELECT last_value FROM page_cache_generation) AND expires_at > now()');

        return ['entries' => (int) $row->entries, 'bytes' => (int) $row->bytes];
    }

    /** @return array{at: int, why: string}|null */
    public function lastFlush(): ?array
    {
        return Cache::get(self::LAST_FLUSH);
    }

    /**
     * Notes why a page was not kept, for the cache screen — once per reason
     * in ten minutes, so a busy page that is never kept costs one write, not
     * one per visitor.
     */
    public function noteRefusal(string $kind, string $address, string $reason): void
    {
        try {
            if (! Cache::add('page_cache.refused.'.sha1($kind.'|'.$reason), 1, 600)) {
                return;
            }

            $list = (array) Cache::get(self::REFUSALS, []);
            array_unshift($list, ['at' => time(), 'kind' => $kind, 'url' => mb_substr($address, 0, 300), 'reason' => mb_substr($reason, 0, 300)]);
            Cache::forever(self::REFUSALS, array_slice($list, 0, 20));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<array{at: int, kind: string, url: string, reason: string}> */
    public function refusals(): array
    {
        return array_values((array) Cache::get(self::REFUSALS, []));
    }

    public function forgetRefusals(): void
    {
        Cache::forget(self::REFUSALS);
    }

    /** @param list<string> $values */
    protected function textArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $v) => '"'.addcslashes($v, '"\\').'"', array_values(array_unique($values)))).'}';
    }
}
