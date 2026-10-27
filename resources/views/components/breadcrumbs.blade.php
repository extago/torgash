{{--
    The trail, printed from App\Core\Seo\Breadcrumbs — the same rows the
    BreadcrumbList in the page's structured data is built from, which is the
    whole reason it stopped being markup assembled in each template.

    The last crumb is the page itself and is deliberately not a link: a link to
    the page you are on is noise to a reader and a self-reference in the graph.
--}}
@php
    $items = breadcrumbs()->items();
@endphp
@if ($items)
    {{-- py-0: daisyUI pads the trail 8px above and below, on top of the
         page's own padding and this margin — the trail sat in a gap three
         times its height. --}}
    <div {{ $attributes->merge(['class' => 'breadcrumbs text-sm py-0 mb-3 opacity-70']) }}>
        <ul>
            @foreach ($items as $item)
                <li>
                    @if ($item['url'] && ! $loop->last)
                        <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                    @else
                        {{ $item['label'] }}
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
