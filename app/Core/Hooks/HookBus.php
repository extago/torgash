<?php

namespace App\Core\Hooks;

use App\Core\Content\Fragment;

/**
 * Named extension points. Modules subscribe with a priority (lower runs first).
 *
 * Two semantics:
 *  - filter(): each listener receives and returns the value (transform chain).
 *  - action(): listeners are notified, return values are ignored.
 *
 * Hook names are dot-namespaced by convention: "<module>.<entity>.<event>",
 * e.g. "catalog.product.get_after", "checkout.place_order_before".
 */
class HookBus
{
    /** @var array<string, list<array{priority: int, callback: callable}>> */
    protected array $listeners = [];

    /** Told about every listener call while set — see HookObserver. */
    protected ?HookObserver $observer = null;

    /** The listeners as they stood when a persistent worker finished booting. */
    protected ?array $frozen = null;

    /**
     * Remember the listeners as they stand now — called once, when a worker
     * has booted (App\Core\Support\WorkerMode).
     *
     * Under php-fpm a listener added while serving a request dies with the
     * request. Under a worker this bus outlives it, so one added at request
     * time stays for every later request of that worker: the admin's "test
     * this code channel" button subscribed a channel for ONE call, and a
     * worker would have kept offering it to customers. Listeners are declared
     * at boot; anything added after it is put back by thaw().
     */
    public function freeze(): void
    {
        $this->frozen = $this->listeners;
    }

    /** Back to the frozen listeners, with no observer — the start of a request. */
    public function thaw(): void
    {
        if ($this->frozen !== null) {
            $this->listeners = $this->frozen;
        }

        $this->observer = null;
    }

    /**
     * Set (or clear, with null) the one observer. One, not a list: it exists
     * for a profiler, and two profilers timing each other's bookkeeping would
     * both be measuring the wrong thing.
     */
    public function observe(?HookObserver $observer): void
    {
        $this->observer = $observer;
    }

    public function listen(string $hook, callable $callback, int $priority = 10): void
    {
        $this->listeners[$hook][] = ['priority' => $priority, 'callback' => $callback];
        usort($this->listeners[$hook], fn ($a, $b) => $a['priority'] <=> $b['priority']);
    }

    public function filter(string $hook, mixed $value, array $context = []): mixed
    {
        if ($this->observer === null) {
            foreach ($this->listeners[$hook] ?? [] as $listener) {
                $value = ($listener['callback'])($value, $context);
            }

            return $value;
        }

        foreach ($this->listeners[$hook] ?? [] as $listener) {
            $observer = $this->observer;
            $observer->enter($hook, $listener['callback']);

            try {
                $value = ($listener['callback'])($value, $context);
            } finally {
                $observer->leave($hook, $listener['callback']);
            }
        }

        return $value;
    }

    /**
     * A filter whose listeners each ADD a piece of a page to a string — a zone,
     * the header's icons, what goes before `</body>` — with every listener
     * drawn as a `Fragment`: one that throws is left out (staff see a plate
     * naming the module it came from) and the others still print. Same seed
     * and accumulation as `filter($hook, '', $context)`, so a listener never
     * knows which of the two called it.
     *
     * Only for positions a page can do without — never a form's fields.
     */
    public function render(string $hook, array $context = []): string
    {
        $html = '';

        foreach ($this->listeners[$hook] ?? [] as $listener) {
            $callback = $listener['callback'];
            $observer = $this->observer;

            $html = Fragment::isolate(
                function () use ($hook, $callback, $observer, $html, $context) {
                    if ($observer === null) {
                        return $callback($html, $context);
                    }

                    $observer->enter($hook, $callback);

                    try {
                        return $callback($html, $context);
                    } finally {
                        $observer->leave($hook, $callback);
                    }
                },
                fn () => trim($hook.' · '.Fragment::origin($callback), ' ·'),
                otherwise: $html,
            );
        }

        return $html;
    }

    public function action(string $hook, array $context = []): void
    {
        if ($this->observer === null) {
            foreach ($this->listeners[$hook] ?? [] as $listener) {
                ($listener['callback'])($context);
            }

            return;
        }

        foreach ($this->listeners[$hook] ?? [] as $listener) {
            $observer = $this->observer;
            $observer->enter($hook, $listener['callback']);

            try {
                ($listener['callback'])($context);
            } finally {
                $observer->leave($hook, $listener['callback']);
            }
        }
    }

    public function hasListeners(string $hook): bool
    {
        return ! empty($this->listeners[$hook]);
    }
}
