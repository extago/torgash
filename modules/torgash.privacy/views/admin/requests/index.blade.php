@extends('admin.layout')

@section('title', __('Personal data'))

@section('content')
    <div class="flex flex-col gap-4">
        @include('torgash.privacy::admin._tabs')

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div role="tablist" class="tabs tabs-border">
                        <a role="tab" href="{{ route('admin.privacy.requests.index') }}" @class(['tab', 'tab-active' => $view === 'open'])>{{ __('Waiting') }}</a>
                        <a role="tab" href="{{ route('admin.privacy.requests.index', ['view' => 'all']) }}" @class(['tab', 'tab-active' => $view === 'all'])>{{ __('All') }}</a>
                    </div>
                    <a href="{{ route('admin.privacy.requests.create') }}" class="btn btn-primary btn-sm gap-1.5"><x-icon name="plus" size="sm" /> {{ __('New request') }}</a>
                </div>

                <p class="text-sm opacity-70 max-w-3xl">{{ __('A customer asks in their account; a person who wrote or called is entered here by email or phone. Download answers "what do you keep about me"; erase removes what the law does not ask to keep — orders stay, without the IP address and browser.') }}</p>

                @if ($requests->isEmpty())
                    <div class="text-center py-12 opacity-60">{{ $view === 'open' ? __('No requests waiting.') : __('No requests yet.') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Who') }}</th>
                                    <th>{{ __('What') }}</th>
                                    <th>{{ __('Received') }}</th>
                                    <th>{{ __('Answer by') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($requests as $listRequest)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.privacy.requests.show', $listRequest) }}" class="font-medium link link-hover">{{ $listRequest->name ?: ($listRequest->email ?: $listRequest->phone ?: '#'.$listRequest->id) }}</a>
                                            <div class="text-xs opacity-60">{{ collect([$listRequest->email, $listRequest->phone])->filter()->implode(' · ') }}</div>
                                        </td>
                                        <td class="text-sm">{{ $listRequest->typeLabel() }}</td>
                                        <td class="text-sm whitespace-nowrap">{{ $listRequest->created_at->translatedFormat('j M Y') }}</td>
                                        <td @class(['text-sm whitespace-nowrap', 'text-error font-medium' => $listRequest->isOverdue()])>{{ $listRequest->due_at?->translatedFormat('j M Y') }}</td>
                                        <td>
                                            <span @class(['badge badge-sm', 'badge-warning' => $listRequest->status === 'new', 'badge-success' => $listRequest->status === 'done', 'badge-ghost' => $listRequest->status === 'rejected'])>{{ $listRequest->statusLabel() }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $requests->links() }}
                @endif
            </div>
        </div>
    </div>
@endsection
