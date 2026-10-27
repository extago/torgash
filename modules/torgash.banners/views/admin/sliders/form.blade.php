@extends('admin.layout')

@section('title', $slider->exists ? $slider->name : __('New slider'))
@section('own_heading', '1')

@section('content')
    @php
        $shapeNames = ['wide' => __('Wide slide'), 'card' => __('Upright card'), 'strip' => __('Low strip')];
        $sliderStates = ['running' => __('Being shown'), 'scheduled' => __('Starts later'), 'ended' => __('Ended'), 'off' => __('Off')];
        $sliderRow = fn ($row) => [
            'id' => $row->id,
            'name' => $row->name,
            'template' => $row->template?->label() ?? '',
            'shapes' => $row->template?->shapes() ?? [],
            'thumb' => $row->relationLoaded('media') ? $row->thumbUrl() : null,
            'state' => $row->state(),
            'edit' => route('admin.banners.edit', $row),
        ];
        $sliderRows = $inSlider->map($sliderRow)->values();
        $sliderOthers = $others->map($sliderRow)->values();
        $frameInfo = $frames->mapWithKeys(fn ($frame) => [$frame->id => ['shape' => $frame->shape ?: 'wide', 'turns' => $frame->turns()]])->all();
        $sliderValues = (array) $slider->values;
    @endphp

    <form method="POST" action="{{ $slider->exists ? route('admin.banners.sliders.update', $slider) : route('admin.banners.sliders.store') }}"
          class="flex flex-col gap-4"
          x-data="{
              rows: @js($sliderRows), others: @js($sliderOthers), adding: '', dragIndex: null,
              frame: {{ (int) old('frame_id', $slider->frame_id) }}, frames: @js($frameInfo), shapeNames: @js($shapeNames),
              get shape() { return this.frames[this.frame]?.shape || 'wide' },
              get turns() { return this.frames[this.frame]?.turns ?? true },
              fits(row) { return row.shapes.includes(this.shape) },
              add() {
                  const i = this.others.findIndex((o) => String(o.id) === String(this.adding));
                  if (i < 0) return;
                  this.rows.push(this.others.splice(i, 1)[0]);
                  this.adding = '';
              },
              remove(i) { this.others.unshift(this.rows.splice(i, 1)[0]); },
              drop(target) {
                  if (this.dragIndex === null || this.dragIndex === target) return;
                  const moved = this.rows.splice(this.dragIndex, 1)[0];
                  this.rows.splice(target, 0, moved);
                  this.dragIndex = null;
              },
          }">
        @csrf
        @if ($slider->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.banners.sliders.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to sliders') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $slider->exists ? __('Edit slider') : __('New slider') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $slider->exists ? $slider->name : __('New slider') }}</div>
            </div>
            @if ($slider->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="slider-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.banners.sliders.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $slider->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                            <input name="name" value="{{ old('name', $slider->name) }}" required maxlength="190" autocomplete="off"
                                   class="input input-bordered w-full" placeholder="{{ __('Home page, main') }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('For your own list and the block\'s choice — never printed on the page.') }}</p>
                        </fieldset>

                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ __('Banners') }}</h3>
                            @if ($slider->exists)
                                <a href="{{ route('admin.banners.create', ['slider' => $slider->id]) }}" class="btn btn-sm">+ {{ __('New banner') }}</a>
                            @endif
                        </div>

                        <div class="flex flex-col gap-2">
                            <template x-for="(row, i) in rows" :key="row.id">
                                <div class="flex items-center gap-3 rounded-box border border-base-300 p-2 bg-base-100"
                                     :class="dragIndex === i ? 'opacity-40' : ''"
                                     draggable="true" @dragstart="dragIndex = i" @dragend="dragIndex = null"
                                     @dragover.prevent @drop.prevent="drop(i)">
                                    <input type="hidden" name="banners[]" :value="row.id">
                                    <span class="cursor-move opacity-40" aria-hidden="true"><x-icon name="grip-vertical" size="sm" /></span>
                                    <span class="w-20 aspect-video rounded-lg overflow-hidden bg-base-200 border border-base-300 shrink-0">
                                        <img x-show="row.thumb" :src="row.thumb" alt="" class="w-full h-full object-cover" draggable="false">
                                    </span>
                                    <span class="grow min-w-0">
                                        <a :href="row.edit" class="font-medium link link-hover block truncate" x-text="row.name"></a>
                                        <span class="text-xs opacity-60" x-text="row.template"></span>
                                        <span class="block text-xs text-warning" x-show="!fits(row)"
                                              x-text="@js(__('Its template cannot draw ":shape" — skipped on the page.')).replace(':shape', shapeNames[shape])"></span>
                                    </span>
                                    <span class="badge badge-sm whitespace-nowrap"
                                          :class="{ 'badge-success': row.state === 'running', 'badge-info': row.state === 'scheduled', 'badge-warning': row.state === 'ended', 'badge-ghost': row.state === 'off' }"
                                          x-text="@js($sliderStates)[row.state]"></span>
                                    <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Take out of the slider') }}"
                                            aria-label="{{ __('Take out of the slider') }}" @click="remove(i)"><x-icon name="x" /></button>
                                </div>
                            </template>
                            <p x-show="!rows.length" class="text-sm opacity-60">{{ __('No banners in it yet.') }}</p>
                        </div>

                        <div class="flex gap-2" x-show="others.length">
                            <select x-model="adding" class="select select-bordered w-full">
                                <option value="">{{ __('Add a banner…') }}</option>
                                <template x-for="other in others" :key="other.id">
                                    <option :value="other.id" x-text="other.name + (fits(other) ? '' : ' — ' + @js(__('does not fit'))) + (other.state !== 'running' ? ' — ' + @js($sliderStates)[other.state] : '')"></option>
                                </template>
                            </select>
                            <button type="button" class="btn" :disabled="!adding" @click="add()">{{ __('Add') }}</button>
                        </div>
                        <p class="text-xs opacity-60" x-show="rows.length > 1">{{ __('Drag to change the order. A banner off, or outside its dates, is skipped on the page and keeps its place here.') }}</p>
                    </div>
                </section>

                {{-- Between the products of a catalogue listing (PLAN §51, step 4):
                     its banners take the places one by one — as cards in a grid,
                     as strips in the list view. --}}
                <section class="card bg-base-100 border border-base-300" x-data="{ between: @js((bool) old('listing_on', $listing['on'])) }">
                    <div class="card-body gap-4">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="listing_on" value="1" class="toggle toggle-sm toggle-success" x-model="between">
                            <span class="font-semibold">{{ __('Also between the products in the catalogue') }}</span>
                        </label>
                        <div x-show="between" x-cloak class="flex flex-col gap-4">
                            <p class="text-sm opacity-70">{{ __('The slider\'s banners take the places one by one: as a card in a cell of the grid, as a low strip in the list view. A banner whose template cannot draw that shape sits that view out.') }}</p>
                            <div class="grid gap-4 md:grid-cols-2">
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('After product') }}</legend>
                                    <input type="number" name="listing_after" min="1" max="100" value="{{ old('listing_after', $listing['after']) }}" class="input input-bordered w-full">
                                </fieldset>
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Again every, products') }}</legend>
                                    <input type="number" name="listing_every" min="0" max="100" value="{{ old('listing_every', $listing['every']) }}" class="input input-bordered w-full">
                                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('0 — once on a page. Never after the last product of the page.') }}</p>
                                </fieldset>
                            </div>
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" name="listing_once" value="1" class="toggle toggle-sm" @checked(old('listing_once', $listing['once']))>
                                <span class="label-text whitespace-normal">{{ __('Each banner once on a page, not round and round') }}</span>
                            </label>
                            <x-admin.display-rules
                                :rules="$listing['rules']"
                                :match="$listing['match']"
                                :groups="['where', 'when', 'who']"
                                :heading="__('In which listings')"
                                :hint="__('No conditions means every listing. “Category being viewed” takes in its subcategories. The banners\' own conditions are asked too.')" />
                        </div>
                    </div>
                </section>

                {{-- The chosen frame's own fields only. --}}
                @foreach ($frames as $frameChoice)
                    @if ($frameChoice->fieldList() !== [])
                        <template x-if="frame === {{ $frameChoice->id }}">
                            <section class="card bg-base-100 border border-base-300">
                                <div class="card-body gap-4">
                                    <h3 class="font-semibold">{{ __('Frame settings') }}</h3>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        @foreach ($frameChoice->fieldList() as $frameField)
                                            @include('torgash.banners::admin._field', ['fieldDef' => $frameField, 'fieldValues' => $sliderValues, 'fieldRecord' => null, 'fieldPictures' => false])
                                        @endforeach
                                    </div>
                                </div>
                            </section>
                        </template>
                    @endif
                @endforeach
            </div>

            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Frame') }}</h3>
                    <fieldset class="fieldset p-0">
                        <select name="frame_id" x-model.number="frame" class="select select-bordered w-full">
                            @foreach ($frames as $frameChoice)
                                <option value="{{ $frameChoice->id }}">{{ $frameChoice->label() }} — {{ $shapeNames[$frameChoice->shape ?: 'wide'] }}</option>
                            @endforeach
                        </select>
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Where the strip, the dots, the arrows and a second slider stand. Banners are drawn in the frame\'s shape.') }}</p>
                    </fieldset>

                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Beside it') }}</legend>
                        <select name="aside_id" class="select select-bordered w-full">
                            <option value="">{{ __('Nothing') }}</option>
                            @foreach ($asides as $asideChoice)
                                <option value="{{ $asideChoice->id }}" @selected((int) old('aside_id', $slider->aside_id) === $asideChoice->id)>{{ $asideChoice->name }}</option>
                            @endforeach
                        </select>
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('A second slider the frame prints where it says {aside} — the cards beside a slide. A frame without {aside} ignores it.') }}</p>
                    </fieldset>

                    <div x-show="turns" class="flex flex-col gap-3 border-t border-base-300 pt-3">
                        <h3 class="font-semibold">{{ __('How it turns') }}</h3>
                        <div class="text-sm font-medium -mb-2">{{ __('In view') }}</div>
                        <div class="grid grid-cols-3 gap-3">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('On a computer') }}</legend>
                                <input type="number" name="per_view" min="1" max="8" step="0.1" value="{{ old('per_view', $options['per_view']) }}" class="input input-bordered w-full">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('On a tablet') }}</legend>
                                <input type="number" name="per_view_tablet" min="1" max="6" step="0.1" value="{{ old('per_view_tablet', $options['per_view_tablet']) }}" class="input input-bordered w-full">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('On a phone') }}</legend>
                                <input type="number" name="per_view_phone" min="1" max="4" step="0.1" value="{{ old('per_view_phone', $options['per_view_phone']) }}" class="input input-bordered w-full">
                            </fieldset>
                        </div>
                        <p class="label text-xs opacity-60 whitespace-normal -mt-2">{{ __('A computer is a screen from 1024px wide, a tablet from 768px. A fraction (4.3) leaves the next card peeking from the edge, so it is plain there is more.') }}</p>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('A throw of the finger') }}</legend>
                            <select name="drag" class="select select-bordered w-full">
                                <option value="snap" @selected(old('drag', $options['drag']) === 'snap')>{{ __('Turns one slide') }}</option>
                                <option value="glide" @selected(old('drag', $options['drag']) === 'glide')>{{ __('Glides and settles on a slide') }}</option>
                                <option value="free" @selected(old('drag', $options['drag']) === 'free')>{{ __('Glides freely') }}</option>
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('A strong throw glides past several slides; "settles" brings it to rest with a slide at the edge, "freely" leaves it wherever it stops.') }}</p>
                        </fieldset>
                        <div class="grid grid-cols-2 gap-3">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('An arrow turns') }}</legend>
                                <select name="step" class="select select-bordered w-full">
                                    <option value="one" @selected(old('step', $options['step']) === 'one')>{{ __('One slide') }}</option>
                                    <option value="view" @selected(old('step', $options['step']) === 'view')>{{ __('All in view') }}</option>
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Speed of a turn') }}</legend>
                                <select name="speed" class="select select-bordered w-full">
                                    <option value="fast" @selected(old('speed', $options['speed']) === 'fast')>{{ __('Fast') }}</option>
                                    <option value="normal" @selected(old('speed', $options['speed']) === 'normal')>{{ __('Normal') }}</option>
                                    <option value="slow" @selected(old('speed', $options['speed']) === 'slow')>{{ __('Slow') }}</option>
                                </select>
                            </fieldset>
                        </div>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('How a slide changes') }}</legend>
                            <select name="effect" class="select select-bordered w-full">
                                <option value="slide" @selected($options['effect'] === 'slide')>{{ __('Slide sideways') }}</option>
                                <option value="fade" @selected($options['effect'] === 'fade')>{{ __('Fade') }}</option>
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Fading is for one slide at a time.') }}</p>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Turn by itself every, seconds') }}</legend>
                            <input type="number" name="autoplay" min="0" max="60" value="{{ old('autoplay', $options['autoplay']) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('0 — only by the visitor. It waits while the pointer is over it, and never turns by itself for a visitor who asked their system for less motion.') }}</p>
                        </fieldset>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="loop" value="1" class="toggle toggle-sm" @checked(old('loop', $options['loop']))>
                            <span class="label-text">{{ __('Round and round') }}</span>
                        </label>
                    </div>

                    @if ($slider->exists)
                        <div class="border-t border-base-300 pt-3 mt-1">
                            <div class="text-sm font-medium mb-1">{{ __('Placed by') }}</div>
                            @forelse ($placed as $placedBlock)
                                <div @class(['text-sm', 'opacity-50' => ! $placedBlock['enabled']])>{{ $placedBlock['title'] }} <span class="opacity-50 font-mono text-xs">{{ $placedBlock['zone'] }}</span></div>
                            @empty
                                <p class="text-sm text-warning">{{ __('No block shows it yet: add a "Banners" block in Content → Blocks and choose this slider.') }}</p>
                            @endforelse
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </form>

    @if ($slider->exists)
        <form id="slider-delete" method="POST" action="{{ route('admin.banners.sliders.destroy', $slider) }}" class="hidden"
              data-confirm="{{ __('Delete this slider? Its banners stay.') }}">@csrf @method('DELETE')</form>
    @endif
@endsection
