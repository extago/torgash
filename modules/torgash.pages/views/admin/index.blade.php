@extends('admin.layout')

@section('title', __('Pages'))

@section('content')
    @php $locale = admin_content_locale(); @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Delivery and payment, About us, the privacy policy — the pages a menu links to. A page can sit inside another one, and its address follows: moving it leaves a 301 behind.') }}
                </p>
                <a href="{{ route('admin.pages.create') }}" class="btn btn-primary btn-sm">+ {{ __('New page') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Title or address…') }}">
                </label>
                <div class="join">
                    @foreach (['' => __('All'), 'published' => __('Published'), 'draft' => __('Drafts')] as $statusKey => $statusLabel)
                        <button type="submit" name="status" value="{{ $statusKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => $filters['status'] === $statusKey])>
                            {{ $statusLabel }} <span class="opacity-50">{{ $statusCounts[$statusKey] }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.pages.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick pages to publish, hide or delete several at once.') }}
                </p>
                <form method="POST" action="{{ route('admin.pages.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        <button name="action" value="publish" class="btn btn-sm join-item">{{ __('Publish') }}</button>
                        <button name="action" value="unpublish" class="btn btn-sm join-item">{{ __('To drafts') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected pages? Pages inside them move up a level and keep working.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($pages->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="file-text" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No pages match this search.') : __('No pages yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($pages->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $pages->count() }}">
                                </th>
                                <th>{{ __('Title') }}</th>
                                <th>{{ __('Shown when') }}</th>
                                <th class="w-16">{{ __('Sort order') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pages as $page)
                                {{-- Depth from the address, not from a recursive render: the
                                     list is ordered by path, so a section's pages already
                                     arrive under it. A search shows the depth too. --}}
                                @php $depth = substr_count($page->path, '/'); @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $page->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $page->id }}" x-model="selected"
                                               aria-label="{{ $page->t('title', $locale) ?: $page->slug }}">
                                    </td>
                                    <td class="min-w-64">
                                        <div style="padding-inline-start: {{ $depth * 1.25 }}rem">
                                            @if ($depth > 0)
                                                <span class="opacity-30 me-1">└</span>
                                            @endif
                                            <a href="{{ route('admin.pages.edit', $page) }}"
                                               @class(['font-medium link link-hover', 'opacity-60' => ! $page->enabled])>{{ $page->t('title', $locale) ?: $page->slug }}</a>
                                            <div class="font-mono text-xs opacity-50 {{ $depth > 0 ? 'ms-4' : '' }}">/{{ $page->path }}</div>
                                        </div>
                                    </td>
                                    <td class="text-xs opacity-60 max-w-xs">
                                        {{-- The rules in the same words the editor writes them in;
                                             "everywhere" is the honest answer for a page with none. --}}
                                        @php $ruleLines = array_map(fn ($rule) => app(\App\Core\Display\DisplayRules::class)->describe($rule), (array) ($page->rules ?? [])); @endphp
                                        {{ $ruleLines ? implode('; ', $ruleLines) : __('everywhere') }}
                                    </td>
                                    <td class="tabular-nums opacity-60">{{ $page->position }}</td>
                                    @include('torgash.pages::admin._status', ['page' => $page])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Preview, edit, and — set apart — delete. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ $page->url() }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Preview') }}" aria-label="{{ __('Preview') }}"><x-icon name="external-link" /></a>
                                            <a href="{{ route('admin.pages.edit', $page) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.pages.destroy', $page) }}" class="inline"
                                                  data-confirm="{{ __('Delete this page?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <x-icon name="trash-2" />
                                                </button>
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
