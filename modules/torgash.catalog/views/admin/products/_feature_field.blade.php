{{-- One of the shop's characteristics as a field of the product form:
     a list's value, a typed text or number — or, outside the base
     content language, this language's word for a typed text. Drawn by the
     form for the product's set (Features\ProductFeatureSet) and by the
     "add a characteristic" search for one found by name; both post the
     same `global_features[id]`, so the save cannot tell them apart.
     `$featureField` (the Feature), `$featureAssigned` (the product's row
     for it, or null) and `$featureLocale` are this partial's own names. --}}
@php
    $featureName = $featureField->t('name', $featureLocale) ?: $featureField->code;
    $featureFilled = $featureAssigned !== null;
@endphp
<fieldset class="fieldset p-0 product-feature-field" data-feature-id="{{ $featureField->id }}"
          data-feature-name="{{ mb_strtolower($featureName.' '.$featureField->code) }}"
          x-show="featureMatches($el)">
    <legend class="fieldset-legend">
        {{ $featureName }}
        @if ($featureFilled) <span class="badge badge-sm badge-ghost">{{ __('filled') }}</span> @endif
    </legend>
    @if ($featureField->isChoiceType())
        <select name="global_features[{{ $featureField->id }}]" class="select select-bordered w-full">
            <option value="">— {{ __('not set') }} —</option>
            @foreach ($featureField->values as $featureValue)
                <option value="{{ $featureValue->id }}" @selected((string) old('global_features.'.$featureField->id, $featureAssigned?->feature_value_id) === (string) $featureValue->id)>
                    {{ $featureValue->display($featureLocale) }}
                </option>
            @endforeach
        </select>
    @elseif ($featureField->isTypedText() && ! content_locale_is_base())
        {{-- Another content language: this language's word for the base
             value, which shows as the placeholder. Nothing to translate
             until the base language has a value. --}}
        <input name="global_features_words[{{ $featureField->id }}]" type="text"
               value="{{ old('global_features_words.'.$featureField->id, ($featureAssigned?->value_translations ?? [])[$featureLocale] ?? '') }}"
               placeholder="{{ $featureAssigned?->value_text }}"
               @disabled(! $featureAssigned?->value_text)
               @if (! $featureAssigned?->value_text) title="{{ __('Fill it in the base language first') }}" @endif
               class="input input-bordered w-full">
    @else
        <input name="global_features[{{ $featureField->id }}]" type="{{ $featureField->input_type === 'number' ? 'number' : 'text' }}"
               value="{{ old('global_features.'.$featureField->id, $featureAssigned?->value_text) }}"
               class="input input-bordered w-full">
    @endif
</fieldset>
