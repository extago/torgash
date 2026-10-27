<?php

namespace Torgash\Cdek;

/**
 * Which CDEK tariff a method is priced and sent by.
 *
 * CDEK does not have "a price to a town": it has tariffs, and each tariff
 * exists once per DELIVERY MODE — where the parcel starts (the shop's door,
 * or a CDEK office the shop takes it to) and where it ends (a door, an
 * office, a locker). So a method names a FAMILY ("Parcel", "Express") and how
 * the shop hands parcels over, and the code follows from where the customer
 * wants it.
 *
 * The table is measured, not remembered: `calculator/tarifflist` Moscow →
 * St Petersburg on the test server returns exactly these codes with these
 * `delivery_mode`s (2026-10-21). Economy has no locker variant there, so a
 * locker priced by Economy has no price rather than a wrong one.
 */
final class Tariff
{
    public const PARCEL = 'parcel';

    public const EXPRESS = 'express';

    public const ECONOMY = 'economy';

    /** CDEK's `delivery_mode`: start-end. */
    public const DOOR_DOOR = 1;

    public const DOOR_OFFICE = 2;

    public const OFFICE_DOOR = 3;

    public const OFFICE_OFFICE = 4;

    public const DOOR_LOCKER = 6;

    public const OFFICE_LOCKER = 7;

    /** family => delivery_mode => tariff_code */
    public const CODES = [
        self::PARCEL => [1 => 139, 2 => 138, 3 => 137, 4 => 136, 6 => 366, 7 => 368],
        self::EXPRESS => [1 => 480, 2 => 481, 3 => 482, 4 => 483, 6 => 485, 7 => 486],
        self::ECONOMY => [1 => 231, 2 => 232, 3 => 233, 4 => 234],
    ];

    /** @return array<string, string> family => label */
    public static function families(): array
    {
        return [
            self::PARCEL => __('Parcel'),
            self::EXPRESS => __('Express'),
            self::ECONOMY => __('Economy parcel'),
        ];
    }

    /**
     * The delivery mode for a parcel that starts at the shop's door (CDEK
     * collects it) or at a CDEK office (the shop drops it off), and ends at
     * $end: 'door', or a point of type PointType::*.
     */
    public static function mode(bool $fromDoor, string $end): int
    {
        return match ($end) {
            'door' => $fromDoor ? self::DOOR_DOOR : self::OFFICE_DOOR,
            PointType::LOCKER => $fromDoor ? self::DOOR_LOCKER : self::OFFICE_LOCKER,
            default => $fromDoor ? self::DOOR_OFFICE : self::OFFICE_OFFICE,
        };
    }

    /** The tariff code, or null when this family has no such mode. */
    public static function code(string $family, int $mode): ?int
    {
        return self::CODES[$family][$mode] ?? null;
    }

    /** The delivery mode a known tariff code stands for, or null for one this table does not have. */
    public static function modeOf(int $code): ?int
    {
        foreach (self::CODES as $modes) {
            if (($mode = array_search($code, $modes, true)) !== false) {
                return (int) $mode;
            }
        }

        return null;
    }

    /**
     * Does the parcel start at the shop's door (CDEK's courier collects it)?
     * A waybill then names an address to collect from; otherwise the CDEK
     * office the shop drops it at.
     */
    public static function startsAtDoor(int $code): bool
    {
        return in_array(self::modeOf($code), [self::DOOR_DOOR, self::DOOR_OFFICE, self::DOOR_LOCKER], true);
    }
}
