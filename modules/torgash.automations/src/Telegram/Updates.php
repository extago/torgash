<?php

namespace Torgash\Automations\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Subjects\Subjects;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;

/**
 * What a bot is told (PLAN §64.1) — the same whether we asked Telegram
 * (`automations:telegram-poll`) or it called us (the webhook).
 *
 * Three things are heard and nothing else:
 *
 * - `/link 483920` (or the bot opened by its link, which sends `/start
 *   483920`) — a staff member tying their Telegram account to their shop
 *   account with the code the admin showed them;
 * - `/id` — "which chat is this?", for the rule form's Chat id;
 * - a button pressed under a message a rule sent.
 *
 * **A press is a PERSON's act, not a rule's.** Whoever pressed is found by
 * their Telegram account, must be staff, and must have the right the admin
 * would ask of them for the same thing — checked on every press, so a right
 * taken away in the admin is gone here too. The action then runs as that
 * person through the very class a rule's action is, the history says "Ivan
 * (via Telegram)", and — a person having acted — rules that wait for that
 * change DO fire, as they would after a click in the admin.
 */
class Updates
{
    public const LINK_MINUTES = 15;

    /** A code to send to the bot, good for a quarter of an hour. */
    public static function linkCode(User $user): string
    {
        $code = (string) random_int(100000, 999999);
        Cache::put('automations:tg-link:'.$code, $user->id, now()->addMinutes(self::LINK_MINUTES));

        return $code;
    }

