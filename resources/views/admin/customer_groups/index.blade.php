@extends('admin.layout')

@section('title', __('Customers'))

@section('content')
    @php $contentLocale = admin_content_locale(); @endphp

    @include('admin.customers._nav', ['customerScreen' => 'groups'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('A group is a way to give some customers other prices: a discount off every price, or — in the catalogue — a price type of its own. Rules elsewhere (promotions, delivery, display rules) can also ask which group a customer is in.') }}
                </p>
                <a href="{{ route('admin.customer-groups.create') }}" class="btn btn-primary btn-sm">+ {{ __('New group') }}</a>
            </div>

            @if ($groups->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="tag" size="xl" class="opacity-40" />
                    <p class="mt-2 max-w-xl mx-auto">{{ __('No customer groups yet. Every customer sees list prices until there is one — "Wholesale" or "Club" is the usual first.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th class="text-end">{{ __('Discount') }}</th>
                                <th class="text-end">{{ __('Customers') }}</th>
                                <th>{{ __('New registrations') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $group)
                                <tr class="hover:bg-base-200/50">
                                    <td>
                                        <a href="{{ route('admin.customer-groups.edit', $group) }}" class="font-medium link link-hover">{{ $group->t('name', $contentLocale) ?: '#'.$group->id }}</a>
                                    </td>
                                    <td class="text-end tabular-nums">
                                        @if ($group->discount_percent > 0)
                                            <span class="font-semibold">−{{ $group->discount_percent }}%</span>
                                        @else
                                            <span class="opacity-40">0%</span>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">
                                        @if ($group->users_count > 0)
                                            <a href="{{ route('admin.customers.index', ['group' => $group->id]) }}" class="link link-hover" title="{{ __('Show them') }}">{{ $group->users_count }}</a>
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- One group at most; pressing the chosen one again means "none". --}}
                                        <form method="POST" action="{{ route('admin.customer-groups.default', $group) }}">
                                            @csrf
                                            @if ($group->is_default)
                                                <button class="badge badge-sm badge-success gap-1 cursor-pointer" title="{{ __('Stop adding new registrations to this group') }}">
                                                    <x-icon name="check" size="sm" /> {{ __('Join automatically') }}
                                                </button>
                                            @else
                                                <button class="badge badge-sm badge-ghost cursor-pointer opacity-60 hover:opacity-100" title="{{ __('Add every new registration to this group') }}">
                                                    {{ __('Make automatic') }}
                                                </button>
                                            @endif
                                        </form>
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.customer-groups.edit', $group) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.customer-groups.destroy', $group) }}" class="inline"
                                                  data-confirm="{{ __('Delete this group? Its customers will see list prices.') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
