@extends('admin.layout')

@section('title', __('Enquiries'))
@section('title_hint', __('Everything visitors ask in one queue: callbacks, questions about products and orders, returns. "Awaiting a reply" is what the shop owes an answer to — nothing said yet, or the customer spoke last.'))

@php
    use Carbon\CarbonInterface;
    use Torgash\Enquiries\Kinds;
    use Torgash\Enquiries\Status;

    $tabs = [
        'awaiting' => __('Awaiting a reply'),
        'open' => __('Still open'),
        Status::NEW => Status::label(Status::NEW),
        Status::IN_PROGRESS => Status::label(Status::IN_PROGRESS),
        Status::DONE => Status::label(Status::DONE),
        Status::REJECTED => Status::label(Status::REJECTED),
        'all' => __('All'),
    ];
@endphp

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The views as one row of tabs over the card (app.css, `admin-tabs`):
         LINKS carrying every other filter. "Awaiting a reply" owed is red. --}}
    <nav class="admin-tabs" aria-label="{{ __('Enquiries') }}">
        @foreach ($tabs as $key => $label)
            <a href="{{ request()->fullUrlWithQuery(['view' => $key, 'page' => null, 'status' => null, 'status_set' => null]) }}"
               @class(['admin-tab', 'is-active' => $filters['view'] === $key])>
                @if ($key === 'awaiting')
                    <x-icon name="clock" size="sm" class="opacity-60" />
                @endif
                {{ $label }}
                <span @class(['admin-tab-count', 'is-error' => $key === 'awaiting' && $counts[$key] > 0])>{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <label class="input input-sm grow min-w-48 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $filters['q'] }}" class="grow"
                               placeholder="{{ __('Name, phone, email, text or #number…') }}">
                    </label>
                    <select name="kind" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Kind') }}">
                        <option value="">{{ __('Any kind') }}</option>
                        @foreach ($kinds as $key => $kind)
                            <option value="{{ $key }}" @selected($filters['kind'] === $key)>{{ Kinds::label($key) }}</option>
                        @endforeach
                    </select>
                    <select name="manager" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Manager') }}">
                        <option value="">{{ __('Any manager') }}</option>
                        <option value="me" @selected($filters['manager'] === 'me')>{{ __('Mine') }}</option>
                        <option value="none" @selected($filters['manager'] === 'none')>{{ __('Nobody yet') }}</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}" @selected($filters['manager'] === (string) $manager->id)>{{ $manager->name }}</option>
                        @endforeach
                    </select>
                    @unless ($isDefault)
                        <a href="{{ route('admin.enquiries.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endunless
                </div>

                {{-- Arrived from a customer's card: kept through every search,
                     named, and one click away from gone. --}}
                @if ($filters['customer'] !== '')
                    <input type="hidden" name="customer" value="{{ $filters['customer'] }}">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="badge badge-primary gap-1.5 py-3">
                            <x-icon name="user" size="sm" />
                            {{ __('Customer: :name', ['name' => $filterCustomer?->name ?? '#'.$filters['customer']]) }}
                            <a href="{{ request()->fullUrlWithQuery(['customer' => null]) }}" class="opacity-70 hover:opacity-100" aria-label="{{ __('Remove') }}"><x-icon name="x" size="sm" /></a>
                        </span>
                        @if ($filterCustomer && auth()->user()?->can('customers.manage'))
                            <a href="{{ route('admin.customers.edit', $filterCustomer) }}" class="link link-hover text-xs opacity-70">{{ __('Open the customer') }}</a>
                        @endif
                    </div>
                @endif

                {{-- The tab is part of what the form asks; the tabs themselves
                     are LINKS carrying every other filter, so the state has one
                     control in the form and not two. --}}
                <input type="hidden" name="view" value="{{ $filters['view'] }}">
            </form>

            <div class="min-h-12 max-md:min-h-0 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick enquiries to change their state, give them to a manager, close or delete several at once.') }}
                </p>
                <form method="POST" action="{{ route('admin.enquiries.bulk') }}"
                      x-show="selected.length" x-cloak x-data="{ assign: 'me' }"
                      class="admin-bulk-bar w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        @foreach (Status::options() as $value => $label)
                            <button name="action" value="{{ $value }}" class="btn btn-sm join-item">{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="join">
                        <select name="manager_id" class="select select-sm join-item w-44" x-model="assign" aria-label="{{ __('Manager') }}">
                            <option value="me">{{ __('To me') }}</option>
                            @foreach ($managers as $manager)
                                <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                            @endforeach
                            <option value="">{{ __('Nobody') }}</option>
                        </select>
                        <button name="action" value="assign" class="btn btn-sm join-item">{{ __('Assign') }}</button>
                    </div>
                    <button name="action" value="close" class="btn btn-sm gap-1.5">
                        <x-icon name="lock" size="sm" /> {{ __('Close') }}
                    </button>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected enquiries with their conversations?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($enquiries->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="inbox" size="xl" class="opacity-40" />
                    <p class="mt-2">
                        {{ $filters['view'] === 'awaiting' && $filters['q'] === ''
                            ? __('Nobody is waiting for an answer.')
                            : ($isDefault ? __('Nobody has asked anything yet.') : __('No enquiries match this search.')) }}
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    {{-- On a phone the same table is a list of cards (app.css,
                         `admin-enquiry-cards`; `data-cell` names the cells). --}}
                    <table class="table admin-cards admin-enquiry-cards">
                        <thead>
                            <tr>
                                <th class="w-10" data-cell="check">
                                    <label class="flex items-center gap-3">
                                        <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                               x-on:change="selected = $event.target.checked ? @js(collect($enquiries->items())->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                               :checked="selected.length === {{ $enquiries->count() }}">
                                        <span class="md:hidden font-normal normal-case">{{ __('Select all on this page') }}</span>
                                    </label>
                                </th>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('What') }}</th>
                                <th>{{ __('Waiting time') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Manager') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($enquiries as $enquiry)
                                @php
                                    $subject = $enquiry->subject();
                                    $kind = Kinds::find($enquiry->kind);
                                    $since = $enquiry->waitingSince();
                                    $overdue = $enquiry->isOverdue();
                                    $url = route('admin.enquiries.show', $enquiry);
                                @endphp
                                <tr class="hover:bg-base-200/50 align-top" :class="selected.includes('{{ $enquiry->id }}') && 'bg-primary/5'">
                                    <td data-cell="check">
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $enquiry->id }}" x-model="selected"
                                               aria-label="#{{ $enquiry->id }}">
                                    </td>
                                    <td class="min-w-48" data-cell="customer">
                                        <div class="flex items-start gap-2.5">
                                            <span class="h-8 w-8 shrink-0 rounded-full bg-base-200 flex items-center justify-center" title="{{ $enquiry->kindLabel() }}">
                                                <x-icon :name="$kind['icon'] ?? 'message-square'" size="sm" class="opacity-70" />
                                            </span>
                                            <div class="min-w-0">
                                                <a href="{{ $url }}" @class(['link link-hover', 'font-semibold' => $since, 'font-medium' => ! $since])>
                                                    {{ $enquiry->name ?: ($enquiry->phone ?: ($enquiry->email ?: __('No name'))) }}
                                                </a>
                                                @if ($enquiry->name)
                                                    <div class="text-xs opacity-60">{{ $enquiry->phone ?: $enquiry->email }}</div>
                                                @endif
                                                <div class="text-xs opacity-50">#{{ $enquiry->id }} · {{ $enquiry->kindLabel() }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="max-w-md" data-cell="what">
                                        @if ($subject)
                                            <div class="text-xs opacity-70 mb-0.5 truncate">
                                                <x-icon name="link-2" size="sm" class="opacity-50 align-text-bottom" /> {{ $subject['label'] }}
                                            </div>
                                        @endif
                                        <a href="{{ $url }}" class="text-sm line-clamp-2 hover:underline decoration-base-300">
                                            @if ($enquiry->lastMessage)
                                                <span class="opacity-50">{{ $enquiry->lastMessage->is_staff ? __('Shop') : __('Customer') }}:</span>
                                            @endif
                                            {{ $enquiry->preview() ?: '—' }}
                                        </a>
                                        @if ($enquiry->messages_count)
                                            <div class="text-xs opacity-50 mt-0.5 inline-flex items-center gap-1">
                                                <x-icon name="messages-square" size="sm" /> {{ $enquiry->messages_count }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-sm" data-cell="waiting">
                                        @if ($since)
                                            <span @class(['badge badge-sm gap-1', 'badge-error' => $overdue, 'badge-warning' => ! $overdue])
                                                  title="{{ __('Waiting since :time', ['time' => $since->format('d.m.Y H:i')]) }}">
                                                <x-icon name="clock" size="sm" />
                                                {{ $since->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE, true) }}
                                            </span>
                                        @else
                                            <span class="text-xs opacity-50" title="{{ __('Received') }}">{{ $enquiry->created_at?->format('d.m.Y H:i') }}</span>
                                        @endif
                                    </td>
                                    @include('torgash.enquiries::admin._status', ['enquiry' => $enquiry])
                                    @include('torgash.enquiries::admin._manager', ['enquiry' => $enquiry, 'managers' => $managers])
                                    <td class="text-end whitespace-nowrap" data-cell="actions">
                                        {{-- A callback is usually dealt with without opening it:
                                             ring from the row, then mark it resolved from the row.
                                             Resolving closes the conversation, and "Reopen" on the
                                             enquiry's page undoes it, so it asks nothing. --}}
                                        <div class="inline-flex items-center gap-1">
                                            @if ($enquiry->phone)
                                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $enquiry->phone) }}" class="btn btn-ghost btn-sm btn-square text-success"
                                                   title="{{ __('Call :phone', ['phone' => $enquiry->phone]) }}" aria-label="{{ __('Call :phone', ['phone' => $enquiry->phone]) }}">
                                                    <x-icon name="phone" />
                                                </a>
                                            @else
                                                {{-- Keeps every row's buttons in the same columns. --}}
                                                <span class="btn btn-ghost btn-sm btn-square invisible" aria-hidden="true"></span>
                                            @endif
                                            @unless ($enquiry->isLocked())
                                                <form method="POST" action="{{ route('admin.enquiries.close', $enquiry) }}" class="inline">
                                                    @csrf
                                                    <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Question resolved') }}" aria-label="{{ __('Question resolved') }}">
                                                        <x-icon name="circle-check" />
                                                    </button>
                                                </form>
                                            @else
                                                <span class="btn btn-ghost btn-sm btn-square invisible" aria-hidden="true"></span>
                                            @endunless
                                            <a href="{{ $url }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Open') }}" aria-label="{{ __('Open') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.enquiries.destroy', $enquiry) }}" class="inline"
                                                  data-confirm="{{ __('Delete this enquiry?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <x-icon name="trash-2" />
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $enquiries->links() }}
            @endif
        </div>
    </div>
@endsection
