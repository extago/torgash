<?php

namespace Torgash\Enquiries\Automations;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Models\RuleCondition;
use Torgash\Automations\Subjects\BaseSubject;
use Torgash\Enquiries\Kinds;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Status;

/**
 * An enquiry, as something a rule can be about — "a callback arrived → tell
 * the manager's Telegram", "nobody has answered for two hours → tell the
 * owner".
 *
 * Here, in the module that owns enquiries, because whoever owns the data says
 * what can be asked of it; `torgash.automations` names none of this. The
 * dependency points one way and is soft: this class is only loaded when that
 * module asks through `automations.subjects`, so a shop without automations
 * never autoloads it.
 *
 * The events are hooks this module announces anyway: an enquiry arriving, the
 * customer writing again, a status changing (announced by the model, so every
 * door moves it the same way), and one that exists for exactly this — waiting
 * longer than the shop promised (`Overdue`, swept on a schedule).
 */
class EnquirySubject extends BaseSubject
{
    public static function label(): string
    {
        return 'Enquiry';
    }

    public function triggers(): array
    {
        return [
            'enquiries.enquiry.created' => 'An enquiry arrives',
            'enquiries.enquiry.customer_replied' => 'The customer writes again',
            'enquiries.enquiry.status_changed' => 'An enquiry status changes',
            'enquiries.enquiry.overdue' => 'An enquiry waits for an answer too long',
        ];
    }

    public function listen(Closure $fire): void
    {
        foreach (array_keys($this->triggers()) as $event) {
            hook_listen($event, function (array $context) use ($fire, $event) {
                if (($context['enquiry'] ?? null) instanceof Enquiry) {
                    $fire($event, $context['enquiry'], $event === 'enquiries.enquiry.status_changed' ? ['status'] : []);
                }
            });
        }
    }

    public function find(int $id): ?Model
    {
        return Enquiry::with(['manager', 'lastMessage'])->find($id);
    }

    public function sample(): ?Model
    {
        return Enquiry::latest('id')->first();
    }

