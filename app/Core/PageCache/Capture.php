<?php

namespace App\Core\PageCache;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * One copy of a page being drawn (PLAN §61) — what the drawing touched.
 *
 * Told by the watched session what the page reads and writes,
 * by `personal()` where its personal parts are, by `visit_action()` what it
 * does for every visit, and by the page's owners what it shows (`tag`) and
 * when it changes by itself (`until`). At the end it answers whether the
 * copy may be kept and cuts it: personal parts out, this visitor's form
 * token and script nonce replaced by placeholders put back fresh on every
 * serve.
 *
 * Everything inside a personal part or a visit action is that part's own
 * business (`enter()` / `leave()`): it is drawn again for every visitor.
 * Everything outside is the copy, and is held to its rules.
 */
class Capture
{
    /** Random per copy: the placeholders and markers cannot be forged by the page's own text. */
    public readonly string $id;

    protected int $depth = 0;

    /** Inside probe(): what is personal is noted, not refused. */
    protected int $probing = 0;

    protected bool $touched = false;

    /** Inside quietly(): reads are answered for by a vary, not the page. */
    protected int $quiet = 0;

    /** @var array<int, array{view: string, data: array<string, mixed>, keys?: array<string, ?string>, html?: string}> */
    protected array $holes = [];

    /**
     * What each part being drawn read — a personal part, or a part being
     * probed (whose reads outside personal parts of its own count), or a
     * visit action (whose reads and writes are nobody's: it is done again
     * for every visitor before any part is looked at).
     *
     * @var list<array{keys: array<string, ?string>, clean: bool, visit: bool}>
     */
    protected array $reading = [];

    /** @var array<string, true> session keys a part wrote while the copy was drawn */
    protected array $written = [];

    protected int $nextHole = 0;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    protected array $visits = [];

    /** @var array<string, true> */
    protected array $tags = [];

    protected ?Carbon $until = null;

    protected ?string $refusal = null;

    /**
     * @param  array{generation: int, changes: int}  $counters  as they were when the drawing began
     */
    public function __construct(
        protected PageCache $cache,
        public readonly string $kind,
        public readonly string $address,
        public readonly string $token,
        public readonly array $counters,
    ) {
        $this->id = bin2hex(random_bytes(8));
    }

