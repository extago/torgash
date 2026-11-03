<?php

namespace Torgash\Api;

/**
 * What a token may be narrowed TO — one resource at a time.
 *
 * A token's `abilities` is null ("whatever its owner may") or a list of
 * words: the old `read` / `write` (every resource) and, since 2026-11-03,
 * `<scope>:read` / `<scope>:write` for ONE scope — a registered admin
 * resource (`admin.products`) or a door a module keeps of its own
 * (`commerceml`, the 1C exchange). So a price feed's key reads products
 * and nothing else, and the CRM's key reads orders without being able to
 * touch the catalogue: one integration, one key, one room.
 *
 * `write` lets in `read` of the same scope: a key that may change a product
 * may look at it, and asking the admin to tick both is asking for a key
 * that can PATCH a row it cannot GET.
 *
 * The scopes a shop offers are the registry's admin resources plus what
 * modules add on `api.token.scopes` (`[key => ['label' => …, 'writable' =>
 * bool, 'readable' => bool]]`): read fresh each time, never kept — the
 * registry is announced lazily and a module may be switched on later.
 */
class TokenScopes
{
    public const MODES = ['read', 'write'];

    /**
     * Every scope a token can be narrowed to, keyed by scope, labelled in
     * English (translated by lang files), admin resources first.
     *
     * @return array<string, array{label: string, readable: bool, writable: bool, description: ?string}>
     */
    public static function all(): array
    {
        $scopes = [];

        foreach (ApiResources::all() as $name => $definition) {
            if (! str_starts_with($name, 'admin.') || ! $definition['query']) {
                continue;
            }

            $scopes[$name] = [
                'label' => label_text($definition['label'], null, $name),
                'readable' => true,
                'writable' => $definition['update'] !== null || $definition['create'] !== null || $definition['actions'] !== [],
                'description' => null,
            ];
        }

        foreach ((array) hook_filter('api.token.scopes', []) as $key => $scope) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_.-]*$/', $key) && ! isset($scopes[$key]) && is_array($scope)) {
                $scopes[$key] = [
                    'label' => label_text($scope['label'] ?? null, null, $key),
                    'readable' => (bool) ($scope['readable'] ?? true),
                    'writable' => (bool) ($scope['writable'] ?? true),
                    'description' => isset($scope['description']) ? label_text($scope['description']) : null,
                ];
            }
        }

        return $scopes;
    }

    /** @return list<string> every word a token's abilities may hold */
    public static function words(): array
    {
        $words = self::MODES;

        foreach (self::all() as $key => $scope) {
            if ($scope['readable']) {
                $words[] = $key.':read';
            }
            if ($scope['writable']) {
                $words[] = $key.':write';
            }
        }

        return $words;
    }

    /**
     * Whether a list of abilities lets a call of $mode into $scope. Null is
     * "everything the owner may"; a bare mode is every scope; a scope's
     * `write` carries its `read`.
     *
     * @param  list<string>|null  $abilities
     */
    public static function allows(?array $abilities, string $mode, ?string $scope = null): bool
    {
        if ($abilities === null) {
            return true;
        }

        $modes = $mode === 'read' ? ['read', 'write'] : [$mode];

        foreach ($modes as $allowed) {
            if (in_array($allowed, $abilities, true)) {
                return true;
            }
            if ($scope !== null && in_array($scope.':'.$allowed, $abilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The abilities in words for the list: "everything", "read everything",
     * "Products: read, write · Orders: read".
     *
     * @param  list<string>|null  $abilities
     */
    public static function describe(?array $abilities): string
    {
        if ($abilities === null || $abilities === []) {
            return __('everything its owner may');
        }

        $scopes = self::all();
        $global = array_values(array_intersect(self::MODES, $abilities));
        $parts = [];

        if ($global !== []) {
            $parts[] = in_array('write', $global, true)
                ? __('read and write everything')
                : __('read everything');
        }

        $byScope = [];
        foreach ($abilities as $word) {
            if (str_contains($word, ':')) {
                [$scope, $mode] = explode(':', $word, 2);
                $byScope[$scope][] = $mode;
            }
        }

        foreach ($byScope as $scope => $modes) {
            $label = isset($scopes[$scope]) ? __($scopes[$scope]['label']) : $scope;
            $parts[] = $label.': '.(in_array('write', $modes, true) ? __('read and write') : __('read'));
        }

        return implode(' · ', $parts);
    }
}
