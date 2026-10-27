@extends('admin.layout')

@section('title', __('Shop rating criteria'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('What a customer rates the shop by — delivery, service, the site. The overall mark of a review is the average of the criteria they filled in; with none switched on, one overall mark is asked.') }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    @include('torgash.shopreviews::admin._nav')
                    <a href="{{ route('admin.shopreviews.criteria.create') }}" class="btn btn-primary btn-sm">+ {{ __('New criterion') }}</a>
                </div>
            </div>

            @if ($criteria->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="list-checks" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No criteria: a review is one overall mark and the text.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-16">{{ __('Sort order') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th class="text-end">{{ __('Scores given') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($criteria as $criterion)
                                <tr class="hover:bg-base-200/50">
                                    <td class="tabular-nums opacity-60">{{ $criterion->sort }}</td>
                                    <td>
                                        <a href="{{ route('admin.shopreviews.criteria.edit', $criterion) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $criterion->enabled])>{{ $criterion->label() }}</a>
                                        <div class="text-xs opacity-50 font-mono">{{ $criterion->code }}</div>
                                    </td>
                                    <td class="text-end tabular-nums">{{ ($scoreCounts[$criterion->id] ?? 0) ?: '—' }}</td>
                                    @include('torgash.shopreviews::admin._criterion-status', ['criterion' => $criterion])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.shopreviews.criteria.edit', $criterion) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.shopreviews.criteria.destroy', $criterion) }}" class="inline"
                                                  data-confirm="{{ __('Delete this criterion? Scores already given keep their numbers.') }}">
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
