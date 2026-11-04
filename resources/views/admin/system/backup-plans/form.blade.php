@extends('admin.layout')

@section('title', $plan->exists ? $plan->name : __('New backup plan'))
@section('own_heading', '1')

{{-- A backup plan (PLAN §67.2). The schedule is a LIST of moments, so "at
     noon and at midnight" is two rows; each storage keeps its own number. --}}
@section('content')
    @php
        $partNames = ['database' => __('Database'), 'files' => __('Uploaded files'), 'code' => __('Modules and themes')];
        $partHints = [
            'database' => __('Orders, products, customers, settings — everything in PostgreSQL.'),
            'files' => app(\App\Core\Files\FileStorage::class)->onS3()
                ? __('The files are in a bucket — they are not copied; the bucket keeps them.')
                : __('Product pictures, attachments and everything else uploaded.'),
            'code' => __('The installed modules and themes, with any changes made to them.'),
        ];
        $contents = old('contents', $plan->contents ?? []);
        $fileOptions = [
            'changed_only' => (bool) old('options.changed_only', $plan->option('changed_only', false)),
            'without_thumbnails' => (bool) old('options.without_thumbnails', $plan->option('without_thumbnails', false)),
        ];
        $schedule = array_values(old('schedule', $plan->schedule ?? []));
        $rules = [
            'last' => __('The newest'),
            'daily' => __('Daily'),
            'weekly' => __('Weekly'),
            'monthly' => __('Monthly'),
            'yearly' => __('Yearly'),
            'days' => __('Not older than, days'),
            'gigabytes' => __('At most, GB'),
        ];
        $chosen = old('destinations');
        $days = collect([1, 2, 3, 4, 5, 6, 0])->mapWithKeys(fn ($d) => [$d => \App\Core\Backups\PlanSchedule::dayName($d)]);
    @endphp

    <x-admin.form-bar>
        <a href="{{ route('admin.system.backup-plans.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to backup plans') }}" aria-label="{{ __('Back to backup plans') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Backup plan') }}</div>
            <div class="font-semibold truncate leading-tight">{{ $plan->exists ? $plan->name : __('New backup plan') }}</div>
        </div>
        <x-slot:actions>
            <a href="{{ route('admin.system.backup-plans.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button type="submit" form="plan-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </x-slot:actions>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm flex-col items-start">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" id="plan-form"
          action="{{ $plan->exists ? route('admin.system.backup-plans.update', $plan) : route('admin.system.backup-plans.store') }}"
          class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        @csrf
        @if ($plan->exists) @method('PUT') @endif

        <div class="flex flex-col gap-4 min-w-0">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Backup plan') }}</h3>
                    <div class="grid sm:grid-cols-[minmax(0,1fr)_14rem] gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                            <input name="name" value="{{ old('name', $plan->name) }}" required maxlength="120" class="input input-bordered w-full" placeholder="{{ __('Database every night') }}">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }}</legend>
                            <input name="code" value="{{ old('code', $plan->code) }}" maxlength="64" autocapitalize="off" spellcheck="false" class="input input-bordered w-full font-mono" placeholder="database">
                            <p class="label whitespace-normal">{{ __('Latin letters, digits, - and _. It is {plan} in the names. Empty — made from the name.') }}</p>
                        </fieldset>
                    </div>

                    {{-- The files' two choices (PLAN §67.10) hang under their
                         row and only mean anything while the files are in. --}}
                    <fieldset class="fieldset p-0" x-data="{ files: @js(in_array('files', $contents, true)) }">
                        <legend class="fieldset-legend">{{ __('What goes in') }}</legend>
                        <div class="flex flex-col gap-2">
                            @foreach (\App\Models\BackupPlan::CONTENTS as $part)
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" name="contents[]" value="{{ $part }}" class="checkbox checkbox-sm mt-0.5" @checked(in_array($part, $contents, true)) @if ($part === 'files') x-model="files" @endif>
                                    <span>
                                        <span class="font-medium">{{ $partNames[$part] }}</span>
                                        <span class="block text-xs opacity-60">{{ $partHints[$part] }}</span>
                                    </span>
                                </label>
                                @if ($part === 'files' && ! app(\App\Core\Files\FileStorage::class)->onS3())
                                    <div class="ms-8 flex flex-col gap-2" x-show="files" x-cloak>
                                        <label class="flex items-start gap-3 cursor-pointer">
                                            <input type="checkbox" name="options[changed_only]" value="1" class="checkbox checkbox-sm mt-0.5" @checked($fileOptions['changed_only'])>
                                            <span>
                                                <span class="font-medium">{{ __('Only the files changed since the last full backup') }}</span>
                                                <span class="block text-xs opacity-60">{{ __('A daily backup beside a weekly full one: the database whole, the files only those added or changed since the newest full backup that holds the files, by any plan. Restoring puts that full backup\'s files back first, then these. With no full backup to lean on, every file is taken.') }}</span>
                                            </span>
                                        </label>
                                        <label class="flex items-start gap-3 cursor-pointer">
                                            <input type="checkbox" name="options[without_thumbnails]" value="1" class="checkbox checkbox-sm mt-0.5" @checked($fileOptions['without_thumbnails'])>
                                            <span>
                                                <span class="font-medium">{{ __('Without thumbnails') }}</span>
                                                <span class="block text-xs opacity-60">{{ __('The small copies of the pictures are left out: they often weigh more than the originals, and after a restore "Rebuild all thumbnails" makes them again.') }}</span>
                                            </span>
                                        </label>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </section>

            {{-- The schedule: rows, each one moment. --}}
            <section class="card bg-base-100 border border-base-300"
                     x-data="{ rows: @js($schedule), add() { this.rows.push({ kind: 'daily', at: '03:40' }) } }">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('When') }}</h3>
                    <p class="text-sm opacity-70">{{ __('Any number of moments, in the shop\'s clock (:zone). No moment — only by the button.', ['zone' => config('app.timezone')]) }}</p>

                    <template x-for="(row, i) in rows" :key="i">
                        <div class="flex flex-wrap items-end gap-3 rounded-box border border-base-300 p-3">
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Repeat') }}</legend>
                                <select class="select select-bordered select-sm w-44" :name="`schedule[${i}][kind]`" x-model="row.kind">
                                    <option value="hourly">{{ __('Every few hours') }}</option>
                                    <option value="daily">{{ __('Every day') }}</option>
                                    <option value="weekly">{{ __('On days of the week') }}</option>
                                    <option value="monthly">{{ __('Every month') }}</option>
                                    <option value="cron">{{ __('Cron expression') }}</option>
                                </select>
                            </fieldset>

                            <template x-if="row.kind === 'hourly'">
                                <div class="flex items-end gap-3">
                                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Every, hours') }}</legend>
                                        <input type="number" min="1" max="23" class="input input-bordered input-sm w-24" :name="`schedule[${i}][every]`" x-model="row.every" x-init="row.every ??= 6">
                                    </fieldset>
                                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('At minute') }}</legend>
                                        <input type="number" min="0" max="59" class="input input-bordered input-sm w-24" :name="`schedule[${i}][minute]`" x-model="row.minute" x-init="row.minute ??= 0">
                                    </fieldset>
                                </div>
                            </template>

                            <template x-if="row.kind === 'weekly'">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Days') }}</legend>
                                    <div class="flex flex-wrap gap-2 py-1" x-init="row.days ??= [1]">
                                        @foreach ($days as $d => $dayName)
                                            <label class="flex items-center gap-1 cursor-pointer text-sm">
                                                <input type="checkbox" class="checkbox checkbox-sm" value="{{ $d }}" :name="`schedule[${i}][days][]`"
                                                       :checked="(row.days || []).map(Number).includes({{ $d }})">
                                                {{ $dayName }}
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            </template>

                            <template x-if="row.kind === 'monthly'">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Day of the month') }}</legend>
                                    <input type="number" min="1" max="28" class="input input-bordered input-sm w-24" :name="`schedule[${i}][day]`" x-model="row.day" x-init="row.day ??= 1">
                                </fieldset>
                            </template>

                            <template x-if="['daily', 'weekly', 'monthly'].includes(row.kind)">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('At') }}</legend>
                                    <input type="time" class="input input-bordered input-sm w-32" :name="`schedule[${i}][at]`" x-model="row.at" x-init="row.at ??= '03:40'">
                                </fieldset>
                            </template>

                            <template x-if="row.kind === 'cron'">
                                <fieldset class="fieldset p-0 grow"><legend class="fieldset-legend">{{ __('Expression') }}</legend>
                                    <input class="input input-bordered input-sm w-full font-mono" placeholder="30 2 * * 1-5" :name="`schedule[${i}][expression]`" x-model="row.expression">
                                    <p class="label">{{ __('minute hour day month weekday') }}</p>
                                </fieldset>
                            </template>

                            <button type="button" class="btn btn-ghost btn-sm text-error ms-auto" x-on:click="rows.splice(i, 1)">{{ __('Remove') }}</button>
                        </div>
                    </template>

                    <div>
                        <button type="button" class="btn btn-sm" x-on:click="add()">+ {{ __('Add a moment') }}</button>
                    </div>
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Where it is kept') }}</h3>
                    <p class="text-sm opacity-70">{{ __('Each storage keeps its own number of backups. Older ones are deleted only after a new backup is made; the newest and a pinned one never are. All empty — every backup is kept.') }}</p>

                    @forelse ($destinations as $destination)
                        @php
                            $on = $chosen !== null ? (bool) ($chosen[$destination->id]['on'] ?? false) : array_key_exists($destination->id, $keep);
                            $values = $chosen !== null ? (array) ($chosen[$destination->id]['keep'] ?? []) : ($keep[$destination->id] ?? ($plan->exists ? [] : ['last' => 7]));
                        @endphp
                        <div class="rounded-box border border-base-300 p-3 flex flex-col gap-3" x-data="{ on: @js($on) }">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="destinations[{{ $destination->id }}][on]" value="1" class="checkbox checkbox-sm" x-model="on">
                                <span class="font-medium">{{ $destination->name }}</span>
                                <span class="text-xs opacity-60">{{ __(app(\App\Core\Backups\Destinations::class)->drivers()[$destination->driver] ?? $destination->driver) }}</span>
                            </label>
                            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3" x-show="on" x-cloak>
                                @foreach ($rules as $rule => $ruleLabel)
                                    <fieldset class="fieldset p-0"><legend class="fieldset-legend text-xs">{{ $ruleLabel }}</legend>
                                        <input type="number" min="0" step="{{ $rule === 'gigabytes' ? '0.1' : '1' }}" name="destinations[{{ $destination->id }}][keep][{{ $rule }}]"
                                               value="{{ ($values[$rule] ?? 0) ?: '' }}" placeholder="—" class="input input-bordered input-sm w-full">
                                    </fieldset>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-sm">{{ __('No storages yet.') }} <a href="{{ route('admin.system.backup-destinations.create') }}" class="link">{{ __('Add one') }}</a></p>
                    @endforelse
                    <p class="text-xs opacity-60">{{ __('Daily, weekly, monthly, yearly: the newest backup of each of that many last days, weeks, months, years. 7 daily + 4 weekly + 12 monthly keep a year in about two dozen files.') }}</p>
                </div>
            </section>
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $plan->enabled ?? true))>
                        <span>{{ __('The plan is on') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ __('Off — it makes nothing by the schedule, and the button does not offer it.') }}</p>

                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Check by restoring') }}</legend>
                        <select name="options[verify]" class="select select-bordered w-full">
                            @foreach (['' => __('Never'), 'weekly' => __('Every week (Sunday, 05:20)'), 'monthly' => __('Every month (the 1st, 05:20)')] as $verifyKey => $verifyLabel)
                                <option value="{{ $verifyKey }}" @selected(old('options.verify', $plan->option('verify', '')) === $verifyKey)>{{ $verifyLabel }}</option>
                            @endforeach
                        </select>
                        <p class="label whitespace-normal">{{ __('The newest backup is restored into a database of its own, its rows compared with the shop\'s at the moment it was made, the database dropped. A backup never restored is a hope, not a backup.') }}</p>
                    </fieldset>

                    @if ($next)
                        <div class="text-sm">
                            <div class="font-medium mb-1">{{ __('Next backups') }}</div>
                            @foreach ($next as $moment)
                                <div class="tabular-nums">{{ $moment->translatedFormat('D, j M Y, H:i') }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Name of the file') }}</h3>
                    <fieldset class="fieldset p-0">
                        <input name="name_template" value="{{ old('name_template', $plan->name_template) }}" required maxlength="200" autocapitalize="off" spellcheck="false" class="input input-bordered w-full font-mono text-sm">
                        <p class="label whitespace-normal">{{ __('Must contain {date} and {time}. Also: {shop}, {plan}, {Y}, {m}, {d}, {H}, {i}, {s}.') }}</p>
                    </fieldset>
                    @if ($plan->name_template)
                        <div class="text-xs opacity-70 break-all">{{ __('For example') }}: <span class="font-mono">{{ \App\Core\Backups\Names::example($plan->name_template, $plan->exists ? $plan : null, $plan->exists ? $plan->extension() : 'zip') }}</span></div>
                    @endif
                </div>
            </section>

            {{-- In what form (PLAN §67.3). The password is never printed back. --}}
            @php
                $formOptions = (array) ($plan->options ?? []);
                $formState = [
                    'format' => old('format', $plan->format ?: 'zip'),
                    'encrypt' => (bool) old('options.encrypt', $formOptions['encrypt'] ?? false),
                ];
            @endphp
            <section class="card bg-base-100 border border-base-300" x-data="@js($formState)">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Form of the backup') }}</h3>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Container') }}</legend>
                        <select name="format" class="select select-bordered w-full" x-model="format">
                            <option value="zip">{{ __('zip — opens on any computer') }}</option>
                            <option value="tar.gz">{{ __('tar.gz — smaller, the Linux way') }}</option>
                            <option value="tar">{{ __('tar — not compressed (quicker)') }}</option>
                            <option value="folder">{{ __('No archive — a folder of files') }}</option>
                        </select>
                        <p class="label whitespace-normal" x-show="format === 'folder'" x-cloak>{{ __('Every file is put in the storage as it is: one can be taken without unpacking anything. Slower to send to a server, and never encrypted.') }}</p>
                    </fieldset>
                    <div class="grid grid-cols-2 gap-3">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Database') }}</legend>
                            <select name="options[db_format]" class="select select-bordered w-full">
                                <option value="custom" @selected(old('options.db_format', $formOptions['db_format'] ?? 'custom') === 'custom')>{{ __('Compressed dump') }}</option>
                                <option value="plain" @selected(old('options.db_format', $formOptions['db_format'] ?? 'custom') === 'plain')>{{ __('Plain SQL') }}</option>
                            </select>
                        </fieldset>
                        <fieldset class="fieldset p-0" :disabled="format === 'tar' || format === 'folder'"><legend class="fieldset-legend">{{ __('Compression') }}</legend>
                            <select name="options[level]" class="select select-bordered w-full">
                                @foreach (['fast' => __('Fast'), 'normal' => __('Normal'), 'max' => __('Maximum')] as $levelKey => $levelLabel)
                                    <option value="{{ $levelKey }}" @selected(old('options.level', $formOptions['level'] ?? 'normal') === $levelKey)>{{ $levelLabel }}</option>
                                @endforeach
                            </select>
                        </fieldset>
                    </div>
                    <p class="text-xs opacity-60">{{ __('Plain SQL is read by eye and moves to another version of PostgreSQL more easily; the dump is smaller and quicker to restore.') }}</p>

                    <div class="flex flex-col gap-3" x-show="format !== 'folder'">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="options[encrypt]" value="1" class="toggle toggle-sm toggle-success" x-model="encrypt" :disabled="format === 'folder'">
                            <span>{{ __('Encrypt with a password') }}</span>
                        </label>
                        <fieldset class="flex flex-col gap-3" x-show="encrypt" x-cloak :disabled="! encrypt || format === 'folder'">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Password') }} *</legend>
                                <input type="password" name="password" value="" autocomplete="new-password" class="input input-bordered w-full font-mono"
                                       placeholder="{{ $plan->secret ? ($plan->passwordLost() ? __('cannot be read here — type it again') : __('kept — type to change')) : '' }}">
                                <input type="password" name="password_confirmation" value="" autocomplete="new-password" class="input input-bordered w-full font-mono mt-2" placeholder="{{ __('The same once more') }}">
                            </fieldset>
                            <div class="alert alert-warning text-xs">{{ __('Write the password down away from this server. Without it the backup is useless — and the copy of it the shop keeps is inside the very database the backup protects.') }}</div>
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="options[env]" value="1" class="checkbox mt-0.5" @checked(old('options.env', $formOptions['env'] ?? false))>
                                <span class="text-sm">{{ __('Put .env inside too') }}
                                    <span class="block text-xs opacity-60">{{ __('The APP_KEY and every password of the server: what a new server needs to read the rest. Only ever inside an encrypted backup.') }}</span>
                                </span>
                            </label>
                            <p class="text-xs opacity-60" x-show="format !== 'zip'">{{ __('Opened without the engine by') }} <code class="font-mono break-all">{{ \App\Core\Backups\Archive\Cipher::command() }}</code></p>
                            <p class="text-xs opacity-60" x-show="format === 'zip'">{{ __('AES-256: opens in 7-Zip, WinRAR, The Unarchiver — not in the zip Windows has built in. The files are encrypted, their names inside the zip are not; tar.gz hides everything.') }}</p>
                        </fieldset>
                    </div>
                </div>
            </section>
        </div>
    </form>
@endsection
