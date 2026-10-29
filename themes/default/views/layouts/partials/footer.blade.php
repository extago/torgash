{{--
    The footer, built of blocks (the owner's design, 2026-10-01). The theme
    decides the BANDS and the columns; the blocks decide what is in them. Each
    band and column is a zone of its own, drawn only when something is in it —
    so a shop that empties a zone loses the band, not just its contents, and
    the columns that remain share the width.

        advantages            storefront.footer.before
        about | menus | side  storefront.footer.about / .columns / .side
        our stores            storefront.footer.stores
        signature · payments  storefront.footer.bottom
        credits               storefront.footer.credits

    How it is laid out is the theme's settings (section "Footer"), two axes
    that combine freely:
    - footer_layout: `columns` (bands, columns side by side), `compact` (no
      advantages band, half the air) or `centred` (one column down the middle);
    - footer_palette: `dark` (the bar's colour, like the dark header) or
      `light` (the page's own palette, a line above);
    and the finer ones: the columns' widths, the dividers, the bottom line
    spread or centred, menus folding on a phone, a logo of its own for each
    colour scheme (its zones say `place: footer`, read by x-theme.logo).
--}}
@php
    $footerLayout = in_array(theme_setting('footer_layout'), ['compact', 'centred'], true) ? theme_setting('footer_layout') : 'columns';
    $footerLight = theme_setting('footer_palette') === 'light';
    $footerLines = (bool) theme_setting('footer_dividers', true);
    $footerCentred = $footerLayout === 'centred';

    $footerZones = [];
    foreach (['before', 'about', 'columns', 'side', 'stores', 'bottom', 'credits'] as $footerKey) {
        // The compact footer has no advantages band: not drawn, not even asked.
        if ($footerKey === 'before' && $footerLayout === 'compact') {
            $footerZones[$footerKey] = null;

            continue;
        }

        // `place`: a {logo} in a footer block is the footer's own (the
        // theme's Footer settings, x-theme.logo).
        $footerHtml = zone('storefront.footer.'.$footerKey, ['storefront' => $storefront, 'place' => 'footer']);
        $footerZones[$footerKey] = trim($footerHtml) !== '' ? $footerHtml : null;
    }
    $footerColumns = array_values(array_filter([$footerZones['about'], $footerZones['columns'], $footerZones['side']]));
    $footerCount = count($footerColumns);
    $footerContainer = 'container mx-auto max-w-(--torgash-container) px-(--torgash-gutter)';

    // A line between bands: the bar's faint white, or the page's border.
    $footerRule = ! $footerLines ? '' : ($footerLight ? 'border-b border-base-300' : 'border-b border-white/5');

    // Literal class names throughout: Tailwind only makes what it reads.
    $footerGrid = match (true) {
        $footerCentred || $footerCount < 2 => '',
        $footerCount === 3 => match (theme_setting('footer_columns')) {
            'first' => 'lg:grid-cols-[2fr_1fr_1fr]',
            'middle' => 'lg:grid-cols-[1fr_2fr_1fr]',
            default => 'lg:grid-cols-3',
        },
        default => match (theme_setting('footer_columns')) {
            'first' => 'lg:grid-cols-[2fr_1fr]',
            'middle' => 'lg:grid-cols-[1fr_2fr]',
            default => 'lg:grid-cols-2',
        },
    };
    $footerDivide = $footerLines && ! $footerCentred ? ($footerLight ? 'lg:divide-x lg:divide-base-300' : 'lg:divide-x lg:divide-white/5') : '';
    $footerCell = match ($footerLayout) {
        'compact' => 'flex flex-col gap-4 py-6 lg:py-8 lg:px-8 lg:first:pl-0 lg:last:pr-0 min-w-0',
        'centred' => 'w-full max-w-3xl flex flex-col items-center text-center gap-5 min-w-0',
        default => 'flex flex-col gap-6 py-8 lg:py-12 lg:px-10 lg:first:pl-0 lg:last:pr-0 min-w-0',
    };
    $footerBottomCentred = $footerCentred || theme_setting('footer_bottom') === 'centred';
@endphp
<footer @class([
    'torgash-footer text-sm',
    'torgash-footer-'.$footerLayout,
    'torgash-bar bg-neutral text-neutral-content/80' => ! $footerLight,
    'bg-base-100 text-base-content/80' => $footerLight,
    'border-t border-base-300' => $footerLight,
    'torgash-footer-fold' => (bool) theme_setting('footer_fold_menus', true),
])>
    @if ($footerZones['before'])
        <div @class([$footerRule, 'bg-white/[0.03]' => ! $footerLight, 'bg-base-200/60' => $footerLight])>
            <div @class([$footerContainer, 'py-8 flex flex-col gap-6', 'items-center text-center' => $footerCentred])>{!! $footerZones['before'] !!}</div>
        </div>
    @endif

    @if ($footerColumns)
        <div class="{{ $footerRule }}">
            @if ($footerCentred)
                <div class="{{ $footerContainer }} py-10 flex flex-col items-center gap-8">
                    @foreach ($footerColumns as $footerColumn)
                        <div class="{{ $footerCell }}">{!! $footerColumn !!}</div>
                    @endforeach
                </div>
            @else
                <div class="{{ $footerContainer }} grid {{ $footerGrid }} {{ $footerDivide }}">
                    @foreach ($footerColumns as $footerColumn)
                        <div class="{{ $footerCell }}">{!! $footerColumn !!}</div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($footerZones['stores'])
        <div class="{{ $footerRule }}">
            <div @class([$footerContainer, 'flex flex-col gap-6', 'items-center' => $footerCentred, 'py-6' => $footerLayout === 'compact', 'py-10' => $footerLayout !== 'compact'])>{!! $footerZones['stores'] !!}</div>
        </div>
    @endif

    {{-- The signature and the payment marks share ONE line whatever the
         blocks' rows say (app.css, .torgash-footer-bottom) — or stand
         centred one above the other. --}}
    <div @class([$footerContainer, 'torgash-footer-bottom', 'torgash-footer-bottom-centred' => $footerBottomCentred, 'py-4' => $footerLayout === 'compact', 'py-6' => $footerLayout !== 'compact'])>
        @if ($footerZones['bottom'])
            {!! $footerZones['bottom'] !!}
        @else
            <div>© {{ date('Y') }} {{ current_storefront()?->name }} · Torgash CMS</div>
        @endif
    </div>

    @if ($footerZones['credits'])
        <div @class(['bg-black/20' => ! $footerLight, 'bg-base-200' => $footerLight])>
            <div @class([$footerContainer, 'py-5 flex flex-col gap-3 text-xs', 'items-center text-center' => $footerBottomCentred])>{!! $footerZones['credits'] !!}</div>
        </div>
    @endif
</footer>
