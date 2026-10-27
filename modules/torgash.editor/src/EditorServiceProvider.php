<?php

namespace Torgash\Editor;

use Illuminate\Support\ServiceProvider;

/**
 * The visual editor, and one seam of its own.
 *
 * Core asks `core.field.rich` for an editor and this answers it, so no core
 * file knows TipTap exists. This module in turn asks `editor.images` for
 * somewhere to put images that go INSIDE the text, and does not know what
 * answers — the media module does, for any record whose model was registered
 * as keeping content images. Two hooks facing opposite directions, and neither
 * module names a class of the other's: the editor works with the media module
 * uninstalled (no image button), and the media module works with a plain
 * textarea (no editor).
 *
 * The answer is a shape, not markup: `['endpoint' => …, 'allow_remote' => …]`.
 * Whoever can store the files says WHERE; drawing the panel and driving the
 * document is this module's job, because that half is TipTap's business and
 * nobody else's.
 */
class EditorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Core asks this filter for a rich editor whenever a field is marked
        // rich; with this module disabled nothing answers and the field stays
        // a plain textarea. No core file knows TipTap exists.
        hook_listen('core.field.rich', fn (mixed $current, array $context) => $current ?? view(
            'torgash.editor::field',
            [
                'name' => $context['name'],
                'value' => (string) ($context['value'] ?? ''),
                'placeholder' => $context['placeholder'] ?? null,
                'label' => $context['label'] ?? null,
                // Null for a field with no row behind it yet — a product being
                // created has no id to hang a file on — and the image half of
                // the editor is then simply not offered.
                'images' => hook_filter('editor.images', null, [
                    'record' => $context['record'] ?? null,
                ]),
            ],
        )->render());
    }
}
