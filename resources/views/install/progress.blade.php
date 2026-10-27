{{-- One step of the install (InstallController::step). Without an error the
     element at the bottom asks for this step as soon as it is shown, and the
     answer replaces this panel with the next one — so the page walks through
     the install one short request at a time. --}}
@php
    $progressTotal = count($steps);
    $progressCurrent = $steps[$index] ?? ['key' => '', 'label' => ''];
    $progressLabel = str_starts_with($progressCurrent['key'], 'module:')
        ? __('Installing the module :name', ['name' => trim($progressCurrent['label'])])
        : __($progressCurrent['label']);
    $progressVals = ['step' => $index, 'key' => $key, 'lang' => app()->getLocale()];
@endphp
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <h2 class="font-semibold text-lg">{{ $error ? __('The install stopped') : __('Installing…') }}</h2>
        <progress class="progress progress-primary w-full" value="{{ $index }}" max="{{ $progressTotal }}"></progress>
        <p class="text-sm flex items-center gap-2">
            @unless ($error)<span class="loading loading-spinner loading-sm text-primary"></span>@endunless
            <span>{{ $progressLabel }}</span>
            <span class="opacity-50 ml-auto tabular-nums">{{ $index + 1 }} / {{ $progressTotal }}</span>
        </p>

        @if ($error)
            <div class="alert alert-error text-sm items-start"><x-icon name="circle-x" /><span class="break-words">{{ $error }}</span></div>
            <p class="text-sm opacity-70">{{ __('Fix the cause and try again: the install goes on from this step, nothing already done is done twice.') }}</p>
            <button class="btn btn-primary w-fit gap-2" hx-post="{{ route('install.step') }}" hx-vals='@json($progressVals)' hx-target="#install-main">
                <x-icon name="rotate-cw" size="sm" /> {{ __('Try again') }}
            </button>
        @else
            <p class="text-xs opacity-60">{{ __('Keep this page open. A shop with every module takes a minute or two.') }}</p>
            <div hx-post="{{ route('install.step') }}" hx-vals='@json($progressVals)' hx-trigger="load" hx-target="#install-main"></div>
        @endif
    </div>
</section>
