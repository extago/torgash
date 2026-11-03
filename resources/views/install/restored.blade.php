{{-- The end of a restore from a backup (PLAN §67.8, stage 6): the shop is
     the one it was — its staff sign in with the accounts they had. What did
     not come back is said here, once. --}}
@php
    $restoredUrl = rtrim($answers['url'], '/');
    $restoredWhat = $answers['restore'];
    $restoredMissing = $restoredWhat['missing_modules'] ?? [];
@endphp
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4 items-start">
        <div class="size-14 rounded-full bg-success/15 text-success flex items-center justify-center"><x-icon name="circle-check" size="xl" /></div>
        <h2 class="text-2xl font-bold">{{ __('The shop is restored') }}</h2>
        <p class="text-sm opacity-70">{{ __('From the backup of :when, made by Torgash :version. Sign in with the account you had.', ['when' => $restoredWhat['manifest']['created_at'] ?? '?', 'version' => $restoredWhat['manifest']['engine'] ?? '?']) }}</p>

        @if ($restoredWhat['new_key'])
            <div class="alert alert-warning text-sm items-start"><x-icon name="triangle-alert" /><span>{{ __('The shop runs with a NEW key: gift card codes, the mail password, storage passwords and second factors of the old shop do not open — type them again in the admin.') }}</span></div>
        @endif
        @if ($restoredMissing !== [])
            <div class="alert alert-warning text-sm items-start"><x-icon name="triangle-alert" /><span>{{ __('Not in this code, so not working until they are put into modules/: :list.', ['list' => implode(', ', $restoredMissing)]) }}</span></div>
        @endif

        <div class="flex flex-col gap-1 text-sm w-full">
            <div class="font-medium">{{ __('One line for the server\'s cron') }}</div>
            <code class="block bg-base-200 rounded-box p-3 font-mono text-xs break-all select-all">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ $restoredUrl }}/admin" class="btn btn-primary">{{ __('Open the admin') }}</a>
            <a href="{{ $restoredUrl }}/" class="btn">{{ __('Open the shop') }}</a>
        </div>
    </div>
</section>
