# Overview

`torgash.hello` is the reference module — the smallest thing that is still a
real module. Copy its shape when you start a new one.

It demonstrates, one file each:

- a **route** (`routes.php`) and a **view** (`views/hello.blade.php`)
- a **migration** with a `m_hello_*` table that install creates and uninstall drops
- a **hook** listener, a **setting** from the manifest, and a **theme override**
- and now a **docs/ folder** — the pages you are reading.

## Documentation lives with the code

Any module with a `docs/` folder gets a book icon on the Modules screen. There
is no manifest flag: the folder is the opt-in, the same way `settings` in the
manifest is what puts a gear there.

A page is a Markdown file:

| File | What it is |
|---|---|
| `overview.md` | a page in the base language |
| `ru_overview.md` | its Russian translation |
| `01-overview.md` | the `01-` orders the tabs; the slug is still `overview` |

Raw HTML in the Markdown is stripped — a module's docs are written by its
vendor, a third party — but images work: put them next to the `.md` file and
reference them with a relative path.
