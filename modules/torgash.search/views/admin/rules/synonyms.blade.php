@extends('admin.layout')

@section('title', __('Synonyms'))

@section('content')
    @include('torgash.search::admin.rules.frame')
        <div class="grid md:grid-cols-2 gap-4">
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('When they type') }}</legend>
                <textarea name="phrases" rows="3" class="textarea textarea-bordered w-full" placeholder="{{ __('One per line') }}">{{ old('phrases', $editing ? implode("\n", (array) $editing->phrases) : request('phrases', '')) }}</textarea>
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Look for') }}</legend>
                <input name="target" value="{{ old('target', $editing?->target) }}" class="input input-bordered w-full">
            </fieldset>
        </div>
        <div class="flex flex-wrap gap-4">
            @foreach (['also' => __('as well as what was typed'), 'instead' => __('instead of what was typed')] as $synonymMode => $synonymLabel)
                <label class="label cursor-pointer gap-2">
                    <input type="radio" name="mode" value="{{ $synonymMode }}" class="radio radio-sm" @checked(old('mode', $editing?->mode ?? 'also') === $synonymMode)>
                    <span>{{ $synonymLabel }}</span>
                </label>
            @endforeach
        </div>
    @include('torgash.search::admin.rules.frame-end')

    @include('torgash.search::admin.rules.list', [
        'rulesIntro' => __('What customers type that is not how the catalogue says it: «мобила» is a phone, «ноут» is a laptop. Whole words, anywhere in the query.'),
        'rulesColumns' => [__('When they type'), __('Look for')],
        'rulesCells' => fn ($row) => [
            e(implode(', ', (array) $row->phrases)),
            e($row->target).' <span class="badge badge-ghost badge-sm">'.e($row->mode === 'instead' ? __('instead') : __('as well')).'</span>',
        ],
    ])
@endsection
