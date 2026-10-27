<?php

namespace Torgash\Seo\Invariants;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Throwable;
use Torgash\Seo\Subjects;

/**
 * What must be true about what this shop publishes to search engines.
 *
 * ONE statement, and the reason there is only one is ownership: what must be
 * true of a PRODUCT — that no two share a title, that a page has something to
 * say, that there is a photograph — is the catalogue's to state, because it owns
 * both the product and the columns involved. What is genuinely this module's is
 * the ADDRESS: whether what the shop publishes can actually be fetched.
 *
 * And that one can only be answered by ASKING. The cheap version — "does any
 * route match this path" — was written first and thrown away: the pages module
 * registers a fallback route, which matches every path there is, so the check
 * could neither find a broken canonical nor clear a good one. A statement that
 * cannot be false is not a statement.
 *
 * So it is `manual`, like the guard's own uploads self-test and for the same
 * reason: it makes HTTP requests to the shop, which is not something a nightly
 * sweep should do unasked.
 *
 * It names no other module's table. The rows come from the subject registry
 * (`seo.subjects`), which the owning modules answer, and the only column read is
 * `canonical_url` — part of core's PageSubject contract, so every kind of page
 * has it under that name by definition.
 *
 * Answered lazily through `guard.invariants`; with the guard absent nothing here
 * is ever asked.
 */
class SeoInvariants
{
    public function __construct(protected Subjects $subjects) {}

    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'seo.published_addresses_answer' => [
                'label' => 'Every address the shop publishes answers',
                'explain' => 'The shop asks itself for the first addresses in its own sitemap and for every canonical address typed on a record, and reports anything that does not answer 200. An address a crawler is invited to and then refused wastes the one thing a sitemap is for; a canonical that leads nowhere is worse, because it tells the engine to index nothing at all. Run it after a large import or a move; it is not part of the nightly sweep, because it is the shop making requests to itself.',
                'manual' => true,
                'check' => fn (int $limit) => $this->addressesAnswer($limit),
            ],
        ];
    }

    /**
     * Ask the shop for its own published addresses.
     *
     * Over HTTP on purpose: the question is what a crawler gets, which includes
     * the web server, the redirects and whatever a module does to the response.
     * Bounded by the limit the engine hands over, and the sentence says so — a
     * shop with forty thousand products is not going to be fetched whole by
     * somebody pressing a button.
     *
     * @return list<array<string, mixed>>
     */
    protected function addressesAnswer(int $limit): array
    {
        $found = [];

        foreach ($this->addresses($limit) as $url => $where) {
            try {
                $status = Http::withoutVerifying()->timeout(10)->get($url)->status();
            } catch (Throwable $e) {
                $found[] = ['id' => $url, 'title' => $url, 'detail' => $e->getMessage(), 'url' => $where];

                continue;
            }

            if ($status !== 200) {
                $found[] = [
                    'id' => $url,
                    'title' => $url,
                    'detail' => ['Answered :status', ['status' => $status]],
                    'url' => $where,
                ];
            }
        }

        return $found;
    }

    /**
     * What to ask for: the head of the sitemap, then every canonical a person
     * has typed on a record.
     *
     * The canonicals come from the subject registry, so no table of another
     * module is named — and the only column read is `canonical_url`, which core's
     * PageSubject contract guarantees every kind of page has under that name.
     * One that points at somebody else's site is skipped: their uptime is not
     * this shop's to report on.
     *
     * The address maps to where a person would go to FIX it — the sitemap's own
     * rows to the page itself, a canonical to the record carrying it.
     *
     * @return array<string, string> url => where to go about it
     */
    protected function addresses(int $limit): array
    {
        $addresses = [];

        if (Route::has('catalog.sitemap')) {
            $sitemap = route('catalog.sitemap');

            try {
                $body = Http::withoutVerifying()->timeout(15)->get($sitemap)->body();
                preg_match_all('#<loc>(.*?)</loc>#', $body, $matches);

                foreach (array_slice($matches[1] ?? [], 0, $limit) as $url) {
                    $url = html_entity_decode($url);
                    $addresses[$url] = $url;
                }
            } catch (Throwable) {
                // The sitemap itself is unreachable; it is in the list below as
                // an address like any other and will be reported as such.
                $addresses[$sitemap] = $sitemap;
            }
        }

        foreach ($this->subjects->all() as $definition) {
            if (! $definition['query']) {
                continue;
            }

            $rows = ($definition['query'])()
                ->whereRaw("coalesce(canonical_url, '') <> ''")
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                $url = (string) $row->canonical_url;
                $host = parse_url($url, PHP_URL_HOST);

                // Against the host the shop BUILDS ITS OWN LINKS with, not the
                // one the request arrived on: a multi-domain shop answers on
                // several, and a canonical typed as a full URL names the one it
                // means.
                if ($host !== null && $host !== parse_url(url('/'), PHP_URL_HOST)) {
                    continue;
                }

                $addresses[$host === null ? url($url) : $url] = ($definition['edit_url'])($row);
            }
        }

        return $addresses;
    }
}
