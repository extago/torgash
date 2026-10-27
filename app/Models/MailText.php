<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The shop's own words for one letter (App\Core\Mail\Letters) — only what it
 * changed, per language. Read with own(), at ONE locale and never another:
 * a Russian reader of a letter the shop rewrote only in English gets the
 * module's default in Russian, not the shop's English.
 */
class MailText extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subject' => 'array',
            'preheader' => 'array',
            'heading' => 'array',
            'body' => 'array',
            'button' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /** What the shop wrote for this field in this language, or null. */
    public function own(string $field, string $locale): ?string
    {
        $value = trim((string) (($this->{$field} ?? [])[$locale] ?? ''));

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    public function recipientList(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,;]+/', (string) $this->recipients) ?: []),
            fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL),
        ));
    }

    /** How a row is named in the guard's audit. */
    public static function auditLabel(): string
    {
        return 'Letter text';
    }
}
