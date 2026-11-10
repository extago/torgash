{{-- At checkout, for an order of goods with an age (torgash.age, PLAN §71):
     required, and read when the order is placed (checkout.order.placing).
     Never ticked for the buyer. --}}
<section class="bg-base-100 border border-base-300 rounded-box p-5 sm:p-6">
    <label class="flex items-start gap-3 cursor-pointer">
        <input type="checkbox" name="age_confirmed" value="1" class="checkbox checkbox-primary mt-0.5" required
               aria-label="{{ __('I am :age or older', ['age' => $ageAsk]) }}" @checked(old('age_confirmed'))>
        <span>
            <span class="font-medium">{{ __('I am :age or older', ['age' => $ageAsk]) }}</span>
            <span class="block text-sm opacity-70">{{ __('The order has goods sold only to people of :age or older. Have a document that shows your age ready: it is asked for when the order is handed over.', ['age' => $ageAsk]) }}</span>
        </span>
    </label>
</section>
