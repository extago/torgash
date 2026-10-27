# Anatomy of a module

```
modules/torgash.hello/
├── manifest.json     id, provider, autoload, requires, settings, permissions, admin_menu
├── src/              PSR-4, one ServiceProvider
├── views/            <module.id>::name — a theme can override these
├── lang/             en.json, ru.json — interface strings
├── migrations/       m_<name>_* tables; install runs them, uninstall rolls them back
└── docs/             this folder
```

## Rules that do not bend

1. A module never patches core or another module. It extends through hooks
   (`hook_listen` / `hook_filter` / `hook_action`) and by overriding views.
2. Install and uninstall leave no trace: module tables are prefixed
   `m_<name>_`, and a migration's filename must be unique across *all* modules
   (Laravel tracks them by filename alone).
3. Settings are declared in the manifest and read with
   `setting('module.id', 'key')` — defaults from the manifest, values from the
   database.

## Where to look next

- `modules/torgash.media` — a module other modules depend on.
- `modules/torgash.editor` — a module that extends core purely through a hook
  and ships vendored JavaScript.

An image put beside this file and referenced with a relative path:

![a 1×1 pixel](images/example.png)
