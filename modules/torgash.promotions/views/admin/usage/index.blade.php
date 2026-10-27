@extends('admin.layout')

@section('title', __('Usage log'))

@section('content')
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Usage log') }}</h1>
            <div class="text-xs opacity-60 mt-1">
                {{ __('What the sales actually gave away, read from each order’s own snapshot — never recomputed, so an edited promotion cannot rewrite what an old order was given.') }}
            </div>
        </div>
        <a href="{{ route('admin.promotions.index') }}" class="btn btn-ghost btn-sm">{{ __('Promotions') }}</a>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            <form method="GET" class="flex flex-wrap gap-2 items-end">
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Promotion') }}</legend>
                    <select name="promotion" class="select select-bordered select-sm">
                        {{-- Not __('Any'): the root dictionary already owns that
                             word in the masculine, and this list is of акции. --}}
                        <option value="">{{ __('Any promotion') }}</option>
                        @foreach ($promotions as $promotion)
                            <option value="{{ $promotion->id }}" @selected(request('promotion') == $promotion->id)>
                                {{ $promotion->t('name', admin_content_locale()) ?: __('(untitled)') }}
                            </option>
                        @endforeach
                    </select>
                </fieldset>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('From') }}</legend>
                    <input type="date" name="from" value="{{ request('from') }}" class="input input-bordered input-sm">
                </fieldset>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('To') }}</legend>
                    <input type="date" name="to" value="{{ request('to') }}" class="input input-bordered input-sm">
                </fieldset>
                <button class="btn btn-sm btn-primary">{{ __('Find') }}</button>
                @if (request()->hasAny(['promotion', 'from', 'to']))
                    <a href="{{ route('admin.promotions.usage') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            {{-- The headline is of what is being LOOKED AT, not of everything:
                 a filter that does not move the number is a filter nobody
                 trusts to answer "what did Black Friday cost us". --}}
            <div class="flex flex-wrap gap-6 border-y border-base-200 py-3 my-2">
                <div>
                    <div class="text-xs opacity-50">{{ __('Given away') }}</div>
                    <div class="text-xl font-semibold">{{ admin_price($given) }}</div>
                </div>
                <div>
                    <div class="text-xs opacity-50">{{ __('Orders') }}</div>
                    <div class="text-xl font-semibold">{{ $orders }}</div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('Order') }}</th>
                            <th>{{ __('Promotion') }}</th>
                            <th class="text-right">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td class="text-xs opacity-60 whitespace-nowrap">
                                    {{ \Illuminate\Support\Carbon::parse($row->created_at)->format('Y-m-d H:i') }}
                                </td>
                                <td class="text-xs font-mono">
                                    {{-- The order route binds on order_number, not the id. --}}
                                    <a href="{{ route('admin.orders.show', $row->order_number) }}" class="link">{{ $row->order_number }}</a>
                                </td>
                                {{-- The name AS IT WAS when the order took it: a
                                     renamed promotion does not rewrite March. --}}
                                <td class="text-sm">{{ $row->name }}</td>
                                <td class="text-right whitespace-nowrap">
                                    @if ((int) $row->amount === 0)
                                        <span class="opacity-50">{{ __('no money') }}</span>
                                    @else
                                        {{ admin_price(-(int) $row->amount, $row->currency_code) }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center opacity-50 py-6">
                                    {{ __('No promotion has been applied to an order yet.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $rows->links() }}
        </div>
    </div>
@endsection
