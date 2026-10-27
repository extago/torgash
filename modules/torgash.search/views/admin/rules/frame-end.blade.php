{{-- Closes the form card frame.blade.php opened (the Alpine root stays open
     for the list). --}}
        <div class="flex gap-2">
            <button class="btn btn-primary btn-sm">{{ $editing ? __('Save') : __('Add') }}</button>
            @if ($editing)
                <a href="{{ route('admin.search-engine.rules.index', $kind) }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            @else
                <button type="button" class="btn btn-ghost btn-sm" @click="adding = false">{{ __('Cancel') }}</button>
            @endif
        </div>
    </form>
</section>
