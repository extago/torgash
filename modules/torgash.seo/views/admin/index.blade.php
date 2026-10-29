@extends('admin.layout')

@section('title', __('SEO'))
@section('title_hint', __('A title, a description and a heading written once and applied to every page of one kind. A template written for a category also governs everything under it, and the nearest one wins.'))

@section('content')
    @php
        $isFiltered = $filters['q'] !== '' || $filters['subject'] !== '';
        $contentLocale = admin_content_locale();
    @endphp

    @include('torgash.seo::admin._nav', ['seoScreen' => 'templates'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The subjects as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('SEO') }}">
            @foreach (['' => __('All')] + $subjects->options() as $subjectKey => $subjectLabel)
                <a href="{{ request()->fullUrlWithQuery(['subject' => $subjectKey ?: null, 'page' => null]) }}"
                   @class(['admin-tab', 'is-active' => $filters['subject'] === $subjectKey])>
                    {{ $subjectLabel }} <span class="admin-tab-count">{{ $subjectCounts[$subjectKey] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.seo.create', array_filter(['subject' => $filters['subject']])) }}" class="btn btn-primary">+ {{ __('New template') }}</a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search in the texts…') }}">
                </label>
                <input type="hidden" name="subject" value="{{ $filters['subject'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.seo.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick templates to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.seo.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        <button name="action" value="enable" class="btn btn-sm join-item">{{ __('Enable') }}</button>
                        <button name="action" value="disable" class="btn btn-sm join-item">{{ __('Disable') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected templates?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($templates->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="file-search" size="xl" class="opacity-40" />
                    <p class="mt-2 max-w-xl mx-auto">{{ $isFiltered ? __('No templates match this search.') : __('No templates yet. Until there are, every page uses what the shop works out on its own — the product name for the title, the first sentences of the description.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($templates->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $templates->count() }}">
                                </th>
                                <th>{{ __('Pages') }}</th>
                                <th>{{ __('What it writes') }}</th>
                                <th>{{ __('When') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($templates as $template)
                                @php
                                    $templateTitle = $template->t('title', $contentLocale);
                                    $templateDescription = $template->t('description', $contentLocale);
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $template->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $template->id }}" x-model="selected" aria-label="{{ $subjects->label($template->subject) }}">
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <a href="{{ route('admin.seo.edit', $template) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $template->enabled])>{{ $subjects->label($template->subject) }}</a>
                                        <div class="text-xs opacity-60 flex items-center gap-1">
                                            @if ($template->categoryId() !== null)
                                                <x-icon name="folder" size="sm" />
                                                {{ $scopeNames[$template->scope] ?? '#'.$template->categoryId() }} {{ __('and below') }}
                                            @else
                                                <x-icon name="globe" size="sm" /> {{ __('everywhere') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="max-w-xl">
                                        @if ($templateTitle || $templateDescription)
                                            <div class="truncate font-mono text-xs">{{ $templateTitle ?: '—' }}</div>
                                            @if ($templateDescription)
                                                <div class="truncate font-mono text-xs opacity-50">{{ $templateDescription }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs opacity-50">{{ __('Nothing in this language') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm whitespace-nowrap">{{ $template->modeLabel() }}</td>
                                    @include('torgash.seo::admin._status', ['template' => $template])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.seo.duplicate', $template) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Duplicate') }}" aria-label="{{ __('Duplicate') }}"><x-icon name="copy" /></button>
                                            </form>
                                            <a href="{{ route('admin.seo.edit', $template) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.seo.destroy', $template) }}" class="inline"
                                                  data-confirm="{{ __('Delete this template?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
