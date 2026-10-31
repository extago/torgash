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
use Torgash\Automations\Models\RuleAction;
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
 *
 * A receiver that could not be reached — the host down, the connection
 * dropped on the way to api.telegram.org, a "try later" (429) or an error
 * of its own (5xx) — is tried again, and ONLY that action: an action
 * answers `retry => true` (Action::run), and this job queues itself again
 * for that one action after the shop's interval, up to the shop's count
 * (settings `retries` / `retry_minutes`), each attempt a row of the log.
 * The rest of the rule is never run twice: a status already set would be
 * set again, a webhook already taken would be sent again. For the same
 * reason the queue's own retry is off (`$tries = 1`): it would run the
 * whole rule again, the delivered actions included.
 */
class RunRule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The queue never re-runs the whole rule; a receiver not reached is retried per action (below). */
    public int $tries = 1;

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
        /** Set on a retry: this one action, nothing else of the rule. */
        public ?int $onlyActionId = null,
        /** 1 on the first run; a retry carries the number it is. */
        public int $attempt = 1,
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

                if (! $class || ($this->onlyActionId !== null && (int) $action->id !== $this->onlyActionId)) {
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
                $detail = ($result['detail'] ?? []) + ($this->attempt > 1 ? ['attempt' => $this->attempt] : []);

                if (! empty($result['retry']) && $this->attempt <= self::retries()) {
                    $this->retryLater($rule, $model, $action, (string) $result['summary'], $detail);

                    continue;
                }

                $this->log($rule, $model, $result['status'], (string) $result['summary'], $detail);
            }

            $subject->afterActions();
        });
    }

    /** How many times an action the receiver did not take is tried again (0: never). */
    public static function retries(): int
    {
        return max(0, min(20, (int) setting('torgash.automations', 'retries')));
    }

    /** Minutes between one attempt and the next. */
    public static function retryMinutes(): int
    {
        return max(1, (int) setting('torgash.automations', 'retry_minutes'));
    }

    /**
     * This one action again, after the interval: a row in the log saying so,
     * and a copy of this job for that action alone with the attempt counted.
     *
     * @param  array<string, mixed>  $detail
     */
    protected function retryLater(Rule $rule, Model $model, RuleAction $action, string $why, array $detail): void
    {
        $minutes = self::retryMinutes();
        $total = self::retries() + 1;

        $this->log($rule, $model, RuleRun::RETRY, __('Attempt :attempt of :total, next in :minutes min: :why', [
            'attempt' => $this->attempt, 'total' => $total, 'minutes' => $minutes, 'why' => $why,
        ]), $detail + ['attempt' => $this->attempt, 'next_attempt_at' => now()->addMinutes($minutes)->toIso8601String()]);

        dispatch((new self(
            $this->ruleId, $this->subjectId, $this->event, $this->changed, $this->idempotencyKey, $this->subject,
            onlyActionId: (int) $action->id, attempt: $this->attempt + 1,
        ))->delay(now()->addMinutes($minutes)));
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
