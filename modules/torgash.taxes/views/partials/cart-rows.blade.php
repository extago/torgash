{{-- Rows above the cart total. An included tax is shown greyed out because it
     is not something being added — it is a disclosure about the number above. --}}
@foreach ($lines as $line)
    <div class="flex justify-between text-sm {{ $line->included() ? 'opacity-60' : '' }}">
        <span>{{ $line->included() ? __('incl. :tax', ['tax' => $line->label()]) : $line->label() }}</span>
        <span>{{ format_price($line->amount) }}</span>
    </div>
@endforeach
