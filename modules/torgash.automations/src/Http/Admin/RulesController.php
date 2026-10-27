<?php

namespace Torgash\Automations\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;
use Torgash\Automations\Actions\HasFields;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Subjects\Subjects;
use Torgash\Automations\Triggers;

class RulesController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? $request->query('status') : '',
        ];

        // Conditions and actions eagerly: the list says what each rule waits
        // for and what it does, which is the question the screen is opened
        // with. A shop has a handful of rules, so search and tabs run in PHP.
        $all = Rule::with(['conditions', 'actions'])->orderBy('position')->orderBy('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (Rule $rule) => mb_stripos($rule->name, $filters['q']) !== false);

        // "Is it actually getting through?" per rule: its last run, one query.
        $lastRuns = RuleRun::query()
            ->whereIn('id', RuleRun::query()->selectRaw('max(id)')->whereNotNull('rule_id')->groupBy('rule_id'))
            ->get()->keyBy('rule_id');

        return view('torgash.automations::admin.rules.index', [
            'rules' => match ($filters['status']) {
                'enabled' => $found->where('enabled', true)->values(),
                'disabled' => $found->where('enabled', false)->values(),
                default => $found->values(),
            },
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'enabled' => $found->where('enabled', true)->count(),
                'disabled' => $found->where('enabled', false)->count(),
            ],
            'lastRuns' => $lastRuns,
            'failedToday' => RuleRun::where('status', RuleRun::FAILED)->where('created_at', '>=', now()->subDay())->count(),
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Rule $rule)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $rule->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.automations::admin.rules._status', ['rule' => $rule]);
    }

    /** Several at once, through the models so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', ValidationRule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $rules = Rule::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $rules->each->delete();

            return back()->with('status', trans_choice(':count rule deleted.|:count rules deleted.', $rules->count(), ['count' => $rules->count()]));
        }

        $rules->each(fn (Rule $rule) => $rule->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count rule updated.|:count rules updated.', $rules->count(), ['count' => $rules->count()]));
    }

    public function create()
    {
        return $this->form(new Rule(['event' => 'checkout.order.changed', 'enabled' => true]));
    }

    public function store(Request $request)
    {
        $rule = Rule::create($this->data($request));
        $this->sync($request, $rule);

        return $this->afterSave($request, $rule, __('Rule created. Send it at a real order with "Test" before you rely on it.'));
    }

    public function edit(Rule $rule)
    {
        return $this->form($rule);
    }

    public function update(Request $request, Rule $rule)
    {
        $rule->update($this->data($request));
        $this->sync($request, $rule);

        return $this->afterSave($request, $rule, __('Rule saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Rule $rule, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.automations.index')
            : redirect()->route('admin.automations.edit', $rule))->with('status', $message);
    }

    public function destroy(Rule $rule)
    {
        $rule->delete();

        return redirect()->route('admin.automations.index')->with('status', __('Rule deleted.'));
    }

    /**
     * Run the rule at the newest order, right now, ignoring the delay.
     *
     * Composing a webhook body blind and waiting for a real order to find out it
     * was wrong is the worst part of every rule engine; this is the answer. The
     * conditions are still checked — a test that fires at an order the rule
     * would skip would teach the admin the wrong thing.
     */
    public function test(Rule $rule)
    {
        $key = $rule->subjectKey();
        $subject = Subjects::get($key);
        $model = $subject?->sample();

        if (! $model) {
            return back()->with('status', __('Nothing to test against yet.'));
        }

        (new RunRule($rule->id, (int) $model->getKey(), $rule->event, array_keys($subject->fields()), $rule->id.'-test', $key))->handle();

        $ref = $subject->refs([(int) $model->getKey()])[$model->getKey()]['label'] ?? '#'.$model->getKey();

        return redirect()->route('admin.automations.runs', ['rule' => $rule->id])
            ->with('status', __('Tested against :what — here is what happened.', ['what' => $ref]));
    }

    /**
     * Everything the form needs to draw a rule about ANY subject: the events
     * grouped by what they are about, and per subject its conditions, its
     * variables and the actions that can act on it. The form switches between
     * them as the event changes, so it never offers an order's status on an
     * enquiry rule.
     */
    protected function form(Rule $rule)
    {
        $subjects = Subjects::all();
        $subjectOf = [];
        $fieldEvents = [];
        foreach ($subjects as $key => $subject) {
            foreach (array_keys($subject->triggers()) as $event) {
                $subjectOf[$event] = $key;
                if ($subject->usesFields($event)) {
                    $fieldEvents[] = $event;
                }
            }
        }

        $schemas = [];
        foreach ($subjects as $key => $subject) {
            foreach ($subject->conditions() as $kind => $schema) {
                $schemas[$key][$kind] = [
                    'label' => __($schema['label']),
                    'operators' => array_map(fn ($label) => __($label), $schema['operators']),
                    'input' => $schema['input'] ?? 'text',
                    // A list, not a map: JS objects re-order numeric keys, and
                    // a category picker must stay in tree order.
                    'options' => collect($schema['options'] ?? [])->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])->values()->all(),
                    'bare' => $schema['bare'] ?? [],
                    'placeholder' => $schema['placeholder'] ?? '',
                ];
            }
        }

        $actionTypes = [];
        foreach (RunRule::actions() as $type => $class) {
            $actionTypes[$type] = [
                'label' => __($class::label()),
                'subjects' => $class::subjects(),
                'fields' => is_subclass_of($class, HasFields::class)
                    ? collect($class::fields())->map(fn ($field) => [
                        'label' => __($field['label'] ?? ''),
                        'type' => $field['type'] ?? 'text',
                        'options' => collect($field['options'] ?? [])->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])->values()->all(),
                    ])->all()
                    : [],
            ];
        }

        return view('torgash.automations::admin.rules.form', [
            'rule' => $rule->loadMissing(['conditions', 'actions']),
            'eventGroups' => Triggers::grouped(),
            'subjectOf' => $subjectOf,
            'fieldEvents' => $fieldEvents,
            'fieldsBySubject' => collect($subjects)->map(fn ($subject) => array_map(fn ($l) => __($l), $subject->fields()))->all(),
            'schemas' => $schemas,
            'variables' => collect($subjects)->map(fn ($subject) => $subject->variables())->all(),
            'actionTypes' => $actionTypes,
        ]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'event' => ['required', 'string', ValidationRule::in(array_keys(Triggers::all()))],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
            'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $subject = Subjects::for($validated['event']);

        return [
            'name' => $validated['name'],
            'event' => $validated['event'],
            // A field filter on an event that carries no diff would be a rule
            // that never fires, so it is not stored for one.
            'fields' => $subject?->usesFields($validated['event'])
                ? array_values(array_intersect($validated['fields'] ?? [], array_keys($subject->fields())))
                : null,
            'delay_minutes' => (int) ($validated['delay_minutes'] ?? 0),
            'position' => (int) ($validated['position'] ?? 0),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /**
     * Conditions and actions are replaced wholesale — a handful of ordered rows
     * carrying no state of their own, where a diff costs more code than it
     * saves. Kinds, operators and action types the rule's SUBJECT does not
     * know are dropped rather than stored: a row nothing can evaluate would
     * silently stop the rule.
     */
    protected function sync(Request $request, Rule $rule): void
    {
        $key = $rule->subjectKey();
        $subject = Subjects::get($key);
        $schema = $subject?->conditions() ?? [];

        $rule->conditions()->delete();
        $position = 0;

        foreach ((array) $request->input('conditions', []) as $row) {
            $kind = (string) ($row['kind'] ?? '');
            $operator = (string) ($row['operator'] ?? '');

            if (! isset($schema[$kind]['operators'][$operator])) {
                continue;
            }

            $rule->conditions()->create([
                'kind' => $kind,
                'operator' => $operator,
                'value' => $subject->valueFrom($kind, (array) $row),
                'position' => $position++,
            ]);
        }

        // A stored bot token is never drawn back into the form, so a blank box
        // means "keep it" — found by the id of the row it was on, since the
        // rows are about to be replaced.
        $tokens = $rule->actions()->where('type', 'telegram')->get()
            ->mapWithKeys(fn ($action) => [$action->id => (string) $action->config('token', '')])
            ->all();

        $rule->actions()->delete();
        $position = 0;
        $known = RunRule::actionsFor($key);

        foreach ((array) $request->input('actions', []) as $row) {
            $type = (string) ($row['type'] ?? '');

            if (! isset($known[$type])) {
                continue;
            }

            $rule->actions()->create([
                'type' => $type,
                'config' => $this->actionConfig($type, $known[$type], (array) $row, $tokens),
                'position' => $position++,
            ]);
        }
    }

    /**
     * Only the keys the action itself uses: a set_status row carrying a URL
     * would be a stored copy of whatever the form last had open, and the run log
     * would print it beside an action that never sends anything.
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $tokens  stored bot tokens by the id of the row that held them
     * @return array<string, mixed>
     */
    protected function actionConfig(string $type, string $class, array $row, array $tokens = []): array
    {
        if (is_subclass_of($class, HasFields::class)) {
            $config = [];
            foreach ($class::fields() as $name => $field) {
                $value = (string) ($row['config'][$name] ?? '');
                // A select only stores one of its own options.
                $config[$name] = ($field['type'] ?? 'text') === 'select' && ! array_key_exists($value, (array) ($field['options'] ?? []))
                    ? ''
                    : $value;
            }

            return $config;
        }

        return match ($type) {
            'telegram' => [
                'token' => trim((string) ($row['token'] ?? '')) ?: ($tokens[(int) ($row['id'] ?? 0)] ?? ''),
                'chat_id' => trim((string) ($row['chat_id'] ?? '')),
                'text' => (string) ($row['text'] ?? ''),
            ],
            default => [
                'method' => in_array($row['method'] ?? '', ['POST', 'PUT', 'PATCH', 'GET'], true) ? $row['method'] : 'POST',
                'url' => trim((string) ($row['url'] ?? '')),
                'headers' => (string) ($row['headers'] ?? ''),
                'body' => (string) ($row['body'] ?? ''),
            ],
        };
    }
}
