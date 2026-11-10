{{-- In the one-click form (checkout.one_click.consents), for goods with an
     age (torgash.age, PLAN §71): required, read when the order is placed.
     `ageTicked` is what came back with a refused form. --}}
<label class="flex items-start gap-3 cursor-pointer text-sm">
    <input type="checkbox" name="age_confirmed" value="1" class="checkbox checkbox-primary mt-0.5" required
           aria-label="{{ __('I am :age or older', ['age' => $ageAsk]) }}" @checked($ageTicked)>
    <span>{{ __('I am :age or older and will show a document when the order is handed over', ['age' => $ageAsk]) }}</span>
</label>
