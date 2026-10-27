{{-- The customer card's enquiries — drawn by this module, placed by core
     through `core.admin.customer.panels`. The ten latest; the inbox itself is
     one link away, narrowed to this customer. --}}
@php
    use Carbon\CarbonInterface;
    use Torgash\Enquiries\Kinds;
    use Torgash\Enquiries\Status;
@endphp
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <div class="flex items-center gap-3 flex-wrap">
            <h3 class="font-semibold grow flex items-center gap-2"><x-icon name="inbox" size="sm" /> {{ __('Enquiries') }}</h3>
            @if ($count > 0)
                <span class="text-sm opacity-70">
                    {{ trans_choice(':count enquiry|:count enquiries', $count, ['count' => $count]) }}@if ($open > 0) · {{ trans_choice(':count open|:count open', $open, ['count' => $open]) }}@endif
                </span>
                <a href="{{ route('admin.enquiries.index', ['customer' => $customer->id, 'view' => 'all']) }}" class="btn btn-ghost btn-sm gap-1.5">{{ __('All enquiries') }} <x-icon name="arrow-right" size="sm" /></a>
            @endif
        </div>

        @if ($enquiries->isEmpty())
            <p class="text-sm opacity-60">{{ __('No enquiries yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('What') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Waiting time') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($enquiries as $customerEnquiry)
                            @php
                                $enquiryKind = Kinds::find($customerEnquiry->kind);
                                $enquirySince = $customerEnquiry->waitingSince();
                                $enquiryOverdue = $customerEnquiry->isOverdue();
                            @endphp
                            <tr class="hover:bg-base-200/50 align-top">
                                <td class="max-w-md">
                                    <div class="flex items-start gap-2">
                                        <x-icon :name="$enquiryKind['icon'] ?? 'message-square'" size="sm" class="opacity-60 mt-0.5 shrink-0" />
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.enquiries.show', $customerEnquiry) }}" class="link link-hover text-sm font-medium">
                                                #{{ $customerEnquiry->id }} · {{ $customerEnquiry->kindLabel() }}
                                            </a>
                                            <div class="text-xs opacity-60 line-clamp-2">{{ $customerEnquiry->preview(120) ?: '—' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-sm opacity-70 whitespace-nowrap">{{ $customerEnquiry->created_at?->format('d.m.Y') }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($enquirySince)
                                        <span @class(['badge badge-sm gap-1', 'badge-error' => $enquiryOverdue, 'badge-warning' => ! $enquiryOverdue])>
                                            <x-icon name="clock" size="sm" />
                                            {{ $enquirySince->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE, true) }}
                                        </span>
                                    @else
                                        <span class="text-xs opacity-40">—</span>
                                    @endif
                                </td>
                                <td><span class="badge badge-sm whitespace-nowrap {{ Status::badge($customerEnquiry->status) }}">{{ Status::label($customerEnquiry->status) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
