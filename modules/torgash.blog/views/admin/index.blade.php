@extends('admin.layout')

@section('title', __('Blog'))
@section('title_hint', __('Articles in rubrics, published by their date: a scheduled one appears on time, drafts and hidden ones never reach the storefront.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`); LINKS carrying the search and the rubric. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Blog') }}">
            @foreach (['' => __('All'), 'published' => __('Published'), 'scheduled' => __('Scheduled'), 'draft' => __('Drafts'), 'hidden' => __('Hidden')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.blog.index', array_filter(['status' => $statusKey, 'q' => $filters['q'], 'rubric' => $filters['rubric'], 'per' => $filters['per'] !== \Torgash\Blog\Http\Admin\PostsController::PER_PAGE[0] ? $filters['per'] : null])) }}"
                   @class(['admin-tab', 'is-active' => $filters['status'] === $statusKey])>
                    {{ $statusLabel }}
                    @if (isset($counts[$statusKey]))
                        <span class="admin-tab-count">{{ $counts[$statusKey] }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.blog.rubrics.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="tags" size="sm" /> {{ __('Rubrics') }}</a>
            <a href="{{ route('admin.blog.create') }}" class="btn btn-primary gap-1.5"><x-icon name="plus" size="sm" /> {{ __('New article') }}</a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Title or address…') }}">
                </label>
                <select name="rubric" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Rubric') }}">
                    <option value="">{{ __('Every rubric') }}</option>
                    @foreach ($rubrics as $rubricOption)
                        <option value="{{ $rubricOption->id }}" @selected($filters['rubric'] === $rubricOption->id)>{{ $rubricOption->t('name', admin_content_locale()) }}</option>
                    @endforeach
                </select>
                @if ($filters['status'] !== '')
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                {{-- How many rows a page shows; a change asks the list again. --}}
                <select name="per" class="select select-sm w-auto shrink-0" data-submit-on-change aria-label="{{ __('Rows per page') }}">
                    @foreach (\Torgash\Blog\Http\Admin\PostsController::PER_PAGE as $perOption)
                        <option value="{{ $perOption }}" @selected($filters['per'] === $perOption)>{{ __(':n per page', ['n' => $perOption]) }}</option>
                    @endforeach
                </select>
            </form>

            @if ($posts->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="newspaper" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No articles yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <tbody>
                            @foreach ($posts as $post)
                                <tr>
                                    <td class="w-20">
                                        @if ($cover = $post->coverUrl(160))
                                            <img src="{{ $cover }}" alt="" class="w-16 h-12 object-cover rounded">
                                        @else
                                            <div class="w-16 h-12 rounded bg-base-200 grid place-items-center"><x-icon name="image" size="sm" class="opacity-40" /></div>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.blog.edit', $post) }}" class="font-medium link link-hover">{{ $post->t('title', admin_content_locale()) ?: $post->slug }}</a>
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach ($post->rubrics as $postRubric)
                                                <span class="badge badge-sm text-white border-0" style="background-color: {{ $postRubric->colour() }}">{{ $postRubric->t('name', admin_content_locale()) }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap text-sm">
                                        @if ($post->status === 'draft')
                                            <span class="badge badge-ghost badge-sm">{{ __('Draft') }}</span>
                                        @elseif ($post->isScheduled())
                                            <span class="badge badge-info badge-sm">{{ __('Scheduled') }}</span>
                                        @elseif ($post->status === 'hidden')
                                            <span class="badge badge-warning badge-sm">{{ __('Hidden') }}</span>
                                        @else
                                            <span class="badge badge-success badge-sm">{{ __('Published') }}</span>
                                        @endif
                                        <div class="text-xs opacity-60 mt-1">{{ $post->published_at?->format('d.m.Y H:i') }}</div>
                                    </td>
                                    <td class="text-sm opacity-70 whitespace-nowrap text-end">
                                        <span title="{{ __('Views') }}"><x-icon name="eye" size="sm" class="inline" /> {{ $post->views_count }}</span>
                                        <span class="ml-2" title="{{ __('Likes') }}"><x-icon name="heart" size="sm" class="inline" /> {{ $post->likes_count }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $posts->links() }}
            @endif
        </div>
    </div>
@endsection
