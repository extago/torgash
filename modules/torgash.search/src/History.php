<?php

namespace Torgash\Search;

/**
 * What THIS visitor searched for lately — "ранее вы искали" in the window.
 *
 * Kept in the session, not a table: it is one person's own list, it has no
 * use to anyone else, and a table of every visitor's words is a record the
 * shop would then have to answer for. (What the shop learns from searches as
 * a whole — the analytics step of §34 — is aggregated, with no visitor in it.)
 * Remembered when a search page is actually opened, not per keystroke.
 */
class History
{
    public const KEY = 'torgash_search_history';

    public const KEEP = 20;

    public function remember(string $query): void
    {
        $query = trim(mb_substr(preg_replace('/\s+/u', ' ', $query), 0, 100));

        if ($query === '' || ! app()->bound('session.store') || ! request()->hasSession()) {
            return;
        }

        $list = array_values(array_filter(
            (array) session(self::KEY, []),
            fn ($q) => mb_strtolower((string) $q) !== mb_strtolower($query),
        ));

        array_unshift($list, $query);
        session([self::KEY => array_slice($list, 0, self::KEEP)]);
    }

    /** @return list<string> */
    public function recent(int $limit): array
    {
        if ($limit <= 0 || ! request()->hasSession()) {
            return [];
        }

        return array_slice(array_map('strval', (array) session(self::KEY, [])), 0, $limit);
    }

    public function forget(): void
    {
        if (request()->hasSession()) {
            session()->forget(self::KEY);
        }
    }
}
