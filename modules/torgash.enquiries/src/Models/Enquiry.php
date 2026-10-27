<?php

namespace Torgash\Enquiries\Models;

use App\Models\Storefront;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Enquiries\Kinds;
use Torgash\Enquiries\Status;
use Torgash\Media\Gallery;

/**
 * One thing somebody asked the shop.
 *
 * @property string $kind
 * @property string $status
 * @property array|null $fields {"values": {...}, "labels": {...}}
 * @property array|null $cart
 */
class Enquiry extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'm_enquiries_enquiries';

    protected $guarded = [];

    protected $attributes = [
        'status' => Status::NEW,
    ];

    /**
     * Thumbnails for what people attach, from the media module's own settings —
     * the same sizes a product photo gets, because a photograph is a photograph.
     * Registered on both halves of a conversation: the enquiry carries what came
     * with the form, a message what came with a reply.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        Gallery::conversions($this, $media);
    }

    /**
     * A status change is announced by the MODEL, so every door that moves it —
     * the inbox's select, the list's in-place badge, a bulk action, the API, a
     * rule — announces it the same way (`enquiries.enquiry.status_changed`),
     * which is what an automation listens to. Only on a real change.
     */
    protected static function booted(): void
    {
        static::updated(function (Enquiry $enquiry) {
            if ($enquiry->wasChanged('status')) {
                hook_action('enquiries.enquiry.status_changed', [
                    'enquiry' => $enquiry,
                    'from' => (string) $enquiry->getOriginal('status'),
                    'to' => (string) $enquiry->status,
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'cart' => 'array',
            'answered_at' => 'datetime',
            'customer_seen_at' => 'datetime',
            'locked_at' => 'datetime',
            'overdue_announced_at' => 'datetime',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    /**
     * The conversation, oldest first.
     *
     * The enquiry itself is the opening of it — what the person typed is stored
     * here, in `fields`, and is not copied into a message row. So an enquiry
     * nobody has answered yet has no messages at all, which is exactly what it
     * means.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * The last turn, in one query rather than one per row — a list of threads
     * asks every one of them who spoke last.
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', Status::OPEN);
    }

    /**
     * The queue: open, and the shop owes the next word — nothing said yet, or
     * the customer spoke last. One definition for the bell, the inbox's tab and
     * the API's `awaiting_reply`, which used to be three copies of it.
     */
    public function scopeAwaitingReply(Builder $query): Builder
    {
        return $query->open()->where(fn (Builder $w) => $w
            ->whereDoesntHave('messages')
            ->orWhereHas('lastMessage', fn (Builder $m) => $m->where('is_staff', false)));
    }

    /** The same question about one row, read off an eager-loaded `lastMessage`. */
    public function isAwaitingReply(): bool
    {
        return Status::isOpen($this->status) && ! $this->lastMessage?->is_staff;
    }

    /**
     * Since when the customer has been waiting for the shop — their last word,
     * or the enquiry itself when nobody has said anything yet. Null when the
     * shop is not the one who owes an answer.
     */
    public function waitingSince(): ?\Illuminate\Support\Carbon
    {
        if (! $this->isAwaitingReply()) {
            return null;
        }

        return $this->lastMessage?->created_at ?? $this->created_at;
    }

    /**
     * Past the shop's own promise of an answer (`reply_within_hours`, 0 = no
     * promise, so nothing is ever late).
     */
    public function isOverdue(): bool
    {
        $hours = (int) setting('torgash.enquiries', 'reply_within_hours', 24);
        $since = $this->waitingSince();

        return $hours > 0 && $since !== null && $since->lt(now()->subHours($hours));
    }

    /**
     * Everything else this person has asked. By `customer_ref` — the same
     * reference their orders carry, derived from the strongest identity they
     * gave — or by their account, whichever links more.
     */
    public function scopeSamePersonAs(Builder $query, self $enquiry): Builder
    {
        return $query->whereKeyNot($enquiry->getKey())
            ->where(function (Builder $w) use ($enquiry) {
                $w->whereRaw('false');
                if ($enquiry->customer_ref) {
                    $w->orWhere('customer_ref', $enquiry->customer_ref);
                }
                if ($enquiry->user_id) {
                    $w->orWhere('user_id', $enquiry->user_id);
                }
            });
    }

    /**
     * Threads holding an answer the customer has not read — the count beside
     * "Enquiries" in their account menu.
     *
     * Asked of the LAST message and not of any message, so that it says exactly
     * what hasUnreadReply() says about one row. The looser form ("some staff
     * message is newer than the stamp") counts a thread the customer answered
     * through some other door — the API, a future agent — and a badge that
     * marks nothing when you open the list is worse than no badge.
     */
    public function scopeUnreadByCustomer(Builder $query): Builder
    {
        return $query->whereHas('lastMessage', fn (Builder $m) => $m
            ->where('is_staff', true)
            ->where(fn (Builder $w) => $w
                ->whereNull('m_enquiries_enquiries.customer_seen_at')
                ->orWhereColumn('m_enquiries_messages.created_at', '>', 'm_enquiries_enquiries.customer_seen_at')));
    }

    /**
     * Where this stands for the person who asked — which is a different
     * question from `status`, and the reason it is derived rather than read.
     *
     * The shop answering leaves an enquiry "in progress", because that is what
     * it is to the shop; printing that outward said "waiting for an answer"
     * directly underneath the answer. So: closed if the shop closed it,
     * answered if the shop spoke last (or marked it done without a thread —
     * a callback that was rung back), and waiting otherwise.
     */
    public function customerState(): string
    {
        if ($this->isLocked()) {
            return Status::CUSTOMER_CLOSED;
        }

        $last = $this->lastMessage;

        if ($last?->is_staff || (! $last && $this->status === Status::DONE)) {
            return Status::CUSTOMER_ANSWERED;
        }

        return Status::CUSTOMER_WAITING;
    }

    public function customerLabel(): string
    {
        return Status::customerLabel($this->customerState());
    }

    public function customerBadge(): string
    {
        return Status::customerBadge($this->customerState());
    }

    /**
     * Is this conversation over?
     *
     * Two ways in, one answer out. `locked_at` is the shop saying "resolved,
     * and we are not doing this again" — which is what stops somebody adding to
     * a thread a year later, on top of a context nobody remembers. REJECTED
     * counts as locked without a stamp of its own: "not worth answering" cannot
     * mean anything if the row can be written into anyway.
     *
     * Neither side may write into a locked thread, the shop included. A manager
     * who needs to say one more thing reopens it, deliberately — which is the
     * point: the conversation restarts because somebody decided so, not because
     * somebody typed.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null || $this->status === Status::REJECTED;
    }

    /**
     * Can anything written here reach the person? An email (the answer is
     * mailed, with a signed link back into the thread) or an account (it waits
     * in their cabinet). A callback that left only a phone number has neither:
     * nobody would ever read a "reply", so the page offers a CALL LOG instead —
     * the same rows, written for the shop itself (NotifyRestocked leaves its
     * news there for whoever picks up the phone).
     */
    public function canBeAnswered(): bool
    {
        return trim((string) $this->email) !== '' || $this->user_id !== null;
    }

    /** Is there an answer here the customer has not opened yet? */
    public function hasUnreadReply(): bool
    {
        $last = $this->lastMessage;

        return (bool) $last?->is_staff
            && (! $this->customer_seen_at || $last->created_at->gt($this->customer_seen_at));
    }

    /**
     * One line for the inbox row: the last thing said, or — before anybody
     * has said anything — what they typed, minus the contact details the row
     * already prints.
     */
    public function preview(int $limit = 140): string
    {
        $text = $this->lastMessage?->body;

        if ($text === null) {
            $text = collect($this->answers())
                ->reject(fn (array $a) => in_array($a['key'], ['name', 'phone', 'email'], true))
                ->pluck('value')
                ->implode(' · ');
        }

        return \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', trim((string) $text)), $limit);
    }

    public function kindLabel(): string
    {
        return Kinds::label($this->kind);
    }

    /**
     * The answers, in the order the form asked for them, each with the label it
     * was asked under.
     *
     * Read from the SNAPSHOT rather than from the kind's current fields: the
     * shop may have renamed a field, dropped one, or uninstalled the module
     * that registered the kind, and an enquiry from March must still read the
     * way it was written. Falls back to the key when an answer arrived with no
     * label recorded — a row written by an older version, or by an import.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    public function answers(): array
    {
        $values = (array) ($this->fields['values'] ?? []);
        $labels = (array) ($this->fields['labels'] ?? []);
        $options = (array) ($this->fields['options'] ?? []);

        $rows = [];

        // In the order the form asked, where the row remembers it (`order`,
        // since jsonb sorts keys); a row from before that, as stored.
        $order = (array) ($this->fields['order'] ?? []);
        $values = array_replace(array_intersect_key(array_flip($order), $values), $values);

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $value = is_array($value) ? $value : [$value];

            // A chosen option under the name it was offered under. Falls back
            // to the stored key, which is what a row written before this was
            // snapshotted has — and what a value that was never a choice is.
            $value = array_map(
                fn ($one) => (string) ($options[$key][$one] ?? $one),
                $value,
            );

            $rows[] = [
                'key' => (string) $key,
                'label' => (string) ($labels[$key] ?? $key),
                'value' => implode(', ', $value),
            ];
        }

        return $rows;
    }

    public function answer(string $key): ?string
    {
        $value = $this->fields['values'][$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * What the enquiry is about, as something a template can print.
     *
     * Asked as a filter, because the subject belongs to whoever owns that kind
     * of row: this module resolves the product it declares kinds for, and a
     * module that registered a kind about its own entity answers for that one.
     * An unanswered subject still prints — "order #12" — rather than vanishing,
     * because a manager reading the inbox is better served by a name they can
     * search for than by a blank.
     *
     * @return array{label: string, url: string|null}|null
     */
    public function subject(): ?array
    {
        if (! $this->subject_type || ! $this->subject_id) {
            return null;
        }

        $described = hook_filter('enquiries.subject.describe', null, [
            'type' => $this->subject_type,
            'id' => $this->subject_id,
            'enquiry' => $this,
        ]);

        return $described ?? [
            'label' => $this->subject_type.' #'.$this->subject_id,
            'url' => null,
        ];
    }
}
