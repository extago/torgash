<?php

namespace Torgash\Blocks;

use App\Core\Settings\SettingsManager;

/**
 * Which zones are switched off in the admin.
 *
 * A zone switch is coarser than a block switch on purpose. Turning a block off
 * says "not this one"; turning the ZONE off says "nothing here at all", which
 * is the answer to "we have no phone number" — the merchant flips one switch
 * instead of hunting down every block that happens to sit in the header.
 *
 * Scope, stated plainly because the label in the admin has to be honest: this
 * suppresses the BLOCKS in a zone, not the zone itself. Other modules may also
 * be listening on the same hook, and silencing them from here would be this
 * module reaching into theirs.
 *
 * Stored as one settings row rather than a table: the payload is a short list
 * of names, it disappears with the module on uninstall like every other
 * setting, and it inherits per-storefront overrides for free if that is ever
 * wanted. Deliberately NOT declared in manifest.json — a manifest setting
 * generates an admin form field, and this one is edited by the toggles on
 * /admin/blocks, not by hand in a JSON textarea.
 */
class ZoneSwitches
{
    public const MODULE = 'torgash.blocks';

    public const KEY = 'disabled_zones';

    public function __construct(protected SettingsManager $settings) {}

    /** @return list<string> */
    public function disabled(): array
    {
        $stored = $this->settings->get(self::MODULE, self::KEY, []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    public function enabled(string $zone): bool
    {
        return ! in_array($zone, $this->disabled(), true);
    }

    public function toggle(string $zone): bool
    {
        $disabled = $this->disabled();
        $nowEnabled = in_array($zone, $disabled, true);

        $this->settings->set(
            self::MODULE,
            self::KEY,
            $nowEnabled
                ? array_values(array_diff($disabled, [$zone]))
                : [...$disabled, $zone],
        );

        return $nowEnabled;
    }
}
