<?php

namespace Torgash\Privacy\Http;

use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Privacy\Models\PrivacyRequest;
use Torgash\Privacy\Requests;

/**
 * "My data" in the account (PLAN §49): download everything the shop keeps
 * about me, at once; ask for it to be erased — which a manager carries out,
 * since an unpaid order or an open return is not something to delete by a
 * click.
 */
class AccountPrivacyController extends Controller
{
    public function index(Request $request)
    {
        return view('torgash.privacy::account.index', [
            'requests' => PrivacyRequest::where('user_id', $request->user()->id)->latest('id')->get(),
            'open' => PrivacyRequest::where('user_id', $request->user()->id)->open()->where('type', PrivacyRequest::ERASE)->first(),
        ]);
    }

    public function download(Request $request, PersonalData $data)
    {
        $user = $request->user();
        // The ACCOUNT's rows only, never "everything under this email": a
        // guest order left under the same address before the account existed
        // is not provably this person's — the inbox rule (§29.3/1). A manager
        // answering a request can widen it to the contacts.
        $export = ['generated_at' => now()->toIso8601String()] + $data->export(new Subject($user));

        return response()->streamDownload(
            fn () => print json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'my-data-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function erase(Request $request)
    {
        // The password, typed again: the request closes the account, and a
        // signed-in phone in somebody else's hands is not its owner. Laravel's
        // own rule, as for a new password or switching the second factor off.
        $request->validate(['current_password' => ['required', 'current_password']], [
            'current_password.required' => __('Type your password.'),
            'current_password.current_password' => __('That is not your password.'),
        ]);

        $user = $request->user();

        $already = PrivacyRequest::where('user_id', $user->id)->open()->where('type', PrivacyRequest::ERASE)->exists();

        if (! $already) {
            // The contacts go with it only where they are CONFIRMED: then the
            // guest orders and enquiries under them are this person's too.
            Requests::open(
                $user,
                PrivacyRequest::ERASE,
                null,
                $user->email_verified_at ? $user->email : null,
                $user->phone_verified_at ? $user->phone : null,
                $request->string('note')->limit(1000)->toString() ?: null,
            );
        }

        return redirect()->route('account.privacy.index')
            ->with('status', __('Your request is with us. We will erase your data and let you know — at the latest by the date shown below.'));
    }
}
