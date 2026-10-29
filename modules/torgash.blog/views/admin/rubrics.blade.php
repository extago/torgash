@extends('admin.layout')

@section('title', __('Blog rubrics'))
@section('title_hint', __('The coloured plates over an article\'s title, each with a feed of its own. An article can be in several.'))

{{-- No states to tab: the screen's actions stand level with the heading. --}}
@section('title_actions')
    <a href="{{ route('admin.blog.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="newspaper" size="sm" /> {{ __('Articles') }}</a>
    <a href="{{ route('admin.blog.rubrics.create') }}" class="btn btn-primary gap-1.5"><x-icon name="plus" size="sm" /> {{ __('New rubric') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            @if ($rubrics->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="tags" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No rubrics yet.') }}</p>
                </div>
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
