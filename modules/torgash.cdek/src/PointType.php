<?php

namespace Torgash\Cdek;

/**
 * The two kinds of place CDEK hands a parcel over at — its `type` field.
 *
 * A locker is a kind of pickup point, not a kind of delivery, exactly as in
 * the Nova Poshta module: one "to a pickup point" method offers both, and a
 * shop that wants lockers named or priced on their own makes a second method
 * and ticks only lockers.
 */
final class PointType
{
    public const OFFICE = 'PVZ';

    public const LOCKER = 'POSTAMAT';

    /** @return array<string, string> type => label */
    public static function all(): array
    {
        return [
            self::OFFICE => __('Pickup point'),
            self::LOCKER => __('Parcel terminal'),
        ];
    }

    public static function label(string $type): string
    {
        return self::all()[$type] ?? $type;
    }
}
