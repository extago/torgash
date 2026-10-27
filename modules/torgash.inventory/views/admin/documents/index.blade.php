@extends('admin.layout')

@section('title', __('Stock documents'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm opacity-70 max-w-2xl">
                    {{ __('A delivery that arrived, goods broken or used by the shop, goods moved between warehouses, a count of what is really on the shelf. A draft can be changed; a posted document moves the stock once and stays as it is.') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    @foreach (\Torgash\Inventory\Models\Document::TYPES as $key => [$label, $prefix, $icon])
                        <form method="POST" action="{{ route('admin.inventory.documents.create', $key) }}">
                            @csrf
                            <button class="btn btn-sm {{ $key === 'receipt' ? 'btn-primary' : '' }} gap-1.5"><x-icon :name="$icon" size="sm" /> {{ __($label) }}</button>
                        </form>
                    @endforeach
                </div>
            </div>

            <div role="tablist" class="tabs tabs-border">
                <a role="tab" href="{{ route('admin.inventory.documents.index') }}" @class(['tab', 'tab-active' => $type === ''])>{{ __('All') }}</a>
                @foreach (\Torgash\Inventory\Models\Document::TYPES as $key => [$label])
                    <a role="tab" href="{{ route('admin.inventory.documents.index', ['type' => $key]) }}" @class(['tab', 'tab-active' => $type === $key])>
                        {{ __($label) }} <span class="opacity-50 ml-1">{{ $counts[$key] ?? 0 }}</span>
                    </a>
                @endforeach
                @if ($revokedCount)
                    <a role="tab" href="{{ route('admin.inventory.documents.index', ['type' => 'revoked']) }}" @class(['tab', 'tab-active' => $type === 'revoked'])>
                        {{ __('Revoked') }} <span class="opacity-50 ml-1">{{ $revokedCount }}</span>
                    </a>
                @endif
            </div>

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
