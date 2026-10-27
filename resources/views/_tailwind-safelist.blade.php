{{--
    Never rendered — exists only so Tailwind4's @source scanner (which reads
    raw file text, not compiled Blade output) sees every daisyUI badge-color
    class as a literal string somewhere. Torgash's Label model lets an admin
    pick ANY of the 8 daisyUI semantic colors at runtime (badge-{{ $color }}
    in label-badges.blade.php, labels/index.blade.php, labels/form.blade.php,
    products/form.blade.php) — only the colors that happen to also appear
    literally elsewhere in the codebase (primary/secondary/success/warning,
    via unrelated "Default"/"enabled" badges) were ever actually being
    generated; error/neutral/accent/info silently compiled to nothing.

    badge-primary badge-secondary badge-accent badge-neutral badge-info
    badge-success badge-warning badge-error
--}}
