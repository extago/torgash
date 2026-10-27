@extends('admin.layout')

@section('title', __('Languages'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('The languages the shop can be written in. A storefront chooses which of them it offers; every text in the admin can be filled in for each.') }}
                </p>
                <a href="{{ route('admin.languages.create') }}" class="btn btn-primary btn-sm">+ {{ __('New language') }}</a>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-16">{{ __('Sort order') }}</th>
                            <th>{{ __('Language') }}</th>
                            <th>{{ __('Code') }}</th>
                            <th>{{ __('Grammatical cases') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($languages as $language)
                            @php $languageCases = \App\Core\Support\GrammaticalCases::normalise((array) ($language->cases ?? [])); @endphp
                            <tr class="hover:bg-base-200/50">
                                <td class="tabular-nums opacity-60">{{ $language->sort }}</td>
                                <td>
                                    <a href="{{ route('admin.languages.edit', $language) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $language->enabled])>{{ $language->native_name }}</a>
                                    @if ($language->code === content_base_locale())
                                        <span class="badge badge-sm badge-primary ml-1" title="{{ $inUse[$language->code] ?? '' }}">{{ __('Base language') }}</span>
                                    @elseif ($language->enabled)
                                        {{-- The base is the owner's choice: a shop in one
                                             language makes it the base and switches the
                                             rest off. --}}
                                        <form method="POST" action="{{ route('admin.languages.base', $language) }}" class="inline"
                                              data-confirm="{{ __('Make :name the base language? Every text will be required in it, and a missing translation will fall back to it.', ['name' => $language->native_name]) }}">
                                            @csrf
                                            <button class="btn btn-ghost btn-xs ml-1 font-normal opacity-60 hover:opacity-100">{{ __('Make it the base') }}</button>
                                        </form>
                                    @endif
                                    <div class="text-xs opacity-50">{{ $language->name }}</div>
                                </td>
                                <td class="font-mono text-xs">{{ $language->code }}</td>
                                <td class="text-sm">
                                    @if ($languageCases)
                                        {{ count($languageCases) }}
                                        <span class="text-xs opacity-50">— {{ collect($languageCases)->pluck('name')->implode(', ') }}</span>
                                    @else
                                        <span class="opacity-40">{{ __('one form') }}</span>
                                    @endif
                                </td>
                                @include('admin.languages._status', ['language' => $language, 'inUse' => $inUse])
                                <td class="text-end whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <a href="{{ route('admin.languages.edit', $language) }}" class="btn btn-ghost btn-sm btn-square"
                                           title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                        <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                        @if (isset($inUse[$language->code]))
                                            <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ $inUse[$language->code] }}"><x-icon name="trash-2" /></span>
                                        @else
                                        <form method="POST" action="{{ route('admin.languages.destroy', $language) }}" class="inline"
                                              data-confirm="{{ __('Delete :name?', ['name' => $language->name]) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                <x-icon name="trash-2" />
                                            </button>
                                        </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