    /** @param  array<string, mixed>  $update */
    public function handle(Bot $bot, array $update): void
    {
        if (is_array($update['callback_query'] ?? null)) {
            $this->pressed($bot, $update['callback_query']);
        } elseif (is_array($update['message'] ?? null)) {
            $this->said($bot, $update['message']);
        }

        $bot->forceFill(['heard_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $message */
    protected function said(Bot $bot, array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $chat = (string) ($message['chat']['id'] ?? '');

        if ($chat === '' || ! preg_match('~^/(start|link|id)(?:@\w+)?(?:\s+(\S+))?$~u', $text, $m)) {
            return;
        }

        if ($m[1] === 'id') {
            $this->reply($bot, $chat, __('Chat id: :id', ['id' => $chat]));

            return;
        }

        $code = preg_replace('/\D/', '', (string) ($m[2] ?? ''));
        $from = $message['from'] ?? [];

        if ($code === '' || empty($from['id'])) {
            $m[1] === 'link' ? $this->reply($bot, $chat, __('Send the code from the admin: Automations → Telegram.')) : null;

            return;
        }

        $user = User::find((int) Cache::pull('automations:tg-link:'.$code));

        if (! $user || ! $user->isStaff()) {
            $this->reply($bot, $chat, __('This code is not known or has run out. Take a new one in the admin: Automations → Telegram.'));

            return;
        }

        DB::transaction(function () use ($user, $from) {
            // One Telegram account is one person: a link it had goes.
            DB::table('m_automations_telegram_users')->where('telegram_id', (int) $from['id'])->orWhere('user_id', $user->id)->delete();
            DB::table('m_automations_telegram_users')->insert([
                'user_id' => $user->id,
                'telegram_id' => (int) $from['id'],
                'telegram_name' => mb_substr(trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: (string) ($from['username'] ?? ''), 0, 190),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->reply($bot, $chat, __('Linked: buttons you press here are pressed by :name.', ['name' => $user->name]));
    }

    /** @param  array<string, mixed>  $query */
    protected function pressed(Bot $bot, array $query): void
    {
        $id = (string) ($query['id'] ?? '');
        $alert = fn (string $text) => $this->answer($bot, $id, $text, true);

        if (! preg_match('/^ta:(\d+):(\d+)$/', (string) ($query['data'] ?? ''), $m)) {
            return;
        }

        $message = Message::where('bot_id', $bot->id)->find((int) $m[1]);
        $button = $message?->buttons[(int) $m[2]] ?? null;

        if (! $message || ! is_array($button)) {
            $alert(__('This button is no longer known.'));

            return;
        }

        $user = User::whereIn('id', DB::table('m_automations_telegram_users')->where('telegram_id', (int) ($query['from']['id'] ?? 0))->select('user_id'))->first();

        if (! $user || ! $user->isStaff()) {
            $alert(__('Your Telegram is not linked to a staff account. In the admin: Automations → Telegram.'));

            return;
        }

        $subject = Subjects::get((string) $message->subject);
        $model = $subject?->find((int) $message->subject_id);
        $permission = $subject && method_exists($subject, 'pressPermission') ? $subject->pressPermission() : 'automations.manage';

        if (! $subject || ! $model) {
            $alert(__('What this message was about is no longer there.'));

            return;
        }

        if (! $user->can($permission)) {
            $alert(__('You have no right to do this.'));

            return;
        }

        // Pressed once: whoever is first has it, the rest are told who.
        $claimed = Message::whereKey($message->id)->whereNull('pressed')
            ->update(['pressed' => (int) $m[2], 'pressed_by' => $user->id, 'pressed_at' => now()]);

        if (! $claimed) {
            $by = User::find((int) $message->fresh()->pressed_by)?->name;
            $alert(__('Already done by :name.', ['name' => $by ?: '—']));

            return;
        }

        $via = __(':name (via Telegram)', ['name' => $user->name]);
        $result = $this->act((string) $message->subject, $subject, $model, $button, $user, $via);

        RuleRun::create([
            'rule_id' => Rule::whereKey($message->rule_id)->value('id'),
            'rule_name' => (string) ($message->rule_name ?: __('Telegram button')),
            'subject' => $message->subject,
            'subject_id' => $message->subject_id,
            'order_id' => $message->subject === 'order' ? $message->subject_id : null,
            'status' => $result['status'],
            'summary' => mb_substr($via.' — «'.$button['label'].'»: '.$result['summary'], 0, 250),
            'detail' => ['button' => $button['label'], 'user_id' => $user->id],
            'created_at' => now(),
        ]);

        if ($result['status'] === RuleRun::FAILED) {
            // Nothing was done: the buttons stay for another try.
            Message::whereKey($message->id)->update(['pressed' => null, 'pressed_by' => null, 'pressed_at' => null]);
            $alert($result['summary']);

            return;
        }

        $this->answer($bot, $id, $result['summary']);

        try {
            $bot->call('editMessageText', [
                'chat_id' => $message->chat_id,
                'message_id' => $message->message_id,
                'text' => mb_substr($message->text."\n\n✅ <b>".e($button['label']).'</b> — '.e($user->name), 0, 4096),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            // The act is done and written down; a message left with its
            // buttons is the smaller failure — pressing again answers "already".
            logger()->warning('Telegram: the pressed message could not be edited', ['error' => $bot->mask($e->getMessage())]);
        }
    }

    /**
     * Do what the button says, as the person who pressed.
     *
     * @param  array{label: string, do: string}  $button
     * @return array{status: string, summary: string}
     */
    protected function act(string $subjectKey, object $subject, \Illuminate\Database\Eloquent\Model $model, array $button, User $user, string $via): array
    {
        $do = Buttons::parse((string) $button['do']);
        $was = Auth::user();
        Auth::setUser($user);

        try {
            if ($do['type'] === 'note') {
                $written = $model instanceof Order
                    ? (bool) OrderLog::record($model, ':rule: :text', ['rule' => $via, 'text' => $button['label']], by: $user->id)
                    : (bool) hook_filter('automations.note', false, ['subject' => $model, 'text' => $button['label'], 'rule' => $via]);

                return $written
                    ? ['status' => RuleRun::SENT, 'summary' => __('Written into the history.')]
                    : ['status' => RuleRun::FAILED, 'summary' => __('Nothing here keeps notes.')];
            }

            $class = RunRule::actionsFor($subjectKey)[$do['type']] ?? null;

            if (! $class) {
                return ['status' => RuleRun::FAILED, 'summary' => __('This button is no longer known.')];
            }

            $action = new RuleAction(['type' => $do['type'], 'config' => [
                $do['field'] => $do['value'] === Buttons::ME ? (string) $user->id : $do['value'],
            ]]);
            $action->setRelation('rule', new Rule(['name' => $via]));

            $context = $subject->context($model, 'automations.telegram.button');
            $context['actor'] = ['kind' => 'staff', 'name' => (string) $user->name];

            $result = app($class)->run($action, $model, $context, 'press-'.$model->getKey().'-'.uniqid());
            // Announced now, with nothing holding the rules back: a person acted.
            $subject->afterActions();

            return [
                'status' => $result['status'] === RuleRun::FAILED ? RuleRun::FAILED : RuleRun::SENT,
                'summary' => $result['status'] === RuleRun::SKIPPED ? __('Nothing to change: :why', ['why' => (string) $result['summary']]) : (string) $result['summary'],
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['status' => RuleRun::FAILED, 'summary' => __('It did not work — see the shop\'s log.')];
        } finally {
            $was ? Auth::setUser($was) : (method_exists(Auth::guard(), 'forgetUser') ? Auth::guard()->forgetUser() : null);
        }
    }

    protected function reply(Bot $bot, string $chat, string $text): void
    {
        try {
            $bot->call('sendMessage', ['chat_id' => $chat, 'text' => $text]);
        } catch (\Throwable $e) {
            logger()->warning('Telegram: a reply could not be sent', ['error' => $bot->mask($e->getMessage())]);
        }
    }

    protected function answer(Bot $bot, string $queryId, string $text, bool $alert = false): void
    {
        try {
            $bot->call('answerCallbackQuery', ['callback_query_id' => $queryId, 'text' => mb_substr($text, 0, 190), 'show_alert' => $alert]);
        } catch (\Throwable $e) {
            logger()->warning('Telegram: a press could not be answered', ['error' => $bot->mask($e->getMessage())]);
        }
    }
}
