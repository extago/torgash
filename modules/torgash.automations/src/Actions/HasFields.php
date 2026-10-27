<?php

namespace Torgash\Automations\Actions;

/**
 * An action whose settings are a few plain choices, declared as data so the
 * rule form draws them and the controller stores exactly those keys — which is
 * how a module adds an action without touching this module's form.
 *
 * name => ['label' => English source, 'type' => 'select'|'text',
 *          'options' => [value => already-translated label]]
 */
interface HasFields
{
    /** @return array<string, array<string, mixed>> */
    public static function fields(): array;
}
