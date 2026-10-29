@extends('admin.layout')

@section('title', __('Sliders'))
@section('title_hint', __('A slider is an ordered set of banners and how it turns. It reaches the page through a "Banners" block — on the home page, above a category, under a buy panel, anywhere a block can stand.'))
@section('title_actions')
    <a href="{{ route('admin.banners.sliders.create') }}" class="btn btn-primary">+ {{ __('New slider') }}</a>
@endsection

@section('content')
    @include('torgash.banners::admin._nav', ['bannersScreen' => 'sliders'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            @if ($sliders->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="gallery-horizontal-end" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No sliders yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Frame') }}</th>
                                <th>{{ __('Banners') }}</th>
                                <th>{{ __('Placed by') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sliders as $slider)
                                <tr class="hover:bg-base-200/50">
                                    <td><a href="{{ route('admin.banners.sliders.edit', $slider) }}" class="font-medium link link-hover">{{ $slider->name }}</a></td>
                                    <td class="text-sm">{{ $slider->frame?->label() }}@if ($slider->aside) <span class="opacity-50">+ {{ $slider->aside->name }}</span>@endif</td>
                                    <td class="text-sm tabular-nums">{{ $slider->banners_count }}</td>
                                    <td class="text-sm">
                                        @forelse ($placed[$slider->id] ?? [] as $placedBlock)
                                            <div @class(['opacity-50' => ! $placedBlock['enabled']])>{{ $placedBlock['title'] }} <span class="opacity-50 font-mono text-xs">{{ $placedBlock['zone'] }}</span></div>
                                        @empty
                                            <span class="text-xs text-warning">{{ __('No block shows it') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.banners.sliders.edit', $slider) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.banners.sliders.destroy', $slider) }}" class="inline"
                                                  data-confirm="{{ __('Delete this slider? Its banners stay.') }}">
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
