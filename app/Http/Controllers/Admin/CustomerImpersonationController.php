<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\Impersonation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The two buttons of {@see Impersonation}: on the customer's card, and on the
 * strip the storefront shows while it lasts. Both POST: a link that changes
 * who you are is one a browser may follow on its own.
 */
class CustomerImpersonationController extends Controller
{
    public function start(Request $request, User $customer, Impersonation $impersonation)
    {
        // An account with a role is staff, and staff are never entered: that
        // would be a way to borrow somebody's permissions.
        abort_if($customer->isStaff(), 404);

        $impersonation->start($request, $request->user(), $customer);

        return redirect()->route('account.dashboard');
    }

    public function stop(Request $request, Impersonation $impersonation)
    {
        return $impersonation->leave($request);
    }
}
