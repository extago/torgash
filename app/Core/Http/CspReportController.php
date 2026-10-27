<?php

namespace App\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Where browsers report what the content security policy blocked — or, in
 * "report only", WOULD have blocked (PLAN §53). Written to
 * storage/logs/csp-*.log, one line per distinct violation a day: the same
 * inline handler on a page every visitor opens is one thing to fix, not ten
 * thousand lines.
 *
 * Outside the `web` group (no session, no CSRF: a browser sends these on its
 * own) and throttled; nothing a report says is trusted beyond being written
 * down.
 */
class CspReportController
{
    public function __invoke(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $report = is_array($body['csp-report'] ?? null) ? $body['csp-report'] : (is_array($body) ? $body : []);

        $line = array_map(fn ($value) => mb_substr(is_scalar($value) ? (string) $value : '', 0, 300), [
            'directive' => $report['violated-directive'] ?? $report['effective-directive'] ?? '',
            'blocked' => $report['blocked-uri'] ?? '',
            'page' => preg_replace('~\?.*$~', '', (string) ($report['document-uri'] ?? '')),
            'source' => $report['source-file'] ?? '',
            'line' => $report['line-number'] ?? '',
            'sample' => $report['script-sample'] ?? '',
        ]);

        if ($line['directive'] !== '' && Cache::add('csp-report:'.md5(implode('|', $line)), 1, now()->endOfDay())) {
            Log::build(['driver' => 'daily', 'path' => storage_path('logs/csp.log'), 'days' => 14])
                ->warning('CSP '.$line['directive'], $line);
        }

        return response('', 204);
    }
}
