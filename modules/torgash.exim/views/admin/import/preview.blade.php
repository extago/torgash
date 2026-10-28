@extends('admin.layout')

@section('title', __('Check the import'))
@section('own_heading', '1')

@section('content')
    @php
        // Only the columns the file actually fills: a column of blanks is noise.
        // A per-column field ("a characteristic named by the column") shows
        // which column it is.
        $columns = collect($rows)
            ->flatMap(fn ($entry) => array_keys(array_filter((array) $entry['row'], fn ($v) => $v !== null && $v !== '')))
            ->unique()
            ->map(function ($rowKey) use ($fieldDefs) {
                [$key, $param] = \Torgash\Exim\Import\Columns::split((string) $rowKey, $fieldDefs);
                $field = $fieldDefs[$key] ?? null;

                return $field ? ['key' => $rowKey, 'title' => \Torgash\Exim\SchemaRegistry::title($field).($param !== null ? ': '.$param : '')] : null;
            })
            ->filter()
            ->values();
        $shown = count($rows);
    @endphp

    <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
        <a href="{{ route('admin.exim.import.map', ['token' => $token]) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to the columns') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Check the import') }}</div>
            <div class="font-semibold truncate leading-tight">{{ __($entityLabel) }} · {{ trans_choice(':count row|:count rows', $total, ['count' => $total]) }}</div>
        </div>
        <a href="{{ route('admin.exim.import.map', ['token' => $token]) }}" class="btn btn-ghost btn-sm">{{ __('Change the columns') }}</a>
        <form method="POST" action="{{ route('admin.exim.import.commit') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button class="btn btn-primary btn-sm gap-1.5"><x-icon name="upload" size="sm" /> {{ __('Import') }}</button>
        </form>
    </div>

    <div class="card bg-base-100 border border-base-300" x-data="{ show: '' }">
        <div class="card-body gap-4">
            <div class="flex flex-wrap gap-2 items-center">
                <span class="badge badge-success gap-1">{{ trans_choice(':count will be created|:count will be created', $counts['create'], ['count' => $counts['create']]) }}</span>
                <span class="badge badge-info gap-1">{{ trans_choice(':count will be updated|:count will be updated', $counts['update'], ['count' => $counts['update']]) }}</span>
                @if ($counts['skip'])
                    <span class="badge badge-ghost gap-1">{{ trans_choice(':count will be skipped (not found)|:count will be skipped (not found)', $counts['skip'], ['count' => $counts['skip']]) }}</span>
                @endif
                @if ($fallbacks)
                    <span class="badge badge-warning gap-1"><x-icon name="triangle-alert" size="sm" /> {{ trans_choice(':count found by name only — check them|:count found by name only — check them', $fallbacks, ['count' => $fallbacks]) }}</span>
                @endif
                @if ($preset)
                    <span class="badge badge-ghost gap-1"><x-icon name="bookmark" size="sm" /> {{ __('Preset ":name" saved', ['name' => $preset->name]) }}</span>
                @endif
            </div>

            @if ($problems)
                <div class="alert alert-warning text-sm items-start">
                    <x-icon name="triangle-alert" size="sm" class="mt-0.5" />
                    <div>
                        <div class="font-medium">{{ __('A formula could not be worked out for some rows — those fields are left as they are:') }}</div>
                        <ul class="list-disc ml-4 mt-1">
                            @foreach ($problems as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @if ($refused)
                <div class="alert alert-warning text-sm items-start">
                    <x-icon name="triangle-alert" size="sm" class="mt-0.5" />
                    <div>
                        <div class="font-medium">{{ __('These rows match nothing and cannot make a new one — they will be skipped:') }}</div>
                        <ul class="list-disc ml-4 mt-1">
                            @foreach ($refused as $refusal)
                                <li>{{ $refusal }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <p class="text-sm opacity-70">
                {{ __('Nothing has changed yet. The import starts when you press Import, and runs in the background.') }}
                @if ($total > $shown)
                    {{ __('The first :count rows are shown here.', ['count' => $shown]) }}
                @endif
            </p>

            <div class="join -mt-1">
                @foreach (['' => __('All'), 'create' => __('Created'), 'update' => __('Updated'), 'skip' => __('Skipped'), 'name-fallback' => __('Found by name')] as $showKey => $showLabel)
                    <button type="button" class="btn btn-sm join-item" :class="show === @js($showKey) && 'btn-active'" @click="show = @js($showKey)">{{ $showLabel }}</button>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>{{ __('Line') }}</th>
                            <th>{{ __('What happens') }}</th>
                            <th>{{ __('Found by') }}</th>
                            @foreach ($columns as $column)
                                <th class="whitespace-nowrap">{{ $column['title'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $entry)
                            <tr x-show="show === '' || show === @js($entry['action']) || show === @js($entry['matchedBy'] ?? '')">
                                {{-- The line a spreadsheet shows: the header is line 1. --}}
                                <td class="text-xs opacity-50 tabular-nums">{{ $entry['line'] + 1 }}</td>
                                <td>
                                    <span @class(['badge badge-sm', 'badge-success' => $entry['action'] === 'create', 'badge-info' => $entry['action'] === 'update', 'badge-ghost' => $entry['action'] === 'skip'])>
                                        {{ match ($entry['action']) { 'create' => __('create'), 'update' => __('update'), default => __('skip') } }}
                                    </span>
                                </td>
                                <td class="text-xs whitespace-nowrap">
                                    @if (($entry['matchedBy'] ?? null) === 'name-fallback')
                                        <span class="badge badge-sm badge-warning">{{ __('by name — check') }}</span>
                                    @else
                                        <span class="opacity-60">{{ $entry['matchedBy'] ?? '—' }}</span>
                                    @endif
                                </td>
                                @foreach ($columns as $column)
                                    <td class="max-w-56 truncate" title="{{ \Illuminate\Support\Str::limit((string) ($entry['row'][$column['key']] ?? ''), 500) }}">{{ \Illuminate\Support\Str::limit((string) ($entry['row'][$column['key']] ?? ''), 60) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
