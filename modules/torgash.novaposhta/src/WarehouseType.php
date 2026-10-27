<?php

namespace Torgash\NovaPoshta;

/**
 * Nova Poshta's warehouse type UUIDs (from its own enum). A branch method can
 * be restricted to some of them — a shop that never ships oversized may want
 * post offices only, not cargo departments.
 */
final class WarehouseType
{
    public const POST_OFFICE = '841339c7-591a-42e2-8233-7a0a00f0ed6f';

    public const CARGO_DEPARTMENT = '9a68df70-0267-42a8-bb5c-37f427e36ee4';

    public const PARCEL_SHOP = '6f8c7162-4b72-4b0a-88e5-906948c6a92f';

    public const PARCEL_LOCKER = 'f9316480-5f2d-425d-bc2c-ac7cd29decf0';

    /** @return array<string, string> ref => label */
    public static function all(): array
    {
        return [
            self::POST_OFFICE => __('Post office'),
            self::CARGO_DEPARTMENT => __('Cargo department'),
            self::PARCEL_SHOP => __('Parcel shop'),
            self::PARCEL_LOCKER => __('Parcel locker'),
        ];
    }

    /** Our short category for a type ref, used by the checkout picker. */
    public static function category(string $ref): string
    {
        return match ($ref) {
            self::PARCEL_LOCKER => 'locker',
            self::CARGO_DEPARTMENT => 'cargo',
            default => 'branch',
        };
    }
}
