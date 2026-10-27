<?php

namespace Torgash\NovaPoshta;

/**
 * The variant a shipping method's provider_config names.
 *
 * TWO of them, not three: a parcel locker is a kind of pickup point, not a kind
 * of delivery. Which pickup points a method offers — post office, cargo
 * department, parcel shop, locker — is the type list beside this, so a shop
 * that wants lockers priced or named separately makes a second method and ticks
 * only lockers, and a shop that does not care offers all four in one.
 *
 * Nova Poshta's own ServiceType is sender-side + recipient-side, and it does
 * have a `WarehousePostomat`. We do not send it: measured against the live API,
 * `getDocumentPrice` answers "Not enough parameters to calculate the cost" for
 * it on every parameter set tried, so a locker was never priced live anyway —
 * it fell through to the shop's own rate table. `WarehouseWarehouse` is the one
 * that answers, and it is what a parcel to a locker is quoted as.
 */
final class ServiceType
{
    public const BRANCH = 'WarehouseWarehouse';

    public const COURIER = 'WarehouseDoors';

    /**
     * Methods saved before the locker became a type rather than a variant.
     * Read (and rewritten on the next save) as "to a branch, lockers only".
     */
    public const LEGACY_LOCKER = 'WarehousePostomat';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::BRANCH, self::COURIER];
    }

    public static function label(string $value): string
    {
        return match ($value) {
            self::BRANCH => __('To a branch'),
            self::COURIER => __('By courier'),
            default => $value,
        };
    }

    /** Pickup needs a warehouse chosen at checkout; courier needs an address. */
    public static function usesWarehouse(string $value): bool
    {
        return $value === self::BRANCH;
    }
}
