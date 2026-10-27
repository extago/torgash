@extends('admin.layout')

@section('title', __('Orders'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm flex-col items-start gap-1">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @php
        use Torgash\Checkout\Orders\PaymentState;

        $activeStatuses = (array) $filter->get('status');
        // A status tab keeps every other filter: "Paid" plus a surname is a question.
        $statusUrl = function (?string $code) use ($filter, $trashed) {
            $query = collect($filter->values)->except('status')->filter(fn ($v) => $v !== '' && $v !== [])->all();

            $query += $trashed ? ['trashed' => 1] : [];

            return route('admin.orders.index', $code ? $query + ['status' => [$code]] : $query);
        };
        $moreActive = collect($filter->values)->except(['q', 'status', 'manager', 'from', 'to'])->contains(fn ($v) => $v !== '' && $v !== []);
    @endphp

    {{-- One Alpine root: the bulk bar and every row's checkbox share `selected`. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [], action: '' }">
        <div class="card-body gap-4">
            {{-- Statuses as tabs, counted under the other filters. --}}
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex flex-wrap gap-1.5 grow">
                    <a href="{{ $statusUrl(null) }}" @class(['btn btn-sm', 'btn-active' => $activeStatuses === []])>
                        {{ __('All') }} <span class="opacity-50">{{ $statusCounts->sum() }}</span>
                    </a>
                    @foreach ($statuses as $status)
                        <a href="{{ $statusUrl($status->code) }}"
                           @class(['btn btn-sm gap-1.5', 'btn-active' => $activeStatuses === [$status->code]])>
                            <span class="h-2 w-2 rounded-full inline-block" style="background-color: {{ $status->color ?? '#999' }}"></span>
                            {{ $status->t('name') }} <span class="opacity-50">{{ $statusCounts[$status->code] ?? 0 }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="flex items-center gap-1">
                    @if ($trashed)
                        <a href="{{ route('admin.orders.index') }}" class="btn btn-ghost btn-sm gap-1.5">
                            <x-icon name="arrow-left" size="sm" /> {{ __('Back to orders') }}
                        </a>
                    @elseif ($trashedCount)
                        <a href="{{ route('admin.orders.index', ['trashed' => 1]) }}" class="btn btn-ghost btn-sm gap-1.5">
                            <x-icon name="trash-2" size="sm" /> {{ __('Trash') }} <span class="badge badge-sm">{{ $trashedCount }}</span>
                        </a>
                    @endif
                    <a href="{{ route('admin.orders.statuses.index') }}" class="btn btn-ghost btn-sm gap-1.5">
                        <x-icon name="settings-2" size="sm" /> {{ __('Manage statuses') }}
                    </a>
                </div>
            </div>

            @if ($trashed)
                <div class="alert alert-warning text-sm">
                    <x-icon name="trash-2" />
                    <span>{{ __('The trash. These orders are out of every list, report and export; their goods are back on the shelf. Restore one to put it back as it was, or delete it for good.') }}</span>
                </div>
            @endif

            <form method="GET" class="flex flex-col gap-3" x-data="{ more: {{ $moreActive ? 'true' : 'false' }} }">
                @if ($trashed)
                    <input type="hidden" name="trashed" value="1">
                @endif
                @foreach ($activeStatuses as $code)
                    <input type="hidden" name="status[]" value="{{ $code }}">
                @endforeach
                {{-- Arrived from a customer's card: kept through every search,
                     named, and one click away from gone. --}}
                @if ($filter->get('customer') !== '')
                    @php $filterCustomer = \App\Models\User::find((int) $filter->get('customer')); @endphp
                    <input type="hidden" name="customer" value="{{ $filter->get('customer') }}">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="badge badge-primary gap-1.5 py-3">
                            <x-icon name="user" size="sm" />
                            {{ __('Customer: :name', ['name' => $filterCustomer?->name ?? '#'.$filter->get('customer')]) }}
                            <a href="{{ request()->fullUrlWithQuery(['customer' => null]) }}" class="opacity-70 hover:opacity-100" aria-label="{{ __('Remove') }}"><x-icon name="x" size="sm" /></a>
                        </span>
                        @if ($filterCustomer && auth()->user()?->can('customers.manage'))
                            <a href="{{ route('admin.customers.edit', $filterCustomer) }}" class="link link-hover text-xs opacity-70">{{ __('Open the customer') }}</a>
                        @endif
                    </div>
                @endif
                <div class="flex flex-wrap items-center gap-2">
                    <label class="input input-sm w-full sm:w-80 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $filter->get('q') }}" class="grow"
                               placeholder="{{ __('Order number, name, email or phone') }}">
                    </label>
                    <select name="manager" class="select select-bordered select-sm w-auto" aria-label="{{ __('Manager') }}">
                        <option value="">{{ __('Any manager') }}</option>
                        <option value="none" @selected($filter->get('manager') === 'none')>{{ __('Not assigned') }}</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}" @selected((string) $filter->get('manager') === (string) $manager->id)>{{ $manager->name }}</option>
                        @endforeach
                    </select>
                    <div class="flex items-center gap-1">
                        <input type="date" name="from" value="{{ $filter->get('from') }}" class="input input-bordered input-sm w-36" aria-label="{{ __('Placed from') }}">
                        <span class="opacity-40">–</span>
                        <input type="date" name="to" value="{{ $filter->get('to') }}" class="input input-bordered input-sm w-36" aria-label="{{ __('Placed until') }}">
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm gap-1.5" @click="more = ! more">
                        <x-icon name="sliders-horizontal" size="sm" /> {{ __('More filters') }}
                        @if ($moreActive)<span class="badge badge-primary badge-xs">•</span>@endif
                    </button>
                    <button class="btn btn-primary btn-sm">{{ __('Find') }}</button>
                    @if ($filter->active())
                        <a href="{{ route('admin.orders.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endif
                </div>

                <div x-show="more" x-cloak class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 rounded-box bg-base-200/60 p-4">
                    {{-- "What is unpaid" is the reason the state is its own
                         column and not a hand-made order status. --}}
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Payment state') }}</legend>
                        <div class="flex flex-col gap-1">
                            @foreach (PaymentState::all() as $state)
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <input type="checkbox" name="payment_state[]" value="{{ $state }}" class="checkbox checkbox-xs"
                                           @checked(in_array($state, (array) $filter->get('payment_state'), true))>
                                    {{ PaymentState::label($state) }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="flex flex-col gap-3">
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Payment') }}</legend>
                            <select name="payment" class="select select-bordered select-sm w-full">
                                <option value="">{{ __('Any') }}</option>
                                @foreach ($paymentNames as $name)
                                    <option value="{{ $name }}" @selected($filter->get('payment') === $name)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Delivery') }}</legend>
                            <select name="shipping" class="select select-bordered select-sm w-full">
                                <option value="">{{ __('Any') }}</option>
                                @foreach ($shippingNames as $name)
                                    <option value="{{ $name }}" @selected($filter->get('shipping') === $name)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                    </div>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Total') }}</legend>
                        <div class="flex items-center gap-1">
                            <input type="text" name="total_from" value="{{ $filter->get('total_from') }}" class="input input-bordered input-sm w-full" placeholder="0">
                            <span class="opacity-40">–</span>
                            <input type="text" name="total_to" value="{{ $filter->get('total_to') }}" class="input input-bordered input-sm w-full" placeholder="∞">
                        </div>
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Contains product #') }}</legend>
                        <input type="text" name="product_id" value="{{ $filter->get('product_id') }}"
                               class="input input-bordered input-sm w-full" placeholder="{{ __('id') }}">
                    </fieldset>
                </div>
            </form>

            {{-- The bulk bar: every action goes through the code a single order's
                 page uses, one order at a time (OrdersController::bulk). --}}
            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ $trashed
                        ? __('Tick orders to restore several at once or delete them for good.')
                        : __('Tick orders to change the status of several at once, give them to a manager, mark them paid, or move them to the trash.') }}
                </p>
                @if ($trashed)
                <form method="POST" action="{{ route('admin.orders.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <button name="action" value="restore" class="btn btn-sm gap-1.5">
                        <x-icon name="rotate-ccw" size="sm" /> {{ __('Restore') }}
                    </button>
                    <button name="action" value="purge" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected orders for good? This cannot be undone.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete for good') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
                @else
                <form method="POST" action="{{ route('admin.orders.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>

                    <div class="join">
                        <select name="status" class="select select-bordered select-sm join-item w-48" x-on:change="action = 'status'" aria-label="{{ __('New status') }}">
                            <option value="">{{ __('Status…') }}</option>
                            @foreach ($statuses as $status)
                                @continue($status->code === \Torgash\Checkout\Models\OrderStatus::MERGED)
                                <option value="{{ $status->code }}">{{ $status->t('name') }}</option>
                            @endforeach
                        </select>
                        <button name="action" value="status" class="btn btn-sm join-item"
                                x-data x-on:click="if (!($el.form.status.value !== '' && confirm(@js(__('Change the status of the selected orders? Stock moves and customers are emailed as if each were changed by hand.'))))) $event.preventDefault()">
                            {{ __('Set') }}
                        </button>
                    </div>

                    <div class="join">
                        <select name="manager_id" class="select select-bordered select-sm join-item w-44" aria-label="{{ __('Manager') }}">
                            <option value="">{{ __('Not assigned') }}</option>
                            @foreach ($managers as $manager)
                                <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                            @endforeach
                        </select>
                        <button name="action" value="manager" class="btn btn-sm join-item">{{ __('Assign') }}</button>
                    </div>

                    <button name="action" value="paid" class="btn btn-sm gap-1.5"
                            data-confirm="{{ __('Mark the selected orders as paid in full?') }}">
                        <x-icon name="banknote" size="sm" /> {{ __('Mark paid') }}
                    </button>

                    <button name="action" value="trash" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Move the selected orders to the trash? Their goods go back on the shelf. Orders with money received stay.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('To the trash') }}
                    </button>

                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
                @endif
            </div>

            @if ($orders->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="shopping-bag" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filter->active() ? __('No orders match these filters.') : __('No orders yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($orders->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $orders->count() }}">
                                </th>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Customer') }}</th>
                                <th class="text-end">{{ __('Total') }}</th>
                                <th>{{ __('Payment') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Manager') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $order)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $order->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $order->id }}" x-model="selected"
                                               aria-label="{{ $order->order_number }}">
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <a href="{{ route('admin.orders.show', $order) }}" class="font-mono font-medium link link-hover">{{ $order->order_number }}</a>
                                        <div class="text-xs opacity-50" title="{{ $order->created_at->format('Y-m-d H:i') }}">{{ $order->created_at->format('d.m.Y H:i') }}</div>
                                    </td>
                                    <td>
                                        <div class="font-medium">{{ $order->company_name ?: $order->customerLabel() }}</div>
                                        <div class="text-xs opacity-60">
                                            @if ($order->company_name){{ $order->customerLabel() }} · @endif{{ collect([$order->phone, $order->email])->filter()->implode(' · ') }}
                                        </div>
                                    </td>
                                    <td class="text-end font-semibold tabular-nums whitespace-nowrap">{{ $order->adminMoney($order->total) }}</td>
                                    <td>
                                        <span class="badge badge-sm whitespace-nowrap {{ PaymentState::badge($order->payment_state) }}">{{ PaymentState::label($order->payment_state) }}</span>
                                        @if ($order->payment_method_name)
                                            <div class="text-xs opacity-50 mt-0.5 truncate max-w-40">{{ $order->paymentMethodLabel() }}</div>
                                        @endif
                                    </td>
                                    @if ($trashed)
                                        @php $rowStatus = $statuses->get($order->status); @endphp
                                        <td>
                                            <span class="inline-flex items-center gap-1.5 text-xs">
                                                <span class="h-2 w-2 rounded-full inline-block" style="background-color: {{ $rowStatus?->color ?? '#999' }}"></span>
                                                {{ $rowStatus?->t('name') ?? $order->status }}
                                            </span>
                                        </td>
                                    @else
                                        @include('torgash.checkout::admin.orders._status_cell', ['order' => $order, 'statuses' => $statuses])
                                    @endif
                                    <td class="text-sm">
                                        @if ($order->manager)
                                            {{ $order->manager->name }}
                                        @else
                                            <span class="opacity-40">{{ __('Not assigned') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        @if ($trashed)
                                        <div class="inline-flex items-center gap-1">
                                            <button form="restore-order-{{ $order->id }}" class="btn btn-ghost btn-sm btn-square"
                                                    title="{{ __('Restore') }}" aria-label="{{ __('Restore') }}">
                                                <x-icon name="rotate-ccw" />
                                            </button>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <button form="purge-order-{{ $order->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                                                    title="{{ __('Delete for good') }}" aria-label="{{ __('Delete for good') }}">
                                                <x-icon name="trash-2" />
                                            </button>
                                        </div>
                                        @else
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.orders.invoice', $order) }}" target="_blank" rel="noopener"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Invoice') }}" aria-label="{{ __('Invoice') }}">
                                                <x-icon name="printer" />
                                            </a>
                                            <a href="{{ route('admin.orders.show', $order) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Open the order') }}" aria-label="{{ __('Open the order') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                        </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $orders->links() }}
            @endif
        </div>
    </div>

    {{-- Beside the table, never inside a row: a nested form's _method leaks. --}}
    @if ($trashed)
        @foreach ($orders as $order)
            <form id="restore-order-{{ $order->id }}" method="POST" class="hidden" action="{{ route('admin.orders.restore', $order->order_number) }}">@csrf</form>
            <form id="purge-order-{{ $order->id }}" method="POST" class="hidden" action="{{ route('admin.orders.purge', $order->order_number) }}"
                  data-confirm="{{ __('Delete this order for good? This cannot be undone.') }}">
                @csrf @method('DELETE')
            </form>
        @endforeach
    @endif
@endsection
