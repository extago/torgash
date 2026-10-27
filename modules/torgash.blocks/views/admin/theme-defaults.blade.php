@extends('admin.layout')

@section('title', __('Restore from the theme'))

{{--
    Putting a zone back the way the theme meant it.

    One card per zone the active theme furnishes, and two buttons that mean
    different things. "Add missing" is safe and says so; "Replace" throws the
    zone away and therefore lists — by name, before anything happens — exactly
    what will be lost, marking whose each block is. A confirmation that does not
    say what it is destroying is a shrug with an OK button on it.

    Every form is its own, side by side: an inner <form> is dropped by the
    parser while its inputs stay behind (LESSONS.md).
--}}

@php
    use Torgash\Blocks\ThemeDefaults;
@endphp

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.blocks.index') }}" class="btn btn-ghost btn-sm gap-1">
            <x-icon name="arrow-left" size="sm" />
            {{ __('All zones') }}
        </a>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-2">
            <h2 class="card-title text-base">{{ __('Restore from the theme') }}</h2>
            <p class="text-sm opacity-60">
                {{ __('The active theme ships content for the zones below. Blocks are laid out once when the shop is installed and never touched again, so this is how you get them back — after taking a zone apart, or when a theme adds something in a new version.') }}
            </p>
        </div>
    </div>

    @forelse ($zones as $name => $zone)
        @php
            $label = $registry->label($name);
            $missing = count($zone['missing']);
            $mine = $zone['present']->reject(fn ($block) => $block->source === ThemeDefaults::SOURCE);
        @endphp

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div>
                    <h2 class="card-title text-base">
                        <a href="{{ route('admin.blocks.zone', $name) }}" class="link link-hover">{{ $label }}</a>
                        @if ($missing)
                            <span class="badge badge-warning badge-sm">{{ __(':n missing', ['n' => $missing]) }}</span>
                        @else
                            <span class="badge badge-success badge-sm">{{ __('Complete') }}</span>
                        @endif
                    </h2>
                    <div class="text-xs font-mono opacity-50 mt-0.5">{{ $name }}</div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 mb-2">
                            {{ __('What the theme ships') }}
                        </div>
                        <ul class="text-sm flex flex-col gap-1">
                            @foreach ($zone['defaults'] as $block)
                                <li class="flex items-center gap-2">
                                    <x-icon name="package" size="sm" class="opacity-40" />
                                    <span>{{ isset($block['title']) ? label_text($block['title']) : $block['type'] }}</span>
                                    <span class="text-xs font-mono opacity-40">{{ $block['type'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 mb-2">
                            {{ __('In this zone now') }}
                        </div>
                        @forelse ($zone['present'] as $block)
                            <div class="text-sm flex items-center gap-2">
                                <x-icon name="{{ $block->source === ThemeDefaults::SOURCE ? 'package' : 'user' }}" size="sm" class="opacity-40" />
                                <span @class(['opacity-50 line-through' => ! $block->enabled])>{{ $block->displayTitle() }}</span>
                                {{-- Whose brick this is, said plainly: it is the
                                     difference between "Replace puts this back"
                                     and "Replace deletes your afternoon". --}}
                                @unless ($block->source === ThemeDefaults::SOURCE)
                                    <span class="badge badge-outline badge-xs">{{ __('yours') }}</span>
                                @endunless
                            </div>
                        @empty
                            <p class="text-sm opacity-60">{{ __('Empty.') }}</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('admin.blocks.theme_defaults.add', $name) }}">
                        @csrf
                        <button class="btn btn-primary btn-sm gap-1.5" @disabled($missing === 0)>
                            <x-icon name="plus" size="sm" />
                            {{ __('Add what is missing') }}
                        </button>
                    </form>

                    <button type="button" class="btn btn-ghost btn-sm gap-1.5 text-error"
                            data-open-dialog="modal-replace-{{ $loop->index }}">
                        <x-icon name="rotate-ccw" size="sm" />
                        {{ __('Replace the zone') }}
                    </button>

                    <span class="text-xs opacity-50">
                        {{ __('Adding leaves everything already there exactly where it is.') }}
                    </span>
                </div>
            </div>
        </div>

        <x-modal name="replace-{{ $loop->index }}" :title="__('Replace :zone?', ['zone' => $label])" icon="rotate-ccw">
            <p class="text-sm">
                {{ __('The zone will hold exactly what the theme ships, in the theme’s own order. Everything in it now is deleted:') }}
            </p>

            @if ($zone['present']->isNotEmpty())
                <ul class="text-sm flex flex-col gap-1 mt-3">
                    @foreach ($zone['present'] as $block)
                        <li class="flex items-center gap-2">
                            <x-icon name="trash-2" size="sm" class="text-error opacity-60" />
                            <span>{{ $block->displayTitle() }}</span>
                            @unless ($block->source === ThemeDefaults::SOURCE)
                                <span class="badge badge-outline badge-xs">{{ __('yours') }}</span>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm opacity-60 mt-3">{{ __('Nothing — the zone is empty.') }}</p>
            @endif

            @if ($mine->isNotEmpty())
                <div class="alert alert-warning py-2 text-sm mt-3">
                    {{ __(':n of them are yours, not the theme’s, and are not in the theme file to be put back.', ['n' => $mine->count()]) }}
                </div>
            @endif

            <x-slot:footer>
                <form method="POST" action="{{ route('admin.blocks.theme_defaults.replace', $name) }}">
                    @csrf
                    <button class="btn btn-error btn-sm">{{ __('Replace the zone') }}</button>
                </form>
            </x-slot:footer>
        </x-modal>
    @empty
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body">
                <p class="text-sm opacity-60">
                    {{ __('The active theme ships no default content for any zone, so there is nothing to restore.') }}
                </p>
            </div>
        </div>
    @endforelse
@endsection
