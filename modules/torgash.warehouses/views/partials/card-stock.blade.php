{{-- What a catalogue card says about stock once the customer has chosen one of
     the shop's points (catalog.product.stock_line).

     Three answers, and the middle one is the reason this exists: "not at the
     shop you picked, but we have it" is neither "in stock" nor "out of stock",
     and printing either of those is how a customer either drives to an empty
     shelf or gives up on a product the shop could have sold them. When another
     warehouse can send it there, the middle answer is a DATE with a truck
     (PLAN §38, `transfer_date`), which is what a customer plans by. --}}
@if ($here > 0)
    <div class="text-xs torgash-in-stock flex items-center gap-1">
        <x-icon name="circle-check" size="sm" />
        {{ __('In stock at :point', ['point' => $point->t('name')]) }}
    </div>
@elseif ($product->isOrderable())
    @if ($bringBy ?? null)
        <div class="text-xs text-primary flex items-center gap-1">
            <x-icon name="truck" size="sm" />
            {{ __('We will bring it by :date', ['date' => $bringBy->translatedFormat('j F')]) }}
        </div>
    @else
        {{-- Orderable overall but not at this point — true of ordinary stock
             elsewhere AND of a preorder, which has no "here" to speak of at all. --}}
        <div class="text-xs opacity-60">
            {{ __('Not at :point — available to order', ['point' => $point->t('name')]) }}
        </div>
    @endif
@else
    {{-- `torgash-availability-{key}` — the EFFECTIVE key, see product.blade.php's own badge. --}}
    <div class="text-xs torgash-out-of-stock torgash-availability torgash-availability-{{ $product->effectiveAvailabilityKey() }}">
        {{ $product->stockLabel() }}
    </div>
@endif
