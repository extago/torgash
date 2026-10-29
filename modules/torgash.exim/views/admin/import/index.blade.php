@extends('admin.layout')

@section('title', __('Import'))

@section('content')
    @include('torgash.exim::admin._nav', ['eximScreen' => 'import'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Upload a file') }}</h3>
                    <form method="POST" action="{{ route('admin.exim.import.upload') }}" enctype="multipart/form-data" class="flex flex-col gap-4 max-w-xl">
                        @csrf
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('What the file holds') }}</legend>
                            <select name="entity" class="select select-bordered w-full">
                                @foreach ($entities as $key => $label)
                                    <option value="{{ $key }}" @selected(old('entity', 'product') === $key)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('CSV or XML/YML file') }}</legend>
                            <input type="file" name="file" accept=".csv,.txt,.tsv,.xml,.yml,text/csv,text/plain,text/xml,application/xml" class="file-input file-input-bordered w-full" required>
                            <p class="label text-xs whitespace-normal">{{ __('Any delimiter (semicolon, comma, tab) and UTF-8 or Windows-1251 — worked out from the file; you can change it on the next step.') }}</p>
                        </fieldset>
                        <div>
                            <button class="btn btn-primary btn-sm gap-1.5"><x-icon name="columns-3" size="sm" /> {{ __('Next: match the columns') }}</button>
                        </div>
                    </form>
                </div>
            </section>

            @if ($drafts->isNotEmpty())
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Unfinished imports') }}</h3>
                        <p class="text-sm opacity-70">{{ __('Uploaded and not imported yet — with the columns matched so far. One nobody opens for :days days is deleted with its file.', ['days' => \Torgash\Exim\Models\ImportDraft::DAYS]) }}</p>
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>{{ __('File') }}</th>
                                        <th>{{ __('What the file holds') }}</th>
                                        <th>{{ __('Columns') }}</th>
                                        <th>{{ __('Rows') }}</th>
                                        <th>{{ __('Uploaded by') }}</th>
                                        <th>{{ __('Changed') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($drafts as $draft)
                                        <tr>
                                            <td class="font-medium max-w-64">
                                                <a href="{{ route('admin.exim.import.map', ['token' => $draft->token]) }}" class="link link-hover block truncate" title="{{ $draft->file_name }}">{{ $draft->file_name }}</a>
                                            </td>
                                            <td>{{ __($entities[$draft->entity] ?? $draft->entity) }}</td>
                                            <td class="text-xs opacity-70 whitespace-nowrap">{{ $draft->column_count !== null ? $draft->mappedCount().' / '.$draft->column_count : '—' }}</td>
                                            <td class="text-xs opacity-70">{{ $draft->row_count ?? '—' }}</td>
                                            <td class="text-xs">{{ $draft->user?->name ?? '—' }}</td>
                                            <td class="text-xs opacity-60 whitespace-nowrap" title="{{ __('Deleted :when if nobody opens it', ['when' => $draft->expiresAt()->isoFormat('LLL')]) }}">{{ $draft->updated_at->diffForHumans() }}</td>
                                            <td class="text-end whitespace-nowrap">
                                                <a href="{{ route('admin.exim.import.map', ['token' => $draft->token]) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Continue') }}" aria-label="{{ __('Continue') }}"><x-icon name="pencil" size="sm" /></a>
                                                <form method="POST" action="{{ route('admin.exim.import.destroy', $draft) }}" class="inline" data-confirm="{{ __('Delete this unfinished import and its file?') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif

            @if ($presets->isNotEmpty())
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Import presets') }}</h3>
                        <p class="text-sm opacity-70">{{ __('How a file was read last time. A file with the same columns is read the same way on its own.') }}</p>
                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('What the file holds') }}</th>
                                        <th>{{ __('Columns') }}</th>
                                        <th>{{ __('Last used') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($presets as $preset)
                                        <tr>
                                            <td class="font-medium">{{ $preset->name }}</td>
                                            <td>{{ __($entities[$preset->entity] ?? $preset->entity) }}</td>
                                            <td class="text-xs opacity-70">{{ count(array_filter((array) $preset->mapping)) }} / {{ count((array) $preset->columns) }}</td>
                                            <td class="text-xs opacity-60 whitespace-nowrap">{{ $preset->last_used_at?->diffForHumans() ?? '—' }}</td>
                                            <td class="text-end">
                                                <form method="POST" action="{{ route('admin.exim.import.presets.destroy', $preset) }}" data-confirm="{{ __('Delete this import preset?') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif
        </div>

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-2 text-sm">
                <h3 class="font-semibold">{{ __('How it works') }}</h3>
                <ol class="list-decimal ms-4 flex flex-col gap-1.5 opacity-80">
                    <li>{{ __('Upload a CSV — our own export or another system\'s — or a supplier\'s XML/YML feed: each offer is a row, its tags the columns.') }}</li>
                    <li>{{ __('Say which column is which field. Columns with names we know are matched on their own; the rest you pick or skip.') }}</li>
                    <li>{{ __('Check: nothing changes yet — you see what would be created and what updated.') }}</li>
                    <li>{{ __('Confirm, and the import runs in the background — follow it under Background tasks. Photos are fetched after the rows, as a task of their own.') }}</li>
                </ol>
                <p class="text-xs opacity-60">{{ __('A row finds its record by the external ID first, then by code, SKU or address; only then, carefully, by name.') }}</p>
            </div>
        </section>
    </div>
@endsection
