@extends('admin.layout')

@section('title', __('Phrases'))

@section('content')
    @include('torgash.search::admin.rules.frame')
        <div class="grid md:grid-cols-[1fr_8rem] gap-4">
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Phrase') }}</legend>
                <input name="phrase" value="{{ old('phrase', $editing?->phrase ?? request('phrase')) }}" class="input input-bordered w-full">
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Priority') }}</legend>
                <input type="number" name="priority" value="{{ old('priority', $editing?->priority ?? 0) }}" class="input input-bordered w-full">
            </fieldset>
        </div>
        <x-admin.picker
            name="product_ids[]"
            :options="$productOptions"
            :selected="array_map('intval', old('product_ids', $editing ? (array) $editing->product_ids : []))"
            :label="__('Products first')"
            :placeholder="__('Search products by SKU or name…')"
            :empty="__('None — the phrase is only suggested.')"
            ordered />
    @include('torgash.search::admin.rules.frame-end')

    @include('torgash.search::admin.rules.list', [
        'rulesIntro' => __('A phrase is offered in the window while a customer types its beginning, highest priority first. When it is exactly what was searched, the products chosen here come first, in this order — even ones whose text does not mention it.'),
        'rulesColumns' => [__('Phrase'), __('Priority'), __('Products first')],
        'rulesCells' => fn ($row) => [
            e($row->phrase),
            (string) $row->priority,
            e(implode(', ', array_map(fn ($id) => $productNames[$id] ?? ('#'.$id), (array) $row->product_ids))) ?: '<span class="opacity-40">—</span>',
        ],
    ])
@endsection
