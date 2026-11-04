<?php

namespace App\Http\Controllers\Admin;

use App\Core\Backups\Backups;
use App\Core\Backups\Names;
use App\Core\Backups\PlanSchedule;
use App\Core\Backups\Retention;
use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use App\Models\BackupPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin → System → Backup plans (PLAN §67.2): what goes in, when, under what
 * name, and which storages keep it — each with its own "how many to keep".
 * "The database twice a day, the files once a week" is two plans.
 */
class BackupPlansController extends Controller
{
    public function index()
    {
        return view('admin.system.backup-plans.index', [
            'plans' => BackupPlan::query()->with(['destinations', 'lastRun'])->orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        $plan = new BackupPlan([
            'enabled' => true,
            'contents' => ['database'],
            'schedule' => [['kind' => 'daily', 'at' => '03:40']],
            'format' => 'zip',
            'name_template' => '{shop}-{plan}-{date}-{time}',
        ]);

        return $this->form($plan);
    }

    public function edit(BackupPlan $plan)
    {
        return $this->form($plan);
    }

    public function store(Request $request)
    {
        $plan = new BackupPlan(['format' => 'zip']);
        $this->save($request, $plan);

        return $this->saved($request, $plan);
    }

    public function update(Request $request, BackupPlan $plan)
    {
        $this->save($request, $plan);

        return $this->saved($request, $plan);
    }

    public function destroy(BackupPlan $plan)
    {
        $plan->delete();

        return redirect()->route('admin.system.backup-plans.index')
            ->with('status', __('Plan deleted. The backups it made stay in the list.'));
    }

    /** "Run now" from the list. */
    public function run(Request $request, Backups $backups, BackupPlan $plan)
    {
        $run = $backups->queue($plan, 'manual', $request->user()?->id);

        return back()->with('status', $run
            ? __('The backup has started. It appears in the list when it is done — it needs a queue worker.')
            : __('A backup by the plan ":plan" is already waiting or being made.', ['plan' => $plan->name]));
    }

    protected function form(BackupPlan $plan)
    {
        $keep = $plan->exists
            ? $plan->destinations->mapWithKeys(fn ($d) => [$d->id => (array) json_decode((string) $d->pivot->keep, true)])->all()
            : [];

        return view('admin.system.backup-plans.form', [
            'plan' => $plan,
            'destinations' => BackupDestination::query()->orderByDesc('system')->orderBy('id')->get(),
            'keep' => $keep,
            'next' => $plan->exists && $plan->enabled ? PlanSchedule::next((array) $plan->schedule) : [],
        ]);
    }

    protected function save(Request $request, BackupPlan $plan): void
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('backup_plans', 'code')->ignore($plan->id)],
            'contents' => ['array'],
            'contents.*' => [Rule::in(BackupPlan::CONTENTS)],
            'name_template' => ['required', 'string', 'max:200'],
            'schedule' => ['array'],
            'destinations' => ['array'],
        ]);

        $errors = [];

        $contents = array_values(array_intersect(BackupPlan::CONTENTS, (array) $request->input('contents', [])));
        if ($contents === []) {
            $errors['contents'] = __('Choose what goes into the backup.');
        }

        $template = trim((string) $request->input('name_template'));
        if ($problem = Names::nameError($template)) {
            $errors['name_template'] = __($problem);
        }

        [$schedule, $scheduleErrors] = PlanSchedule::clean(array_values((array) $request->input('schedule', [])));
        if ($scheduleErrors) {
            $errors['schedule'] = implode(' ', $scheduleErrors);
        }

        $chosen = [];
        foreach ((array) $request->input('destinations', []) as $id => $row) {
            if (! ($row['on'] ?? false)) {
                continue;
            }

            $keep = [];
            foreach (Retention::RULES as $rule) {
                $value = (float) str_replace(',', '.', (string) ($row['keep'][$rule] ?? 0));
                if ($value > 0) {
                    $keep[$rule] = $rule === 'gigabytes' ? round($value, 1) : (int) $value;
                }
            }

            $chosen[(int) $id] = ['keep' => json_encode($keep)];
        }

        $known = BackupDestination::query()->whereKey(array_keys($chosen))->pluck('id')->all();
        $chosen = array_intersect_key($chosen, array_flip($known));

        if ($chosen === []) {
            $errors['destinations'] = __('Choose at least one storage to keep the backups in.');
        }

        // The form (§67.3). A password typed is checked against its repeat; a
        // blank one keeps the stored one; a folder is never encrypted.
        $format = in_array($request->input('format'), BackupPlan::FORMATS, true) ? (string) $request->input('format') : 'zip';
        $input = (array) $request->input('options', []);
        $options = [
            'db_format' => ($input['db_format'] ?? '') === 'plain' ? 'plain' : 'custom',
            'level' => array_key_exists($input['level'] ?? '', BackupPlan::LEVELS) ? $input['level'] : 'normal',
            'encrypt' => $format !== 'folder' && ! empty($input['encrypt']),
            'env' => false,
            'verify' => in_array($input['verify'] ?? '', ['weekly', 'monthly'], true) ? $input['verify'] : '',
            // The files (PLAN §67.10): only those changed since the last full
            // backup, and without the pictures' thumbnails.
            'changed_only' => in_array('files', $contents, true) && ! empty($input['changed_only']),
            'without_thumbnails' => in_array('files', $contents, true) && ! empty($input['without_thumbnails']),
        ];
        $options['env'] = $options['encrypt'] && ! empty($input['env']);

        $typed = (string) $request->input('password', '');
        if ($options['encrypt']) {
            if ($typed !== '' && $typed !== (string) $request->input('password_confirmation', '')) {
                $errors['password'] = __('The password and its repeat differ.');
            } elseif ($typed !== '' && mb_strlen($typed) < 10) {
                $errors['password'] = __('The password is too short: at least 10 characters — it is all that stands between the backup and whoever finds it.');
            } elseif ($typed === '' && $plan->password() === '') {
                $errors['password'] = __('Type the password the backups are encrypted with.');
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $plan->format = $format;
        $plan->options = $options;
        if ($options['encrypt'] && $typed !== '') {
            $plan->putPassword($typed);
        }

        $code = trim((string) $request->input('code')) ?: Str::slug((string) $request->input('name')) ?: 'plan';
        $base = $code;
        for ($i = 2; BackupPlan::query()->where('code', $code)->whereKeyNot($plan->id)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        $plan->fill([
            'name' => trim((string) $request->input('name')),
            'code' => $code,
            'enabled' => $request->boolean('enabled'),
            'contents' => $contents,
            'schedule' => $schedule,
            'name_template' => $template,
        ])->save();

        $plan->destinations()->sync($chosen);
    }

    protected function saved(Request $request, BackupPlan $plan)
    {
        return $request->input('after_save') === 'close'
            ? redirect()->route('admin.system.backup-plans.index')->with('status', __('Plan saved.'))
            : redirect()->route('admin.system.backup-plans.edit', $plan)->with('status', __('Plan saved.'));
    }
}
