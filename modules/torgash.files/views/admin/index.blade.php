{{--
    The file storage (torgash.files, PLAN §69): one folder at a time — its
    folders, then its files as a grid of tiles — or a search across all.

    Every file's link is permanent: renaming and moving change rows, never
    the address (see the migration), and the screen says so where it matters.
    Uploading, ticking, copying links and the preview are Alpine's
    `torgashFiles` (assets/files.js); the forms work as plain forms too.
    The bulk form and the preview's delete form stand BESIDE the others,
    never inside them.
--}}
@extends('admin.layout')

@section('title', __('File manager'))
@section('title_hint', __('Pictures and files of the shop with a permanent link each — for blocks, letters, a marketplace feed. Renaming a file or moving it to another folder never changes its link.'))
@section('title_actions')
    <button type="button" class="btn" data-open-dialog="files-new-folder">
        <x-icon name="folder-plus" size="sm" /> {{ __('New folder') }}
    </button>
    <label for="files-input" class="btn btn-primary">
        <x-icon name="upload" size="sm" /> {{ __('Upload files') }}
    </label>
@endsection

@push('scripts')
    <script nonce="{{ csp_nonce() }}" src="{{ module_asset('torgash.files', 'files.js') }}"></script>
@endpush

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300 relative"
         x-data="torgashFiles"
         data-upload-url="{{ route('admin.files.upload') }}"
         data-folder="{{ $folder?->id }}"
         data-failed="{{ __('The file did not arrive. It may be bigger than the server accepts.') }}"
         x-on:dragenter.prevent="dragging++"
         x-on:dragleave.prevent="dragging--"
         x-on:dragover.prevent
         x-on:drop.prevent="drop($event)">

        {{-- Dropping files anywhere on the card. --}}
        <div x-show="dragging > 0" x-cloak
             class="absolute inset-0 z-20 rounded-box border-2 border-dashed border-primary bg-primary/10 flex items-center justify-center pointer-events-none">
            <span class="text-lg font-medium text-primary flex items-center gap-2">
                <x-icon name="upload" size="lg" /> {{ __('Drop the files to upload them here') }}
            </span>
        </div>

        <div class="card-body gap-4">
            {{-- Without JavaScript this is an ordinary form; with it, picking
                 files starts the uploader and the button is never needed. --}}
            <form method="POST" action="{{ route('admin.files.upload') }}" enctype="multipart/form-data" class="hidden">
                @csrf
                <input type="hidden" name="folder" value="{{ $folder?->id }}">
                <input type="file" id="files-input" name="files[]" multiple
                       accept="{{ collect($library->types())->map(fn ($t) => '.'.$t)->implode(',') }}"
                       x-on:change="pick($event)">
                <noscript><button class="btn btn-primary">{{ __('Upload') }}</button></noscript>
            </form>

            {{-- Where we are. --}}
            <div class="flex flex-wrap items-center gap-2">
                <nav class="breadcrumbs text-sm py-0 grow min-w-0" aria-label="{{ __('Folder') }}">
                    <ul>
                        <li>
                            <a href="{{ route('admin.files.index') }}" class="gap-1.5">
                                <x-icon name="hard-drive" size="sm" /> {{ __('File manager') }}
                            </a>
                        </li>
                        @foreach ($trail as $step)
                            <li>
                                @if ($loop->last)
                                    <span class="font-medium">{{ $step->name }}</span>
                                @else
                                    <a href="{{ route('admin.files.index', ['folder' => $step->id]) }}">{{ $step->name }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </nav>
                @if ($folder)
                    <button type="button" class="btn btn-ghost btn-sm" data-open-dialog="files-rename-folder">
                        <x-icon name="pencil" size="sm" /> {{ __('Rename folder') }}
                    </button>
                    <form method="POST" action="{{ route('admin.files.folders.destroy', $folder) }}"
                          data-confirm="{{ __('Delete the folder «:name» with every file and folder in it? Their links stop working.', ['name' => $folder->name]) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-ghost btn-sm text-error"><x-icon name="trash-2" size="sm" /> {{ __('Delete folder') }}</button>
                    </form>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" class="flex flex-wrap items-center gap-2 grow">
                    <label class="input input-sm grow min-w-48 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $q }}" class="grow" placeholder="{{ __('Search by name in every folder…') }}">
                    </label>
                    @if ($folder && $q === '')
                        <input type="hidden" name="folder" value="{{ $folder->id }}">
                    @endif
                    <select name="sort" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Sorting') }}">
                        <option value="new" @selected($sort === 'new')>{{ __('Newest first') }}</option>
                        <option value="name" @selected($sort === 'name')>{{ __('By name') }}</option>
                    </select>
                    @if ($q !== '')
                        <a href="{{ route('admin.files.index', array_filter(['folder' => $folder?->id])) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endif
                </form>
                {{-- Tiles or a list: LINKS, remembered for the next visit. --}}
                <div class="join" role="group" aria-label="{{ __('Display as') }}">
                    <a href="{{ request()->fullUrlWithQuery(['view' => 'grid']) }}" title="{{ __('Tiles') }}" aria-label="{{ __('Tiles') }}"
                       @class(['btn btn-sm btn-square join-item', 'btn-active' => $view === 'grid'])><x-icon name="layout-grid" size="sm" /></a>
                    <a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}" title="{{ __('List') }}" aria-label="{{ __('List') }}"
                       @class(['btn btn-sm btn-square join-item', 'btn-active' => $view === 'list'])><x-icon name="list" size="sm" /></a>
                </div>
            </div>

            {{-- Uploads in flight: one bar for the whole batch, a line per
                 file being sent right now, then what was refused. --}}
            <div x-show="total" x-cloak class="flex flex-col gap-2 bg-base-200 rounded-box p-3 text-sm">
                <div class="flex items-center gap-3">
                    <span class="shrink-0 tabular-nums">
                        <span x-text="finished"></span> / <span x-text="total"></span>
                    </span>
                    <progress class="progress grow" :class="errorCount ? 'progress-warning' : 'progress-primary'" :value="percent" max="100"></progress>
                    <span class="shrink-0 w-10 text-right tabular-nums" x-text="percent + '%'"></span>
                </div>
                <template x-for="upload in sending" :key="upload.id">
                    <div class="flex items-center gap-3 text-xs opacity-70">
                        <span class="truncate w-56 shrink-0" x-text="upload.name"></span>
                        <progress class="progress progress-primary grow h-1" :value="upload.progress" max="100"></progress>
                    </div>
                </template>
                <template x-if="errorCount">
                    <div class="flex flex-col gap-0.5 max-h-48 overflow-y-auto">
                        <p class="font-medium text-error">{{ __('Not uploaded') }}: <span x-text="errorCount"></span></p>
                        <template x-for="(error, index) in errors" :key="index">
                            <p class="text-error text-xs" x-text="error"></p>
                        </template>
                    </div>
                </template>
                <div x-show="!busy && errorCount" class="pt-1">
                    <a href="{{ request()->fullUrl() }}" class="btn btn-sm">{{ __('Show the uploaded files') }}</a>
                </div>
            </div>

            {{-- What can be done with the ticked files. --}}
            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>{{ __('Tick files to copy their links in one go (separated by commas), move them to another folder or delete them.') }}</span>
                    @if ($files->isNotEmpty())
                        <button type="button" class="link link-hover" x-on:click="selectPage()">{{ __('Select every file on this page') }}</button>
                    @endif
                </p>
                <form method="POST" action="{{ route('admin.files.bulk') }}" id="files-bulk"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <button type="button" class="btn btn-sm gap-1.5" x-on:click="copySelected()">
                        <x-icon name="copy" size="sm" />
                        <span x-show="copied !== 'selected'">{{ __('Copy links') }}</span>
                        <span x-show="copied === 'selected'" x-cloak>{{ __('Copied') }}</span>
                    </button>
                    <div class="join">
                        <select name="target" class="select select-sm join-item w-auto max-w-56" aria-label="{{ __('Move to') }}">
                            <option value="0">{{ __('File manager') }} ({{ __('top') }})</option>
                            @foreach ($tree as $branch)
                                <option value="{{ $branch['id'] }}">{{ $branch['label'] }}</option>
                            @endforeach
                        </select>
                        <button name="action" value="move" class="btn btn-sm join-item">{{ __('Move') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected files? Their links stop working.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($folders->isEmpty() && $files->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="folder-open" size="xl" class="opacity-40" />
                    <p class="mt-2">
                        {{ $q !== '' ? __('No files match this search.') : __('Nothing here yet. Drop files on this card or press "Upload files".') }}
                    </p>
                </div>
            @elseif ($view === 'list')
                <div class="overflow-x-auto">
                    <table class="table table-sm admin-cards">
                        <thead>
                            <tr>
                                <th class="w-8"></th>
                                <th class="w-14"></th>
                                <th>{{ __('Name') }}</th>
                                @if ($q !== '')
                                    <th>{{ __('Folder') }}</th>
                                @endif
                                <th>{{ __('Type') }}</th>
                                <th class="text-right">{{ __('Size') }}</th>
                                <th>{{ __('Resolution') }}</th>
                                <th>{{ __('Uploaded') }}</th>
                                <th class="w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($folders as $child)
                                <tr class="hover:bg-base-200/50">
                                    <td></td>
                                    <td><x-icon name="folder" size="lg" class="text-warning" /></td>
                                    <td colspan="{{ $q !== '' ? 7 : 6 }}">
                                        <a href="{{ route('admin.files.index', ['folder' => $child->id]) }}" class="link link-hover font-medium">{{ $child->name }}</a>
                                        <span class="text-xs opacity-60 ml-2">
                                            {{ trans_choice(':count file|:count files', $child->files_count) }}@if ($child->children_count), {{ trans_choice(':count folder|:count folders', $child->children_count) }}@endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                            @foreach ($files as $stored)
                                <tr class="hover:bg-base-200/50" :class="selected.includes({{ $stored->id }}) && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $stored->id }}" x-model.number="selected"
                                               aria-label="{{ __('Select') }}: {{ $stored->name }}">
                                    </td>
                                    <td>
                                        <span class="size-10 rounded bg-base-200 flex items-center justify-center overflow-hidden">
                                            @if ($filePreview = $stored->previewUrl())
                                                <img src="{{ $filePreview }}" alt="" loading="lazy" class="object-contain w-full h-full">
                                            @else
                                                <x-icon name="{{ $stored->extension === 'pdf' ? 'file-text' : 'file' }}" size="md" class="opacity-50" />
                                            @endif
                                        </span>
                                    </td>
                                    <td data-cell="{{ __('Name') }}" class="max-w-80">
                                        <button type="button" class="link link-hover text-left break-all"
                                                @foreach ($stored->dialogData() as $dataKey => $dataValue) data-{{ $dataKey }}="{{ $dataValue }}" @endforeach
                                                x-on:click="open($el)">{{ $stored->name }}</button>
                                    </td>
                                    @if ($q !== '')
                                        <td data-cell="{{ __('Folder') }}" class="text-xs">
                                            @if ($stored->folder)
                                                <a href="{{ route('admin.files.index', ['folder' => $stored->folder_id]) }}" class="link link-hover">{{ $stored->folder->name }}</a>
                                            @else
                                                <span class="opacity-50">—</span>
                                            @endif
                                        </td>
                                    @endif
                                    <td data-cell="{{ __('Type') }}" class="text-xs">{{ strtoupper($stored->extension) }}</td>
                                    <td data-cell="{{ __('Size') }}" class="text-xs text-right tabular-nums whitespace-nowrap">{{ $stored->sizeText() }}</td>
                                    <td data-cell="{{ __('Resolution') }}" class="text-xs tabular-nums whitespace-nowrap">{{ $stored->width ? $stored->width.'×'.$stored->height : '' }}</td>
                                    <td data-cell="{{ __('Uploaded') }}" class="text-xs whitespace-nowrap">{{ $stored->created_at?->format('d.m.Y H:i') }}</td>
                                    <td>
                                        <button type="button" class="btn btn-ghost btn-sm btn-square"
                                                title="{{ __('Copy link') }}" aria-label="{{ __('Copy link') }}: {{ $stored->name }}"
                                                x-on:click="copyLinks(['{{ $stored->url() }}'], {{ $stored->id }})">
                                            <x-icon name="link" size="sm" x-show="copied !== {{ $stored->id }}" />
                                            <x-icon name="check" size="sm" class="text-success" x-show="copied === {{ $stored->id }}" x-cloak />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                    @foreach ($folders as $child)
                        <a href="{{ route('admin.files.index', ['folder' => $child->id]) }}"
                           class="flex flex-col rounded-box border border-base-300 hover:border-primary hover:bg-base-200/50 transition-colors">
                            <span class="aspect-square flex items-center justify-center">
                                <x-icon name="folder" size="xl" class="text-warning size-16!" />
                            </span>
                            <span class="px-3 pb-3">
                                <span class="block text-sm font-medium truncate">{{ $child->name }}</span>
                                <span class="block text-xs opacity-60">
                                    {{ trans_choice(':count file|:count files', $child->files_count) }}@if ($child->children_count), {{ trans_choice(':count folder|:count folders', $child->children_count) }}@endif
                                </span>
                            </span>
                        </a>
                    @endforeach

                    @foreach ($files as $stored)
                        <div class="relative flex flex-col rounded-box border border-base-300 overflow-hidden"
                             :class="selected.includes({{ $stored->id }}) && 'border-primary! ring-2 ring-primary/30'">
                            <label class="absolute top-2 left-2 z-10 bg-base-100/90 rounded p-1 flex cursor-pointer">
                                <input type="checkbox" class="checkbox checkbox-sm" value="{{ $stored->id }}" x-model.number="selected"
                                       aria-label="{{ __('Select') }}: {{ $stored->name }}">
                            </label>
                            <button type="button" class="aspect-square bg-base-200 flex items-center justify-center overflow-hidden cursor-zoom-in"
                                    @foreach ($stored->dialogData() as $dataKey => $dataValue) data-{{ $dataKey }}="{{ $dataValue }}" @endforeach
                                    x-on:click="open($el)">
                                @if ($filePreview = $stored->previewUrl())
                                    <img src="{{ $filePreview }}" alt="" loading="lazy" class="object-contain w-full h-full">
                                @else
                                    <span class="flex flex-col items-center gap-1 opacity-60">
                                        <x-icon name="{{ $stored->extension === 'pdf' ? 'file-text' : 'file' }}" size="xl" />
                                        <span class="text-xs font-medium">{{ strtoupper($stored->extension) }}</span>
                                    </span>
                                @endif
                            </button>
                            <div class="px-3 py-2 flex items-start gap-1">
                                <div class="min-w-0 grow">
                                    <span class="block text-sm truncate" title="{{ $stored->name }}">{{ $stored->name }}</span>
                                    <span class="block text-xs opacity-60 truncate">
                                        {{ $stored->sizeText() }}@if ($stored->width) · {{ $stored->width }}×{{ $stored->height }}@endif
                                        @if ($q !== '' && $stored->folder) · {{ $stored->folder->name }}@endif
                                    </span>
                                </div>
                                <button type="button" class="btn btn-ghost btn-sm btn-square shrink-0 -mr-2"
                                        title="{{ __('Copy link') }}" aria-label="{{ __('Copy link') }}: {{ $stored->name }}"
                                        x-on:click="copyLinks(['{{ $stored->url() }}'], {{ $stored->id }})">
                                    <x-icon name="link" size="sm" x-show="copied !== {{ $stored->id }}" />
                                    <x-icon name="check" size="sm" class="text-success" x-show="copied === {{ $stored->id }}" x-cloak />
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{ $files->links() }}
            @endif
        </div>

        {{-- The preview: one dialog, filled by `open()` from the tile. --}}
        <dialog class="modal" x-ref="preview">
            <div class="modal-box max-w-4xl">
                <form method="dialog"><button class="btn btn-ghost btn-sm btn-circle absolute right-3 top-3" aria-label="{{ __('Close') }}"><x-icon name="x" /></button></form>
                <h3 class="font-semibold text-lg pr-10 truncate" x-text="file.name"></h3>

                <div class="mt-4 grid gap-5 md:grid-cols-[1fr_18rem]">
                    <div class="bg-base-200 rounded-box flex items-center justify-center min-h-64 max-h-[70vh] overflow-hidden">
                        <template x-if="file.preview">
                            <img :src="file.preview" alt="" class="object-contain max-w-full max-h-[70vh]">
                        </template>
                        <template x-if="!file.preview">
                            <span class="flex flex-col items-center gap-2 opacity-60 py-16">
                                <x-icon name="file" size="xl" />
                                <span class="text-sm font-medium" x-text="file.type"></span>
                            </span>
                        </template>
                    </div>

                    <div class="flex flex-col gap-4 text-sm">
                        <div>
                            <span class="label text-xs mb-1">{{ __('Link') }}</span>
                            <div class="join w-full">
                                <input type="text" readonly class="input input-sm join-item grow" :value="file.url" data-select>
                                <button type="button" class="btn btn-sm join-item" x-on:click="copyLinks([file.url], 'dialog')"
                                        title="{{ __('Copy link') }}" aria-label="{{ __('Copy link') }}">
                                    <x-icon name="copy" size="sm" x-show="copied !== 'dialog'" />
                                    <x-icon name="check" size="sm" class="text-success" x-show="copied === 'dialog'" x-cloak />
                                </button>
                            </div>
                            <a :href="file.url" target="_blank" rel="noopener" class="link link-hover text-xs mt-1 inline-flex items-center gap-1">
                                <x-icon name="external-link" size="sm" /> {{ __('Open the file') }}
                            </a>
                        </div>

                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                            <dt class="opacity-60">{{ __('Type') }}</dt><dd x-text="file.type"></dd>
                            <dt class="opacity-60">{{ __('Size') }}</dt><dd x-text="file.size"></dd>
                            <template x-if="file.dimensions">
                                <dt class="opacity-60">{{ __('Resolution') }}</dt>
                            </template>
                            <template x-if="file.dimensions">
                                <dd x-text="file.dimensions"></dd>
                            </template>
                            <dt class="opacity-60">{{ __('Uploaded') }}</dt><dd x-text="file.uploaded"></dd>
                        </dl>

                        <form method="POST" :action="file.rename" class="flex flex-col gap-1">
                            @csrf @method('PATCH')
                            <span class="label text-xs">{{ __('Name') }}</span>
                            <div class="join w-full">
                                <input type="text" name="name" required maxlength="200" class="input input-sm join-item grow" :value="file.name">
                                <button class="btn btn-sm join-item">{{ __('Rename') }}</button>
                            </div>
                            <span class="text-xs opacity-60">{{ __('Only the name shown here changes. The link stays the same.') }}</span>
                        </form>

                        <form method="POST" :action="file.delete" class="mt-auto"
                              data-confirm="{{ __('Delete this file? Its link stops working.') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-ghost btn-sm text-error"><x-icon name="trash-2" size="sm" /> {{ __('Delete file') }}</button>
                        </form>
                    </div>
                </div>
            </div>
            <form method="dialog" class="modal-backdrop"><button>{{ __('Close') }}</button></form>
        </dialog>
    </div>

    <dialog id="files-new-folder" class="modal">
        <div class="modal-box">
            <h3 class="font-semibold text-lg">{{ __('New folder') }}</h3>
            <form method="POST" action="{{ route('admin.files.folders.store') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <input type="hidden" name="parent" value="{{ $folder?->id }}">
                <label class="flex flex-col gap-1">
                    <span class="label text-sm">{{ __('Name') }}</span>
                    <input type="text" name="name" required maxlength="120" class="input w-full" autofocus>
                </label>
                @if ($folder)
                    <p class="text-xs opacity-60">{{ __('Inside «:name».', ['name' => $folder->name]) }}</p>
                @endif
                <div class="modal-action mt-0">
                    <button type="button" class="btn" data-close-dialog>{{ __('Cancel') }}</button>
                    <button class="btn btn-primary">{{ __('Create') }}</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>{{ __('Close') }}</button></form>
    </dialog>

    @if ($folder)
        <dialog id="files-rename-folder" class="modal">
            <div class="modal-box">
                <h3 class="font-semibold text-lg">{{ __('Rename folder') }}</h3>
                <form method="POST" action="{{ route('admin.files.folders.update', $folder) }}" class="mt-4 flex flex-col gap-4">
                    @csrf @method('PATCH')
                    <label class="flex flex-col gap-1">
                        <span class="label text-sm">{{ __('Name') }}</span>
                        <input type="text" name="name" required maxlength="120" class="input w-full" value="{{ $folder->name }}">
                    </label>
                    <p class="text-xs opacity-60">{{ __('Folders are only for finding files here: the links of the files inside do not change.') }}</p>
                    <div class="modal-action mt-0">
                        <button type="button" class="btn" data-close-dialog>{{ __('Cancel') }}</button>
                        <button class="btn btn-primary">{{ __('Save') }}</button>
                    </div>
                </form>
            </div>
            <form method="dialog" class="modal-backdrop"><button>{{ __('Close') }}</button></form>
        </dialog>
    @endif
@endsection
