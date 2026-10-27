{{-- The customer's card: their points, the latest movements, a correction. --}}
<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold flex items-center gap-1.5"><x-icon name="coins" size="sm" /> {{ __('Bonus points') }}</h3>
            <span class="text-xl font-bold tabular-nums">{{ number_format($pointsBalance, 0, '.', ' ') }}</span>
        </div>

        @foreach ($pointsEntries as $pointsEntry)
            <div class="flex justify-between gap-2 text-sm">
                <span class="opacity-70">{{ $pointsEntry->created_at?->format('d.m.Y') }} · {{ $pointsEntry->typeLabel() }}</span>
                <span class="tabular-nums {{ $pointsEntry->points > 0 ? 'text-success' : '' }}">{{ $pointsEntry->points > 0 ? '+' : '−' }}{{ number_format(abs($pointsEntry->points), 0, '.', ' ') }}</span>
            </div>
        @endforeach

        <form method="POST" action="{{ route('admin.points.adjust') }}" class="flex flex-col gap-2 border-t border-base-300 pt-3">
            @csrf
            <input type="hidden" name="email" value="{{ $pointsCustomer->email }}">
            <div class="join w-full">
                <input name="points" type="number" required class="input input-bordered join-item w-32" placeholder="{{ __('+100 or −50') }}">
                <input name="reason" required maxlength="500" class="input input-bordered join-item w-full" placeholder="{{ __('Why — the customer sees it in the history') }}">
            </div>
            <button class="btn btn-sm w-fit">{{ __('Correct') }}</button>
        </form>
    </div>
</div>
