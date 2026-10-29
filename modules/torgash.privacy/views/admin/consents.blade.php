@extends('admin.layout')

@section('title', __('Personal data'))
@section('title_hint', __('Every "I agree" given on the site — in the cookie banner and in the forms — with the words it was given under. This is what shows a consent was really given.'))

@section('content')
    <div class="flex flex-col gap-4">
        @include('torgash.privacy::admin._tabs')

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <label class="input input-sm grow min-w-48 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $q }}" class="grow" placeholder="{{ __('Email, phone or IP') }}">
                    </label>
                    <select name="kind" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('What') }}">
                        <option value="">{{ __('Everything') }}</option>
                        @foreach ($kinds as $kindOption)
                            <option value="{{ $kindOption }}" @selected($kind === $kindOption)>{{ $kindOption === 'cookies' ? __('Cookies') : $kindOption }}</option>
                        @endforeach
                    </select>
                </form>

                @if ($records->isEmpty())
                    <div class="text-center py-12 opacity-60">{{ __('Nothing recorded yet.') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('When') }}</th>
                                    <th>{{ __('Who') }}</th>
                                    <th>{{ __('Where') }}</th>
                                    <th>{{ __('What') }}</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($records as $record)
                                    <tr>
                                        <td class="whitespace-nowrap">{{ $record->created_at?->translatedFormat('j M Y, H:i') }}</td>
                                        <td>{{ $record->user?->name ?? (collect([$record->email, $record->phone])->filter()->implode(' · ') ?: __('a visitor')) }}</td>
                                        <td class="whitespace-nowrap">{{ $record->kind === 'cookies' ? __('Cookies') : $record->kind }}</td>
                                        <td class="text-xs">
                                            @if ($record->kind === 'cookies')
                                                {{ $record->categories ? collect($record->categories)->map(fn ($c) => __(\App\Core\Privacy\Consent::CATEGORIES[$c] ?? $c))->implode(', ') : __('Only the necessary') }}
                                            @else
                                                {{ \Illuminate\Support\Str::limit((string) $record->text, 120) }}
                                            @endif
                                        </td>
                                        <td class="font-mono text-xs opacity-70">{{ $record->ip ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $records->links() }}
                @endif
            </div>
        </div>
    </div>
@endsection
