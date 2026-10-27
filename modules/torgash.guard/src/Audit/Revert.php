<?php

namespace Torgash\Guard\Audit;

use App\Core\Settings\SettingsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Torgash\Guard\Models\AuditEntry;

/**
 * "Put it back as it was" for one change in the trail.
 *
 * The trail was written to be READ, and what it keeps decides what can be
 * undone — so this refuses rather than guesses, field by field:
 *
 * - **Only a change, never a creation or a deletion.** Putting back a
 *   deleted product's row would bring back a product without its pictures,
 *   prices and categories, which went with it; removing a created one is a
 *   deletion with no confirmation screen of its own.
 * - **Only what the owner says is content.** A model opts in with
 *   `public static function auditRevertible(): bool` — the same place it
 *   names itself with `auditLabel()`. An order, a payment or a stock row is
 *   never put back by copying a column: those move through their own doors
 *   (PlaceOrder, RecordPayment, StockMoves) and a copied number would skip
 *   everything those doors do. Settings are core's and always revertible.
 * - **Only a value kept whole.** The trail shortens long values and masks
 *   credentials; a description cut at 300 characters is not the old
 *   description, and a mask is not a password.
 * - **Only if nobody changed it since.** The field must still hold what
 *   this change left; otherwise putting back the old value would also
 *   silently undo the later edit.
 *
 * The write goes through the model's own `save()` (or SettingsManager), so
 * everything a save does still happens — a price is recomputed from the
 * typed one, caches are dropped — and the revert is itself an entry in the
 * trail, by the person who pressed the button.
 */
class Revert
{
    public function __construct(protected Auditor $auditor) {}

    /** Whether the entry is of a kind this can undo at all (no database read). */
    public function offered(AuditEntry $entry): bool
    {
        if ($entry->action !== 'updated' || ! is_array($entry->changes) || $entry->changes === []) {
            return false;
        }

        if (! $this->kindRevertible($entry->entity_type)) {
            return false;
        }

        foreach ($entry->changes as $sides) {
            if (is_array($sides) && array_key_exists('from', $sides) && self::whole($sides['from'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Undo what can be undone of one entry.
     *
     * `$parked` is what a field REALLY holds when the caller has moved it
     * aside for a moment (`RevertTask` parks unique values to untangle a
     * swap): the "nobody changed it since" check reads that instead.
     *
     * @param  array<string, mixed>  $parked
     * @return array{reverted: list<string>, skipped: array<string, string>, gone?: bool}
     */
    public function apply(AuditEntry $entry, array $parked = []): array
    {
        if (! $this->offered($entry)) {
            return ['reverted' => [], 'skipped' => []];
        }

        return $entry->entity_type === 'setting' ? $this->setting($entry) : $this->model($entry, $parked);
    }

    /** A value the trail kept in full — not cut short, not masked. */
    public static function whole(mixed $value): bool
    {
        if (! is_string($value)) {
            return true;
        }

        if (str_contains($value, Auditor::MASK)) {
            return false;
        }

        return ! (mb_strlen($value) === Auditor::MAX_VALUE + 1 && str_ends_with($value, '…'));
    }

    protected function kindRevertible(?string $type): bool
    {
        if ($type === 'setting') {
            return true;
        }

        return is_string($type) && class_exists($type) && is_subclass_of($type, Model::class)
            && method_exists($type, 'auditRevertible') && $type::auditRevertible();
    }

    /**
     * @param  array<string, mixed>  $parked
     * @return array{reverted: list<string>, skipped: array<string, string>, gone?: bool}
     */
    protected function model(AuditEntry $entry, array $parked = []): array
    {
        /** @var class-string<Model> $class */
        $class = $entry->entity_type;
        $model = $class::query()->find($entry->entity_id);

        if (! $model) {
            return ['reverted' => [], 'skipped' => [], 'gone' => true];
        }

        $current = $model->getAttributes();

        // A parked field is read — and recorded in the trail — as what it
        // really holds, not as the placeholder it wears for a moment.
        if ($parked !== []) {
            $current = array_merge($current, array_intersect_key($parked, $current));
            $model->setRawAttributes($current, true);
        }

        $set = [];
        $skipped = [];

        foreach ($entry->changes as $key => $sides) {
            $key = (string) $key;

            if (! is_array($sides) || ! array_key_exists('from', $sides) || ! self::whole($sides['from'])) {
                $skipped[$key] = 'kept_cut';

                continue;
            }

            if (! array_key_exists($key, $current)) {
                $skipped[$key] = 'no_column';

                continue;
            }

            if (! $this->same($this->auditor->value($key, $current[$key]), $sides['to'] ?? null)) {
                $skipped[$key] = 'changed_since';

                continue;
            }

            $set[$key] = $sides['from'];
        }

        if ($set !== []) {
            // Raw, the way the trail recorded them (Auditor::diff reads raw on
            // both sides), so a jsonb column gets its JSON text back and the
            // model's casts read it as they read any stored row.
            $model->setRawAttributes(array_merge($current, $set));
            $model->save();
        }

        return ['reverted' => array_keys($set), 'skipped' => $skipped];
    }

    /**
     * A setting is addressed `module.key` (`@storefront` when overridden for
     * one); the key is the one field of its change, which is how a module id
     * with dots in it is told from the key.
     *
     * @return array{reverted: list<string>, skipped: array<string, string>}
     */
    protected function setting(AuditEntry $entry): array
    {
        $key = (string) array_key_first($entry->changes);
        $sides = $entry->changes[$key];
        [$address, $storefront] = array_pad(explode('@', (string) $entry->entity_id, 2), 2, null);
        $module = Str::beforeLast($address, '.'.$key);
        $storefrontId = $storefront !== null ? (int) $storefront : null;

        $settings = app(SettingsManager::class);
        $now = $settings->get($module, $key, null, $storefrontId);

        if (! self::whole($sides['from'] ?? null)) {
            return ['reverted' => [], 'skipped' => [$key => 'kept_cut']];
        }

        if (! $this->same($this->auditor->value($key, $now), $sides['to'] ?? null)) {
            return ['reverted' => [], 'skipped' => [$key => 'changed_since']];
        }

        // The trail keeps a list as its JSON text; a setting that IS a list
        // gets the list back.
        $from = $sides['from'];
        if (is_array($now) && is_string($from)) {
            $decoded = json_decode($from, true);
            $from = is_array($decoded) ? $decoded : $from;
        }

        $settings->set($module, $key, $from, $storefrontId);

        return ['reverted' => [$key], 'skipped' => []];
    }

    /**
     * Two stored values as the same value: a number read back from Postgres
     * and the number kept in the trail's JSON, `true` and `"1"` from a form.
     * NULL is only ever the same as NULL — "was empty" and "is 0" are
     * different answers.
     */
    protected function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        return (string) $a === (string) $b;
    }
}
