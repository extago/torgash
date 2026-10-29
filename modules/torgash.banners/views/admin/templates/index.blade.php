@extends('admin.layout')

@section('title', __('Banner templates'))
@section('title_hint', __('A template is HTML with {tokens} and its CSS. A banner template draws one banner — as a wide slide, an upright card or a low strip; a frame draws a slider around its slides. Each {token} you write becomes a field of the banner\'s form.'))
@section('title_actions')
    <form method="POST" action="{{ route('admin.banners.templates.import') }}" enctype="multipart/form-data" x-data>
        @csrf
        <label class="btn btn-ghost gap-1.5">
            <x-icon name="upload" size="sm" /> {{ __('Import') }}
            <input type="file" name="file" accept=".json,application/json" class="hidden" @change="$el.form.submit()">
        </label>
    </form>
    <a href="{{ route('admin.banners.templates.create', ['kind' => 'frame']) }}" class="btn">+ {{ __('New frame') }}</a>
    <a href="{{ route('admin.banners.templates.create') }}" class="btn btn-primary">+ {{ __('New banner template') }}</a>
@endsection

@section('content')
    @include('torgash.banners::admin._nav', ['bannersScreen' => 'templates'])

    @php $shapeNames = ['wide' => __('Wide slide'), 'card' => __('Upright card'), 'strip' => __('Low strip')]; @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            @if ($missing !== [])
                <div class="alert text-sm flex flex-wrap justify-between gap-2">
                    <span>{{ __('The module or the theme has templates that are not here:') }}
                        <b>{{ collect($missing)->map(fn ($key) => label_text($presets[$key]['name']))->implode(', ') }}</b></span>
                    <form method="POST" action="{{ route('admin.banners.templates.seed') }}">
                        @csrf
                        <button class="btn btn-sm">{{ __('Add from the theme') }}</button>
                    </form>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Kind') }}</th>
                            <th>{{ __('Shapes') }}</th>
                            <th>{{ __('Used by') }}</th>
                            <th>{{ __('Preset') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($templates as $template)
                            <tr class="hover:bg-base-200/50">
                                <td>
                                    <a href="{{ route('admin.banners.templates.edit', $template) }}" class="font-medium link link-hover">{{ $template->label() }}</a>
                                    <div class="text-xs opacity-50 font-mono">{{ $template->key }}</div>
                                </td>
                                <td class="text-sm">{{ $template->isFrame() ? __('Frame') : __('Banner') }}</td>
                                <td class="text-sm">
                                    @if ($template->isFrame())
                                        {{ $shapeNames[$template->shape ?: 'wide'] }}
                                    @else
                                        {{ collect($template->shapes())->map(fn ($shape) => $shapeNames[$shape])->implode(', ') }}
                                    @endif
                                </td>
                                <td class="text-sm tabular-nums">{{ $template->isFrame() ? $template->sliders_count : $template->banners_count }}</td>
                                <td class="text-sm">
                                    @if ($differs[$template->id] === null)
                                        <span class="opacity-50">—</span>
                                    @elseif ($differs[$template->id])
                                        <span class="badge badge-warning badge-sm">{{ __('Changed') }}</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">{{ __('As in the theme') }}</span>
                                    @endif
                                </td>
                                <td class="text-end whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <a href="{{ route('admin.banners.templates.export', $template) }}" class="btn btn-ghost btn-sm btn-square"
                                           title="{{ __('Export') }}" aria-label="{{ __('Export') }}"><x-icon name="download" /></a>
                                        <a href="{{ route('admin.banners.templates.edit', $template) }}" class="btn btn-ghost btn-sm btn-square"
                                           title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                        <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                        <form method="POST" action="{{ route('admin.banners.templates.destroy', $template) }}" class="inline"
                                              data-confirm="{{ __('Delete this template?') }}">
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
        </div>
    </div>
@endsection
