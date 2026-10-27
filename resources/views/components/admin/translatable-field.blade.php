@props([
    'name',
    'label',
    'value' => [],
    'required' => false,
    'textarea' => false,
    'rows' => 3,
    'placeholder' => null,
    'rich' => false,
    'record' => null,
])
@php
    $locale = admin_content_locale();
    $isBase = $locale === content_base_locale();
    // old() addresses nested input with dots, the HTML name attribute with
    // brackets: settings[html] is old('settings.html'). Flat names are
    // unaffected, so this only makes grouped fields repopulate correctly after
    // a failed validation instead of silently falling back to the stored value.
    $current = old(str_replace(['[', ']'], ['.', ''], $name), $value[$locale] ?? '');
    $otherLocales = collect($value ?? [])->except($locale)->filter();

    // Blank in this locale doesn't mean "empty" — it means "not translated
    // yet". Showing the other locale's own text as the placeholder makes
    // that visible in place, instead of a bare empty box that reads as data
    // loss the moment you switch the language toggle.
    $untranslatedPreview = $current === '' ? $otherLocales->first() : null;
    $effectivePlaceholder = $untranslatedPreview ?? $placeholder;

    // The legend names the GROUP; the field itself needs its own name, or a
    // screen reader announces "edit text" (audit 2026-10-15). The fieldset
    // stays because the notes below it cannot sit inside a <label>.
    $fieldName = $label.' ('.strtoupper($locale).')';

    // A rich-text module (torgash.editor) answers this filter with a rendered
    // editor. With no such module installed the field stays a plain textarea.
    //
    // `record` is the row being edited, when the caller has one — a product, a
    // page, tomorrow a post. Core does nothing with it and knows nothing about
    // what an editor might: it is passed through so that a module which can put
    // IMAGES inside this text has something to hang them on. Absent (a new,
    // unsaved row, or a field belonging to no row at all) simply means that
    // half of the editor is not offered, which is the same rule the gallery
    // editor already follows on an unsaved product.
    $richField = $rich
        ? hook_filter('core.field.rich', null, [
            'name' => $name,
            'value' => (string) $current,
            'placeholder' => $effectivePlaceholder,
            'rows' => $rows,
            'record' => $record,
            'label' => $fieldName,
        ])
        : null;
@endphp
<fieldset class="fieldset p-0">
    <legend class="fieldset-legend">
        {{ $label }}{{ ($required && $isBase) ? ' *' : '' }}
        <span class="opacity-40 font-normal">({{ strtoupper($locale) }})</span>
    </legend>
    @if ($richField)
        {!! $richField !!}
    @elseif ($textarea)
        <textarea name="{{ $name }}" rows="{{ $rows }}" @required($required && $isBase) aria-label="{{ $fieldName }}"
                  {{ $attributes->merge(['class' => 'textarea textarea-bordered w-full'.($untranslatedPreview ? ' placeholder:italic' : '')]) }}
                  placeholder="{{ $effectivePlaceholder }}">{{ $current }}</textarea>
    @else
        <input name="{{ $name }}" value="{{ $current }}" @required($required && $isBase) aria-label="{{ $fieldName }}"
               {{ $attributes->merge(['class' => 'input input-bordered w-full'.($untranslatedPreview ? ' placeholder:italic' : '')]) }}
               placeholder="{{ $effectivePlaceholder }}">
    @endif
    {{-- Inside the fieldset, like every other field's note: outside it, it had
         to pull itself up with a negative margin that assumed the caller's
         gap, and in a wrapper with no gap it landed on the input. --}}
    @if ($untranslatedPreview)
        <p class="label text-xs opacity-60 whitespace-normal">
            {{ __('Not translated yet — showing the :locale text as a placeholder above; type here to add this language.', ['locale' => strtoupper($otherLocales->keys()->first())]) }}
        </p>
    @elseif ($otherLocales->isNotEmpty())
        <p class="label text-xs opacity-60">
            {{ __('Also set in:') }} {{ $otherLocales->keys()->map(fn ($l) => strtoupper($l))->implode(', ') }}
        </p>
    @endif
</fieldset>
