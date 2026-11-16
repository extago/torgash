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
        // One act's notes about the shelf (ProductWatch), emptied when it ends.
        $this->app->singleton(ProductWatch::class);
    }

    public function boot(): void
    {
        // Rules and their runs are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_automations_rules', 'm_automations_conditions', 'm_automations_actions', 'm_automations_runs', 'm_automations_code_channels', 'm_automations_product_batches', 'm_automations_product_marks',
            'm_automations_telegram_bots', 'm_automations_telegram_users', 'm_automations_telegram_messages']);

        $this->triggers();
        $this->products();
        $this->schedule();

        // What must be true about the rules, for whoever is watching. A rule is
        // meant to be silent, so a broken one looks exactly like an idle one —
        // which is precisely what a statement is for. Nobody fires this filter
        // when `torgash.guard` is absent.
        // The run log IS a log — see the guard's ignored tables.
        hook_listen('guard.audit.ignored_tables', fn (array $tables) => [...$tables, 'm_automations_runs', 'm_automations_product_batches', 'm_automations_product_marks', 'm_automations_telegram_messages']);
        // The letter a rule writes to a customer (EmailAction).
        hook_listen('core.mail.events', fn (array $events) => $events + Actions\EmailAction::event());

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

        $this->telegram();

        // A staff member's Telegram account, tied to theirs for the buttons
        // (PLAN §64.1) — theirs to see and to have erased (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => $context['registry']->register('automations.telegram', [
            'label' => 'Telegram account linked for buttons',
            'export' => fn (\App\Core\Privacy\Subject $s) => $s->user
                ? \Illuminate\Support\Facades\DB::table('m_automations_telegram_users')->where('user_id', $s->user->id)
                    ->get(['telegram_id', 'telegram_name', 'created_at'])->map(fn ($row) => (array) $row)->all()
                : [],
            'erase' => fn (\App\Core\Privacy\Subject $s) => $s->user
                ? \Illuminate\Support\Facades\DB::table('m_automations_telegram_users')->where('user_id', $s->user->id)->delete()
                : 0,
        ]));
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
                $subject->listen(function (string $event, Model $model, array $changed = [], array $before = []) use ($key) {
                    $this->fire($key, $event, $model, $changed, $before);
                });
            }
        });
    }

    /**
     * Buttons under a Telegram message (PLAN §64.1): the screen where a staff
     * member links THEIR Telegram — open to every staff member, since it is
     * their own account they tie, not a rule they edit; Telegram's own call
     * into the shop, outside `web` (no session, no CSRF — the secret header
     * is the proof); and the asking, every minute, for a shop Telegram cannot
     * reach.
     */
    protected function telegram(): void
    {
        Route::middleware(['web', 'auth', 'can:admin.access'])->prefix('admin/automations/telegram')->name('admin.automations.telegram.')->group(function () {
            Route::get('/', [Http\Admin\TelegramController::class, 'index'])->name('index');
            Route::post('code', [Http\Admin\TelegramController::class, 'code'])->name('code');
            Route::delete('links/{user}', [Http\Admin\TelegramController::class, 'unlink'])->name('unlink')->whereNumber('user');
            Route::post('bots/{bot}/mode', [Http\Admin\TelegramController::class, 'mode'])->name('mode')
                ->whereNumber('bot')->middleware('can:automations.manage');
        });

        Route::post('automations/telegram/{bot}', Http\TelegramWebhookController::class)
            ->name('automations.telegram.webhook')->where('bot', '[a-f0-9]{64}');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\TelegramPollCommand::class]);
        }

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->command('automations:telegram-poll')
                ->everyMinute()->name('automations:telegram-poll')->withoutOverlapping(2)->runInBackground();
        });

        // A message's buttons are of no use once the log that names it is gone.
        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => Telegram\Message::where('created_at', '<', now()->subDays(max(30, (int) setting('torgash.automations', 'keep_runs_days'))))->delete())
                ->daily()->name('automations:prune-telegram-messages');
        });
    }

    /**
     * The shelf (PLAN §64.2): every movement of stock and every change of a
     * price is noted as it happens and looked at ONCE, when the request or
     * the job is over — {@see ProductWatch}. The listeners resolve the watch
     * when they are called, never at boot: it holds one act's notes.
     */
    protected function products(): void
    {
        hook_listen('catalog.stock.moved', fn (array $move) => $this->app->make(ProductWatch::class)
            ->moved((int) $move['product_id'], (int) $move['delta']));

        hook_listen('catalog.product.created', fn (array $context) => $this->app->make(ProductWatch::class)
            ->born((int) $context['product']->id));

        hook_listen('catalog.product.price_changed', fn (array $context) => $this->app->make(ProductWatch::class)
            ->repriced((int) $context['product']->id, (int) $context['from'], (int) $context['to']));

        $flush = function () {
            // A listener that fails must not take the request or the job with
            // it: the stock is written and correct, a missed message is the
            // smaller failure — and goes to the log.
            try {
                $this->app->make(ProductWatch::class)->flush();
            } catch (\Throwable $e) {
                report($e);
            }
        };

        $this->app->terminating($flush);
        \Illuminate\Support\Facades\Event::listen([
            \Illuminate\Queue\Events\JobProcessed::class,
            \Illuminate\Queue\Events\JobFailed::class,
            \Illuminate\Console\Events\CommandFinished::class,
        ], $flush);
    }

    /**
     * Queue every enabled rule that wants this event.
     *
     * The idempotency key is minted HERE, once per trigger, and carried into the
     * job: every retry of the same trigger then presents the same key, which is
     * what stops a receiver creating a second deal when the queue redelivers.
     *
     * @param  list<string>  $changed
     * @param  array<string, mixed>  $before  what the changed fields were, in the subject's words
     */
    protected function fire(string $subject, string $event, Model $model, array $changed = [], array $before = []): void
    {
        // A rule's own changes do not trigger rules — see RuleGuard for why
        // chaining is refused rather than depth-limited.
        if ($this->app->make(RuleGuard::class)->running()) {
            return;
        }

        $rules = Rule::where('event', $event)->where('enabled', true)->orderBy('position')->get();
        $moment = ['before' => $before, 'actor' => \Torgash\Automations\Actor::now()];

        foreach ($rules as $rule) {
            if (! $rule->wantsFields($changed)) {
                continue;
            }

            $job = new RunRule($rule->id, (int) $model->getKey(), $event, $changed, $rule->id.'-'.Str::uuid(), $subject, moment: $moment);

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
                // What the product rules were about goes with the log that names it.
                Models\ProductBatch::where('created_at', '<', now()->subDays($days))->delete();
            })->daily()->name('automations:prune-runs')->withoutOverlapping();
        });
    }
}
