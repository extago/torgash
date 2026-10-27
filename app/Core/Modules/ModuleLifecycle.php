<?php

namespace App\Core\Modules;

/**
 * A module that has something to do when it is installed or removed, beyond
 * its migrations, permissions and settings.
 *
 * Implemented by the module's SERVICE PROVIDER — the one class core already
 * knows the name of, from the manifest — so declaring lifecycle work costs a
 * module an interface and two methods, and costs core no registry.
 *
 * The rules are the ones the rest of the engine already lives by:
 *
 * - `install()` runs LAST, when the module's tables, permissions and settings
 *   are all in place, so it may use them.
 * - `uninstall()` runs FIRST, before any of that is taken away, for the same
 *   reason — and because the whole point of it is to leave no traces, which is
 *   hard to do once your own tables are gone.
 * - Both are given a booted application but NOT a booted module: the provider
 *   is instantiated for this call alone and never registered, so `install()`
 *   must not assume `boot()` has run.
 *
 * Whatever is created here must be undone there, and `uninstall()` must be
 * safe to run when `install()` never finished — a half-installed module is
 * exactly the one somebody removes.
 */
interface ModuleLifecycle
{
    public function install(): void;

    public function uninstall(): void;
}
