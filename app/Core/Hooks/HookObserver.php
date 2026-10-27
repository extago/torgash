<?php

namespace App\Core\Hooks;

/**
 * Somebody who wants to know what each hook listener cost — a profiler.
 *
 * `enter()` before a listener runs and `leave()` after it (in a `finally`, so
 * an exception still closes the frame). Nested hooks nest their calls, which
 * is what lets an observer tell a listener's OWN time from the time of the
 * hooks it fired in turn.
 *
 * Core ships none. The bus asks one only when it has been given one, so a
 * shop that measures nothing pays a single null check per hook call.
 */
interface HookObserver
{
    public function enter(string $hook, callable $listener): void;

    public function leave(string $hook, callable $listener): void;
}
