@extends('admin.layout')

@section('title', __('Exclusions'))

@section('content')
    @include('torgash.search::admin.rules.frame')
        <div class="grid md:grid-cols-3 gap-4">
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('When the query has') }}</legend>
                <textarea name="when_words" rows="3" class="textarea textarea-bordered w-full" placeholder="{{ __('One per line') }}">{{ old('when_words', $editing ? implode("\n", (array) $editing->when_words) : '') }}</textarea>
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Do not show products whose name has') }}</legend>
                <textarea name="exclude_words" rows="3" class="textarea textarea-bordered w-full" placeholder="{{ __('One per line') }}">{{ old('exclude_words', $editing ? implode("\n", (array) $editing->exclude_words) : '') }}</textarea>
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('…or that are in the category') }}</legend>
                <select name="exclude_category_id" class="select select-bordered w-full">
                    <option value="">—</option>
                    @foreach ($categoryOptions as $exclusionCategoryId => $exclusionCategoryName)
                        <option value="{{ $exclusionCategoryId }}" @selected((int) old('exclude_category_id', $editing?->exclude_category_id) === (int) $exclusionCategoryId)>{{ $exclusionCategoryName }}</option>
                    @endforeach
                </select>
                <span class="text-xs opacity-60 mt-1">{{ __('With everything under it.') }}</span>
            </fieldset>
        </div>
        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend">{{ __('Note for yourself') }}</legend>
            <input name="note" value="{{ old('note', $editing?->note) }}" class="input input-bordered w-full">
        </fieldset>
    @include('torgash.search::admin.rules.frame-end')

    @include('torgash.search::admin.rules.list', [
        'rulesIntro' => __('A query holding one of these words must not show certain products: «чехол» should not show the phones themselves. Write the START of a word — «чехл» covers чехлы, чехла, чехлов. A category is the surer target: a case «for a phone» has the word phone in its own name.'),
        'rulesColumns' => [__('When the query has'), __('Do not show')],
        'rulesCells' => fn ($row) => [
            e(implode(', ', (array) $row->when_words)),
            e(implode(', ', array_filter([
                implode(', ', (array) $row->exclude_words),
                $row->exclude_category_id ? trim($categoryOptions[$row->exclude_category_id] ?? ('#'.$row->exclude_category_id)) : null,
            ]))).($row->note ? '<div class="text-xs opacity-60">'.e($row->note).'</div>' : ''),
        ],
    ])
@endsection
