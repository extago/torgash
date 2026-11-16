<?php

namespace Torgash\Automations\Telegram;

use Torgash\Automations\Actions\HasFields;
use Torgash\Automations\Jobs\RunRule;

/**
 * What a button under a Telegram message can DO (PLAN §64.1): one of the
 * actions a rule already has, with its one choice made — "Set the status:
 * Shipped", "Assign a manager: whoever pressed". Nothing new is invented for
 * a button; pressing it runs the same action class a rule would, so a status
 * set from a phone moves stock and writes history exactly as it does from the
 * admin.
 *
 * A choice is stored as `type|field|value`. `@me` in a manager's field is the
 * person who pressed.
 */
class Buttons
{
    public const ME = '@me';

    /** Telegram's own limits: a row reads badly past three, a label past ~30 characters. */
    public const MAX = 6;

    /**
     * do => label, for the rule form — what the actions of this subject offer.
     *
     * @return array<string, string>
     */
    public static function choices(string $subject): array
    {
        $choices = [];

        foreach (RunRule::actionsFor($subject) as $type => $class) {
            if ($type === 'note') {
                $choices['note||'] = __('Write the button\'s name into the history');

                continue;
            }

            if (! is_subclass_of($class, HasFields::class)) {
                continue;
            }

            $fields = $class::fields();

            // One choice is one press: an action that asks for more than a
            // single pick (the words of a letter, a number of points) is a
            // rule's, not a button's.
            if (count($fields) !== 1 || (reset($fields)['type'] ?? 'text') !== 'select') {
                continue;
            }

            $name = (string) array_key_first($fields);
            $label = __($class::label());

            if ($name === 'manager_id') {
                $choices[$type.'|'.$name.'|'.self::ME] = $label.': '.__('whoever pressed');
            }

            foreach ((array) (reset($fields)['options'] ?? []) as $value => $option) {
                $choices[$type.'|'.$name.'|'.$value] = $label.': '.$option;
            }
        }

        return $choices;
    }

    /**
     * The buttons a form posted, kept to what this subject can do.
     *
     * @param  mixed  $rows  [{label, do}]
     * @return list<array{label: string, do: string}>
     */
    public static function clean(mixed $rows, string $subject): array
    {
        $known = self::choices($subject);
        $buttons = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $do = (string) ($row['do'] ?? '');

            if ($label !== '' && isset($known[$do]) && count($buttons) < self::MAX) {
                $buttons[] = ['label' => mb_substr($label, 0, 40), 'do' => $do];
            }
        }

        return $buttons;
    }

    /**
     * Telegram's `inline_keyboard` for a message row: two buttons to a line.
     *
     * @param  list<array{label: string, do: string}>  $buttons
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public static function keyboard(int $messageId, array $buttons): array
    {
        $keys = [];

        foreach (array_values($buttons) as $index => $button) {
            $keys[] = ['text' => $button['label'], 'callback_data' => 'ta:'.$messageId.':'.$index];
        }

        return array_chunk($keys, 2);
    }

    /** @return array{type: string, field: string, value: string} */
    public static function parse(string $do): array
    {
        [$type, $field, $value] = array_pad(explode('|', $do, 3), 3, '');

        return ['type' => $type, 'field' => $field, 'value' => $value];
    }
}
