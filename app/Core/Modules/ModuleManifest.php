<?php

namespace App\Core\Modules;

use RuntimeException;

/**
 * Immutable view of a module's manifest.json. The manifest is the single
 * source of truth for everything the core needs to know about a module.
 */
class ModuleManifest
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        /** @var array<string,string>|string English (translated through the module's lang files), or locale => text */
        public readonly array|string $description,
        public readonly string $provider,
        /** @var array<string,string> psr-4 prefix => relative dir */
        public readonly array $autoload,
        /** @var array{core?: string, modules?: list<string>} */
        public readonly array $requires,
        /** @var list<array<string,mixed>> setting definitions: key, type, default, label */
        public readonly array $settings,
        /**
         * Permissions this module introduces. An entry is either a bare name
         * ("catalog.manage") or an object carrying what the permission-group
         * screen prints beside the checkbox:
         * {"name": "...", "label": "..."} — English, translated by the module's lang files.
         * Read it through permissionNames() / permissionLabel(), never directly.
         *
         * @var list<string|array{name: string, label?: array<string,string>}>
         */
        public readonly array $permissions,
        /** @var list<array<string,mixed>> admin menu items: label, route, icon, position */
        public readonly array $adminMenu,
        /** @var list<array{name: string, label?: array<string,string>, group?: string}> output positions this module's templates expose — see ZoneRegistry */
        public readonly array $zones,
        public readonly string $path,
        /**
         * Who made it — `{"name": …, "url": …, "email": …}`, every part
         * optional. Absent on the engine's own modules, which is why the
         * modules screen prints nothing rather than "Torgash" under each.
         *
         * @var array{name?: string, url?: string, email?: string}
         */
        public readonly array $author = [],
        /**
         * The author's links beside the module — documentation elsewhere,
         * support, a changelog, a licence: `[{"label": "…" (English),
         * "url": …, "icon": …}]`. Read through links(), which drops anything
         * that is not a web or mail address.
         *
         * @var list<array<string, mixed>>
         */
        public readonly array $links = [],
        /**
         * How the module is recognised at a glance on the modules screen —
         * `"icon"`: a lucide.dev name; `"logo"`: a picture the module ships,
         * a path inside its own assets/ (svg, png, jpg, webp), which wins over
         * the icon; `"color"`: a #hex the icon's tile is tinted with;
         * `"section"`: which sidebar section it belongs to when it has no menu
         * item to say so (a payment gateway belongs to Sales). All optional:
         * with none, the icon and section of the module's first menu item.
         *
         * @var array{icon?: string, logo?: string, color?: string, section?: string}
         */
        public readonly array $look = [],
        /**
         * The countries the module is for — `"regions": ["UA"]`, ISO 3166
         * two-letter codes: a carrier, a payment gateway, a tax that exists
         * in one country. Empty means any country. One distribution carries
         * every country's modules; this is what lets the installer put in the
         * ones for the shop's country and the modules screen filter by it.
         *
         * @var list<string>
         */
        public readonly array $regions = [],
    ) {}

    public static function fromFile(string $manifestPath): self
    {
        $data = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($data)) {
            throw new RuntimeException('manifest.json is not valid JSON ('.json_last_error_msg().')');
        }

        if (empty($data['id']) || empty($data['provider'])) {
            throw new RuntimeException('manifest.json has no "id" or no "provider"');
        }

        return new self(
            id: $data['id'],
            name: $data['name'] ?? $data['id'],
            version: $data['version'] ?? '0.0.0',
            description: $data['description'] ?? [],
            provider: $data['provider'],
            autoload: $data['autoload'] ?? [],
            requires: $data['requires'] ?? [],
            settings: $data['settings'] ?? [],
            permissions: $data['permissions'] ?? [],
            adminMenu: $data['admin_menu'] ?? [],
            zones: $data['zones'] ?? [],
            path: dirname($manifestPath),
            author: is_array($data['author'] ?? null) ? $data['author'] : [],
            links: is_array($data['links'] ?? null) ? array_values(array_filter($data['links'], 'is_array')) : [],
            look: array_filter([
                'icon' => $data['icon'] ?? null,
                'logo' => $data['logo'] ?? null,
                'color' => $data['color'] ?? null,
                'section' => $data['section'] ?? null,
            ], 'is_string'),
            regions: array_values(array_unique(array_filter(
                array_map(fn ($code) => strtoupper(trim((string) $code)), (array) ($data['regions'] ?? [])),
                fn (string $code) => preg_match('/^[A-Z]{2}$/', $code) === 1,
            ))),
        );
    }

    /** Whether the module is for this country — any module without regions is. */
    public function isFor(?string $country): bool
    {
        return $this->regions === [] || ($country !== null && in_array(strtoupper($country), $this->regions, true));
    }

    /**
     * The author, with an address only when it is one a browser may follow
     * from the admin — a manifest is written by whoever wrote the module, and
     * `javascript:` in it would run in a staff member's session.
     *
     * @return array{name: ?string, url: ?string, email: ?string}|null
     */
    public function author(): ?array
    {
        $name = trim((string) ($this->author['name'] ?? ''));
        $url = self::safeUrl($this->author['url'] ?? null);
        $email = filter_var($this->author['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;

        if ($name === '' && $url === null && $email === null) {
            return null;
        }

        return [
            'name' => $name !== '' ? $name : null,
            'url' => $url,
            'email' => $email,
        ];
    }

    /**
     * The author's links, each with its label in the reader's language and
     * an address that is safe to put in an href. A link failing either is
     * left out, not shown broken.
     *
     * @return list<array{label: string, url: string, icon: string}>
     */
    public function links(): array
    {
        $links = [];

        foreach ($this->links as $link) {
            $url = self::safeUrl($link['url'] ?? null);
            $label = $link['label'] ?? null;
            $label = label_text($label);

            if ($url === null || ! is_string($label) || trim($label) === '') {
                continue;
            }

            $links[] = [
                'label' => trim($label),
                'url' => $url,
                // Checked, not trusted: an unknown icon throws under APP_DEBUG,
                // and a typo in somebody's manifest must not take the modules
                // screen down with it.
                'icon' => is_string($link['icon'] ?? null) && \App\Core\Support\Icons::exists($link['icon']) ? $link['icon'] : 'external-link',
            ];
        }

        return $links;
    }

    /**
     * The lucide icon to draw: the manifest's own, else its first menu item's,
     * else a neutral block. Checked against the icon set, not trusted — a typo
     * in somebody's manifest must not take the modules screen down.
     */
    public function icon(): string
    {
        foreach ([$this->look['icon'] ?? null, ...array_column($this->adminMenu, 'icon')] as $name) {
            if (is_string($name) && $name !== '' && \App\Core\Support\Icons::exists($name)) {
                return $name;
            }
        }

        return 'blocks';
    }

    /**
     * The module's own picture, as a file on disk — only inside its assets/,
     * only a picture. Null when there is none or it is not one.
     */
    public function logoFile(): ?string
    {
        $logo = $this->look['logo'] ?? null;

        if (! is_string($logo) || ! in_array(strtolower(pathinfo($logo, PATHINFO_EXTENSION)), ['svg', 'png', 'jpg', 'jpeg', 'webp'], true)) {
            return null;
        }

        $root = realpath($this->path.DIRECTORY_SEPARATOR.'assets');
        $file = realpath($this->path.DIRECTORY_SEPARATOR.ltrim($logo, '/\\'));

        return $root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    /** The tile's tint — a real #hex or nothing (it lands in a style attribute). */
    public function color(): ?string
    {
        $color = $this->look['color'] ?? null;

        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;
    }

    /** Whoever wrote it said so: a module with no author is the engine's own. */
    public function isBundled(): bool
    {
        return $this->author() === null;
    }

    /** http(s) or mailto, nothing else. */
    protected static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url(trim($url), PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true) ? trim($url) : null;
    }

    /** The core versions this module says it runs on (`requires.core`, a Composer constraint), or null for any. */
    public function coreConstraint(): ?string
    {
        $constraint = $this->requires['core'] ?? null;

        return is_string($constraint) && trim($constraint) !== '' ? trim($constraint) : null;
    }

    /**
     * Whether it runs on this core (PLAN §45.5). A constraint that cannot be
     * read does not fit: a module may not be trusted to run on code its
     * author never named.
     */
    public function fitsCore(?string $core = null): bool
    {
        $constraint = $this->coreConstraint();
        if ($constraint === null) {
            return true;
        }

        try {
            return \Composer\Semver\Semver::satisfies($core ?? (string) config('torgash.version'), $constraint);
        } catch (\Throwable) {
            return false;
        }
    }

    public function migrationsPath(): ?string
    {
        $path = $this->path.DIRECTORY_SEPARATOR.'migrations';

        return is_dir($path) ? $path : null;
    }

    public function viewsPath(): ?string
    {
        $path = $this->path.DIRECTORY_SEPARATOR.'views';

        return is_dir($path) ? $path : null;
    }

    public function langPath(): ?string
    {
        $path = $this->path.DIRECTORY_SEPARATOR.'lang';

        return is_dir($path) ? $path : null;
    }

    /**
     * The module's docs/ folder, when it ships one. Presence of the folder is
     * the signal — like hasSettings(), nothing in the manifest to keep in sync.
     */
    public function docsPath(): ?string
    {
        $path = $this->path.DIRECTORY_SEPARATOR.'docs';

        return is_dir($path) ? $path : null;
    }

    /**
     * True when there is anything to read: a Markdown page in docs/, or a
     * CHANGELOG — "what changed in this version" is read on the same screen.
     */
    public function hasDocs(): bool
    {
        $path = $this->docsPath();

        return ($path !== null && glob($path.DIRECTORY_SEPARATOR.'*.md') !== []) || $this->changelogFile() !== null;
    }

    /**
     * What changed, version by version: `CHANGELOG.md` in the module's root —
     * where every repository keeps one — and `CHANGELOG.<locale>.md` beside it
     * for a translation, read in the reader's language when there is one.
     */
    public function changelogFile(?string $locale = null): ?string
    {
        foreach (array_filter([$locale ? 'CHANGELOG.'.$locale.'.md' : null, 'CHANGELOG.md']) as $name) {
            $file = $this->path.DIRECTORY_SEPARATOR.$name;

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The permission names alone — what install() registers and uninstall()
     * removes. A module that never labelled its permissions is unchanged by
     * the labelled form existing.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_values(array_filter(array_map(
            fn ($permission) => is_array($permission) ? ($permission['name'] ?? null) : $permission,
            $this->permissions,
        )));
    }

    /**
     * What the admin should read beside the checkbox, locale => text, or null
     * when the module did not say. The catalogue falls back to the raw name;
     * a module that wants a sentence there writes one in its manifest, the
     * same way it labels a setting.
     *
     * @return array<string,string>|null
     */
    public function permissionLabel(string $name): array|string|null
    {
        foreach ($this->permissions as $permission) {
            if (is_array($permission) && ($permission['name'] ?? null) === $name) {
                return $permission['label'] ?? null;
            }
        }

        return null;
    }

    /**
     * The description in the reader's language. Written in English and
     * translated by the module's own lang file — which is loaded only while
     * the module is ENABLED, and the modules screen lists the disabled ones
     * too, so this reads the file itself when the translator does not know it.
     */
    public function descriptionText(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $text = label_text($this->description, $locale);

        if (is_string($this->description) && $text === $this->description && ($lang = $this->langPath())) {
            $file = $lang.DIRECTORY_SEPARATOR.$locale.'.json';
            $own = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

            if (is_array($own) && is_string($own[$this->description] ?? null)) {
                return $own[$this->description];
            }
        }

        return $text;
    }

    /** Default values declared in the manifest, key => default. */
    public function settingDefaults(): array
    {
        $defaults = [];
        foreach ($this->storedSettings() as $definition) {
            $defaults[$definition['key']] = $definition['default'] ?? null;
        }

        return $defaults;
    }

    /** Definition types that render on the settings screen but hold no value. */
    private const NON_VALUE_TYPES = ['group', 'action', 'link'];

    /**
     * Setting definitions that carry a value. "group" headings, "action"
     * buttons and "link" pointers to a module's own screen are screen
     * furniture and never reach the settings store.
     */
    public function storedSettings(): array
    {
        return array_values(array_filter(
            $this->settings,
            fn ($definition) => isset($definition['key'])
                && ! in_array($definition['type'] ?? 'text', self::NON_VALUE_TYPES, true),
        ));
    }

    /** True when the module has anything for the admin to configure. */
    public function hasSettings(): bool
    {
        return $this->storedSettings() !== [];
    }
}
