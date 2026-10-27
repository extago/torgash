<?php

namespace Torgash\Enquiries\Models;

use App\Core\Support\HasTranslations;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A form the shop built itself (PLAN §48) — a kind of enquiry made of data.
 *
 * Its kind is `form.<id>`, not its code: an enquiry keeps the kind it was sent
 * under forever, and the code is what a person renames.
 *
 * A field is {key, type, label, required, help, options}; every word in it is
 * a {locale: text} map, and `options` is a list of {value, label} so the order
 * a person typed them in survives jsonb (which keeps no key order).
 */
class Form extends Model
{
    use HasTranslations;

    public const KIND_PREFIX = 'form.';

    /**
     * What a field can be. `name`, `tel` and `email` are the person's own
     * details and land in the enquiry's columns (its name, how to reach them),
     * which is why a form has at most one of each and their keys are fixed.
     */
    public const TYPES = [
        'name' => "Person's name",
        'tel' => 'Phone number',
        'email' => 'Email address',
        'text' => 'Line of text',
        'textarea' => 'Several lines',
        'number' => 'Number',
        'date' => 'Date',
        'select' => 'Drop-down list',
        'radio' => 'One of several',
        'checkboxes' => 'Several of several',
        'yes' => 'Tick box',
        'file' => 'Photos',
    ];

    /** The types whose key is fixed — see TYPES. */
    public const OWN_KEYS = ['name' => 'name', 'tel' => 'phone', 'email' => 'email', 'file' => 'attachments'];

    /** The types that offer a list of answers. */
    public const WITH_OPTIONS = ['select', 'radio', 'checkboxes'];

    protected $table = 'm_enquiries_forms';

    protected $guarded = [];

    protected static function booted(): void
    {
        // The memo of this request has read the old row.
        $forget = fn () => app()->bound(\Torgash\Enquiries\Forms::class) && app(\Torgash\Enquiries\Forms::class)->flush();
        static::saved($forget);
        static::deleted($forget);
    }

    public static function auditLabel(): string
    {
        return 'Enquiry form';
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'intro' => 'array',
            'fields' => 'array',
            'button' => 'array',
            'success' => 'array',
            'consent' => 'bool',
            'consent_text' => 'array',
            'enabled' => 'bool',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function kind(): string
    {
        return self::KIND_PREFIX.$this->id;
    }

    /**
     * This form as the enquiry registry describes a kind — the shape a
     * callback is declared in code with, so Submit, the inbox and the letters
     * read it without knowing it was typed in the admin.
     *
     * Labels stay {locale: text} maps; Kinds::localized() reads either.
     *
     * @return array<string, mixed>
     */
    public function asKind(): array
    {
        $fields = [];

        foreach ($this->fields ?? [] as $field) {
            $type = (string) ($field['type'] ?? 'text');

            if (! isset(self::TYPES[$type])) {
                continue;
            }

            $options = [];
            foreach ($field['options'] ?? [] as $option) {
                $options[(string) $option['value']] = (array) ($option['label'] ?? []);
            }

            $fields[] = [
                'key' => (string) $field['key'],
                'type' => $type,
                'label' => (array) ($field['label'] ?? []),
                'help' => (array) ($field['help'] ?? []),
                'required' => (bool) ($field['required'] ?? false),
                'options' => $type === 'yes' ? ['yes' => 'Yes'] : $options,
            ];
        }

        return [
            'label' => (array) $this->name,
            'icon' => 'clipboard-list',
            'fields' => $fields,
            'subject' => null,
            // Only a form that ASKS how to reach the person insists on one of
            // them; a survey with neither is answered as it is.
            'contact' => array_intersect(['phone', 'email'], array_column($fields, 'key')) ? 'required' : 'none',
            'form' => $this,
        ];
    }
}
