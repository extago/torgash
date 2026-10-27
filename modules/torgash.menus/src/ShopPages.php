<?php

namespace Torgash\Menus;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * The shop's own pages a menu item can point at BY NAME — "Sale", "Brands",
 * "Wishlist", an information page — answered by whoever owns the page through
 * `menus.pages`, lazily, because provider boot order is not ours.
 *
 *     hook_listen('menus.pages', fn (array $pages) => $pages + [
 *         'route:wishlist.index' => [
 *             'label' => 'Wishlist',
 *             'route' => 'wishlist.index',      // or 'url' => fn () => …
 *             'icon'  => 'heart',                // the item's icon unless one is typed
 *             'group' => 'Shop',
 *         ],
 *     ]);
 *
 * By name, never by a typed `/sale`: route() builds the address in the shape
 * of the language the visitor is reading in (a typed path took a Russian
 * reader to the English page), and an item whose owner has been uninstalled
 * simply stops being drawn instead of leading to a 404.
 *
 * Per request (PerRequest::forget in the provider): a module lists rows of its
 * own here, and those change.
 */
class ShopPages
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $pages = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->pages ??= collect((array) hook_filter('menus.pages', []))
            ->filter(fn ($page) => is_array($page) && (isset($page['route']) || isset($page['url'])))
            ->filter(fn ($page) => ! isset($page['route']) || Route::has($page['route']))
            ->all();
    }

    public function find(?string $key): ?array
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    public function url(string $key): ?string
    {
        $page = $this->find($key);

        if (! $page) {
            return null;
        }

        if (isset($page['route'])) {
            return route($page['route'], $page['parameters'] ?? []);
        }

        $url = $page['url'] instanceof Closure ? ($page['url'])() : $page['url'];

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function label(string $key, ?string $locale = null): string
    {
        return self::word($this->find($key)['label'] ?? $key, $locale);
    }

    /**
     * For the admin's select: group => [key => label], in the order registered.
     *
     * @return array<string, array<string, string>>
     */
    public function options(?string $locale = null): array
    {
        $out = [];

        foreach ($this->all() as $key => $page) {
            $out[self::word($page['group'] ?? 'Shop', $locale)][$key] = self::word($page['label'] ?? $key, $locale);
        }

        return $out;
    }

    /** A registrant's word: a plain string, or {en, ru, …}. */
    public static function word(mixed $value, ?string $locale = null): string
    {
        return label_text($value, $locale);
    }

    public function flush(): void
    {
        $this->pages = null;
    }
}
