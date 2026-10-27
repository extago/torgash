<?php

namespace App\Http\Controllers\Admin;

use App\Core\Verification\Channels;
use App\Core\Verification\Verifier;
use App\Http\Controllers\Controller;
use App\Models\VerificationCode;
use Illuminate\Http\Request;

/**
 * Admin → Verification codes: every code the shop sent, what for, by which
 * channel and how it ended — the one place a customer's "the code never
 * came" can be answered, and where a channel starting to fail shows first.
 * The rows are the codes themselves (never the digits — only a hash is kept).
 */
class VerificationCodesController extends Controller
{
    public const STATUSES = ['sent', 'verified', 'invalid_address', 'unreachable', 'temporary', 'pending'];

    public function index(Request $request, Channels $channels)
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $search = trim((string) $request->query('q', ''));

        $codes = VerificationCode::query()
            ->with('user:id,name,email')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where('address', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $today = VerificationCode::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('channel, count(*) as codes, sum(sends) as sends, sum(case when verified_at is not null then 1 else 0 end) as verified, sum(case when status in (\'invalid_address\', \'unreachable\', \'temporary\') then 1 else 0 end) as failed')
            ->groupBy('channel')
            ->get();

        $counts = VerificationCode::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.verification-codes.index', [
            'codes' => $codes,
            'counts' => $counts,
            'today' => $today,
            'status' => $status,
            'search' => $search,
            'channels' => $channels->all(),
            'purposes' => Verifier::names() + Verifier::purposes(),
        ]);
    }

    /**
     * A provider's answer, taken apart for the screen: the HTTP status, the
     * body pretty-printed when it is JSON, and ONE short line — the most
     * specific status or error word inside it (`NOT_ALLOWED_RECIPIENT_COUNTRY`),
     * which is what a person scanning the list needs; the rest opens on click.
     *
     * @return array{http: ?string, body: string, short: ?string}
     */
    public static function answer(?string $raw): array
    {
        $raw = (string) $raw;
        $http = preg_match('/^HTTP (\d{3}): /', $raw, $m) ? $m[1] : null;
        $body = $http ? substr($raw, strlen($m[0])) : $raw;
        $json = json_decode($body, true);

        if (! is_array($json)) {
            return ['http' => $http, 'body' => $body, 'short' => $http ? null : \Illuminate\Support\Str::limit($body, 80)];
        }

        $short = null;
        array_walk_recursive($json, function ($value, $key) use (&$short) {
            if (is_string($value) && preg_match('/status|error|description/i', (string) $key)
                && ! preg_match('/^(ok|success\w*|true)$/i', $value)) {
                $short = $value;   // the last one found is the deepest: a recipient's own answer
            }
        });

        return [
            'http' => $http,
            'body' => json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'short' => $short,
        ];
    }
}
