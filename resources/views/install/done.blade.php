{{-- The end of the web install: where to go, the password when it was made
     up (shown this once, nowhere stored in the clear), and the one cron line
     the server still needs. --}}
@php
    $doneUrl = rtrim($answers['url'], '/');
@endphp
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4 items-start">
        <div class="size-14 rounded-full bg-success/15 text-success flex items-center justify-center"><x-icon name="circle-check" size="xl" /></div>
        <h2 class="text-2xl font-bold">{{ __(':name is installed', ['name' => $answers['name']]) }}</h2>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="opacity-60">{{ __('Admin') }}</dt><dd><a href="{{ $doneUrl }}/admin" class="link font-mono">{{ $doneUrl }}/admin</a></dd>
            <dt class="opacity-60">{{ __('Email') }}</dt><dd class="font-mono">{{ $answers['owner']['email'] }}</dd>
            @if ($password)
                <dt class="opacity-60">{{ __('Password') }}</dt><dd class="font-mono font-semibold select-all">{{ $password }}</dd>
            @endif
        </dl>

        @if ($password)
            <div class="alert alert-warning text-sm"><x-icon name="triangle-alert" /><span>{{ __('The password is shown this once. Write it down, sign in and change it in your profile.') }}</span></div>
        @endif

        <div class="flex flex-col gap-1 text-sm w-full">
            <div class="font-medium">{{ __('One line for the server\'s cron') }}</div>
            <p class="opacity-70">{{ __('Without it letters are not sent and nightly jobs (backups, exchange rates) do not run. Add it in the hosting\'s cron settings:') }}</p>
            <code class="block bg-base-200 rounded-box p-3 font-mono text-xs break-all select-all">* * * * * cd {{ base_path() }} && php artisan schedule:run >> /dev/null 2>&1</code>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ $doneUrl }}/admin" class="btn btn-primary">{{ __('Open the admin') }}</a>
            <a href="{{ $doneUrl }}/" class="btn">{{ __('Open the shop') }}</a>
        </div>
    </div>
</section>
