{{--
    The Security card, drawn the same in the admin and in the account area.

    Included by both screens rather than written twice: the words on it ARE the
    feature for most people, and two copies is how one of them ends up telling
    somebody that recovery codes can be looked at again while the other does
    not. Every variable it reads is prefixed `tf` — an include inherits its
    caller's whole scope, and a partial that read $codes or $required would take
    whatever a loop in a theme's template happened to leave there.

    Three states, and the middle one is what the rest is written around: off,
    half-enrolled (a secret shown, nothing proved, nobody locked out), and on.
--}}

@if ($tfCodes)
    {{-- Shown once, at the moment they are made, and after that only to
         somebody who types their password again. The wording is doing real
         work here: a person who does not understand that this is the way back
         from a lost phone will not write them down. --}}
    <div class="card bg-base-100 shadow-sm border-2 border-warning">
        <div class="card-body gap-3">
            <h2 class="card-title text-base">
                <x-icon name="key-round" /> {{ __('Your recovery codes') }}
            </h2>
            <p class="text-sm opacity-80">
                {{ __('Each one signs you in once, without your phone. Write them down or print them now, and keep them somewhere that is not the phone — a code you cannot reach when the phone is gone is not a way back in.') }}
            </p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 font-mono text-sm">
                @foreach ($tfCodes as $tfCode)
                    <div class="px-2 py-1 rounded bg-base-200 text-center tracking-wide select-all">{{ $tfCode }}</div>
                @endforeach
            </div>

            <div class="text-xs opacity-60">
                {{ __('You can see this list again on this page by entering your password.') }}
            </div>
        </div>
    </div>
@endif

