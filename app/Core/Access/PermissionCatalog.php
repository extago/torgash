<?php

namespace App\Core\Access;

use App\Core\Modules\ModuleManager;
use App\Core\Settings\CoreSettings;
use Spatie\Permission\Models\Permission;

/**
 * The permission list as an admin should read it: grouped by the module that
 * introduced it, each entry carrying the sentence its manifest wrote.
 *
 * The DATABASE is the truth about which permissions exist — a permission is
 * grantable only once ModuleManager::install() has created its row — and the
 * manifests are the truth about what each one means. A row no manifest claims
 * (a module deleted from disk while still installed, a hand-made permission)
 * lands in a trailing "Other" group rather than disappearing from the screen:
 * a permission that cannot be seen cannot be taken away either.
 */
class PermissionCatalog
{
    public function __construct(protected ModuleManager $modules) {}

    /**
     * @return list<array{
     *     module: string,
     *     title: string,
     *     permissions: list<array{name: string, label: string}>
     * }>
     */
    public function groups(): array
    {
        $remaining = array_flip($this->names());
        $groups = [];

        // Core first, then what is running, then modules installed but turned
        // off — a disabled module still owns its permissions, and a permission
        // that vanishes from the screen cannot be taken away either.
        $manifests = [CoreSettings::manifest(), ...array_values($this->modules->enabled()), ...array_values($this->modules->all())];

        foreach ($manifests as $manifest) {
            $entries = [];
            foreach ($manifest->permissionNames() as $name) {
                if (! isset($remaining[$name])) {
                    continue;
                }
                unset($remaining[$name]);
                $entries[] = ['name' => $name, 'label' => $this->label($manifest->permissionLabel($name), $name)];
            }

            if ($entries !== []) {
                $groups[] = [
                    'module' => $manifest->id,
                    // Core's manifest is named "Store" for the settings screen;
                    // signing in and managing staff are not about the store.
                    // A module's name is a declared English word, translated
                    // by the module's own lang file (ADR-005).
                    'title' => $manifest->id === CoreSettings::ID ? __('General') : label_text($manifest->name),
                    'permissions' => $entries,
                ];
            }
        }

        if ($remaining !== []) {
            $groups[] = [
                'module' => '',
                'title' => __('Other'),
                'permissions' => array_map(
                    fn (string $name) => ['name' => $name, 'label' => $name],
                    array_keys($remaining),
                ),
            ];
        }

        return $groups;
    }

    /** Every permission name that exists, flat. */
    public function names(): array
    {
        return Permission::orderBy('name')->pluck('name')->all();
    }

    /** @param array<string,string>|string|null $label */
    protected function label(array|string|null $label, string $name): string
    {
        return label_text($label, null, $name);
    }
}
