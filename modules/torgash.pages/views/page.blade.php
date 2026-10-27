@extends('layouts.storefront')

@section('content')
    @php
        $sectionPages = $page->hasSection() ? $page->sectionPages() : collect();
        // The sidebar is a plain blocks zone: whatever the merchant put there,
        // rendered only if there is anything. An empty zone must not leave a
        // column of white space beside the text.
        $sidebar = zone('pages.sidebar', ['page' => $page]);
        $hasAside = $sectionPages->isNotEmpty() || trim($sidebar) !== '';
    @endphp

    <x-breadcrumbs />

    <div class="flex flex-col lg:flex-row gap-4 items-start">
        @if ($hasAside)
            <aside class="w-full lg:w-64 shrink-0 flex flex-col gap-3">
                @if ($sectionPages->isNotEmpty())
                    <div class="bg-base-100 border border-base-300 rounded-box p-4">
                        <div class="text-sm text-base-content/50 pb-2.5 mb-2.5 border-b border-base-300">
                            {{ $page->sectionRoot()->t('title') }}
                        </div>
                        <ul class="flex flex-col gap-2.5 text-sm">
                            @foreach ($sectionPages as $sectionPage)
                                <li>
                                    <a href="{{ $sectionPage->url() }}"
                                       @class(['font-semibold', 'text-primary' => $sectionPage->id === $page->id, 'hover:text-primary' => $sectionPage->id !== $page->id])>
                                        {{ $sectionPage->t('title') }}
                                    </a>
                                    @if ($sectionPage->children->isNotEmpty())
                                        <ul class="flex flex-col gap-1.5 mt-1.5 ms-3 text-sm">
                                            @foreach ($sectionPage->children as $child)
                                                <li>
                                                    <a href="{{ $child->url() }}"
                                                       @class(['text-primary font-semibold' => $child->id === $page->id, 'opacity-70 hover:text-primary' => $child->id !== $page->id])>
                                                        {{ $child->t('title') }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {!! $sidebar !!}
            </aside>
        @endif

        <div class="grow min-w-0 w-full flex flex-col gap-4">
            <div class="bg-base-100 border border-base-300 rounded-box p-6">
                <h1 class="text-2xl font-bold mb-4">{{ page_meta()->h1() }}</h1>

                {{-- Admin-authored HTML with placeholders already expanded (see
                     Page::renderedBody) — same trust level and same wrapper class
                     as a product description or an HTML block. --}}
                <div class="torgash-rich">{!! $page->renderedBody() !!}</div>
            </div>

            {{-- Where a "share" block, and later the comments, sit. --}}
            {!! zone('pages.after_content', ['page' => $page]) !!}
        </div>
    </div>
@endsection
