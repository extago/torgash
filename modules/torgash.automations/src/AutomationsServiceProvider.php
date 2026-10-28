<?php

namespace Torgash\Automations;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Subjects\Subjects;

/**
 * Listens to what the shop announces and queues the rules that asked for it.
 *
 * Everything this module does to the rest of the shop happens through hooks it
 * subscribes to, so uninstalling it takes the rules with it and changes nothing
 * else.
 */
class AutomationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton, not a static: a guard stuck "on" past the end of a request
        // would silently stop every rule in the shop.
        $this->app->singleton(RuleGuard::class);
    }

    public function boot(): void
    {
        // Rules and their runs are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_automations_rules', 'm_automations_conditions', 'm_automations_actions', 'm_automations_runs', 'm_automations_code_channels']);

        $this->triggers();
        $this->schedule();

        // What must be true about the rules, for whoever is watching. A rule is
        // meant to be silent, so a broken one looks exactly like an idle one —
        // which is precisely what a statement is for. Nobody fires this filter
        // when `torgash.guard` is absent.
        // The run log IS a log — see the guard's ignored tables.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_automations_runs']);

        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\AutomationInvariants)->register());

        // Codes by an HTTP request the shop composed — an SMS gateway, a
        // messenger — offered to core's verification in the admin's order.
        // Only switched-on rows; a row is asked again on every request, so a
        // channel switched off stops at once.
        hook_listen('core.verification.channels', fn (array $all) => $all + Models\CodeChannel::where('enabled', true)
            ->orderBy('position')->orderBy('id')->get()
            ->mapWithKeys(fn (Models\CodeChannel $row) => [$row->key() => new Codes\HttpCodeChannel($row)])
            ->all());

        Route::middleware(['web', 'auth', 'can:automations.manage'])
            ->prefix('admin/automations')
            ->name('admin.automations.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }

    /**
     * Every subject's events, each handed to the same dispatcher.
     *
     * Asked AFTER every provider has booted: a subject is registered through
     * `automations.subjects` by whichever module owns it, and provider boot
     * order is not ours to depend on — at this module's own boot the enquiries
     * module may not have answered yet.
     */
    protected function triggers(): void
    {
        $this->app->booted(function () {
            foreach (Subjects::all() as $key => $subject) {
                $subject->listen(function (string $event, Model $model, array $changed = []) use ($key) {
                    $this->fire($key, $event, $model, $changed);
                });
            }
        });
    }

    /**
     * Queue every enabled rule that wants this event.
     *
     * The idempotency key is minted HERE, once per trigger, and carried into the
     * job: every retry of the same trigger then presents the same key, which is
     * what stops a receiver creating a second deal when the queue redelivers.
     *
     * @param  list<string>  $changed
     */
    protected function fire(string $subject, string $event, Model $model, array $changed = []): void
    {
        // A rule's own changes do not trigger rules — see RuleGuard for why
        // chaining is refused rather than depth-limited.
        if ($this->app->make(RuleGuard::class)->running()) {
            return;
        }

        $rules = Rule::where('event', $event)->where('enabled', true)->orderBy('position')->get();

        foreach ($rules as $rule) {
            if (! $rule->wantsFields($changed)) {
                continue;
            }

            $job = new RunRule($rule->id, (int) $model->getKey(), $event, $changed, $rule->id.'-'.Str::uuid(), $subject);

            // "Wait N minutes, then look again" — the delay is the queue's, so
            // nothing here sleeps and the admin's click never waits on a rule.
            $rule->delay_minutes > 0
                ? dispatch($job->delay(now()->addMinutes($rule->delay_minutes)))
                : dispatch($job);
        }
    }

    /**
     * The run log is the only place "did it actually arrive?" is answered, and
     * it grows with every order. Swept nightly, keeping what the setting says.
     */
    protected function schedule(): void
    {
        hook_listen('core.schedule', function (array $context) {
            /** @var Schedule $schedule */
            $schedule = $context['schedule'];

            $schedule->call(function () {
                $days = max(1, (int) setting('torgash.automations', 'keep_runs_days'));

                RuleRun::where('created_at', '<', now()->subDays($days))->delete();
            })->daily()->name('automations:prune-runs')->withoutOverlapping();
        });
    }
}
