{{-- Rendered into the delivery method form AND the payment method form, from
     the same file: "use this one when nobody chose" is one question, and asking
     it in two different words on two screens is how they end up meaning
     different things.

     A checkbox for what is really a single choice: ticking another method moves
     the nomination rather than adding to it, which the help text says out loud
     — a radio would need every method on one page to be drawn together, and
     these are edited one at a time. --}}
<div class="card bg-base-100 border border-base-300">
    <div class="card-body gap-2">
        <h3 class="font-semibold">{{ __('Buying in one click') }}</h3>

        <label class="label cursor-pointer justify-start gap-3 p-0">
            <input type="checkbox" name="{{ $oneClickField }}" value="1" class="checkbox"
                   @checked($oneClickChosen)>
            <span>{{ __('Use this one for orders placed in one click') }}</span>
        </label>

        <p class="text-xs opacity-60">
            {{ __('One click asks for a phone number and nothing else, so the shop has to say which delivery and payment such an order is placed with — the manager agrees the real ones on the phone. Only one method of each kind can be nominated; ticking another moves it.') }}
        </p>
    </div>
</div>