    public function context(Model $model, string $event, array $changed = []): array
    {
        /** @var Enquiry $model */
        $model->loadMissing(['manager', 'lastMessage']);
        $subject = $model->subject();
        $since = $model->waitingSince();

        return [
            'event' => $event,
            'changed' => implode(',', $changed),
            'shop' => [
                'name' => (string) config('app.name'),
                'url' => (string) config('app.url'),
            ],
            'enquiry' => [
                'id' => $model->id,
                'number' => '#'.$model->id,
                'kind' => (string) $model->kind,
                'kind_label' => $model->kindLabel(),
                'status' => (string) $model->status,
                'status_label' => Status::label((string) $model->status),
                'name' => (string) $model->name,
                'phone' => (string) $model->phone,
                'email' => (string) $model->email,
                // What they typed, minus the contacts printed on their own —
                // one "Label: value" per line, in the words they were asked in.
                'text' => collect($model->answers())
                    ->reject(fn (array $answer) => in_array($answer['key'], ['name', 'phone', 'email'], true))
                    ->map(fn (array $answer) => $answer['label'].': '.$answer['value'])
                    ->implode("\n"),
                'last_message' => (string) ($model->lastMessage?->body ?? ''),
                'about' => (string) ($subject['label'] ?? ''),
                'about_url' => (string) ($subject['url'] ?? ''),
                'page_url' => (string) $model->page_url,
                'manager' => (string) ($model->manager?->name ?? ''),
                'admin_url' => route('admin.enquiries.show', $model),
                'created_at' => $model->created_at?->format('d.m.Y H:i') ?? '',
                'waiting' => $since ? $since->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE, true) : '',
                'locale' => (string) $model->locale,
            ],
        ];
    }

    public function variables(): array
    {
        return [
            'Enquiry' => [
                'enquiry.number' => 'Number, "#12"',
                'enquiry.kind_label' => 'Kind, by its name',
                'enquiry.status_label' => 'Status, by its name',
                'enquiry.admin_url' => 'Link to the enquiry in the admin',
                'enquiry.about' => 'What it is about (a product, an order)',
                'enquiry.about_url' => 'Link to what it is about',
                'enquiry.created_at' => 'When it arrived',
                'enquiry.waiting' => 'How long they have waited',
                'enquiry.manager' => 'Manager',
                'enquiry.page_url' => 'The page they asked from',
            ],
            'Customer' => [
                'enquiry.name' => 'Customer name',
                'enquiry.phone' => 'Phone',
                'enquiry.email' => 'Email',
            ],
            'What they wrote' => [
                'enquiry.text' => 'The enquiry itself, line by line',
                'enquiry.last_message' => 'The last message',
            ],
            'Technical' => [
                'enquiry.id' => 'Enquiry id',
                'enquiry.kind' => 'Kind code',
                'enquiry.status' => 'Status code',
                'enquiry.locale' => 'Language',
                'event' => 'What triggered the rule',
                'shop.name' => 'Shop name',
                'shop.url' => 'Shop address',
            ],
        ];
    }

    public function conditions(): array
    {
        return [
            'kind' => [
                'label' => 'Kind of enquiry',
                'operators' => ['in' => 'is in', 'not_in' => 'is not in'],
                'input' => 'list',
                'options' => collect(Kinds::all())->mapWithKeys(fn ($kind, $key) => [$key => Kinds::label($key)])->all(),
            ],
            'status' => [
                'label' => 'Enquiry status',
                'operators' => ['in' => 'is in', 'not_in' => 'is not in'],
                'input' => 'list',
                'options' => Status::options(),
            ],
            'contact' => [
                'label' => 'Contact left',
                'operators' => [
                    'has_phone' => 'a phone number',
                    'has_email' => 'an email',
                    'phone_only' => 'only a phone number',
                    'has_account' => 'asked from an account',
                ],
                'input' => 'none',
            ],
            'manager' => [
                'label' => 'Manager',
                'operators' => ['manager_any' => 'somebody is assigned', 'manager_none' => 'nobody is assigned'],
                'input' => 'none',
            ],
            'text' => [
                'label' => 'What they wrote',
                'operators' => ['contains' => 'contains'],
                'input' => 'text',
            ],
        ];
    }

    public function passes(RuleCondition $condition, Model $model): bool
    {
        /** @var Enquiry $model */
        $list = $condition->list();
        $phone = trim((string) $model->phone) !== '';
        $email = trim((string) $model->email) !== '';

        return match ($condition->kind.'.'.$condition->operator) {
            'kind.in' => in_array((string) $model->kind, $list, true),
            'kind.not_in' => ! in_array((string) $model->kind, $list, true),
            'status.in' => in_array((string) $model->status, $list, true),
            'status.not_in' => ! in_array((string) $model->status, $list, true),
            'contact.has_phone' => $phone,
            'contact.has_email' => $email,
            'contact.phone_only' => $phone && ! $email && $model->user_id === null,
            'contact.has_account' => $model->user_id !== null,
            'manager.manager_any' => $model->manager_id !== null,
            'manager.manager_none' => $model->manager_id === null,
            'text.contains' => ($needle = mb_strtolower(trim($condition->text()))) !== ''
                && str_contains(mb_strtolower(collect($model->answers())->pluck('value')->implode(' ').' '.$model->lastMessage?->body), $needle),
            // An operator nobody implements must not silently pass.
            default => false,
        };
    }

    public function refs(array $ids): array
    {
        return Enquiry::whereIn('id', $ids)->get(['id', 'kind'])
            ->mapWithKeys(fn (Enquiry $enquiry) => [$enquiry->id => [
                'label' => '#'.$enquiry->id.' · '.$enquiry->kindLabel(),
                'url' => route('admin.enquiries.show', $enquiry),
            ]])->all();
    }
}
