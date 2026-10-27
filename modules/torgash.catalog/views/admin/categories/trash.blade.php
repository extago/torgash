@extends('admin.layout')

@section('title', __('Categories') . ' · ' . __('Trash'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body">
            <div class="flex justify-between items-center gap-2 flex-wrap">
                <div>
                    <h2 class="card-title">{{ __('Trash') }}</h2>
                    <div class="text-xs opacity-50">{{ __('Products stay linked to a trashed category and come back with it.') }}</div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.catalog.categories.index') }}" class="btn btn-ghost btn-sm gap-1.5">
                        <x-icon name="arrow-left" /> {{ __('Categories') }}
                    </a>
                    @if ($categories->isNotEmpty())
                        <form method="POST" action="{{ route('admin.catalog.categories.trash.empty') }}" x-data="{ confirming: false }">
                            @csrf @method('DELETE')
                            <button type="button" class="btn btn-error btn-outline btn-sm" x-show="!confirming"
                                    @click="confirming = true">{{ __('Empty trash') }}</button>
                            <span x-show="confirming" x-cloak class="flex items-center gap-1.5 text-sm">
                                {{ __('Delete all permanently?') }}
                                <button class="btn btn-error btn-sm">{{ __('Yes') }}</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="confirming = false">{{ __('No') }}</button>
                            </span>
                        </form>
                    @endif
                </div>
            </div>

            @if ($categories->isEmpty())
                <div class="text-sm opacity-50 py-6 text-center">{{ __('The trash is empty.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Slug') }}</th><th>{{ __('Deleted') }}</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($categories as $category)
                                <tr>
                                    <td>{{ $category->t('name', admin_content_locale()) }}</td>
                                    <td class="font-mono text-xs">{{ $category->slug }}</td>
                                    <td class="text-xs opacity-60">{{ $category->deleted_at?->diffForHumans() }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        <form method="POST" action="{{ route('admin.catalog.categories.restore', $category->id) }}" class="inline">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm gap-1"><x-icon name="undo-2" size="sm" /> {{ __('Restore') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.catalog.categories.force-delete', $category->id) }}"
                                              class="inline" x-data="{ confirming: false }">
                                            @csrf @method('DELETE')
                                            <button type="button" class="btn btn-ghost btn-sm text-error" x-show="!confirming"
                                                    @click="confirming = true">{{ __('Delete permanently') }}</button>
                                            <span x-show="confirming" x-cloak class="inline-flex items-center gap-1">
                                                <button class="btn btn-error btn-sm">{{ __('Yes') }}</button>
                                                <button type="button" class="btn btn-ghost btn-sm" @click="confirming = false">{{ __('No') }}</button>
                                            </span>
                                        </form>
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
