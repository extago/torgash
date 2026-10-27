<?php

namespace Torgash\Automations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Torgash\Automations\Actions\Action;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\RuleGuard;
use Torgash\Automations\Subjects\Subject;
use Torgash\Automations\Subjects\Subjects;

/**
 * One rule, against one subject (an order, an enquiry), at the moment it is
 * actually due.
 *
 * Not a TrackedJob: those exist to show progress on work that takes minutes,
 * and a webhook takes a second. A rule that ran leaves a row in the module's own
 * run log, which says what was sent and what came back — and a rule that blew up
 * still lands in failed_jobs, which the Background tasks screen already shows.
 *
 * Ids are carried rather than model instances so a rule edited (or an order
 * changed) during the delay is read as it is NOW, not as it was when queued.
 */
class RunRule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds. A receiver that is briefly down should not lose the event. */
    public array $backoff = [60, 300];

    /**
     * @param  int  $subjectId  the order's id, or the enquiry's — see `$subject`
     * @param  list<string>  $changed
     */
    public function __construct(
        public int $ruleId,
        public int $subjectId,
        public string $event,
        public array $changed,
        public string $idempotencyKey,
        public string $subject = 'order',
    ) {}

    public function handle(): void
    {
        $rule = Rule::with(['conditions', 'actions'])->find($this->ruleId);
        $subject = Subjects::get($this->subject);
        $model = $subject?->find($this->subjectId);

        // All ordinary outcomes after a delay: a rule can be turned off or
        // deleted, an order can be gone, a module can have been uninstalled.
        // None is a failure to retry.
        if (! $rule || ! $rule->enabled || ! $subject || ! $model) {
            return;
        }

        // Conditions are checked NOW, not when the rule was queued. Half an hour
        // is long enough for the order to have been cancelled, and firing "still
        // unpaid → chase the customer" at an order that was paid ten minutes ago
        // is the one behaviour nobody would forgive.
        foreach ($rule->conditions as $condition) {
            if (! $subject->passes($condition, $model)) {
                $this->log($rule, $model, RuleRun::SKIPPED, __('Conditions no longer held'));

                return;
            }
        }

        $context = $subject->context($model, $this->event, $this->changed);
        $registry = self::actions();

        app(RuleGuard::class)->while(function () use ($rule, $subject, $model, $context, $registry) {
            foreach ($rule->actions as $action) {
                $class = $registry[$action->type] ?? null;

                if (! $class) {
                    continue;
                }

                // An action written for another subject (an order's status on
                // an enquiry rule) is refused out loud rather than guessed at.
                if ($class::subjects() !== [] && ! in_array($this->subject, $class::subjects(), true)) {
                    $this->log($rule, $model, RuleRun::FAILED, __('":action" cannot act on this.', ['action' => __($class::label())]));

                    continue;
                }

                /** @var Action $runner */
                $runner = app($class);
                $result = $runner->run($action, $model, $context, $this->idempotencyKey);

                $this->log($rule, $model, $result['status'], (string) $result['summary'], $result['detail']);
            }

            $subject->afterActions();
        });
    }

    /** @param  array<string, mixed>|null  $detail */
    protected function log(Rule $rule, Model $model, string $status, string $summary, ?array $detail = null): void
    {
        RuleRun::create([
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'subject' => $this->subject,
            'subject_id' => $model->getKey(),
            // Kept for order runs: older rows and their readers know this column.
            'order_id' => $this->subject === 'order' ? $model->getKey() : null,
            'status' => $status,
            'summary' => mb_substr($summary, 0, 250),
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }

    /**
     * type => class. A module adds its own action here and inherits the trigger,
     * the delay, the conditions, the retries and the log.
     *
     * @return array<string, class-string<Action>>
     */
    public static function actions(): array
    {
        return hook_filter('automations.actions', [
            'webhook' => \Torgash\Automations\Actions\WebhookAction::class,
            'telegram' => \Torgash\Automations\Actions\TelegramAction::class,
            'set_status' => \Torgash\Automations\Actions\SetStatusAction::class,
            'set_manager' => \Torgash\Automations\Actions\SetManagerAction::class,
        ]);
    }

    /**
     * The actions a rule about this subject may use, for the picker.
     *
     * @return array<string, class-string<Action>>
     */
    public static function actionsFor(string $subject): array
    {
        return array_filter(self::actions(), fn (string $class) => $class::subjects() === [] || in_array($subject, $class::subjects(), true));
    }

    /** @return Subject|null */
    public function subjectInstance(): ?Subject
    {
        return Subjects::get($this->subject);
    }
}
