<?php

namespace App\Http\Controllers\Account;

use App\Core\Access\AccountContact;
use App\Core\Support\Phones;
use App\Core\Verification\FormVerification;
use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public const FORM = 'account.register';

    /**
     * Proving a phone put on an account (PLAN §44, 3b). Not a choice the shop
     * ticks: a phone signs in and gets the account back, so it is written
     * only once proven — here as everywhere else.
     */
    public const PHONE_FORM = 'account.phone';

    public function create(AccountContact $contact)
    {
        return view('account.register', ['contact' => $contact]);
    }

    /**
     * Which contacts are asked is the shop's setting (AccountContact): an
     * email, a phone, either, or both. A phone is proven by a code on this
     * very form; an email is proven only where the shop asks this form to
     * (`verify_forms`), as before.
     */
    public function store(Request $request, AccountContact $contact)
    {
        $either = $contact->eitherWillDo();

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'email' => array_values(array_filter([
                $contact->requires('email') ? 'required' : 'nullable',
                $either ? 'required_without:phone' : null,
                'email', 'max:190', Rule::unique('users', 'email'),
            ])),
            'phone' => array_values(array_filter([
                $contact->asks('phone') ? ($contact->requires('phone') ? 'required' : 'nullable') : 'prohibited',
                $either ? 'required_without:email' : null,
                'string', 'max:40',
            ])),
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $email = filled($validated['email'] ?? null) ? $validated['email'] : null;
        $phone = null;

        if (filled($validated['phone'] ?? null)) {
            $phone = Phones::parse($validated['phone'], $request->input('country') ?: Phones::defaultCountry());

            if (! $phone) {
                throw ValidationException::withMessages(['phone' => __('This does not look like a phone number. Type it with the country code, like :example.', ['example' => Phones::callingCode(Phones::defaultCountry()).' …'])]);
            }

            if (User::where('phone', $phone['e164'])->exists()) {
                throw ValidationException::withMessages(['phone' => __('This address is already bound to another account. Type another one.')]);
            }
        }

        $verification = app(FormVerification::class);

        if ($phone && ! $verification->verified($request, 'phone', $phone['e164'])) {
            throw ValidationException::withMessages(['phone' => __('Confirm the phone: press "Get a code" and type the code we send.')]);
        }

        // The shop may ask the email to be proven before an account is made
        // (PLAN §44) — accounts registered by the thousand to someone else's
        // address are the same abuse as fake orders.
        if ($email && ($refusal = $verification->refusal($request, self::FORM, ['email' => $email]))) {
            throw ValidationException::withMessages(['email' => $refusal]);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $email,
            'password' => $validated['password'],
            'customer_group_id' => CustomerGroup::where('is_default', true)->value('id'),
        ]);

        // Proven in this browser a minute ago — which is what "verified" means.
        $user->forceFill(array_filter([
            'email_verified_at' => $email && $verification->verified($request, 'email', mb_strtolower($email)) ? now() : null,
            'phone' => $phone['e164'] ?? null,
            'phone_country' => $phone['country'] ?? null,
            'phone_verified_at' => $phone ? now() : null,
        ]))->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('account.dashboard'));
    }
}
