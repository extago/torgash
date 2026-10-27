{{-- Every status with its count, in the shop's own order, each a link to that list. --}}
<div class="flex flex-col">
    @foreach ($statuses as $status)
        @php $statusCount = (int) ($counts[$status->code] ?? 0); @endphp
        <a href="{{ route('admin.orders.index', ['status' => [$status->code]]) }}"
           @class(['flex items-center gap-2 -mx-2 px-2 py-1 rounded-field hover:bg-base-200 text-sm', 'opacity-40' => $statusCount === 0])>
            <span class="h-2.5 w-2.5 rounded-full shrink-0" style="background-color: {{ $status->color ?? '#999' }}"></span>
            <span class="grow truncate">{{ $status->t('name') }}</span>
            <span class="tabular-nums font-medium">{{ $statusCount }}</span>
        </a>
    @endforeach
</div>
