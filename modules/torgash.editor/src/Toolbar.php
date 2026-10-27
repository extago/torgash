<?php

namespace Torgash\Editor;

/**
 * Turns the module's "toolbar" setting ("bold, italic, | , h2") into the
 * buttons the field view renders.
 *
 * Buttons are markup, not JavaScript: rendering them server-side lets them use
 * the core <x-icon> component like every other icon in the admin, and the
 * toolbar is visible before editor.mjs has even loaded.
 */
class Toolbar
{
    /** key => [lucide icon, tooltip]. Anything not listed is silently dropped. */
    public const BUTTONS = [
        'bold' => ['bold', 'Bold'],
        'italic' => ['italic', 'Italic'],
        'strike' => ['strikethrough', 'Strikethrough'],
        'code' => ['code-xml', 'Inline code'],
        'h1' => ['heading-1', 'Heading 1'],
        'h2' => ['heading-2', 'Heading 2'],
        'h3' => ['heading-3', 'Heading 3'],
        'bullet' => ['list', 'Bullet list'],
        'ordered' => ['list-ordered', 'Numbered list'],
        'quote' => ['text-quote', 'Blockquote'],
        'codeblock' => ['square-code', 'Code block'],
        'hr' => ['minus', 'Horizontal rule'],
        'link' => ['link', 'Link'],
        // Rendered only when something can actually keep an image for this
        // record — see the field view. A button that opens a panel with
        // nowhere to upload to is worse than no button.
        'image' => ['image', 'Image'],
        // Pictures to leaf through — see gallery.mjs. Offered on the same
        // condition as the image, since it is filled from the same panel.
        'gallery' => ['gallery-horizontal-end', 'Gallery'],
        // Raw HTML kept verbatim — see htmlblock.mjs.
        'html' => ['file-code', 'HTML block'],
        'clear' => ['remove-formatting', 'Clear formatting'],
        'undo' => ['undo', 'Undo'],
        'redo' => ['redo', 'Redo'],
    ];

    /**
     * @return list<array{key: string, icon: ?string, title: ?string}>
     *                                                                 a key of "|" is a separator and carries no icon
     */
    public static function parse(string $spec): array
    {
        $buttons = [];

        foreach (preg_split('/\s*,\s*/', trim($spec)) ?: [] as $key) {
            if ($key === '|') {
                $buttons[] = ['key' => '|', 'icon' => null, 'title' => null];

                continue;
            }

            if (! isset(self::BUTTONS[$key])) {
                continue;
            }

            [$icon, $title] = self::BUTTONS[$key];
            $buttons[] = ['key' => $key, 'icon' => $icon, 'title' => $title];
        }

        return $buttons;
    }
}
