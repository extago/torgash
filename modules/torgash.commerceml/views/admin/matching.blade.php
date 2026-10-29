@extends('admin.layout')

@section('title', __('1C exchange'))

@php
    use Torgash\CommerceMl\Models\Link;

    $titles = [
        Link::KIND_PRICE_TYPE => __('Price types'),
        Link::KIND_FEATURE => __('Characteristics'),
    ];

    /**
     * The select's current value: one string carrying both the decision and
     * what it points at, because they are one answer.
     */
    $chosen = function (Link $link) {
        if ($link->decision !== Link::LINK) {
            return $link->decision;
        }

        return $link->local_key !== null ? 'key:'.$link->local_key : 'id:'.$link->local_id;
    };
@endphp

@section('content')
    @include('torgash.commerceml::admin._nav')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.commerceml.matching.save', $kind) }}" class="flex flex-col gap-4">
        @csrf

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h3 class="font-semibold">{{ __('What 1C has sent, and what it is here') }}</h3>
                    <button class="btn btn-primary btn-sm ml-auto">{{ __('Save') }}</button>
                </div>

                <div class="text-xs opacity-60">
                    {{ __('Matched by identifier, never by name: 1C renames things, and two price types called “Оферта” and “оферта” is something a real shop has. The name is here so you can recognise the row.') }}
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Called in 1C') }}</th>
                                <th>{{ __('Its identifier') }}</th>
                                <th class="w-96">{{ __('Is here') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php $link = $row['link']; @endphp
                                <tr class="{{ $link->decision === Link::ASK ? 'bg-warning/10' : '' }}">
                                    <td class="font-medium">{{ $link->name ?: '—' }}</td>
                                    <td><code class="font-mono text-xs opacity-60 break-all">{{ $link->guid }}</code></td>
                                    <td>
                                        <select name="rows[{{ $link->id }}]" class="select select-bordered w-full">
                                            <option value="{{ Link::ASK }}" @selected($chosen($link) === Link::ASK)>
                                                — {{ __('not decided — skipped for now') }}
                                            </option>
                                            <option value="{{ Link::CREATE }}" @selected($chosen($link) === Link::CREATE)>
                                                {{ __('Create one at the next exchange') }}
                                            </option>
                                            <option value="{{ Link::SKIP }}" @selected($chosen($link) === Link::SKIP)>
                                                {{ __('Never import this') }}
                                            </option>
                                            @foreach ($options as $value => $label)
                                                <option value="{{ $value }}" @selected($chosen($link) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>

                                {{-- The variants of one property, under it. One
                                     form and one Save, because "import this
                                     characteristic, and these of its values" is
                                     a single decision. --}}
                                @foreach ($row['children'] as $child)
                                    <tr class="{{ $child->decision === Link::ASK ? 'bg-warning/5' : '' }}">
                                        <td class="pl-8 text-sm opacity-80">{{ $child->name ?: '—' }}</td>
                                        <td><code class="font-mono text-xs opacity-40 break-all">{{ $child->guid }}</code></td>
                                        <td>
                                            <select name="rows[{{ $child->id }}]" class="select select-bordered select-sm w-full">
                                                <option value="{{ Link::ASK }}" @selected($chosen($child) === Link::ASK)>
                                                    — {{ __('not decided') }}
                                                </option>
                                                <option value="{{ Link::CREATE }}" @selected($chosen($child) === Link::CREATE)>
                                                    {{ __('Create one at the next exchange') }}
                                                </option>
                                                <option value="{{ Link::SKIP }}" @selected($chosen($child) === Link::SKIP)>
                                                    {{ __('Never import this') }}
                                                </option>
                                                @foreach ($valueOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected($chosen($child) === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center opacity-60 py-6">
                                        {{ __('Nothing yet — this fills up when 1C sends its first file.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($rows !== [])
                    <div class="flex justify-end">
                        <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
                    </div>
                @endif
            </div>
        </div>
    </form>
@endsection
