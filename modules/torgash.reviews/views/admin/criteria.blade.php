@extends('admin.layout')

@section('title', __('Rating criteria'))
@section('title_hint', __('What a customer rates besides the overall mark — quality, value for money, how it fits. A criterion with no categories is asked about every product; attached to a category, it is asked there and in everything under it.'))
@section('title_actions')
    <a href="{{ route('admin.reviews.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="arrow-left" size="sm" /> {{ __('Back to reviews') }}</a>
    <a href="{{ route('admin.reviews.criteria.create') }}" class="btn btn-primary">+ {{ __('New criterion') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            @if ($criteria->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="list-checks" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No criteria: a review is only the overall mark and the text.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-16">{{ __('Sort order') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Asked about') }}</th>
                                <th class="text-end">{{ __('Scores given') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($criteria as $criterion)
                                @php
                                    $criterionCategories = $criterion->categories->pluck('category_id')->map('intval');
                                    $scores = (int) ($scoreCounts[$criterion->id] ?? 0);
                                @endphp
                                <tr class="hover:bg-base-200/50">
                                    <td class="tabular-nums opacity-60">{{ $criterion->sort }}</td>
                                    <td>
                                        <a href="{{ route('admin.reviews.criteria.edit', $criterion) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $criterion->enabled])>{{ $criterion->label() ?: $criterion->code }}</a>
                                        <div class="text-xs opacity-50 font-mono">{{ $criterion->code }}</div>
                                    </td>
                                    <td class="text-sm">
                                        @if ($criterionCategories->isEmpty())
                                            <span class="opacity-60">{{ __('Every product') }}</span>
                                        @else
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($criterionCategories->take(4) as $categoryId)
                                                    <span class="badge badge-sm badge-ghost">{{ trim($categories[$categoryId] ?? '#'.$categoryId, " \u{00A0}—-") }}</span>
                                                @endforeach
                                                @if ($criterionCategories->count() > 4)
                                                    <span class="text-xs opacity-60">+{{ $criterionCategories->count() - 4 }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">{{ $scores ?: '—' }}</td>
                                    @include('torgash.reviews::admin._criterion-status', ['criterion' => $criterion])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.reviews.criteria.edit', $criterion) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.reviews.criteria.destroy', $criterion) }}" class="inline"
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
