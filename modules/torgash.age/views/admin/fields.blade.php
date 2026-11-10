{{-- "Age limit" beside "Who sees it" on a category's and a product's form
     (torgash.age, PLAN §71), on catalog.category.visibility_extra /
     catalog.product.visibility_extra. Saved on catalog.category.saved /
     catalog.product.saved — only when the marker came with the form, so an
     import or a quick edit never wipes a limit it did not show. --}}
@php
    $ageLimitValue = old('age_limit', $ageOwn['age'] ?? '');
    $ageDisplayValue = (string) old('age_display', $ageOwn['display'] ?? '');
@endphp
<fieldset class="fieldset p-0">
    <legend class="fieldset-legend">{{ __('Age limit') }}</legend>
    <input type="hidden" name="age_form" value="1">
    <label class="flex items-center gap-2">
        <input type="number" name="age_limit" value="{{ $ageLimitValue }}" min="0" max="99" step="1" inputmode="numeric"
               placeholder="—" class="input input-bordered w-24" aria-label="{{ __('Age limit') }}">
        <span class="text-sm opacity-70">{{ __('years and older') }}</span>
    </label>
    <select name="age_display" class="select select-bordered w-full" aria-label="{{ __('A visitor who has not confirmed their age sees') }}">
        @foreach (\Torgash\Age\Rules::displayChoices() as $ageChoice => $ageChoiceText)
            <option value="{{ $ageChoice }}" @selected($ageDisplayValue === (string) $ageChoice)>{{ $ageChoiceText }}</option>
        @endforeach
    </select>
    <p class="label text-xs opacity-60 whitespace-normal">
        @if ($ageSubject === 'category')
            {{ __('Empty — no age of its own. An age here holds every category and product under this one.') }}
        @else
            {{ __('Empty — no age of its own.') }}
        @endif
        @if ($ageInherited)
            {{ __('Already :age+ from the categories above or the whole shop; a rule here can only add to that.', ['age' => $ageInherited->age()]) }}
        @endif
    </p>
</fieldset>
