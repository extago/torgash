{{--
    The band a row or a block stands in (PLAN §51) — the zone screen's window
    for one group, posted to `admin.blocks.section` for its FIRST block, whose
    band the whole row or set of tabs is drawn in.

    Given: $sectionLead (the group's first block). Variables are named
    distinctively on purpose: an included view inherits the caller's scope.
--}}
@php
    $sectionNow = \Torgash\Blocks\Section::accept((array) ($sectionLead->section ?? [])) ?? ['width' => 'page', 'background' => '', 'colour' => null, 'space' => 'none'];
    $sectionLabels = \Torgash\Blocks\Section::labels();
@endphp
<form method="POST" action="{{ route('admin.blocks.section', $sectionLead) }}" class="flex flex-col gap-3"
      x-data="{ background: @js($sectionNow['background']), colour: @js($sectionNow['colour'] ?: '#f4f4f6') }">
    @csrf @method('PATCH')
    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Width') }}</legend>
        <select name="section[width]" class="select select-bordered w-full">
            @foreach ($sectionLabels['width'] as $sectionKey => $sectionLabel)
                <option value="{{ $sectionKey }}" @selected($sectionNow['width'] === $sectionKey)>{{ $sectionLabel }}</option>
            @endforeach
        </select>
        <p class="label text-xs opacity-60 whitespace-normal">{{ __('"Edge to edge" is for a slider or a picture across the whole screen.') }}</p>
    </fieldset>
    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Background') }}</legend>
        <select name="section[background]" x-model="background" class="select select-bordered w-full">
            @foreach ($sectionLabels['background'] as $sectionKey => $sectionLabel)
                <option value="{{ $sectionKey }}">{{ $sectionLabel }}</option>
            @endforeach
        </select>
    </fieldset>
    <div class="flex items-center gap-2" x-show="background === 'colour'" x-cloak>
        <input type="color" x-model="colour" class="w-12 h-10 rounded-field border border-base-300 cursor-pointer bg-transparent">
        <input name="section[colour]" x-model="colour" maxlength="7" class="input input-bordered w-32 font-mono">
    </div>
    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Room above and below') }}</legend>
        <select name="section[space]" class="select select-bordered w-full">
            @foreach ($sectionLabels['space'] as $sectionKey => $sectionLabel)
                <option value="{{ $sectionKey }}" @selected($sectionNow['space'] === $sectionKey)>{{ $sectionLabel }}</option>
            @endforeach
        </select>
    </fieldset>
    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('CSS class') }}</legend>
        <input name="section[class]" value="{{ $sectionNow['class'] ?? '' }}" maxlength="{{ \Torgash\Blocks\CssClasses::MAX }}"
               class="input input-bordered w-full font-mono" placeholder="home-promo">
        <p class="label text-xs opacity-60 whitespace-normal">{{ __('For the shop\'s own stylesheet: added to the band around the row. Several are separated by spaces.') }}</p>
    </fieldset>
    <div class="flex justify-end gap-2">
        <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
    </div>
</form>