<div class="card bg-base-100 shadow-sm border border-base-300/60">
    <div class="card-body gap-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h2 class="card-title text-base">
                    <x-icon name="shield-check" /> {{ __('Two-factor authentication') }}
                </h2>
                <p class="text-sm opacity-70 max-w-prose mt-1">
                    {{ __('A six-digit code from an app on your phone, on top of your password. Somebody who learns the password still cannot sign in.') }}
                </p>
            </div>

            @if ($tfRecord)
                <span class="badge badge-success gap-1"><x-icon name="check" size="sm" /> {{ __('Turned on') }}</span>
            @elseif ($tfRequired)
                <span class="badge badge-warning gap-1"><x-icon name="triangle-alert" size="sm" /> {{ __('Required') }}</span>
            @else
                <span class="badge badge-ghost">{{ __('Turned off') }}</span>
            @endif
        </div>

        @if ($tfRequired && ! $tfRecord)
            <div class="alert alert-warning text-sm">
                <x-icon name="triangle-alert" size="md" />
                <span>{{ __('This shop requires two-factor authentication for your account. Until it is set up, this is the only page you can open.') }}</span>
            </div>
        @endif

        {{-- ------------------------------------------------------- it is on --}}
        @if ($tfRecord)
            <div class="text-sm opacity-70">
                {{ __('On since') }} {{ $tfRecord->confirmed_at?->format('d.m.Y H:i') }}.
                @if ($tfRecord->last_used_at)
                    {{ __('Last confirmed') }} {{ $tfRecord->last_used_at->diffForHumans() }}.
                @endif
            </div>

            @if ($tfRecord->codesLeft() <= 2)
                <div class="alert alert-warning text-sm">
                    <x-icon name="key-round" size="md" />
                    <span>
                        {{ __('Recovery codes left: :n.', ['n' => $tfRecord->codesLeft()]) }}
                        {{ __('Make a new set below.') }}
                    </span>
                </div>
            @endif

            {{-- Two forms, beside each other and never nested: this project has
                 deleted products by putting one form inside another (CLAUDE.md)
                 — the parser drops the inner tag and keeps its inputs, so a
                 hidden _method leaks into whichever submit happens. The second
                 button here reaches its own address with formaction= for the
                 same reason. --}}
            <div class="divider my-0"></div>

            <div class="grid md:grid-cols-2 gap-4">
                <form method="POST" action="{{ route($tfRoute.'.codes') }}" class="flex flex-col gap-2">
                    @csrf
                    <div class="font-medium text-sm">{{ __('Recovery codes') }}</div>
                    <p class="text-xs opacity-60">
                        {{ __('Recovery codes left: :n.', ['n' => $tfRecord->codesLeft()]) }}
                        {{ __('Your password shows them, or replaces the whole set with a new one.') }}
                    </p>
                    <input type="password" name="current_password" autocomplete="current-password" required
                           placeholder="{{ __('Your password') }}" class="input input-bordered input-sm w-full">
                    <div class="flex gap-2 flex-wrap">
                        <button class="btn btn-sm">{{ __('Show them') }}</button>
                        <button class="btn btn-sm btn-ghost" formaction="{{ route($tfRoute.'.codes.new') }}"
                                data-confirm="{{ __('Make a new set? Every code you have written down stops working.') }}">
                            {{ __('New set') }}
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route($tfRoute.'.disable') }}" class="flex flex-col gap-2">
                    @csrf @method('DELETE')
                    <div class="font-medium text-sm">{{ __('Turn it off') }}</div>
                    <p class="text-xs opacity-60">
                        @if ($tfRequired)
                            {{ __('This shop requires two-factor authentication for your account, so it cannot be switched off here. If your phone is gone, ask an administrator.') }}
                        @else
                            {{ __('Your password alone will sign you in again. Do this before changing phones, then set it up again on the new one.') }}
                        @endif
                    </p>
                    @unless ($tfRequired)
                        <input type="password" name="current_password" autocomplete="current-password" required
                               placeholder="{{ __('Your password') }}" class="input input-bordered input-sm w-full">
                        <div>
                            <button class="btn btn-sm btn-error btn-outline">{{ __('Turn off') }}</button>
                        </div>
                    @endunless
                </form>
            </div>

        {{-- ---------------------------------------- a secret, nothing proved --}}
        @elseif ($tfSecret)
            <div class="divider my-0"></div>

            <div class="flex flex-col md:flex-row gap-6">
                {{-- A white ground whatever the theme is: this is read by a
                     camera, and an inverted QR code does not scan. --}}
                <div class="shrink-0 self-start p-3 rounded-box bg-white border border-base-300">
                    @if ($tfQr)
                        {!! $tfQr !!}
                    @else
                        <div class="w-[190px] h-[190px] flex items-center justify-center text-center text-xs text-neutral p-3">
                            {{ __('No QR code can be drawn here. Type the key into the app by hand.') }}
                        </div>
                    @endif
                </div>

                <div class="flex-1 flex flex-col gap-3">
                    <ol class="list-decimal list-inside text-sm space-y-1 opacity-80">
                        <li>{{ __('Install an authenticator app — Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden, or the one built into your phone.') }}</li>
                        <li>{{ __('Scan this picture with it, or type the key below in by hand.') }}</li>
                        <li>{{ __('Enter the six digits it shows.') }}</li>
                    </ol>

                    <div>
                        <div class="text-xs opacity-60 mb-1">{{ __('The key, if you cannot scan') }}</div>
                        <code class="font-mono text-sm tracking-wider select-all block p-2 rounded bg-base-200 break-all">{{ $tfReadable }}</code>
                    </div>

                    <form method="POST" action="{{ route($tfRoute.'.confirm') }}" class="flex flex-col gap-2">
                        @csrf
                        <label class="fieldset p-0">
                            <span class="fieldset-legend">{{ __('The code from the app') }}</span>
                            <input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus
                                   maxlength="7" placeholder="000000"
                                   class="input input-bordered w-40 font-mono text-lg tracking-[0.3em]">
                        </label>
                        @error('code')
                            <div class="text-error text-sm">{{ $message }}</div>
                        @enderror
                        <div>
                            <button class="btn btn-primary btn-sm">{{ __('Turn it on') }}</button>
                        </div>
                    </form>

                    <p class="text-xs opacity-60">
                        {{ __('Nothing is switched on until that code is right, so a camera that will not focus or an app that will not install costs you nothing — having started locks you out of nothing.') }}
                    </p>
                </div>
            </div>

        {{-- ------------------------------------------------------- it is off --}}
        @else
            <form method="POST" action="{{ route($tfRoute.'.start') }}">
                @csrf
                {{-- Short: the card's heading already says what is set up, and
                     the long name wrapped inside a phone's button. --}}
                <button class="btn btn-primary btn-sm">
                    <x-icon name="shield-plus" size="sm" /> {{ __('Set it up') }}
                </button>
            </form>
        @endif
    </div>
</div>
