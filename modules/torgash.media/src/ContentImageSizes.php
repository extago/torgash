<?php

namespace Torgash\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Width and height for the pictures inside a text, as it is printed
 * (core's `core.content.cleaned`).
 *
 * The editor puts the numbers on a picture it inserts, but a text that came
 * from an import, the API, 1C or an older editor carries a bare `<img src>`,
 * and the browser then lays the text out without the picture and pushes
 * everything under it down when it arrives. The library already knows each
 * file's size (`width`/`height` custom properties, written on upload), so a
 * picture that is OURS — its address ends in `/<media id>/<its file name>`,
 * the library's own layout on either disk — gets them here. One query per
 * text, only for pictures without numbers; anything else is left alone.
 */
class ContentImageSizes
{
    public static function fill(string $html): string
    {
        if (! preg_match_all('~<img\b(?![^>]*\swidth=)[^>]*\ssrc="[^"]*/(\d+)/([^"/?#]+)"[^>]*>~i', $html, $found, PREG_SET_ORDER)) {
            return $html;
        }

        $media = Media::query()
            ->whereIn('id', array_unique(array_map(fn (array $m) => (int) $m[1], $found)))
            ->get(['id', 'file_name', 'custom_properties'])
            ->keyBy('id');

        foreach ($found as [$tag, $id, $file]) {
            $item = $media->get((int) $id);
            $width = (int) $item?->getCustomProperty('width');
            $height = (int) $item?->getCustomProperty('height');

            if (! $item || $item->file_name !== rawurldecode($file) || $width < 1 || $height < 1 || str_contains($tag, ' height=')) {
                continue;
            }

            $html = str_replace($tag, substr($tag, 0, 4).' width="'.$width.'" height="'.$height.'"'.substr($tag, 4), $html);
        }

        return $html;
    }
}
