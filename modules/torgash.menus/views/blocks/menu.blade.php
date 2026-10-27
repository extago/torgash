{{--
    The `menu` block (PLAN §39.2): which menu, drawn by which look, how deep.
    Everything about WHICH entries this visitor sees was decided by
    MenuBuilder; the look only draws them. A look that has gone (its module
    uninstalled) falls back to the plain list rather than drawing nothing.
--}}
@php
    $menuLooks = app(\Torgash\Menus\Looks::class);
    $menuLook = $menuLooks->find($settings['look'] ?? 'inline') ?? $menuLooks->find('list');
    $menuNodes = $menuLook ? app(\Torgash\Menus\MenuBuilder::class)->nodes((string) ($settings['menu'] ?? '')) : [];
    $menuDepth = max(1, min((int) ($settings['depth'] ?? 2), (int) ($menuLook['depth'] ?? 1)));
    $menuHeading = trim((string) ($settings['heading'] ?? ''));
    $menuHeadingIcon = trim((string) ($settings['icon'] ?? ''));
    $menuHeadingIcon = $menuHeadingIcon !== '' && preg_match('/^[a-z0-9-]+$/', $menuHeadingIcon) && is_file(resource_path('icons/lucide/'.$menuHeadingIcon.'.svg')) ? $menuHeadingIcon : null;
@endphp
@if ($menuNodes !== [])
    @include($menuLook['view'], [
        'menuNodes' => $menuNodes,
        'menuDepth' => $menuDepth,
        'menuHeading' => $menuHeading,
        'menuHeadingIcon' => $menuHeadingIcon,
        'menuShowIcons' => (bool) ($settings['icons'] ?? true),
        'menuShowBadges' => (bool) ($settings['badges'] ?? true),
    ])
@endif
