<?php

namespace App\Core\PageCache;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\View;
use Illuminate\View\Factory;
use InvalidArgumentException;

/**
 * Personal parts of a page (PLAN §61): `personal('view', [data])`, and the
 * `@personal` directive that prints it.
 *
 * Drawn as an ordinary view in every request. While a copy of the page is
 * being drawn it is drawn between markers, and the stored copy keeps only
 * its place; every visitor served from that copy gets it drawn again with
 * the same data, in their own session.
 *
 * So the data is what the stored copy keeps: plain values — numbers,
 * strings, lists of them — and models, which are kept as their class and key
 * and come back FRESH, all of one class in one query: what the view loads
 * through one is loaded again, so a part reads columns, not relations. And
 * the view is drawn ALONE: it sees its data and what is shared with every
 * view, never the variables of the template around it, which a copy does not
 * have. A part must not push to a stack either: the copy's stack holds what
 * the first visitor's part pushed.
 *
 * `$reusable`: the part depends on nothing of the visitor's but their
 * session — not their address, not a header, not the clock. Then a visitor
 * who holds none of the personal keys it read when it was drawn gets it as
 * it was drawn, without drawing it again: the heart on every card of a
 * listing costs nothing for a guest with no wishlist.
 */
class Holes
{
    /** How a model is kept in a stored part's data. */
    public const MODEL = '__model';

    public function __construct(protected PageCache $cache) {}

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = [], bool $reusable = false): string
    {
        $capture = $this->cache->current();

        if ($capture === null || $capture->inside()) {
            return $this->draw($view, $data);
        }

        $kept = $this->freeze($data);

        if (($bad = $this->notPlain($kept)) !== null) {
            if (config('app.debug')) {
                throw new InvalidArgumentException("A personal part's data must be plain values or models: [{$view}] is given {$bad}.");
            }

            $capture->refuse('personal part '.$view.' is given '.$bad);
        }

        $stacks = $this->stacks();
        $capture->enter();
        $capture->openHole();

        try {
            $html = $this->draw($view, $data);
        } finally {
            $read = $capture->closeHole();
            $capture->leave();
        }

        if ($this->stacks() !== $stacks) {
            $capture->refuse('personal part '.$view.' pushes to a stack');
        }

        return $capture->hole($view, $kept, $html, $reusable && $read['clean'] ? $read['keys'] : null);
    }

    /**
     * Draws a part that may turn out to be the visitor's own — a zone's
     * block, whose display rule may ask the visitor's town — and says whether
     * it did, and whether it pushed to a stack (which a personal part must
     * not). Outside the drawing of a copy, simply draws it.
     *
     * `read` is for adopt(): the personal keys it read and what they held,
     * when it is right as drawn for whoever holds the same — or null.
     *
     * @return array{html: string, personal: bool, pushed: bool, read: array<string, ?string>|null}
     */
    public function probe(callable $draw): array
    {
        if (! $this->cache->drawing()) {
            return ['html' => (string) $draw(), 'personal' => false, 'pushed' => false, 'read' => null];
        }

        $stacks = $this->stacks();
        [$html, $personal, $read] = $this->cache->current()->probe($draw);

        return ['html' => $html, 'personal' => $personal, 'pushed' => $this->stacks() !== $stacks, 'read' => $read['clean'] ? $read['keys'] : null];
    }

    /**
     * A part drawn already — by probe() — made a personal part of the copy,
     * which `$view` draws again from `$data` for every visitor; with `$read`
     * (probe()'s), as drawn for whoever holds what it read.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, ?string>|null  $read
     */
    public function adopt(string $view, array $data, string $html, ?array $read = null): string
    {
        if (! $this->cache->drawing()) {
            return $html;
        }

        $capture = $this->cache->current();
        $kept = $this->freeze($data);

        if (($bad = $this->notPlain($kept)) !== null) {
            $capture->refuse('personal part '.$view.' is given '.$bad);

            return $html;
        }

        return $capture->hole($view, $kept, $html, $read);
    }

    /** @param array<string, mixed> $data */
    public function draw(string $view, array $data): string
    {
        return view($view, $data)->render();
    }

    /**
     * The stored parts of a copy, drawn for this visitor — the models of all
     * of them fetched first, one query a class.
     *
     * @param  array<int|string, array{view: string, data: array<string, mixed>}>  $holes
     * @return array<int|string, string>
     */
    public function drawAll(array $holes): array
    {
        $wanted = [];

        foreach ($holes as $hole) {
            $this->collect((array) $hole['data'], $wanted);
        }

        $found = [];

        foreach ($wanted as $class => $keys) {
            if (is_subclass_of($class, Model::class)) {
                $found[$class] = $class::query()->whereKey(array_keys($keys))->get()->keyBy(fn (Model $m) => (string) $m->getKey())->all();
            }
        }

        $html = [];

        foreach ($holes as $n => $hole) {
            $html[$n] = $this->draw($hole['view'], $this->thaw((array) $hole['data'], $found));
        }

        return $html;
    }

    /**
     * Models replaced by what finds them again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function freeze(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Model) {
                $data[$key] = [self::MODEL => $value::class, 'key' => (string) $value->getKey()];
            } elseif (is_array($value)) {
                $data[$key] = $this->freeze($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<class-string, array<string, true>>  $wanted
     */
    protected function collect(array $data, array &$wanted): void
    {
        foreach ($data as $value) {
            if (! is_array($value)) {
                continue;
            }

            if (isset($value[self::MODEL], $value['key'])) {
                $wanted[(string) $value[self::MODEL]][(string) $value['key']] = true;
            } else {
                $this->collect($value, $wanted);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<class-string, array<string, Model>>  $found
     * @return array<string, mixed>
     */
    protected function thaw(array $data, array $found): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (isset($value[self::MODEL], $value['key'])) {
                $data[$key] = $found[$value[self::MODEL]][(string) $value['key']]
                    ?? throw new InvalidArgumentException('A personal part\'s '.$value[self::MODEL].' '.$value['key'].' is gone.');
            } else {
                $data[$key] = $this->thaw($value, $found);
            }
        }

        return $data;
    }

    /** What is not a plain value in the data, named — or null. */
    protected function notPlain(mixed $value, string $path = 'data'): ?string
    {
        if ($value === null || is_scalar($value)) {
            return null;
        }

        if (! is_array($value)) {
            return get_debug_type($value).' at '.$path;
        }

        foreach ($value as $key => $item) {
            if (($bad = $this->notPlain($item, $path.'.'.$key)) !== null) {
                return $bad;
            }
        }

        return null;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} what the page's stacks hold so far */
    protected function stacks(): array
    {
        static $read = null;
        $read ??= Closure::bind(static fn (Factory $views): array => [$views->pushes, $views->prepends], null, Factory::class);

        return $read(View::getFacadeRoot());
    }
}
