<?php

namespace App\Core\Seo;

/**
 * The storefront's opening hours, typed as people write them — "Пн–Пт
 * 9:00–18:00, Сб 10:00–16:00", "Ежедневно 10:00–20:00", "Mon-Fri 9-18",
 * "Щодня 9:00-21:00", "24/7" — read into schema.org's
 * openingHoursSpecification, which a search engine shows under the shop.
 *
 * Best effort by design: the line is free text and stays so on the page;
 * only what the whole line reads as unambiguous days and times becomes
 * machine-readable, and a line with anything else in it yields nothing
 * rather than a wrong schedule.
 */
final class OpeningHours
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** A day's names in the languages the admin writes in, lower case, by its index. */
    private const NAMES = [
        0 => ['пн', 'пон', 'понедельник', 'понеділок', 'mo', 'mon', 'monday'],
        1 => ['вт', 'вторник', 'вівторок', 'tu', 'tue', 'tues', 'tuesday'],
        2 => ['ср', 'среда', 'середа', 'we', 'wed', 'wednesday'],
        3 => ['чт', 'четверг', 'четвер', 'th', 'thu', 'thur', 'thurs', 'thursday'],
        4 => ['пт', 'пятница', 'п\'ятниця', 'пʼятниця', 'fr', 'fri', 'friday'],
        5 => ['сб', 'суббота', 'субота', 'sa', 'sat', 'saturday'],
        6 => ['вс', 'воскресенье', 'нд', 'неділя', 'su', 'sun', 'sunday'],
    ];

    private const EVERY_DAY = ['ежедневно', 'каждый день', 'без выходных', 'щодня', 'щоденно', 'без вихідних', 'daily', 'every day', 'everyday', '7 days'];

    private const ALWAYS = ['круглосуточно', 'цілодобово', '24/7', '24 часа', '24 години', '24h', '24 hours'];

    /**
     * @return list<array<string, mixed>> schema.org OpeningHoursSpecification nodes, [] when the line is not readable
     */
    public static function parse(?string $text): array
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return [];
        }

        $specs = [];
        foreach (preg_split('/\s*[,;]\s*/u', $text) ?: [] as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $spec = self::segment($segment);
            if ($spec === null) {
                return [];
            }
            $specs[] = $spec;
        }

        return $specs;
    }

    /** One "days times" piece: "пн–пт 9:00–18:00", "ежедневно 10:00-20:00", "сб 10-16", "24/7". */
    private static function segment(string $segment): ?array
    {
        $at = '@';
        $spec = [$at.'type' => 'OpeningHoursSpecification'];

        foreach (self::ALWAYS as $word) {
            if ($segment === $word || str_starts_with($segment, $word.' ') || str_ends_with($segment, ' '.$word)) {
                $days = trim(str_replace($word, '', $segment));
                $spec['dayOfWeek'] = $days === '' ? self::DAYS : self::days($days);
                if ($spec['dayOfWeek'] === null) {
                    return null;
                }
                $spec['opens'] = '00:00';
                $spec['closes'] = '23:59';

                return $spec;
            }
        }

        // The times are the last thing in the piece: "9:00–18:00", "9-18", "10.00–20.00".
        if (! preg_match('/^(.*?)\s*(\d{1,2})(?:[:.](\d{2}))?\s*[–—-]\s*(\d{1,2})(?:[:.](\d{2}))?$/u', $segment, $m)) {
            return null;
        }
        $opens = (int) $m[2];
        $closes = (int) $m[4];
        if ($opens > 23 || $closes > 24 || (int) ($m[3] ?? 0) > 59 || (int) ($m[5] ?? 0) > 59) {
            return null;
        }

        $days = trim($m[1]);
        $spec['dayOfWeek'] = $days === '' ? self::DAYS : self::days($days);
        if ($spec['dayOfWeek'] === null) {
            return null;
        }
        // A time without minutes ("9–18") leaves its group unmatched.
        $spec['opens'] = sprintf('%02d:%s', $opens, ($m[3] ?? '') !== '' ? $m[3] : '00');
        $spec['closes'] = $closes === 24 ? '23:59' : sprintf('%02d:%s', $closes, ($m[5] ?? '') !== '' ? $m[5] : '00');

        return $spec;
    }

    /**
     * "пн–пт", "пн, ср, пт" (inside one segment the comma was already cut, so
     * "пн ср пт"), "ежедневно", "mon-fri", "сб и вс".
     *
     * @return list<string>|null
     */
    private static function days(string $days): ?array
    {
        $days = trim(str_replace(['.', ' и ', ' та ', ' and ', '&'], ['', ' ', ' ', ' ', ' '], $days));
        if (in_array($days, self::EVERY_DAY, true)) {
            return self::DAYS;
        }

        if (preg_match('/^(\S+)\s*[–—-]\s*(\S+)$/u', $days, $m)) {
            $from = self::day($m[1]);
            $to = self::day($m[2]);
            if ($from === null || $to === null) {
                return null;
            }
            $list = [];
            for ($i = $from; ; $i = ($i + 1) % 7) {
                $list[] = self::DAYS[$i];
                if ($i === $to) {
                    break;
                }
            }

            return $list;
        }

        $list = [];
        foreach (preg_split('/\s+/u', $days) ?: [] as $word) {
            $index = self::day($word);
            if ($index === null) {
                return null;
            }
            $list[] = self::DAYS[$index];
        }

        return $list ?: null;
    }

    private static function day(string $word): ?int
    {
        $word = rtrim(trim($word), '.');
        foreach (self::NAMES as $index => $names) {
            if (in_array($word, $names, true)) {
                return $index;
            }
        }

        return null;
    }
}
