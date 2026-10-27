<?php

namespace Torgash\Automations\Subjects;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Models\RuleCondition;

/**
 * What a rule is ABOUT — an order, an enquiry, whatever a module registers next.
 *
 * The engine (trigger → wait → conditions → actions, the retries, the log) is
 * the same for all of them; what differs is everything that has to know the
 * row: which events announce it, what can be asked of it, which variables a
 * message can print. So each of those is answered here, by whoever owns the
 * data — this module registers the order, `torgash.enquiries` registers the
 * enquiry through `automations.subjects`, and neither module names the other's
 * classes. The rule itself stores nothing new: its event says which subject it
 * is about.
 */
interface Subject
{
    /** English source string: what one of these is called ("Order"). */
    public static function label(): string;

    /**
     * event => English source label. Events are hook names the owner already
     * announces, the module's rule since the start.
     *
     * @return array<string, string>
     */
    public function triggers(): array;

    /**
     * Subscribe to this subject's events and call `$fire($event, $model,
     * $changed)` for each. Called once, after every provider has booted.
     */
    public function listen(Closure $fire): void;

    /** Does this event carry a diff a rule can narrow by field? */
    public function usesFields(string $event): bool;

    /** @return array<string, string> field => English label, for the diff filter */
    public function fields(): array;

    public function find(int $id): ?Model;

    /** The newest one, for "Test on the latest …". */
    public function sample(): ?Model;

    /**
     * The variables a message can print, as a nested array.
     *
     * @param  list<string>  $changed
     * @return array<string, mixed>
     */
    public function context(Model $model, string $event, array $changed = []): array;

    /**
     * The variables, grouped and described for the picker.
     *
     * @return array<string, array<string, string>> group => [path => English label]
     */
    public function variables(): array;

    /**
     * What a condition can ask, as DATA the form draws from:
     * kind => [label, operators => [op => label], input, options?, bare?].
     * `input` is list | amount | categories | text | csv | none; `bare` lists
     * operators that take no value even though the kind usually does.
     *
     * @return array<string, array<string, mixed>>
     */
    public function conditions(): array;

    public function passes(RuleCondition $condition, Model $model): bool;

    /**
     * Short names and admin links for a page of run-log rows, in one query.
     *
     * @param  list<int>  $ids
     * @return array<int, array{label: string, url: string|null}>
     */
    public function refs(array $ids): array;

    /** Whatever must be announced while the rule guard is still up. */
    public function afterActions(): void;
}
