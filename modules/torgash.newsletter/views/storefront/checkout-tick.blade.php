{{-- At checkout (setting offer_at_checkout): one tick, read when the order is
     placed (checkout.order_placed) for the order's email. Not ticked unless
     the shop chose so — a tick put there is not the customer's consent. --}}
<section class="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6">
    <label class="flex items-start gap-3 cursor-pointer">
        <input type="checkbox" name="newsletter_subscribe" value="1" class="checkbox checkbox-primary mt-0.5"
               @checked(old('newsletter_subscribe', setting('torgash.newsletter', 'checkout_ticked', false)))>
        <span>
            <span class="font-medium">{{ __('Subscribe to news and offers') }}</span>
            <span class="block text-sm opacity-70">{{ __('To the order\'s email. You can unsubscribe in any letter.') }}</span>
        </span>
    </label>
</section>
