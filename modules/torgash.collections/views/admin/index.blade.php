@extends('admin.layout')

@section('title', __('Collections'))
@section('title_hint', __('Pages of products gathered by conditions — the catalogue\'s filter, saved — and products picked by hand. Each has its address, cover and words; the page is the catalogue\'s listing with its filters and sorts.'))
{{-- No states to filter by: the action stands level with the heading. --}}
@section('title_actions')
    <a href="{{ route('admin.collections.create') }}" class="btn btn-primary gap-1"><x-icon name="plus" size="sm" /> {{ __('New collection') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            @if ($collections->isEmpty())
                <div class="text-sm opacity-60 py-6 text-center">{{ __('No collections yet.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Products') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($collections as $collectionRow)
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <span class="w-12 h-9 rounded bg-base-200 overflow-hidden shrink-0 grid place-items-center">
                                                @if ($collectionRowCover = $collectionRow->coverUrl())
                                                    <img src="{{ $collectionRowCover }}" alt="" class="w-full h-full object-cover">
                                                @else
                                                    <x-icon name="layers" size="sm" class="opacity-40" />
                                                @endif
                                            </span>
                                            <div>
                                                <a href="{{ route('admin.collections.edit', $collectionRow) }}" class="link link-hover font-medium">{{ $collectionRow->t('name', admin_content_locale()) }}</a>
                                                <div class="text-xs opacity-60">/collections/{{ $collectionRow->slug }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="tabular-nums">{{ $counts[$collectionRow->id] ?? 0 }}</td>
                                    <td>
                                        @if (! $collectionRow->enabled)
                                            <span class="badge badge-ghost badge-sm">{{ __('Switched off') }}</span>
                                        @elseif (! $collectionRow->isShown())
                                            <span class="badge badge-info badge-sm">{{ __('Outside its dates') }}</span>
                                        @else
                                            <span class="badge badge-success badge-sm">{{ __('Shown now') }}</span>
                                        @endif
                                        @unless ($collectionRow->listed)
                                            <span class="badge badge-ghost badge-sm">{{ __('Not in the list') }}</span>
                                        @endunless
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        @if ($collectionRow->isShown())
                                            <a href="{{ route('collections.show', $collectionRow->slug) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square" title="{{ __('Open on the storefront') }}" aria-label="{{ __('Open on the storefront') }}"><x-icon name="external-link" size="sm" /></a>
                                        @endif
                                        <a href="{{ route('admin.collections.edit', $collectionRow) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
                                        <button form="collection-delete-{{ $collectionRow->id }}" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @foreach ($collections as $collectionRow)
        <form method="POST" id="collection-delete-{{ $collectionRow->id }}" action="{{ route('admin.collections.destroy', $collectionRow) }}"
              data-confirm="{{ __('Delete this collection? Its page stops answering.') }}">@csrf @method('DELETE')</form>
    @endforeach
@endsection
