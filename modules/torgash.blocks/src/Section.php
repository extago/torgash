<?php

namespace Torgash\Blocks;

/**
 * The band a block stands in (PLAN §51) — `m_blocks.section`:
 *
 *  - `width`: `page` (the page's width — what every block had), `band` (the
 *    background across the whole screen, the content still the page's width:
 *    a coloured strip behind a slider) or `full` (the content edge to edge
 *    too: a slider across the screen);
 *  - `background`: none, one of the theme's tones, or the shop's own #hex;
 *  - `space`: room above and below;
 *  - `class`: the shop's own CSS classes on the band (CssClasses), for its
 *    stylesheet to find it by — a band with nothing but a class is still
 *    drawn, or the class would have nothing to stand on.
 *
 * Blocks drawn together — a row of columns, a row of tabs — stand in ONE
 * band: the first member's. Only the classes below ever reach the page, each
 * written out whole so the stylesheet's scanner sees it.
 */
class Section
{
    public const WIDTHS = ['page', 'band', 'full'];

    /** Theme tones by name — a class each, so a dark theme recolours them. */
    public const TONES = [
        'base' => 'bg-base-100 text-base-content',
        'muted' => 'bg-base-200 text-base-content',
        'deep' => 'bg-base-300 text-base-content',
        'dark' => 'bg-neutral text-neutral-content',
        'primary' => 'bg-primary text-primary-content',
    ];

    public const SPACES = ['none' => '', 'small' => 'py-4', 'medium' => 'py-8 md:py-10', 'large' => 'py-12 md:py-16'];

    /**
     * What the form posted, reduced to what this knows — or null when it is
     * the plain page-width band with nothing behind it, which needs no column.
     *
     * @return array{width: string, background: string, colour: ?string, space: string, class: ?string}|null
     */
    public static function accept(mixed $posted): ?array
    {
        $posted = (array) $posted;
        $width = in_array($posted['width'] ?? '', self::WIDTHS, true) ? $posted['width'] : 'page';
        $background = (string) ($posted['background'] ?? '');
        $colour = is_string($posted['colour'] ?? null) && preg_match('/^#[0-9a-f]{6}$/i', $posted['colour']) ? strtolower($posted['colour']) : null;

        if ($background !== 'colour' && ! isset(self::TONES[$background])) {
            $background = '';
        }

        if ($background === 'colour' && $colour === null) {
            $background = '';
        }

        $space = isset(self::SPACES[$posted['space'] ?? '']) ? $posted['space'] : 'none';
        $class = CssClasses::accept($posted['class'] ?? null);

        if ($width === 'page' && $background === '' && $space === 'none' && $class === null) {
            return null;
        }

        return ['width' => $width, 'background' => $background, 'colour' => $background === 'colour' ? $colour : null, 'space' => $space, 'class' => $class];
    }

    /** The choices as the admin reads them; one list for the form and the zone screen's summary. */
    public static function labels(): array
    {
        return [
            'width' => ['page' => __('The page\'s width'), 'band' => __('Background across the screen, content the page\'s width'), 'full' => __('Edge to edge')],
            'background' => ['' => __('None'), 'base' => __('Like the cards'), 'muted' => __('Light grey'), 'deep' => __('Grey'), 'dark' => __('Dark'), 'primary' => __('The shop\'s colour'), 'colour' => __('A colour of its own')],
            'space' => ['none' => __('None'), 'small' => __('A little'), 'medium' => __('Medium'), 'large' => __('A lot')],
        ];
    }

    /** "Edge to edge · Dark · room: a lot" — what the zone screen shows beside a row. */
    public static function summary(?array $section): string
    {
        $section = $section ? self::accept($section) : null;

        if ($section === null) {
            return __('The page\'s width');
        }

        $labels = self::labels();
        $parts = [$labels['width'][$section['width']]];

        if ($section['background'] !== '') {
            $parts[] = $section['background'] === 'colour' ? $section['colour'] : $labels['background'][$section['background']];
        }

        if ($section['space'] !== 'none') {
            $parts[] = __('room: :space', ['space' => mb_strtolower($labels['space'][$section['space']])]);
        }

        if ($section['class'] !== null) {
            $parts[] = '.'.str_replace(' ', ' .', $section['class']);
        }

        return implode(' · ', $parts);
    }

    /** The blocks' HTML inside their band; untouched when there is no band to draw. */
    public static function wrap(?array $section, string $html): string
    {
        $section = $section ? self::accept($section) : null;

        if ($section === null || trim($html) === '') {
            return $html;
        }

        // Nothing but a class: a plain box to carry it, none of a band's own
        // margins and padding — naming a row must not move it.
        if ($section['width'] === 'page' && $section['background'] === '' && $section['space'] === 'none') {
            return '<div class="'.$section['class'].'">'.$html.'</div>';
        }

        $classes = array_filter([
            'torgash-section',
            'torgash-section-'.$section['width'],
            self::TONES[$section['background']] ?? '',
            self::SPACES[$section['space']],
            // A background needs room to show: no "space" still pads it a little.
            $section['background'] !== '' && $section['space'] === 'none' ? 'py-4' : '',
            $section['class'] ?? '',
        ]);

        $style = $section['background'] === 'colour' ? ' style="background-color: '.$section['colour'].'"' : '';

        return '<section class="'.implode(' ', $classes).'"'.$style.'><div class="torgash-section-inner">'.$html.'</div></section>';
    }
}
