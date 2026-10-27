@extends('admin.layout')

@section('title', __('SEO'))

@section('content')
    @include('torgash.seo::admin._nav', ['seoScreen' => 'audit'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70 max-w-3xl">
                {{ __('Every page of one kind, with what it says about itself. Type over anything here, or tick the rows and let the template that governs them fill them in.') }}
            </p>

            {{-- Kinds of page, each with how many titles are still missing. --}}
            <div class="join flex-wrap">
                @foreach ($subjects->options() as $value => $label)
                    <a href="{{ route('admin.seo.audit', ['subject' => $value]) }}"
                       @class(['btn btn-sm join-item gap-1.5', 'btn-active' => $kind === $value])>
                        {{ $label }}
                        @if ((($emptyPerKind ?? [])[$value] ?? 0) > 0)
                            <span class="badge badge-xs badge-warning" title="{{ __('Without a title') }}">{{ $emptyPerKind[$value] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            @if ($rows === null)
                <div class="alert">{{ __('Nothing here answers for a kind of page yet — install the catalogue or the pages module.') }}</div>
            @else
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="subject" value="{{ $kind }}">
                    <input type="hidden" name="filled" value="{{ $filter }}">
                    <label class="input input-sm w-full sm:w-72 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $term }}" class="grow" placeholder="{{ __('Search by title or id…') }}">
                    </label>
                    @if ($term !== '' || $filter !== '')
                        <a href="{{ route('admin.seo.audit', ['subject' => $kind]) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endif
                </form>

                <div class="join flex-wrap -mt-1">
                    @foreach (['' => __('All'), 'empty' => __('Without a title'), 'filled' => __('With a title')] as $filterKey => $filterLabel)
                        <a href="{{ request()->fullUrlWithQuery(['filled' => $filterKey ?: null, 'page' => null]) }}"
                           @class(['btn btn-sm join-item', 'btn-active' => $filter === $filterKey])>
                            {{ $filterLabel }} <span class="opacity-50">{{ $counts[$filterKey] }}</span>
                        </a>
                    @endforeach
                </div>

                @if ($rows->isEmpty())
                    <div class="text-center py-12 opacity-60">
                        <x-icon name="{{ $filter === 'empty' ? 'circle-check' : 'search-x' }}" size="xl" class="opacity-40" />
                        <p class="mt-2">{{ $filter === 'empty' && $term === '' ? __('Every page of this kind has a title.') : __('Nothing matches.') }}</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.seo.audit.save') }}" class="flex flex-col gap-3">
                        @csrf
                        <input type="hidden" name="subject" value="{{ $kind }}">

                        <div class="min-h-12 flex items-center">
                            <p x-show="!selected.length" class="text-xs opacity-50">
                                {{ __('A grey text in a box is what the page prints now. Tick rows to let the template write it onto them.') }}
                            </p>
                            <div x-show="selected.length" x-cloak class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                                <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                                <button name="action" value="template" class="btn btn-sm gap-1.5">
                                    <x-icon name="wand-sparkles" size="sm" /> {{ __('Fill the ticked rows from the template') }}
                                </button>
                                <span class="text-xs opacity-60">{{ __('Filling in writes the text onto the record itself, so it stays if this module is removed.') }}</span>
                                <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="table align-top">
                                <thead>
                                    <tr>
                                        <th class="w-10">
                                            <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                                   x-on:change="selected = $event.target.checked ? @js($rows->getCollection()->map(fn ($row) => (string) $row->getKey())->values()) : []"
                                                   :checked="selected.length === {{ $rows->count() }}">
                                        </th>
                                        <th>{{ __('Page') }}</th>
                                        <th>{{ __('Page title') }}</th>
                                        <th>{{ __('Meta description') }}</th>
                                        <th>{{ __('Heading (H1)') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php
                                            $columns = $definition['columns'];
                                            // What the template WOULD say, shown as the
                                            // placeholder: the box is empty because nobody
                                            // typed anything, and this is what the page
                                            // prints meanwhile.
                                            $governing = $templates->for($row);
                                            $preview = $governing && $governing->mode !== 'skip'
                                                ? $templates->render($governing, $row)
                                                : ['title' => '', 'description' => '', 'h1' => ''];
                                            $storefrontUrl = ($definition['url'])($row);
                                            $editUrl = ($definition['edit_url'])($row);
                                            $limits = ['title' => 60, 'description' => $descriptionLimit, 'h1' => 0];
                                        @endphp
                                        <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $row->getKey() }}') && 'bg-primary/5'">
                                            <td class="pt-5">
                                                <input type="checkbox" name="selected[]" value="{{ $row->getKey() }}" x-model="selected"
                                                       class="checkbox checkbox-sm" aria-label="{{ ($definition['title'])($row) }}">
                                            </td>
                                            <td class="max-w-56 pt-4">
                                                <div class="truncate font-medium">
                                                    @if ($editUrl)
                                                        <a href="{{ $editUrl }}" class="link link-hover">{{ ($definition['title'])($row) }}</a>
                                                    @else
                                                        {{ ($definition['title'])($row) }}
                                                    @endif
                                                </div>
                                                @if ($storefrontUrl)
                                                    <a href="{{ $storefrontUrl }}" target="_blank" rel="noopener"
                                                       class="text-xs opacity-50 truncate flex items-center gap-1 hover:text-primary hover:opacity-100">
                                                        <x-icon name="external-link" size="sm" class="shrink-0" />
                                                        <span class="truncate">{{ parse_url($storefrontUrl, PHP_URL_PATH) ?: $storefrontUrl }}</span>
                                                    </a>
                                                @endif
                                            </td>
                                            @foreach (['title', 'description', 'h1'] as $field)
                                                @php
                                                    $typedValue = $row->{$columns[$field]}[$locale] ?? '';
                                                    $fieldLimit = $limits[$field];
                                                @endphp
                                                <td @class(['min-w-56', 'min-w-72' => $field === 'description'])
                                                    x-data="{ length: {{ mb_strlen($typedValue) }} }">
                                                    @if ($field === 'description')
                                                        <textarea name="rows[{{ $row->getKey() }}][{{ $field }}]" rows="2"
                                                                  x-on:input="length = $event.target.value.length"
                                                                  placeholder="{{ $preview[$field] ?: __('worked out by the shop') }}"
                                                                  class="textarea textarea-bordered w-full leading-snug placeholder:italic">{{ $typedValue }}</textarea>
                                                    @else
                                                        <input name="rows[{{ $row->getKey() }}][{{ $field }}]" value="{{ $typedValue }}"
                                                               x-on:input="length = $event.target.value.length"
                                                               placeholder="{{ $preview[$field] ?: __('worked out by the shop') }}"
                                                               class="input input-bordered w-full placeholder:italic">
                                                    @endif
                                                    @if ($fieldLimit > 0)
                                                        <div x-show="length > 0" @if ($typedValue === '') x-cloak @endif
                                                             class="text-xs text-end tabular-nums mt-0.5"
                                                             :class="length > {{ $fieldLimit }} ? 'text-error font-semibold' : 'opacity-40'">
                                                            <span x-text="length">{{ mb_strlen($typedValue) }}</span> / {{ $fieldLimit }}
                                                        </div>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex flex-wrap gap-3 items-center">
                            <button name="action" value="save" class="btn btn-primary btn-sm">{{ __('Save what is typed') }}</button>
                            <div class="grow"></div>
                            {{ $rows->links() }}
                        </div>
                    </form>
                @endif
            @endif
        </div>
    </div>
@endsection
