<?php

namespace App\Core\Content;

use Closure;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
use ReflectionFunction;
use Throwable;

/**
 * A part of a page the page can live without — a block, a zone, what a module
 * adds to the header — drawn so that its failure is ITS failure and not the
 * page's.
 *
 * Without this one broken block (a template a theme overrode, a setting a
 * module does not expect, a listener that throws) turns the whole page into a
 * 500, and a broken header block turns every page into one. With it, the
 * block is left out: the visitor sees the rest of the page, the error goes to
 * the log (and so to the guard's journal), and staff looking at the
 * storefront see a red plate where the block should be, naming it.
 *
 * **The view factory's state is put back by hand.** When a Blade view throws,
 * Laravel calls `Factory::flushState()` — every section, stack, component
 * and loop of the WHOLE page is forgotten, not just the failing view's. That
 * is right when the exception ends the request, and exactly wrong here: the
 * layout goes on rendering, `@yield('content')` finds nothing and
 * `@stack('scripts')` prints nothing, so catching the exception alone would
 * swap a loud error for a page silently missing its middle. The state is
 * copied before and restored after a failure; the copy is a handful of array
 * references (copy-on-write), so it costs nothing measurable on the path
 * where nothing fails — and a `try` costs nothing at all in PHP until it
 * catches.
 *
 * Under APP_DEBUG the exception is thrown on: a developer wants the page with
 * the stack trace, not a red plate. `DebugOnlyLocally` already switches debug
 * off for anything that is not this machine, so a live shop always isolates.
 *
 * Only for what a page can do WITHOUT. A field of a form, a payment method or
 * a price is never drawn through here: leaving out a part that matters is a
 * worse answer than an error page.
 */
class Fragment
{
    /**
     * @param  callable(): mixed  $render
     * @param  string|Closure(): string  $what  what to call it on the plate — a closure is asked only on failure
     * @param  string|Closure(): ?string|null  $editUrl  where to fix it — a closure is asked only on failure
     * @param  string  $otherwise  printed before the plate on failure (what a zone had drawn before this listener)
     */
    public static function isolate(callable $render, string|Closure $what, string|Closure|null $editUrl = null, string $otherwise = ''): string
    {
        $views = View::getFacadeRoot();
        $state = self::viewState($views);
        $level = ob_get_level();

        try {
            return (string) $render();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            self::restore($views, $state);

            if (config('app.debug')) {
                throw $e;
            }

            report($e);

            return $otherwise.self::plate($what, $editUrl, $e);
        }
    }

    /**
     * Where a listener was written, as a person would look for it: the
     * module's (or theme's) folder, else the file. Asked only on failure.
     */
    public static function origin(callable $callback): string
    {
        try {
            $file = (new ReflectionFunction(Closure::fromCallable($callback)))->getFileName() ?: '';
        } catch (Throwable) {
            return '';
        }

        $file = str_replace('\\', '/', $file);

        if (preg_match('#/(modules|themes)/([^/]+)/#', $file, $match)) {
            return $match[2];
        }

        return Str::after($file, str_replace('\\', '/', base_path()).'/');
    }

    /** What staff see in place of the part that failed; nobody else sees anything. */
    protected static function plate(string|Closure $what, string|Closure|null $editUrl, Throwable $e): string
    {
        try {
            if (! auth()->user()?->isStaff()) {
                return '';
            }

            return view('errors.fragment', [
                'fragmentWhat' => $what instanceof Closure ? (string) $what() : $what,
                'fragmentEditUrl' => $editUrl instanceof Closure ? $editUrl() : $editUrl,
                'fragmentError' => class_basename($e).': '.Str::limit($e->getMessage(), 400),
            ])->render();
        } catch (Throwable) {
            // The plate is a courtesy; if even it cannot be drawn, the part
            // is simply left out.
            return '';
        }
    }

    /**
     * Everything `Factory::flushState()` clears, plus the loop stack a
     * half-run `@foreach` leaves behind.
     *
     * @return array<string, mixed>
     */
    protected static function viewState(Factory $views): array
    {
        // Each property by name rather than get_object_vars(): the factory
        // also holds its finder caches, and copying those made every
        // isolated part cost microseconds instead of a fraction of one.
        // A static closure with the factory's SCOPE and no object bound —
        // it holds nothing, so keeping it outlives no request.
        static $read = null;
        $read ??= Closure::bind(static fn (Factory $views): array => [
            'renderCount' => $views->renderCount, 'renderedOnce' => $views->renderedOnce,
            'sections' => $views->sections, 'sectionStack' => $views->sectionStack,
            'pushes' => $views->pushes, 'prepends' => $views->prepends, 'pushStack' => $views->pushStack,
            'componentStack' => $views->componentStack, 'componentData' => $views->componentData,
            'currentComponentData' => $views->currentComponentData, 'slots' => $views->slots, 'slotStack' => $views->slotStack,
            'fragments' => $views->fragments, 'fragmentStack' => $views->fragmentStack,
            'loopsStack' => $views->loopsStack,
        ], null, Factory::class);

        return $read($views);
    }

    /** @param  array<string, mixed>  $state */
    protected static function restore(Factory $views, array $state): void
    {
        Closure::bind(static function (Factory $views, array $state): void {
            foreach ($state as $key => $value) {
                $views->{$key} = $value;
            }
        }, null, Factory::class)($views, $state);
    }
}
