<?php

namespace Torgash\Menus;

/**
 * One entry of a menu as a template draws it — whatever produced it: a typed
 * link, a shop page, a category and every subcategory it opened into. A look
 * (Torgash\Menus\Looks) reads nothing else, which is what lets one menu be a
 * strip of links in the header and a column in the footer.
 *
 * `kind` is the item's kind, or one the builder made up for rows nobody typed:
 * `all` ("Everything in X"), `more` ("12 more →").
 */
final class Node
{
    /** @param list<Node> $children */
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public ?string $href = null,
        public ?string $icon = null,
        public ?string $badge = null,
        public string $badgeStyle = 'primary',
        public bool $highlight = false,
        public string $showOn = 'all',
        public bool $newTab = false,
        public ?string $cssClass = null,
        public ?int $count = null,
        public array $children = [],
        public bool $active = false,
        public bool $trail = false,
        // The small line under the name ("Products on sale").
        public ?string $note = null,
        // A shortcut's key (`cart`, `wishlist`) — what an out-of-band answer
        // addresses its counter by (`#tabbar-badge-{key}`).
        public ?string $key = null,
        // `drawer`: the entry opens the phone's menu instead of leaving the page.
        public ?string $action = null,
        // A shortcut's live number (items in the basket); null — it has none.
        public ?int $counter = null,
        // A picture of its own (a category's uploaded icon) — drawn instead of `icon`.
        public ?string $image = null,
    ) {}

    /** Opens the phone's menu rather than navigating (a script's job; without one it is a link). */
    public function opensDrawer(): bool
    {
        return $this->action === 'drawer';
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    public function isDivider(): bool
    {
        return $this->kind === 'divider';
    }

    public function isHeading(): bool
    {
        return $this->kind === 'heading' || ($this->href === null && ! $this->isDivider());
    }

    /**
     * "Only on a computer / only on a phone" is a CSS class: the server does
     * not know the device, and pretending to (user-agent sniffing) breaks the
     * moment a tablet turns sideways.
     */
    public function screenClass(): string
    {
        return match ($this->showOn) {
            'desktop' => 'max-lg:hidden',
            'mobile' => 'lg:hidden',
            default => '',
        };
    }

    /** Link attributes every look repeats: target, aria-current. */
    public function linkAttributes(): string
    {
        $out = '';

        if ($this->newTab) {
            $out .= ' target="_blank" rel="noopener"';
        }

        if ($this->active) {
            $out .= ' aria-current="page"';
        }

        return $out;
    }
}
