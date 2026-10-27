<?php

namespace Torgash\Api\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Api\Models\ApiToken;

class TokensController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['active', 'expired', 'unused'], true) ? (string) $request->query('status') : '',
        ];

        // A shop has a handful of tokens: filtered in PHP, over the name and
        // the owner's email.
        $all = ApiToken::with('user')->latest('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (ApiToken $token) => mb_stripos($token->name.' '.($token->user?->email ?? ''), $filters['q']) !== false);

        $by = [
            'active' => fn (ApiToken $token) => ! $token->isExpired(),
            'expired' => fn (ApiToken $token) => $token->isExpired(),
            'unused' => fn (ApiToken $token) => $token->last_used_at === null,
        ];

        return view('torgash.api::admin.tokens', [
            'tokens' => ($filters['status'] !== '' ? $found->filter($by[$filters['status']]) : $found)->values(),
            'filters' => $filters,
            'statusCounts' => ['' => $found->count()] + array_map(fn ($test) => $found->filter($test)->count(), $by),
        ]);
    }

    /** Revoke several at once — each through the model, so the audit hears it. */
    public function bulk(Request $request)
    {
        $validated = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);

        $tokens = ApiToken::whereIn('id', $validated['ids'])->get();
        $tokens->each->delete();

        return back()->with('status', trans_choice(':count token revoked.|:count tokens revoked.', $tokens->count(), ['count' => $tokens->count()]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'abilities' => 'nullable|array',
            'abilities.*' => 'in:read,write',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        // Issued to the person pressing the button: a token can never do more
        // than its owner may, so "who is this integration" has an answer that
        // is a real account with real permissions, not a floating key.
        [, $plaintext] = ApiToken::issue(
            $request->user(),
            $validated['name'],
            $validated['abilities'] ?? null,
            isset($validated['expires_in_days']) ? now()->addDays((int) $validated['expires_in_days']) : null,
        );

        // Flashed once and never stored: this is the only moment the token
        // exists in readable form anywhere.
        return back()->with('status', __('Token created.'))->with('new_token', $plaintext);
    }

    public function destroy(ApiToken $token)
    {
        $token->delete();

        return back()->with('status', __('Token revoked.'));
    }
}
