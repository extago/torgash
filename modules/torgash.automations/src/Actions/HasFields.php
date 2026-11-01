<?php

namespace Torgash\Automations\Actions;

/**
 * An action whose settings are a few plain choices, declared as data so the
 * rule form draws them and the controller stores exactly those keys — which is
 * how a module adds an action without touching this module's form.
 *
 * name => ['label' => English source, 'type' => 'select'|'text'|'textarea',
 *          'options' => [value => already-translated label], 'help' => English source]
 *
 * A select is drawn in the action's row; text and textarea full width under
 * it — the words of a letter or a note, written with the template's
 * {{ variables }} (the panel inserts into them like any other field).
 */
interface HasFields
{
    /** @return array<string, array<string, mixed>> */
    public static function fields(): array;
}
