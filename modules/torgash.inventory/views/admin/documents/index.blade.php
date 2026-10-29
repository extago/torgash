@extends('admin.layout')

@section('title', __('Stock documents'))
@section('title_hint', __('A delivery that arrived, goods broken or used by the shop, goods moved between warehouses, a count of what is really on the shelf. A draft can be changed; a posted document moves the stock once and stays as it is.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    {{-- The kinds as one row of tabs over the card, a new document of each
         kind at its end (app.css, `admin-tabs`). --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Stock documents') }}">
            <a href="{{ route('admin.inventory.documents.index') }}" @class(['admin-tab', 'is-active' => $type === ''])>{{ __('All') }}</a>
            @foreach (\Torgash\Inventory\Models\Document::TYPES as $key => [$label])
                <a href="{{ route('admin.inventory.documents.index', ['type' => $key]) }}" @class(['admin-tab', 'is-active' => $type === $key])>
                    {{ __($label) }} <span class="admin-tab-count">{{ $counts[$key] ?? 0 }}</span>
                </a>
            @endforeach
            @if ($revokedCount)
                <a href="{{ route('admin.inventory.documents.index', ['type' => 'revoked']) }}" @class(['admin-tab', 'is-active' => $type === 'revoked'])>
                    {{ __('Revoked') }} <span class="admin-tab-count">{{ $revokedCount }}</span>
                </a>
            @endif
        </nav>
        <div class="admin-tabs-actions">
            @foreach (\Torgash\Inventory\Models\Document::TYPES as $key => [$label, $prefix, $icon])
                <form method="POST" action="{{ route('admin.inventory.documents.create', $key) }}">
                    @csrf
                    <button class="btn {{ $key === 'receipt' ? 'btn-primary' : '' }} gap-1.5"><x-icon :name="$icon" size="sm" /> {{ __($label) }}</button>
                </form>
            @endforeach
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            @if ($documents->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="clipboard-list" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No documents yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Number') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Kind') }}</th>
                                <th>{{ __('Warehouse') }}</th>
                                <th class="text-end">{{ __('Lines') }}</th>
                                <th>{{ __('State') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($documents as $document)
                                <tr>
                                    <td><a href="{{ route('admin.inventory.documents.edit', $document) }}" class="link font-mono">{{ $document->number }}</a></td>
                                    <td class="whitespace-nowrap">{{ $document->document_date?->format('d.m.Y') }}</td>
                                    <td><span class="inline-flex items-center gap-1.5"><x-icon :name="$document->icon()" size="sm" class="opacity-60" /> {{ $document->typeLabel() }}</span></td>
                                    <td>
                                        {{ pick_translation($warehouses->get($document->warehouse_id)?->name) }}
                                        @if ($document->to_warehouse_id)
                                            → {{ pick_translation($warehouses->get($document->to_warehouse_id)?->name) }}
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">{{ $document->lines_count }}</td>
                                    <td>
                                        @if ($document->isRevoked())
                                            <span class="badge badge-sm badge-error">{{ __('Revoked') }}</span>
                                        @elseif ($document->isPosted())
                                            <span class="badge badge-sm badge-success">{{ __('Posted') }}</span>
                                        @else
                                            <span class="badge badge-sm badge-ghost">{{ __('Draft') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $documents->links() }}
            @endif
        </div>
    </div>
@endsection
