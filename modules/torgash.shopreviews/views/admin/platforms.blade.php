@extends('admin.layout')

@section('title', __('The shop\'s rating on other sites'))
@section('title_hint', __('Google, Hotline, a marketplace: the shop\'s mark there, typed by hand and printed on the reviews page with a link where a reader can check it. Keep the numbers current — nothing fetches them.'))
@section('title_actions')
    <a href="{{ route('admin.shopreviews.platforms.create') }}" class="btn btn-primary">+ {{ __('Add a site') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('torgash.shopreviews::admin._nav')

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            @if ($platforms->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="globe" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No sites: the page shows only the reviews written here.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-16">{{ __('Sort order') }}</th>
                                <th>{{ __('Site') }}</th>
                                <th class="text-end">{{ __('Rating') }}</th>
                                <th class="text-end">{{ __('Reviews') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($platforms as $platform)
                                <tr class="hover:bg-base-200/50">
                                    <td class="tabular-nums opacity-60">{{ $platform->sort }}</td>
                                    <td>
                                        <a href="{{ route('admin.shopreviews.platforms.edit', $platform) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $platform->enabled])>{{ $platform->name }}</a>
                                        @if ($platform->url)
                                            <div class="text-xs opacity-50 truncate max-w-md">{{ $platform->url }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">{{ $platform->rating !== null ? number_format($platform->rating, 1) : '—' }}</td>
                                    <td class="text-end tabular-nums">{{ $platform->reviews_count ?? '—' }}</td>
                                    <td>
                                        <span @class(['badge badge-sm', 'badge-success' => $platform->enabled, 'badge-ghost' => ! $platform->enabled])>
                                            {{ $platform->enabled ? __('Shown') : __('Hidden') }}
                                        </span>
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.shopreviews.platforms.edit', $platform) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.shopreviews.platforms.destroy', $platform) }}" class="inline"
                                                  data-confirm="{{ __('Delete this site?') }}">
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
