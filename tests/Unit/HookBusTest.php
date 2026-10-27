<?php

namespace Tests\Unit;

use App\Core\Hooks\HookBus;
use PHPUnit\Framework\TestCase;

/**
 * Hooks are how modules extend each other without depending on each other, so
 * their two guarantees — a filter chains, an action does not — and the
 * priority order are worth pinning down.
 */
class HookBusTest extends TestCase
{
    public function test_a_filter_passes_the_value_down_the_chain(): void
    {
        $bus = new HookBus;
        $bus->listen('test.value', fn ($value) => $value.' one');
        $bus->listen('test.value', fn ($value) => $value.' two');

        $this->assertSame('start one two', $bus->filter('test.value', 'start'));
    }

    public function test_lower_priority_runs_first_regardless_of_registration_order(): void
    {
        $bus = new HookBus;
        $bus->listen('test.value', fn ($value) => $value.'late', 50);
        $bus->listen('test.value', fn ($value) => $value.'early', 1);

        $this->assertSame('earlylate', $bus->filter('test.value', ''));
    }

    public function test_context_reaches_every_listener(): void
    {
        $bus = new HookBus;
        $bus->listen('test.value', fn ($value, $context) => $value + $context['add']);
        $bus->listen('test.value', fn ($value, $context) => $value * $context['times']);

        $this->assertSame(20, $bus->filter('test.value', 5, ['add' => 5, 'times' => 2]));
    }

    public function test_an_action_ignores_return_values_and_notifies_everyone(): void
    {
        $bus = new HookBus;
        $seen = [];

        $bus->listen('test.event', function (array $context) use (&$seen) {
            $seen[] = 'first:'.$context['id'];

            return 'ignored';
        });
        $bus->listen('test.event', function (array $context) use (&$seen) {
            $seen[] = 'second:'.$context['id'];
        });

        $bus->action('test.event', ['id' => 7]);

        $this->assertSame(['first:7', 'second:7'], $seen);
    }

    public function test_an_unknown_hook_returns_the_value_untouched(): void
    {
        $bus = new HookBus;

        $this->assertSame('untouched', $bus->filter('nobody.listens', 'untouched'));
        $this->assertFalse($bus->hasListeners('nobody.listens'));

        // An action with no listeners must be a no-op, not an error: modules
        // fire hooks whether or not anything is installed to answer them.
        $bus->action('nobody.listens');
    }
}