    // ---------------------------------------------------------------- inside personal parts

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function inside(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Draws a part that MAY turn out personal, and says whether it did: it
     * read or wrote something personal. The caller then makes it a personal
     * part of the page (hole()) — a zone's block whose display rule asks the
     * visitor's town, say, which nobody had to declare. A part that touched
     * nothing personal stays in the copy.
     *
     * What it read is answered as for a personal part (closeHole()): as
     * drawn, it is right for anybody whose personal keys it read are as
     * this visitor's were.
     *
     * @return array{0: string, 1: bool, 2: array{keys: array<string, ?string>, clean: bool}} the HTML, whether it is personal, what it read
     */
    public function probe(callable $draw): array
    {
        $outer = $this->touched;
        $this->touched = false;
        $this->probing++;
        $this->openHole();

        try {
            $html = (string) $draw();
        } finally {
            $read = $this->closeHole();
            $this->probing--;
        }

        $touched = $this->touched;
        $this->touched = $outer || $touched;

        return [$html, $touched, $read];
    }

    /** Reads answered for by a vary the caller has named (PageCache::under()). */
    public function quietly(callable $read): mixed
    {
        $this->quiet++;

        try {
            return $read();
        } finally {
            $this->quiet--;
        }
    }

    // ---------------------------------------------------------------- what a personal part read

    public function openHole(): void
    {
        $this->reading[] = ['keys' => [], 'clean' => true, 'visit' => false];
    }

    /**
     * What the part just drawn read of the session: each personal key and
     * what it held (seen()), and whether it is CLEAN — wrote nothing, and
     * read nothing a part before it wrote — so that as drawn it is right
     * for anybody whose keys hold the same.
     *
     * @return array{keys: array<string, ?string>, clean: bool}
     */
    public function closeHole(): array
    {
        $read = array_pop($this->reading) ?? ['keys' => [], 'clean' => false];

        return ['keys' => $read['keys'], 'clean' => $read['clean']];
    }

    /**
     * A visit action done (PageCache::visit()). Every visitor's own, done
     * again before any part is drawn or reused: what it reads and writes
     * concerns no part — but a part that does one is drawn for every
     * visitor, or the visit would be done only for its first.
     */
    public function visit(callable $run): void
    {
        $this->holeSpoiled();
        $this->enter();
        $this->reading[] = ['keys' => [], 'clean' => true, 'visit' => true];

        try {
            $run();
        } finally {
            array_pop($this->reading);
            $this->leave();
        }
    }

    /** What a part saw in a session key: null while absent, else what it held. */
    public static function seen(bool $present, mixed $value): ?string
    {
        return $present ? hash('xxh128', serialize($value)) : null;
    }

    /**
     * Two parts read as one — a part holding the other, or neighbours drawn
     * together — or null when either is not right as drawn, or they saw a
     * key differently.
     *
     * @param  array<string, ?string>|null  $a
     * @param  array<string, ?string>|null  $b
     * @return array<string, ?string>|null
     */
    public static function together(?array $a, ?array $b): ?array
    {
        if ($a === null || $b === null) {
            return null;
        }

        foreach (array_intersect_key($a, $b) as $key => $seen) {
            if ($b[$key] !== $seen) {
                return null;
            }
        }

        return $a + $b;
    }

    protected function holeRead(string $key, bool $present, mixed $value): void
    {
        $role = $this->cache->sessionRole($key);
        $personal = PageCache::isPersonal($role)
            || ($role === null && array_filter($this->cache->rolesUnder($key), PageCache::isPersonal(...)) !== []);

        if ($personal) {
            $this->noteRead($key, $present, $value);
        }
    }

    /** A personal key read, against the part being drawn. */
    protected function noteRead(string $key, bool $present, mixed $value): void
    {
        if ($this->reading === []) {
            return;
        }

        $frame = &$this->reading[count($this->reading) - 1];

        if ($frame['visit']) {
            return;
        }

        $seen = self::seen($present, $value);

        if (array_key_exists($key, $frame['keys']) && $frame['keys'][$key] !== $seen) {
            $frame['clean'] = false;
        }

        $frame['keys'] += [$key => $seen];

        // A part before it changed it: what this one saw is not what a
        // visitor holds when the parts are looked at.
        foreach (array_keys($this->written) as $done) {
            if ($done === $key || str_starts_with($key, $done.'.') || str_starts_with($done, $key.'.')) {
                $frame['clean'] = false;
            }
        }
    }

    protected function holeSpoiled(): void
    {
        if ($this->reading !== []) {
            $frame = &$this->reading[count($this->reading) - 1];

            if (! $frame['visit']) {
                $frame['clean'] = false;
            }
        }
    }

    /** Written inside a part — not inside a visit action, which every visitor does first. */
    protected function partWrote(string $key): void
    {
        if ($this->reading !== [] && ! $this->reading[count($this->reading) - 1]['visit']) {
            $this->written[$key] = true;
        }
    }

    // ---------------------------------------------------------------- what the page touched

    /** Not kept, and why. The first reason stays: it is the one to fix first. */
    public function refuse(string $reason): void
    {
        $this->refusal ??= $reason;
    }

    /**
     * Something personal touched: refused — or, inside probe(), only noted:
     * with the key and what it held when it was a personal key read (the
     * part is right as drawn for whoever holds the same), else as a part
     * that is drawn for every visitor.
     */
    protected function personalTouched(string $reason, ?string $key = null, bool $present = false, mixed $value = null): void
    {
        if ($this->probing > 0) {
            $this->touched = true;

            if ($key !== null) {
                $this->noteRead($key, $present, $value);
            } else {
                $this->holeSpoiled();
            }

            return;
        }

        $this->refuse($reason);
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * A session key read outside a personal part. Nobody's key is fine to
     * read while it is absent — a visitor who HAS one is never served a copy
     * — but present means somebody wrote it during this very page.
     */
    public function sessionRead(string $key, bool $present, mixed $value = null): void
    {
        if ($this->depth > 0) {
            $this->holeRead($key, $present, $value);

            return;
        }

        if ($this->quiet > 0) {
            return;
        }

        if (($role = $this->cache->sessionRole($key)) !== null) {
            $this->obey($role, $key, $present, $value);

            return;
        }

        $under = $this->cache->rolesUnder($key);

        if ($under === []) {
            if ($present) {
                $this->personalTouched('reads the session\'s '.$key.', written while the page was drawn');
            }

            return;
        }

        foreach ($under as $role) {
            $this->obey($role, $key, $present, $value);
        }
    }

    public function sessionReadAll(): void
    {
        if ($this->depth > 0) {
            $this->holeSpoiled();
        } elseif ($this->quiet === 0) {
            $this->personalTouched('reads the whole session');
        }
    }

    /** Whatever a page writes there is what every visitor's visit would write — so a visit action, not the copy. */
    public function sessionWrite(string $key): void
    {
        if ($this->depth > 0) {
            $this->partWrote($key);
            $this->holeSpoiled();
        } else {
            $this->personalTouched('writes '.$key.' to the session');
        }
    }

    protected function obey(string $role, string $key, bool $present, mixed $value): void
    {
        if (PageCache::isPersonal($role)) {
            $this->personalTouched('reads the session\'s '.$key.' outside a personal part', $key, $present, $value);
        }
    }

    // ---------------------------------------------------------------- personal parts, visits, tags

    /**
     * A personal part as drawn for THIS visitor, between markers: their copy
     * keeps it, the stored one keeps only its place — and, with `$reuse` (the
     * personal keys it read and what they held), the part as drawn, for
     * whoever holds the same.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, ?string>|null  $reuse
     */
    public function hole(string $view, array $data, string $html, ?array $reuse = null): string
    {
        // A part found personal only after it was drawn (probe()) may hold
        // personal parts of its own: they are drawn again inside it — and it
        // is right as drawn only where each of them is.
        $inner = '/<!--\/?'.preg_quote('pc-'.$this->id.'-h', '/').'(\d+)-->/';

        if (preg_match_all($inner, $html, $found)) {
            foreach (array_unique($found[1]) as $n) {
                $reuse = self::together($reuse, $this->holes[(int) $n]['keys'] ?? null);

                unset($this->holes[(int) $n]);
            }

            $html = (string) preg_replace($inner, '', $html);
        }

        $n = $this->nextHole++;
        $this->holes[$n] = ['view' => $view, 'data' => $data] + ($reuse === null ? [] : ['keys' => $reuse]);

        return '<!--'.$this->placeholder('h'.$n).'-->'.$html.'<!--/'.$this->placeholder('h'.$n).'-->';
    }

    /** @return array<int, array{view: string, data: array<string, mixed>, keys?: array<string, ?string>, html?: string}> */
    public function holes(): array
    {
        return $this->holes;
    }

    /** @param array<string, mixed> $data */
    public function visited(string $name, array $data): void
    {
        if ($this->depth === 0) {
            $this->visits[] = [$name, $data];
        }
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    public function visits(): array
    {
        return $this->visits;
    }

    public function tag(string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->tags[$tag] = true;
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_keys($this->tags);
    }

    public function until(DateTimeInterface $when): void
    {
        $when = Carbon::instance($when);

        if ($this->until === null || $when->lt($this->until)) {
            $this->until = $when;
        }
    }

    /** When the copy stops being served: the shop's lifetime, or sooner. */
    public function expires(int $minutes): Carbon
    {
        $lifetime = now()->addMinutes($minutes);

        return $this->until !== null && $this->until->lt($lifetime) ? $this->until : $lifetime;
    }

    /** True when something the page shows changes by itself before it was even drawn. */
    public function stale(): bool
    {
        return $this->until !== null && $this->until->lte(now());
    }

    public function placeholder(string $name): string
    {
        return 'pc-'.$this->id.'-'.$name;
    }

    // ---------------------------------------------------------------- cutting the copy

    /**
     * The page as stored, and as this visitor gets it — or null, refused,
     * when a personal part did not reach the page as it was drawn (printed
     * escaped, or dropped by a part that failed): the stored copy would then
     * carry this visitor's own.
     *
     * @return array{stored: string, visitor: string}|null
     */
    public function cut(string $html, string $nonce): ?array
    {
        $stored = $html;

        foreach (array_keys($this->holes) as $n) {
            $open = '<!--'.$this->placeholder('h'.$n).'-->';
            $close = '<!--/'.$this->placeholder('h'.$n).'-->';
            $found = 0;
            $out = '';
            $at = 0;

            while (($start = strpos($stored, $open, $at)) !== false) {
                $end = strpos($stored, $close, $start + strlen($open));

                if ($end === false) {
                    break;
                }

                if ($found === 0 && isset($this->holes[$n]['keys'])) {
                    $this->holes[$n]['html'] = substr($stored, $start + strlen($open), $end - $start - strlen($open));
                }

                $out .= substr($stored, $at, $start - $at).$open;
                $at = $end + strlen($close);
                $found++;
            }

            $stored = $out.substr($stored, $at);

            if ($found === 0 || str_contains($stored, $close)) {
                $this->refuse('personal part '.$this->holes[$n]['view'].' did not reach the page as it was drawn');

                return null;
            }
        }

        $visitor = $html;

        if ($this->holes !== []) {
            $visitor = preg_replace('/<!--\/?'.preg_quote('pc-'.$this->id.'-h', '/').'\d+-->/', '', $visitor);
        }

        $private = array_filter([
            $this->token => $this->placeholder('token'),
            $nonce => $this->placeholder('nonce'),
        ], fn ($value, $key) => $key !== '', ARRAY_FILTER_USE_BOTH);

        foreach ($this->holes as $n => $hole) {
            if (isset($hole['html'])) {
                $this->holes[$n]['html'] = strtr($hole['html'], $private);
            }
        }

        return ['stored' => strtr($stored, $private), 'visitor' => $visitor];
    }
}
