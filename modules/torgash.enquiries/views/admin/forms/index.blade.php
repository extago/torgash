@extends('admin.layout')

@section('title', __('Forms'))
@section('title_hint', __('Forms with your own fields — a job application, a wholesale request, a survey. What they bring lands in Enquiries, with statuses, a manager and letters, like a callback.'))

{{-- No states to tab: the screen's actions stand level with the heading. --}}
@section('title_actions')
    @can('enquiries.manage')
        <a href="{{ route('admin.enquiries.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="inbox" size="sm" /> {{ __('Enquiries') }}</a>
    @endcan
    <a href="{{ route('admin.enquiries.forms.create') }}" class="btn btn-primary gap-1.5"><x-icon name="plus" size="sm" /> {{ __('New form') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="alert alert-warning text-sm">{{ session('warning') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            @if ($forms->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="clipboard-list" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('No forms yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <tbody>
                            @foreach ($forms as $listForm)
                                @php $listCount = (int) ($counts[$listForm->kind()] ?? 0); @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.enquiries.forms.edit', $listForm) }}" class="font-medium link link-hover">
                                            {{ $listForm->t('name', admin_content_locale()) ?: $listForm->code }}
                                        </a>
                                        @unless ($listForm->enabled)
                                            <span class="badge badge-ghost badge-sm ml-2">{{ __('Off') }}</span>
                                        @endunless
                                        <div class="text-xs opacity-60">{{ trans_choice(':count field|:count fields', count($listForm->fields ?? []), ['count' => count($listForm->fields ?? [])]) }}</div>
                                    </td>
                                    <td class="font-mono text-xs opacity-70">{form:{{ $listForm->code }}}</td>
                                    <td class="text-end text-sm">
                                        @if ($listCount && auth()->user()?->can('enquiries.manage'))
                                            <a href="{{ route('admin.enquiries.index', ['kind' => $listForm->kind()]) }}" class="link link-hover">{{ trans_choice(':count enquiry|:count enquiries', $listCount, ['count' => $listCount]) }}</a>
                                        @else
                                            <span class="opacity-60">{{ trans_choice(':count enquiry|:count enquiries', $listCount, ['count' => $listCount]) }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end w-px whitespace-nowrap">
                                        <a href="{{ route('admin.enquiries.forms.edit', $listForm) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
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
