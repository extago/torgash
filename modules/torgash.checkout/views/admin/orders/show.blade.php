@extends('admin.layout')

@section('title', __('Order').' '.$order->order_number)
@section('own_heading', '1')

@section('content')
    @php
        use Torgash\Checkout\Orders\PaymentState;

        $currentStatus = $statuses->firstWhere('code', $order->status);
        $addressLine = collect([
            trim((string) $order->address_line),
            trim(implode(' ', array_filter([$order->city, $order->postal_code]))),
            $order->countryName(),
        ])->filter()->implode(', ');
    @endphp

    {{-- The order's own bar: what it is, where it stands, and what to do with it. --}}
    <x-admin.form-bar>
        <a href="{{ route('admin.orders.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to orders') }}" aria-label="{{ __('Back to orders') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Order') }} · {{ $order->created_at->format('d.m.Y H:i') }}</div>
            <div class="font-semibold truncate leading-tight flex items-center gap-2">
                <span class="font-mono">{{ $order->order_number }}</span>
                <span class="inline-flex items-center gap-1.5 text-xs font-normal opacity-80">
                    <span class="h-2 w-2 rounded-full inline-block" style="background-color: {{ $currentStatus?->color ?? '#999' }}"></span>
                    {{ $currentStatus?->t('name') ?? $order->status }}
                </span>
                <span class="badge badge-sm max-sm:hidden {{ PaymentState::badge($order->payment_state) }}">{{ PaymentState::label($order->payment_state) }}</span>
            </div>
        </div>

        <span class="hidden md:inline text-lg font-semibold tabular-nums">{{ $order->adminMoney($order->total) }}</span>

        <a href="{{ route('admin.orders.invoice', $order) }}" target="_blank" rel="noopener" class="btn btn-sm gap-1.5 max-md:btn-square"
           title="{{ __('Invoice') }}" aria-label="{{ __('Invoice') }}">
            <x-icon name="printer" size="sm" /> <span class="max-md:hidden">{{ __('Invoice') }}</span>
        </a>

        {{-- Delete submits the standalone form below through the HTML "form"
             attribute — the page holds several forms of its own. --}}
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                <x-icon name="settings" size="lg" />
            </div>
            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                <li>
                    <a href="{{ $order->customerUrl() }}" target="_blank" rel="noopener">
                        <x-icon name="external-link" /> {{ __('The customer’s page of this order') }}
                    </a>
                </li>
                @unless ($order->merged_into_id)
                    <li>
                        {{-- The customer ordered twice and wants one parcel
                             (PLAN §40.10/4). The window is filled when opened. --}}
                        <button type="button"
                                hx-get="{{ route('admin.orders.merge.preview', $order) }}" hx-target="#order-merge-slot" hx-swap="innerHTML"
                                hx-on::after-request="document.getElementById('modal-order-merge').showModal()">
                            <x-icon name="merge" /> {{ __('Merge another order into this one…') }}
                        </button>
                    </li>
                @endunless
                <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                <li>
                    @if ((int) $order->paid_total > 0)
                        <span class="opacity-50 pointer-events-none whitespace-normal" title="{{ __('Money has been received for order :number — refund it or correct the payment first.', ['number' => $order->order_number]) }}">
                            <x-icon name="trash-2" /> {{ __('To the trash') }}
                        </span>
                    @else
                        <button type="submit" form="order-trash" class="text-error"
                                data-confirm="{{ __('Move this order to the trash? Its goods go back on the shelf; it can be restored from the trash.') }}">
                            <x-icon name="trash-2" /> {{ __('To the trash') }}
                        </button>
                    @endif
                </li>
            </ul>
        </div>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <x-modal name="order-merge" title="{{ __('Merge another order into this one') }}" icon="merge">
        <div id="order-merge-slot"></div>
    </x-modal>

    {{-- A merged order is a shell: where its goods went, and nothing to do here. --}}
    @if ($order->merged_into_id && ($root = \Torgash\Checkout\Models\Order::withTrashed()->find($order->merged_into_id)))
        <div class="alert alert-info text-sm">
            <x-icon name="merge" />
            <span>{!! __('This order was merged into :link — its goods and money are there now.', [
                'link' => '<a class="link font-mono" href="'.e(route('admin.orders.show', $root)).'">'.e($root->order_number).'</a>',
            ]) !!}</span>
        </div>
    @endif

    {{-- What was poured into this one, and the way back while there still is one. --}}
    @foreach (\Torgash\Checkout\Models\OrderMerge::where('into_id', $order->id)->whereNull('undone_at')->with('from')->orderBy('id')->get() as $merge)
        @php $undoRefusals = app(\Torgash\Checkout\Orders\MergeOrders::class)->undoRefusals($merge); @endphp
        <div class="alert text-sm flex flex-wrap items-center gap-2">
            <x-icon name="merge" />
            <span class="flex-1">{!! __('Order :link was merged into this one :when by :who.', [
                'link' => '<a class="link font-mono" href="'.e(route('admin.orders.show', $merge->from)).'">'.e($merge->from?->order_number).'</a>',
                'when' => e($merge->created_at->format('d.m.Y H:i')),
                'who' => e($merge->user_name ?? '—'),
            ]) !!}</span>
            @if ($undoRefusals)
                <span class="opacity-60" title="{{ implode(' ', $undoRefusals) }}">{{ __('Can no longer be undone') }}</span>
            @else
                <form method="POST" action="{{ route('admin.orders.merge.undo', [$order, $merge]) }}"
                      data-confirm="{{ __('Split order :number off again, exactly as it was?', ['number' => $merge->from?->order_number]) }}">
                    @csrf
                    <button class="btn btn-sm gap-1.5"><x-icon name="undo-2" size="sm" /> {{ __('Undo the merge') }}</button>
                </form>
            @endif
        </div>
    @endforeach

    {{-- Below the wide screen the two columns dissolve into one (their cards
         become the grid's own) and stand in the order of a manager's day:
         what to do with it, whether it is paid, what is in it, who to ring,
         how it travels, the modules' cards, what has happened. --}}
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        {{-- Left, the wide column: what was bought, how it travels and is paid
             (the delivery editor a module draws there needs the width), and
             what has happened to the order since. --}}
        <div class="flex flex-col gap-4 min-w-0 max-xl:contents">
            <section class="card bg-base-100 border border-base-300 min-w-0 max-xl:order-3">
                <div class="card-body">
                    @include('torgash.checkout::admin.orders._editor', ['order' => $order, 'editing' => $editing])
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300 min-w-0 max-xl:order-5">
                <div class="card-body gap-2">
                    <h3 class="font-semibold">{{ __('Delivery') }}</h3>
                    <div class="text-sm">{{ $order->shippingMethodLabel() ?? '—' }}</div>
                    {{-- Extension point for whoever owns delivery: the shipping
                         module puts the method/cost editor here, and the "this
                         order still needs a delivery price" box when it applies.
                         Checkout knows the order carries a shipping_details blob,
                         not what is in it. --}}
                    {!! hook_filter('checkout.order.shipping_extra', '', ['order' => $order]) !!}
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300 min-w-0 max-xl:order-2">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Payment') }}</h3>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Method') }}</legend>
                        @include('torgash.checkout::admin.orders._payment', ['order' => $order, 'paymentMethods' => $paymentMethods])
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Payment state') }}</legend>
                        @include('torgash.checkout::admin.orders._payment_state', ['order' => $order])
                    </fieldset>
                    {{-- Extension point for a payment gateway: its own buttons
                         (re-check, capture a hold, refund) belong beside the state
                         they act on, and checkout must not know any of them. --}}
                    {!! hook_filter('checkout.order.payment_extra', '', ['order' => $order]) !!}
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300 min-w-0 max-xl:order-7">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Activity') }}</h3>
                    @include('torgash.checkout::admin.orders._activity', ['order' => $order])
                </div>
            </section>
        </div>

        {{-- Right: the work on it and who bought it. --}}
        <div class="flex flex-col gap-4 max-xl:contents">
            <section class="card bg-base-100 border border-base-300 min-w-0 max-xl:order-1">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Work on the order') }}</h3>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Status') }}</legend>
                        @include('torgash.checkout::admin.orders._status', ['order' => $order, 'statuses' => $statuses])
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Manager') }}</legend>
                        @include('torgash.checkout::admin.orders._manager', ['order' => $order, 'managers' => $managers])
                    </fieldset>
                    {{-- On a phone the customer's card is further down: the
                         two things a manager does with a phone, here. --}}
                    @if ($order->phone || $order->email)
                        <div class="flex gap-2 md:hidden">
                            @if ($order->phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $order->phone) }}" class="btn btn-sm flex-1 gap-1.5"><x-icon name="phone" size="sm" /> {{ __('Call') }}</a>
                            @endif
                            @if ($order->email)
                                <a href="mailto:{{ $order->email }}" class="btn btn-sm flex-1 gap-1.5"><x-icon name="mail" size="sm" /> {{ __('Write') }}</a>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            {{-- A module's own card about this order — its money, its stock —
                 in the order it asks for (`position`); checkout names no module.
                 One block, so a phone can put them all in their place. --}}
            @php
                $sidePanels = collect(hook_filter('checkout.order.side_panels', [], ['order' => $order]))
                    ->filter(fn ($panel) => ($panel['html'] ?? '') !== '')->sortBy('position');
            @endphp
            @if ($sidePanels->isNotEmpty())
                <div class="flex flex-col gap-4 min-w-0 max-xl:order-6">
                    @foreach ($sidePanels as $panel)
                        {!! $panel['html'] !!}
                    @endforeach
                </div>
            @endif

            <section class="card bg-base-100 border border-base-300 min-w-0 max-xl:order-4">
                <div class="card-body gap-2">
                    <h3 class="font-semibold">{{ __('Customer') }}</h3>
                    {{-- A company's order names the company first: that is who is
                         billed; the person is who to ring. --}}
                    @if ($order->company_name)
                        <div class="font-semibold flex items-center gap-1.5"><x-icon name="building-2" size="sm" class="opacity-60" />{{ $order->company_name }}</div>
                        @if ($order->company_tax_id)
                            <div class="text-sm opacity-70">{{ __('Tax code') }}: {{ $order->company_tax_id }}</div>
                        @endif
                    @endif
                    <div class="flex items-center gap-1.5"><x-icon name="user" size="sm" class="opacity-50" />{{ $order->customerLabel() }}</div>
                    @if ($order->phone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $order->phone) }}" class="link link-hover text-sm flex items-center gap-1.5">
                            <x-icon name="phone" size="sm" class="opacity-50" />{{ $order->phone }}
                        </a>
                    @endif
                    @if ($order->email)
                        <a href="mailto:{{ $order->email }}" class="link link-hover text-sm flex items-center gap-1.5 break-all">
                            <x-icon name="mail" size="sm" class="opacity-50 shrink-0" />{{ $order->email }}
                        </a>
                    @endif
                    {{-- Joined from the parts that are actually there: an order
                         bought in one click has no address at all. --}}
                    @if ($addressLine !== '')
                        <div class="text-sm flex items-start gap-1.5"><x-icon name="map-pin" size="sm" class="opacity-50 mt-0.5 shrink-0" />{{ $addressLine }}</div>
                    @endif
                    @if ($order->ip_address)
                        <div class="text-sm flex items-center flex-wrap gap-x-3 gap-y-1">
                            <span class="flex items-center gap-1.5" title="{{ __('Placed from this address') }}">
                                <x-icon name="network" size="sm" class="opacity-50" /><span class="font-mono">{{ $order->ip_address }}</span>
                            </span>
                            @if ($sameAddressOrders)
                                <a href="{{ route('admin.orders.index', ['q' => $order->ip_address]) }}" class="link link-hover text-xs opacity-70">
                                    {{ trans_choice('{1}:n more order from here|[2,*]:n more orders from here', $sameAddressOrders, ['n' => $sameAddressOrders]) }}
                                </a>
                            @endif
                            {{-- The guard's screen, when the guard is here: checkout
                                 asks the route table, never the module's classes. --}}
                            @if (\Illuminate\Support\Facades\Route::has('admin.guard.blocks.index') && auth()->user()?->can('guard.manage'))
                                <a href="{{ route('admin.guard.blocks.index', ['block' => $order->ip_address, 'note' => __('Order :number', ['number' => $order->order_number])]) }}"
                                   class="link link-hover text-xs text-error">{{ __('Block this address') }}</a>
                            @endif
                        </div>
                    @endif
                    {{-- The rest of what was written down about the browser
                         (Orders\ClientFacts): quiet, one line each, the whole
                         value on hover — a user agent is two hundred characters. --}}
                    @foreach ($clientFacts as $fact)
                        <div class="text-xs flex gap-1.5 min-w-0" title="{{ $fact['raw'] }}">
                            <span class="opacity-50 shrink-0">{{ $fact['label'] }}:</span>
                            <span class="opacity-70 truncate">{{ $fact['value'] }}</span>
                        </div>
                    @endforeach
                    @if ($order->notes)
                        <div class="text-sm rounded-box bg-base-200 p-3 mt-1">
                            <div class="text-xs opacity-60 mb-1">{{ __('Customer’s note') }}</div>
                            {{ $order->notes }}
                        </div>
                    @endif
                </div>
            </section>

        </div>
    </div>

    <form id="order-trash" method="POST" class="hidden" action="{{ route('admin.orders.destroy', $order) }}">
        @csrf @method('DELETE')
    </form>
@endsection
