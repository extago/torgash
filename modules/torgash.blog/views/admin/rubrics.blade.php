@extends('admin.layout')

@section('title', __('Blog rubrics'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm opacity-70 max-w-2xl">{{ __('The coloured plates over an article\'s title, each with a feed of its own. An article can be in several.') }}</p>
                <div class="flex gap-2">
                    <a href="{{ route('admin.blog.index') }}" class="btn btn-ghost btn-sm gap-1.5"><x-icon name="newspaper" size="sm" /> {{ __('Articles') }}</a>
                    <a href="{{ route('admin.blog.rubrics.create') }}" class="btn btn-primary btn-sm gap-1.5"><x-icon name="plus" size="sm" /> {{ __('New rubric') }}</a>
                </div>
            </div>

            @if ($rubrics->isEmpty())
                <div class="text-center py-12 opacity-60">{{ __('No rubrics yet.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <tbody>
                            @foreach ($rubrics as $rubric)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.blog.rubrics.edit', $rubric) }}" class="inline-flex items-center gap-2">
                                            <span class="w-8 h-8 rounded-lg grid place-items-center text-white" style="background-color: {{ $rubric->colour() }}">
                                                @if ($rubric->icon) <x-icon :name="$rubric->icon" size="sm" /> @endif
                                            </span>
                                            <span class="font-medium link link-hover">{{ $rubric->t('name', admin_content_locale()) ?: $rubric->slug }}</span>
                                        </a>
                                        @unless ($rubric->enabled)
                                            <span class="badge badge-ghost badge-sm ml-2">{{ __('Off') }}</span>
                                        @endunless
                                    </td>
                                    <td class="font-mono text-xs opacity-60">/blog/rubric/{{ $rubric->slug }}</td>
                                    <td class="text-end text-sm opacity-70">{{ trans_choice(':count article|:count articles', $rubric->posts_count, ['count' => $rubric->posts_count]) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
