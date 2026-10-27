{{-- The published filter combinations of this category, as links.

     Not decoration: an address nothing links to is an address nothing crawls,
     and a filter page that can only be reached by typing it is a page that was
     never published. It sits where a subcategory list would, because to a
     visitor that is exactly what it is.

     Two rows, narrowing first and towns second: "Apple laptops" is a way INTO
     the category and "Laptops in Kyiv" is the same set of products under
     different words, so a strip that mixed them would lead two ways at once.
     Which pages are here at all, and how many, is decided in FilterPages. --}}
@php
    $filterPageRows = array_values(array_filter([
        $filterPages['filters'] ?? collect(),
        $filterPages['places'] ?? collect(),
    ], fn ($row) => $row->isNotEmpty()));
@endphp
@if ($filterPageRows)
    <div class="bg-base-100 border border-base-300 rounded-box p-3 flex flex-col gap-2">
        @foreach ($filterPageRows as $row)
            <div class="flex flex-wrap gap-2">
                @foreach ($row as $page)
                    @php $isCurrent = $filterPage && $filterPage->id === $page->id; @endphp
                    <a href="{{ $page->url() }}"
                       @class(['btn btn-sm normal-case', 'btn-primary' => $isCurrent, 'btn-outline' => ! $isCurrent])
                       @if ($isCurrent) aria-current="page" @endif>
                        {{ $page->title() }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
