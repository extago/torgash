@extends('admin.layout')

@section('title', __('Age limits'))
@section('title_hint', __('Every category and product with an age of its own, and the whole shop\'s. An age is set on the category\'s or the product\'s form, beside "Who sees it"; a category\'s holds everything under it.'))

@section('title_actions')
    @can('settings.manage')
        <a href="{{ route('admin.settings.edit', 'torgash.age') }}" class="btn btn-ghost gap-1.5"><x-icon name="settings" size="sm" /> {{ __('Settings') }}</a>
    @endcan
@endsection

@section('content')
    @php $ageLocale = admin_content_locale(); @endphp

    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-2">
            <h3 class="font-semibold">{{ __('The whole shop') }}</h3>
            <p class="text-sm">
                @if ($siteAge > 0)
                    {{ __('Every product is for people of :age or older: :showing.', ['age' => $siteAge, 'showing' => mb_strtolower($labels[$defaultDisplay])]) }}
                @else
                    {{ __('No age for the whole shop — only the categories and products below.') }}
                @endif
            </p>
            <p class="text-xs opacity-60">{{ __('What a visitor who has not confirmed their age sees, unless a rule says otherwise: :showing.', ['showing' => mb_strtolower($labels[$defaultDisplay])]) }}</p>
        </div>
    </section>

    @foreach ([
        ['title' => __('Categories'), 'rows' => $categories, 'empty' => __('No category has an age.'), 'kind' => 'category'],
        ['title' => __('Products'), 'rows' => $products, 'empty' => __('No product has an age of its own.'), 'kind' => 'product'],
    ] as $ageList)
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <h3 class="font-semibold">{{ $ageList['title'] }}</h3>
                @if ($ageList['rows']->isEmpty())
                    <p class="text-sm opacity-60">{{ $ageList['empty'] }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table admin-cards">
                            <thead>
                                <tr>
                                    <th>{{ __('Name') }}</th>
                                    <th class="text-end">{{ __('Age') }}</th>
                                    <th>{{ __('A visitor who has not confirmed their age sees') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ageList['rows'] as $ageEntry)
                                    @php
                                        $ageRecord = $ageEntry[$ageList['kind']];
                                        $ageEdit = $ageList['kind'] === 'category'
                                            ? route('admin.catalog.categories.edit', $ageRecord)
                                            : route('admin.catalog.products.edit', $ageRecord);
                                        $ageShown = in_array((string) $ageEntry['row']->display, \Torgash\Age\Limit::KINDS, true)
                                            ? $labels[$ageEntry['row']->display]
                                            : __('As in the settings: :choice', ['choice' => mb_strtolower($labels[$defaultDisplay])]);
                                    @endphp
                                    <tr class="hover:bg-base-200/50">
                                        <td data-cell="name">
                                            <a href="{{ $ageEdit }}" class="font-medium link link-hover">{{ $ageRecord->t('name', $ageLocale) ?: '#'.$ageRecord->id }}</a>
                                            @if ($ageList['kind'] === 'product' && $ageRecord->sku)
                                                <div class="text-xs opacity-50">{{ $ageRecord->sku }}</div>
                                            @endif
                                        </td>
                                        <td data-cell="age" class="text-end tabular-nums font-medium">{{ $ageEntry['row']->age }}+</td>
                                        <td data-cell="display" class="text-sm">{{ $ageShown }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($ageList['kind'] === 'product' && $productCount > $ageList['rows']->count())
                        <p class="text-xs opacity-60">{{ __('The last :shown changed of :count.', ['shown' => $ageList['rows']->count(), 'count' => $productCount]) }}</p>
                    @endif
                @endif
            </div>
        </section>
    @endforeach
@endsection
