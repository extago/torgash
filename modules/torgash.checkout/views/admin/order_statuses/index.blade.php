@extends('admin.layout')

@section('title', __('Order statuses'))
@section('title_hint', __('The shop\'s own workflow for an order: what it is called at each step, its colour in the lists, and what the step does to the stock. Whether the money has arrived is a second axis, the payment state, and is not a status.'))
@section('title_actions')
    <a href="{{ route('admin.orders.index') }}" class="btn btn-ghost gap-1.5">
        <x-icon name="arrow-left" size="sm" /> {{ __('Back to orders') }}
    </a>
    <a href="{{ route('admin.orders.statuses.create') }}" class="btn btn-primary">+ {{ __('New status') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    @php
        // What a status does to the shelf, in words and an icon — the three
        // inventory actions are the one thing about a status that moves goods.
        $stockWords = [
            'decrease' => ['package-minus', __('Takes the goods off the shelf')],
            'increase' => ['package-plus', __('Puts the goods back on the shelf')],
            'none' => ['minus', __('Does not touch the stock')],
        ];
    @endphp

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-16">{{ __('Position') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Stock action') }}</th>
                            <th class="text-end">{{ __('Orders') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($statuses as $status)
                            @php
                                [$stockIcon, $stockText] = $stockWords[$status->inventory_action] ?? $stockWords['none'];
                                $inUse = (int) ($orderCounts[$status->code] ?? 0);
                            @endphp
                            <tr class="hover:bg-base-200/50">
                                <td class="tabular-nums opacity-60">{{ $status->position }}</td>
                                <td>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="h-3 w-3 rounded-full inline-block shrink-0" style="background-color: {{ $status->color }}"></span>
                                        <a href="{{ route('admin.orders.statuses.edit', $status) }}" class="font-medium link link-hover">{{ $status->t('name') }}</a>
                                        @if ($status->is_default)
                                            <span class="badge badge-sm badge-primary whitespace-nowrap">{{ __('for new orders') }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs opacity-50 font-mono ms-5">{{ $status->code }}</div>
                                </td>
                                <td class="text-sm">
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-icon :name="$stockIcon" size="sm" class="opacity-60" /> {{ $stockText }}
                                    </span>
                                </td>
                                <td class="text-end tabular-nums">
                                    @if ($inUse)
                                        <a href="{{ route('admin.orders.index', ['status' => [$status->code]]) }}" class="link link-hover">{{ $inUse }}</a>
                                    @else
                                        <span class="opacity-40">0</span>
                                    @endif
                                </td>
                                <td class="text-end whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1">
                                        <a href="{{ route('admin.orders.statuses.edit', $status) }}" class="btn btn-ghost btn-sm btn-square"
                                           title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                            <x-icon name="pencil" />
                                        </a>
                                        <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                        @if ($inUse || $status->is_default)
                                            <span class="btn btn-ghost btn-sm btn-square btn-disabled"
                                                  title="{{ $status->is_default ? __('Cannot delete the default status. Make another status default first.') : __('This status is used by existing orders and cannot be deleted.') }}">
                                                <x-icon name="trash-2" class="opacity-40" />
                                            </span>
                                        @else
                                            <form method="POST" action="{{ route('admin.orders.statuses.destroy', $status) }}" class="inline"
                                                  data-confirm="{{ __('Delete this status?') }}">
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
