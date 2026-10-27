{{--
    A list of links typed one per line — "Label | /address | icon | subtitle",
    a leading "!" to set a line off in the accent colour (an Outlet, a Sale).
    Rows with icons for a menu, plain links for a footer column. Everything
    escaped; an address must be an address (http(s) or a path) or the line
    becomes plain text; an unknown icon is simply not drawn.
--}}
@php
    $linksHeading = trim((string) ($settings['heading'] ?? ''));
    $linksRows = [];

    foreach (preg_split('/\r\n|\r|\n/', (string) ($settings['items'] ?? '')) as $linksLine) {
        $linksLine = trim($linksLine);
        if ($linksLine === '') {
            continue;
        }
        $linksAccent = str_starts_with($linksLine, '!');
        $linksParts = array_map('trim', explode('|', ltrim($linksLine, '! ')));
        $linksUrl = $linksParts[1] ?? '';
        $linksIcon = $linksParts[2] ?? '';
        $linksRows[] = [
            'label' => $linksParts[0] ?? '',
            'url' => preg_match('#^(https?://|/(?!/))#i', $linksUrl) ? $linksUrl : null,
            'icon' => $linksIcon !== '' && preg_match('/^[a-z0-9-]+$/', $linksIcon) && is_file(resource_path('icons/lucide/'.$linksIcon.'.svg')) ? $linksIcon : null,
            'note' => $linksParts[3] ?? '',
            'accent' => $linksAccent,
        ];
    }
    $linksPlain = ($settings['style'] ?? 'rows') === 'plain';
@endphp
@if ($linksRows !== [])
    <nav class="torgash-block torgash-block-links {{ $linksPlain ? 'px-5 py-3' : 'py-2' }}" @if ($linksHeading !== '') aria-label="{{ $linksHeading }}" @endif>
        @if ($linksHeading !== '')
            <div class="text-sm opacity-60 {{ $linksPlain ? 'mb-2' : 'px-5 pt-2 pb-1' }}">{{ $linksHeading }}</div>
        @endif
        <ul class="{{ $linksPlain ? 'flex flex-col gap-2.5' : 'flex flex-col' }}">
            @foreach ($linksRows as $linksRow)
                <li>
                    @php $linksTag = $linksRow['url'] ? 'a' : 'span'; @endphp
                    <{{ $linksTag }} @if ($linksRow['url']) href="{{ $linksRow['url'] }}" @endif
                        @class([
                            'flex items-center gap-3',
                            'px-5 py-2.5 min-h-12 hover:bg-base-200 active:bg-base-200' => ! $linksPlain,
                            'hover:text-primary' => $linksPlain,
                            'text-accent' => $linksRow['accent'],
                        ])>
                        @if ($linksRow['icon'] && ! $linksPlain)
                            <x-icon :name="$linksRow['icon']" class="w-6 h-6 shrink-0 {{ $linksRow['accent'] ? '' : 'opacity-80' }}" />
                        @endif
                        <span class="flex flex-col min-w-0">
                            <span @class(['font-medium' => ! $linksPlain])>{{ $linksRow['label'] }}</span>
                            @if ($linksRow['note'] !== '')
                                <span class="text-xs opacity-60 {{ $linksRow['accent'] ? 'text-base-content' : '' }}">{{ $linksRow['note'] }}</span>
                            @endif
                        </span>
                    </{{ $linksTag }}>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
