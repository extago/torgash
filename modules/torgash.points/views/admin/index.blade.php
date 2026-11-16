@extends('admin.layout')

@section('title', __('Bonus points'))
@section('title_hint', __('Points customers earn for orders and spend in the basket as a discount. How many an order earns, when they are credited and what a point is worth are in the module\'s settings; here is every movement; which goods earn more, less or nothing, and which points do not pay for, are the Rules.'))
@section('title_actions')
    <a href="{{ url('/admin/settings/torgash.points') }}" class="btn btn-ghost gap-1.5"><x-icon name="settings" size="sm" /> {{ __('Settings') }}</a>
@endsection

@section('content')
    @include('torgash.points::admin._nav', ['pointsScreen' => 'movements'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <p class="text-sm">{{ __('Points customers hold') }}: <span class="font-semibold tabular-nums">{{ number_format($outstanding, 0, '.', ' ') }}</span></p>

                <form method="GET" class="flex items-center gap-2">
                    <label class="input input-sm grow min-w-48 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $q }}" class="grow" placeholder="{{ __('Customer\'s name or email…') }}">
                    </label>
                    @if ($q !== '')
                        <a href="{{ route('admin.points.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endif
                </form>

                @if ($entries->isEmpty())
                    <div class="text-center py-12 opacity-60">
                        <x-icon name="coins" size="xl" class="opacity-40" />
                        <p class="mt-2">{{ __('No movements yet.') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Customer') }}</th>
                                    <th>{{ __('What') }}</th>
                                    <th class="text-end">{{ __('Points') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entries as $entry)
                                    @php $entryUser = $users->get($entry->user_id); @endphp
                                    <tr>
                                        <td class="opacity-60 whitespace-nowrap">{{ $entry->created_at?->format('d.m.Y H:i') }}</td>
                                        <td>
                                            @if ($entryUser)
                                                <a href="{{ route('admin.customers.edit', $entryUser->id) }}" class="link link-hover">{{ $entryUser->name }}</a>
                                                <div class="text-xs opacity-60">{{ $entryUser->email }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            {{ $entry->typeLabel() }}
                                            @if ($entry->order_id && isset($orders[$entry->order_id]))
                                                · <a href="{{ url('/admin/orders/'.$orders[$entry->order_id]) }}" class="link">{{ $orders[$entry->order_id] }}</a>
                                            @endif
                                            @if ($entry->note)
                                                <div class="text-xs opacity-60">{{ $entry->note }}@if ($entry->actor_name) — {{ $entry->actor_name }}@endif</div>
                                            @endif
                                        </td>
                                        <td class="text-end tabular-nums {{ $entry->points > 0 ? 'text-success' : '' }}">
                                            {{ $entry->points > 0 ? '+' : '−' }}{{ number_format(abs($entry->points), 0, '.', ' ') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $entries->links() }}
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Correct a balance') }}</h3>
                    <form method="POST" action="{{ route('admin.points.adjust') }}" class="flex flex-col gap-2">
                        @csrf
                        <input name="email" type="email" required class="input input-bordered w-full" value="{{ old('email') }}" placeholder="{{ __('Customer\'s email') }}">
                        <input name="points" type="number" required class="input input-bordered w-full" value="{{ old('points') }}" placeholder="{{ __('+100 or −50') }}">
                        <input name="reason" required maxlength="500" class="input input-bordered w-full" value="{{ old('reason') }}" placeholder="{{ __('Why — the customer sees it in the history') }}">
                        <button class="btn btn-sm">{{ __('Correct') }}</button>
                    </form>
                </div>
            </section>

        </div>
    </div>
@endsection
