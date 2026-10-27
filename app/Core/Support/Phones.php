<?php

namespace App\Core\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * A phone number, understood the way every country writes its own (PLAN
 * §44.3) — libphonenumber's rules, not ours: a shop is not only Ukrainian,
 * and a length check written by hand is wrong somewhere for years.
 *
 * Stored as E.164 (`+380675550128`) with the COUNTRY beside it (`UA`),
 * because a calling code is not a country: +1 is the USA and Canada, +7 is
 * Russia and Kazakhstan. What a person typed without a plus is read in the
 * default country: the one the visitor chose their town in (whoever keeps
 * that answers `core.visitor.country`), else the shop's `default_country`,
 * else none — and then the number must carry its own plus.
 */
class Phones
{
    /**
     * @return array{e164: string, country: string, mobile: bool, display: string}|null
     *         null when this is not a number anybody can be reached on
     */
    public static function parse(?string $input, ?string $country = null): ?array
    {
        $input = trim((string) $input);

        if ($input === '' || ! preg_match('/\d/', $input)) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();
        $country = strtoupper((string) ($country ?: self::defaultCountry())) ?: null;

        // As typed, in the default country; then — for "380…" typed without
        // its plus — as an international number. The second reading is tried
        // only when the first did not give a real number.
        $number = null;
        $readings = [[$input, $country]];

        if (! str_starts_with($input, '+')) {
            $readings[] = ['+'.preg_replace('/\D/', '', $input), null];
        }

        foreach ($readings as [$text, $region]) {
            try {
                $candidate = $util->parse($text, $region);
            } catch (NumberParseException) {
                continue;
            }

            if ($util->isValidNumber($candidate)) {
                $number = $candidate;
                break;
            }
        }

        if (! $number) {
            return null;
        }

        $type = $util->getNumberType($number);

        return [
            'e164' => $util->format($number, PhoneNumberFormat::E164),
            'country' => (string) $util->getRegionCodeForNumber($number),
            // A number the rules cannot tell apart (much of North America)
            // counts as mobile: refusing an SMS to every US customer is worse
            // than one SMS to a desk phone.
            'mobile' => in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE, PhoneNumberType::PERSONAL_NUMBER], true),
            'display' => $util->format($number, PhoneNumberFormat::INTERNATIONAL),
        ];
    }

    /** `+380675550128` → `+380 67 555 0128`, as its own country writes it. */
    public static function display(?string $e164): string
    {
        return $e164 ? (self::parse($e164)['display'] ?? $e164) : '';
    }

    /** The country a number without a plus is read in, or null. */
    public static function defaultCountry(): ?string
    {
        $country = hook_filter('core.visitor.country', null)
            ?: setting('core', 'default_country');

        return is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : null;
    }

    /** The calling code of a country, for a placeholder: `UA` → `+380`. */
    public static function callingCode(?string $country): string
    {
        $code = $country ? PhoneNumberUtil::getInstance()->getCountryCodeForRegion(strtoupper($country)) : 0;

        return $code ? '+'.$code : '+';
    }

    /** The first country that uses a calling code: `380` → `UA`. */
    public static function countryForCallingCode(int $code): ?string
    {
        $region = PhoneNumberUtil::getInstance()->getRegionCodeForCountryCode($code);

        return $region !== 'ZZ' ? $region : null;
    }
}
