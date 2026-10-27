@extends('admin.layout')

@section('title', $privacyRequest->typeLabel().' · '.($privacyRequest->name ?: '#'.$privacyRequest->id))

@section('content')
    <div class="flex flex-col gap-4">
        @include('torgash.privacy::admin._tabs')

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if (session('warning'))
            <div class="alert alert-warning text-sm">{{ session('warning') }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Whose data') }}</h3>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            <dt class="opacity-60">{{ __('Name') }}</dt><dd>{{ $privacyRequest->name ?: '—' }}</dd>
                            <dt class="opacity-60">{{ __('Account') }}</dt>
                            <dd>
                                @if ($privacyRequest->user)
                                    <a href="{{ url('/admin/customers/'.$privacyRequest->user_id) }}" class="link">{{ $privacyRequest->user->name }}</a>
                                    @if ($isStaff) <span class="badge badge-warning badge-sm ml-1">{{ __('staff') }}</span> @endif
                                @else
                                    {{ $privacyRequest->user_id ? __('deleted') : __('none — a guest') }}
                                @endif
                            </dd>
                            <dt class="opacity-60">{{ __('Email') }}</dt><dd>{{ $privacyRequest->email ?: '—' }}</dd>
                            <dt class="opacity-60">{{ __('Phone') }}</dt><dd>{{ $privacyRequest->phone ?: '—' }}</dd>
                        </dl>
                        @if ($privacyRequest->note)
                            <div class="text-sm whitespace-pre-line rounded-box bg-base-200/60 p-3">{{ $privacyRequest->note }}</div>
                        @endif
                    </div>
                </section>

                @if ($privacyRequest->report)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('What was done') }}</h3>
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($privacyRequest->report as $line)
                                        <tr>
                                            <td>{{ $line['label'] }}</td>
                                            <td class="text-end tabular-nums">{{ $line['count'] }}</td>
                                            <td class="text-xs opacity-70">{{ $line['keep'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="text-xs opacity-70">{{ __('Orders also live in 1C or your accounting program, if the shop sends them there. The engine cannot reach it — check it by hand.') }}</p>
                        </div>
                    </section>
                @else
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('What was found') }}</h3>
                            @if ($found->isEmpty())
                                <p class="text-sm opacity-70">{{ __('Nothing — no rows under this account, email or phone.') }}</p>
                            @else
                                <table class="table table-sm">
                                    <tbody>
                                        @foreach ($found as $foundLabel => $foundCount)
                                            <tr><td>{{ $foundLabel }}</td><td class="text-end tabular-nums">{{ $foundCount }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </section>
                @endif
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold">{{ $privacyRequest->typeLabel() }}</h3>
                            <span @class(['badge badge-sm', 'badge-warning' => $privacyRequest->status === 'new', 'badge-success' => $privacyRequest->status === 'done', 'badge-ghost' => $privacyRequest->status === 'rejected'])>{{ $privacyRequest->statusLabel() }}</span>
                        </div>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            <dt class="opacity-60">{{ __('Received') }}</dt><dd>{{ $privacyRequest->created_at->translatedFormat('j F Y, H:i') }}</dd>
                            <dt class="opacity-60">{{ __('Answer by') }}</dt><dd @class(['text-error font-medium' => $privacyRequest->isOverdue()])>{{ $privacyRequest->due_at?->translatedFormat('j F Y') }}</dd>
                            @if ($privacyRequest->done_at)
                                <dt class="opacity-60">{{ __('Done') }}</dt><dd>{{ $privacyRequest->done_at->translatedFormat('j F Y, H:i') }} · {{ $privacyRequest->doneBy?->name }}</dd>
                            @endif
                        </dl>

                        <a href="{{ route('admin.privacy.requests.download', $privacyRequest) }}" class="btn btn-sm gap-1.5">
                            <x-icon name="download" size="sm" /> {{ __('Download the data') }}
                        </a>

                        @if ($privacyRequest->status === 'new')
                            @if ($privacyRequest->type === 'erase')
                                <form method="POST" action="{{ route('admin.privacy.requests.erase', $privacyRequest) }}"
                                      data-confirm="{{ __('Erase this person\'s data? It cannot be undone.') }}">
                                    @csrf
                                    <button class="btn btn-error btn-sm w-full gap-1.5" @disabled($isStaff)><x-icon name="eraser" size="sm" /> {{ __('Erase') }}</button>
                                </form>
                                <p class="text-xs opacity-70">{{ __('The account, addresses, wishlist and enquiries go; reviews and comments lose the name; orders stay for the accounts, without IP address and browser.') }}</p>
                            @endif
                            <form method="POST" action="{{ route('admin.privacy.requests.reject', $privacyRequest) }}" class="flex flex-col gap-2 pt-2 border-t border-base-300">
                                @csrf
                                <textarea name="reason" rows="2" class="textarea textarea-bordered textarea-sm w-full" placeholder="{{ __('Why — an unpaid order, an open return…') }}"></textarea>
                                <button class="btn btn-ghost btn-sm">{{ __('Refuse') }}</button>
                            </form>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
