<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Storefront extends Model
{
    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    /**
     * Which locales get a prefixed copy of the storefront routes is decided
     * once per process (LocaleResolver::routePrefixes) — so a storefront that
     * gains or loses a language changes the route table, and a FrankenPHP
     * worker has to boot again to see it. Under php-fpm the stamp it writes is
     * simply never read.
     */
    protected static function booted(): void
    {
        $routes = function (self $storefront) {
            if ($storefront->wasRecentlyCreated || $storefront->wasChanged(['locales', 'default_locale', 'enabled'])) {
                \App\Core\Support\WebWorkers::restart();
            }
        };

        static::saved($routes);
        static::deleted(fn () => \App\Core\Support\WebWorkers::restart());
    }

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'locales' => 'array',
            'currencies' => 'array',
            'contacts' => 'array',
            'home_title' => 'array',
            'home_description' => 'array',
            'is_default' => 'bool',
            'enabled' => 'bool',
            'closed' => 'bool',
            'closed_contacts' => 'bool',
            'closed_message' => 'array',
        ];
    }

    /** Where a preview link's secret is remembered for the rest of the visit. */
    public const PREVIEW_SESSION = 'storefront_preview';

    /**
     * Whether this visitor gets the "closed" notice instead of the page.
     * Staff never do — the point of closing is to keep working on the shop
     * where it is — and neither does someone who came by the preview link
     * while that link is still the current one.
     */
    public function isClosedFor(Request $request): bool
    {
        if (! $this->closed) {
            return false;
        }

        if ($request->user()?->isStaff()) {
            return false;
        }

        $token = $request->hasSession() ? $request->session()->get(self::PREVIEW_SESSION.'.'.$this->getKey()) : null;

        return ! ($this->closed_token && is_string($token) && hash_equals($this->closed_token, $token));
    }

    /** The address a client is sent to see the closed shop; null until a token exists. */
    public function previewUrl(): ?string
    {
        return $this->closed_token ? $this->url('/?preview='.$this->closed_token) : null;
    }

    /** Channels a contacts bag may hold — the admin form and anything reading it share this list. */
    public const CONTACT_KEYS = ['phone', 'email', 'hours', 'address', 'telegram', 'whatsapp', 'viber', 'callback_url'];

    /**
     * One contact channel, or '' when unset — store identity, not any one
     * module's setting. Deliberately returns a string rather than null so
     * callers can trim()/compare without null-guarding every use.
     */
    public function contact(string $key): string
    {
        $value = $this->contacts[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Contacts that come as a LIST, each row with its own fields: more
     * numbers than the main one ("Sales" / "Service", one per operator) and
     * the shop's pages on social networks. The main phone stays `phone` —
     * tel: links, the schema.org telephone and every template written with
     * {phone} keep meaning the one the shop put first.
     */
    public const CONTACT_LISTS = [
        // The row's MAIN field first: without it the row is empty.
        'phones' => ['number', 'label'],
        'socials' => ['url', 'network'],
    ];

    /**
     * The rows of one list, each with every field of it as a string.
     *
     * @return list<array<string, string>>
     */
    public function contactList(string $key): array
    {
        $fields = self::CONTACT_LISTS[$key] ?? [];
        $rows = [];

        foreach ((array) ($this->contacts[$key] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];
            foreach ($fields as $field) {
                $clean[$field] = trim((string) ($row[$field] ?? ''));
            }
            $rows[] = $clean;
        }

        return $rows;
    }

    public function hasContacts(): bool
    {
        foreach (self::CONTACT_KEYS as $key) {
            if ($this->contact($key) !== '') {
                return true;
            }
        }

        foreach (array_keys(self::CONTACT_LISTS) as $key) {
            if ($this->contactList($key) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The storefront's own address — its first domain, in the scheme the
     * current request came in (a dev install on http, production on https).
     * Null when it has no domain at all.
     */
    public function url(string $path = '/'): ?string
    {
        $domain = collect($this->domains ?? [])->first();

        return $domain ? request()->getScheme().'://'.$domain.'/'.ltrim($path, '/') : null;
    }

    public static function resolveByHost(string $host): ?self
    {
        return static::query()->where('enabled', true)->get()
            ->first(fn (self $s) => in_array($host, $s->domains ?? [], true))
            ?? static::query()->where('enabled', true)->where('is_default', true)->first();
    }
}
