<?php

namespace Torgash\Automations\Subjects;

use Torgash\Automations\Models\RuleCondition;

/**
 * The half of a subject that is the same for all of them: reading a submitted
 * condition row into what is stored, and saying a stored one back in words.
 * Both follow the kind's `input`, so a subject only has to declare its kinds.
 */
abstract class BaseSubject implements Subject
{
    public function usesFields(string $event): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [];
    }

    public function afterActions(): void {}

    /**
     * What a submitted condition row stores, by the kind's input.
     *
     * A comma-separated line (`csv`) is how a human types "UA, PL, DE"; storing
     * it as a list is what makes the check an in_array rather than a substring
     * match that would let "UA" match "GUAM".
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function valueFrom(string $kind, array $row): array
    {
        return match ($this->conditions()[$kind]['input'] ?? 'text') {
            'list' => ['list' => array_values(array_filter((array) ($row['list'] ?? [])))],
            'csv' => ['list' => array_values(array_filter(array_map('trim', explode(',', (string) ($row['text'] ?? '')))))],
            'amount' => ['amount' => (int) round((float) str_replace(',', '.', (string) ($row['amount'] ?? 0)) * 100)],
            'categories' => ['ids' => array_values(array_filter(array_map('intval', (array) ($row['ids'] ?? []))))],
            'none' => [],
            default => ['text' => trim((string) ($row['text'] ?? ''))],
        };
    }

    /**
     * One condition in plain words — "Order status — is in New, Paid" — so a
     * rule that "did not fire" is visibly a rule with requirements.
     */
    public function describe(RuleCondition $condition): string
    {
        $schema = $this->conditions()[$condition->kind] ?? null;

        if (! $schema) {
            return $condition->kind.' — '.$condition->operator;
        }

        $label = __($schema['label']);
        $operator = __($schema['operators'][$condition->operator] ?? $condition->operator);
        $options = (array) ($schema['options'] ?? []);

        $value = in_array($condition->operator, (array) ($schema['bare'] ?? []), true) ? '' : match ($schema['input'] ?? 'text') {
            'list' => implode(', ', array_map(fn ($v) => $options[$v] ?? $v, $condition->list())),
            'csv' => implode(', ', $condition->list()),
            // Read on the rules screen only, so the admin's own "show amounts
            // in" applies — format_price() would follow the storefront session.
            'amount' => admin_price($condition->amount()),
            'categories' => $this->names($condition->ids(), $options),
            'none' => '',
            default => $condition->text(),
        };

        return trim("{$label} — {$operator} {$value}");
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int|string, string>  $names
     */
    protected function names(array $ids, array $names): string
    {
        if ($ids === []) {
            return '';
        }

        $known = array_filter(array_map(fn (int $id) => isset($names[$id]) ? trim($names[$id], " \u{00A0}—-") : null, $ids));

        return $known !== [] ? implode(', ', $known) : __(':count selected', ['count' => count($ids)]);
    }
}
