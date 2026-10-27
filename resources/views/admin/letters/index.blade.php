@extends('admin.layout')

@section('title', __('Letters'))

{{-- Every letter the shop sends, and the frame they share (LettersController). --}}
@section('content')
    @php
        $locale = admin_content_locale();
        $audienceNames = ['customer' => __('To customers'), 'shop' => __('To the shop')];
        // One audience at a time, the lists' tabs; the address keeps the choice.
        $audience = array_key_exists(request('audience'), $groups) ? request('audience') : (array_key_first($groups) ?? 'customer');
        $audienceCount = fn (string $key) => collect($groups[$key] ?? [])->sum(fn ($events) => count($events));
    @endphp

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <p class="text-sm opacity-70 max-w-3xl">
                        {{ __('Every letter the shop sends, in one frame. A letter nobody edited speaks its module\'s own words in the reader\'s language; what you write replaces them only in the language you write it in.') }}
                    </p>

                    <div class="join flex-wrap">
                        @foreach ($groups as $audienceKey => $unused)
                            <a href="{{ request()->fullUrlWithQuery(['audience' => $audienceKey]) }}"
                               @class(['btn btn-sm join-item', 'btn-active' => $audience === $audienceKey])>
                                {{ $audienceNames[$audienceKey] ?? $audienceKey }} <span class="opacity-50">{{ $audienceCount($audienceKey) }}</span>
                            </a>
                        @endforeach
                    </div>

                    @foreach (array_intersect_key($groups, [$audience => true]) as $byGroup)
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Letter') }}</th>
                                        <th>{{ __('Subject') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($byGroup as $groupName => $events)
                                        <tr><td colspan="4" class="text-xs uppercase tracking-wide opacity-50 pt-4 pb-1">{{ $groupName }}</td></tr>
                                        @foreach ($events as $key => $event)
                                            @php
                                                $row = $rows[$key] ?? null;
                                                $edited = $row && collect(['subject', 'heading', 'body', 'button'])->contains(fn ($f) => $row->own($f, $locale) !== null);
                                                $on = $event['required'] || ($row?->enabled ?? true);
                                            @endphp
                                            <tr>
                                                <td>
                                                    <a href="{{ route('admin.letters.edit', $key) }}" class="link link-hover font-medium">{{ __($event['label']) }}</a>
                                                    @if ($edited)
                                                        <span class="badge badge-sm badge-info ml-1">{{ __('edited') }}</span>
                                                    @endif
                                                </td>
                                                <td class="text-sm opacity-80">{{ \Illuminate\Support\Str::limit(\App\Core\Mail\Letters::words($key, 'subject', $locale), 70) }}</td>
                                                <td>
                                                    @if ($on && array_key_exists('recipients', $event) && \App\Core\Mail\Letters::recipients($key) === [])
                                                        {{-- On, but with nowhere to go: it sends nothing until an address is written. --}}
                                                        <span class="badge badge-sm badge-warning" title="{{ __('Write an address on its page and it starts going out.') }}">{{ __('No address') }}</span>
                                                    @elseif ($event['required'])
                                                        <span class="badge badge-sm badge-neutral">{{ __('Always sent') }}</span>
                                                    @else
                                                        <span @class(['badge badge-sm', 'badge-success' => $on, 'badge-ghost' => ! $on])>{{ $on ? __('Enabled') : __('Disabled') }}</span>
                                                    @endif
                                                </td>
                                                <td class="text-right">
                                                    <a href="{{ route('admin.letters.edit', $key) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-4">
        {{-- The frame every letter is drawn in. --}}
        <form method="POST" action="{{ route('admin.letters.frame') }}" enctype="multipart/form-data" class="card bg-base-100 border border-base-300">
            @csrf
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('The frame of every letter') }}</h3>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Colour') }}</legend>
                    <div class="flex gap-2">
                        <input type="color" value="{{ $frame['color'] }}" class="w-12 h-10 rounded cursor-pointer border border-base-300"
                               x-data x-on:input="$el.nextElementSibling.value = $el.value">
                        <input name="color" value="{{ old('color', $frame['color']) }}" class="input input-bordered w-full font-mono"
                               x-data x-on:input="if (/^#[0-9a-f]{6}$/i.test($el.value)) $el.previousElementSibling.value = $el.value">
                    </div>
                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('The line over the letter and its button.') }}</p>
                </fieldset>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Background') }}</legend>
                    <div class="flex gap-2">
                        <input type="color" value="{{ $frame['background'] }}" class="w-12 h-10 rounded cursor-pointer border border-base-300"
                               x-data x-on:input="$el.nextElementSibling.value = $el.value">
                        <input name="background" value="{{ old('background', $frame['background']) }}" class="input input-bordered w-full font-mono"
                               x-data x-on:input="if (/^#[0-9a-f]{6}$/i.test($el.value)) $el.previousElementSibling.value = $el.value">
                    </div>
                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('Around the letter.') }}</p>
                </fieldset>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Logo') }}</legend>
                    @if ($frame['logo'])
                        <div class="h-16 rounded-box bg-base-200 flex items-center justify-center mb-2">
                            <img src="{{ $frame['logo'] }}" alt="" class="max-h-10 max-w-[80%] object-contain">
                        </div>
                    @endif
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" class="file-input file-input-bordered w-full">
                    @if ($frame['logo'])
                        <label class="label cursor-pointer justify-start gap-2 p-0 mt-1">
                            <input type="checkbox" name="logo_remove" value="1" class="checkbox checkbox-sm">
                            <span class="label-text text-xs">{{ __('Remove the logo') }}</span>
                        </label>
                    @endif
                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('Without one, the shop\'s name is written in the colour above. PNG or JPG — mail clients do not draw SVG.') }}</p>
                </fieldset>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Line beside the logo') }}</legend>
                    <input name="topline" value="{{ old('topline', $frame['topline']) }}" class="input input-bordered w-full" placeholder="{phone}">
                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('Short: a slogan or a phone. Takes the same tokens as the footer.') }}</p>
                </fieldset>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Footer') }}</legend>
                    <textarea name="footer" rows="3" class="textarea textarea-bordered w-full text-sm">{{ old('footer', $frame['footer']) }}</textarea>
                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('Under every letter. Takes {shop_name}, {shop_url} and the shop\'s contacts — {phone}, {email}.') }}</p>
                </fieldset>

                <button class="btn btn-primary btn-sm self-start">{{ __('Save') }}</button>
            </div>
        </form>

        {{-- Where letters leave from. Off: .env, as before. --}}
        <form method="POST" action="{{ route('admin.letters.server') }}" class="card bg-base-100 border border-base-300"
              x-data="{ on: @js((bool) old('smtp_enabled', $server['enabled'])) }">
            @csrf
            <div class="card-body gap-3">
                <h3 class="font-semibold">{{ __('Mail server') }}</h3>

                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Sender\'s address') }}</legend>
                    <input name="mail_from_address" type="email" value="{{ old('mail_from_address', $server['from_address']) }}" class="input input-bordered w-full" placeholder="{{ config('mail.from.address') }}">
                </fieldset>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Sender\'s name') }}</legend>
                    <input name="mail_from_name" value="{{ old('mail_from_name', $server['from_name']) }}" class="input input-bordered w-full" placeholder="{{ config('mail.from.name') }}">
                </fieldset>

                <label class="label cursor-pointer justify-start gap-3 mt-1">
                    <input type="checkbox" name="smtp_enabled" value="1" class="toggle toggle-sm toggle-success" x-model="on">
                    <span class="label-text">{{ __('Send through this SMTP server') }}</span>
                </label>
                <p class="text-xs opacity-60" x-show="! on">{{ __('Off: letters go the way .env says (:mailer).', ['mailer' => $envMailer]) }}</p>

                <div class="flex flex-col gap-3" x-show="on" x-cloak>
                    <div class="grid grid-cols-[minmax(0,1fr)_6rem] gap-2">
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Server') }}</legend>
                            <input name="smtp_host" value="{{ old('smtp_host', $server['host']) }}" class="input input-bordered w-full font-mono text-sm" placeholder="smtp.gmail.com">
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Port') }}</legend>
                            <input name="smtp_port" type="number" value="{{ old('smtp_port', $server['port']) }}" class="input input-bordered w-full font-mono text-sm">
                        </fieldset>
                    </div>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Encryption') }}</legend>
                        <select name="smtp_encryption" class="select select-bordered w-full">
                            @foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => __('None (25, a local relay)')] as $value => $text)
                                <option value="{{ $value }}" @selected(old('smtp_encryption', $server['encryption']) === $value)>{{ $text }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Login') }}</legend>
                        <input name="smtp_username" value="{{ old('smtp_username', $server['username']) }}" autocomplete="off" class="input input-bordered w-full">
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Password') }}</legend>
                        <input name="smtp_password" type="password" autocomplete="new-password" class="input input-bordered w-full"
                               placeholder="{{ $server['has_password'] ? __('saved — leave blank to keep it') : '' }}">
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Stored encrypted with the app key; never shown again.') }}</p>
                    </fieldset>
                </div>

                @if ($serverCheck)
                    <div @class(['alert text-xs py-2 break-all', 'alert-success' => $serverCheck['ok'], 'alert-error' => ! $serverCheck['ok']])>
                        <span>{{ $serverCheck['message'] }}</span>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
                    <button name="action" value="check" class="btn btn-sm gap-1.5" x-show="on"><x-icon name="plug-zap" size="sm" /> {{ __('Check the connection') }}</button>
                </div>
            </div>
        </form>
        </div>
    </div>
@endsection
