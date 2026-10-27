<?php

namespace Torgash\Newsletter\Http;

use App\Core\Support\FormGuard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Newsletter\Models\Subscriber;
use Torgash\Newsletter\Subscriptions;

/**
 * The storefront's side: the form's answer (htmx swaps the form for it, or a
 * plain POST comes back to the page), the letter's link, the way out, and
 * the account's switch.
 *
 * The letter's links open a PAGE with a button, never act on a GET: a mail
 * service's link scanner opens every link in a letter by itself, and it would
 * confirm addresses — and unsubscribe people — nobody asked about.
 */
class NewsletterController extends Controller
{
    public const FORM = 'newsletter';

    public function subscribe(Request $request, Subscriptions $subscriptions, FormGuard $guard)
    {
        $email = trim((string) $request->input('email'));
        $source = in_array($request->input('source'), ['block', 'cart'], true) ? $request->input('source') : 'block';

        if ($refusal = $guard->check($request, $email ?: null, self::FORM)) {
            // A bot is told it went fine; a person is told why not.
            return $refusal['reason'] === 'trap'
                ? $this->answer($request, 'confirm')
                // The guard's own words for a limit speak of "enquiries"; this is not one.
                : $this->answer($request, 'refused', $refusal['reason'] === 'rate' ? null : ($refusal['message'] ?? null));
        }
        $guard->record($request, $email ?: null, self::FORM);

        $result = $subscriptions->subscribe($email, $source, $request->user(), (string) $request->input('consent_text', ''));

        return $this->answer($request, $result);
    }

    public function confirmPage(string $token)
    {
        $subscriber = Subscriber::where('token', $token)->firstOrFail();

        return view('torgash.newsletter::storefront.confirm', ['subscriber' => $subscriber, 'done' => $subscriber->isSubscribed()]);
    }

    public function confirm(string $token, Subscriptions $subscriptions)
    {
        $subscriber = $subscriptions->confirm($token) ?? abort(404);

        return view('torgash.newsletter::storefront.confirm', ['subscriber' => $subscriber, 'done' => true]);
    }

    public function unsubscribePage(string $token)
    {
        $subscriber = Subscriber::where('token', $token)->firstOrFail();

        return view('torgash.newsletter::storefront.unsubscribe', ['subscriber' => $subscriber, 'done' => $subscriber->status === Subscriber::UNSUBSCRIBED]);
    }

    public function unsubscribe(Request $request, string $token, Subscriptions $subscriptions)
    {
        $subscriber = Subscriber::where('token', $token)->firstOrFail();
        $subscriptions->unsubscribe($subscriber);

        // From a campaign's letter: that campaign counts the unsubscribe.
        $delivery = \Torgash\Newsletter\Models\Delivery::where('token', (string) $request->input('d'))->where('subscriber_id', $subscriber->id)->first();
        if ($delivery) {
            app(\Torgash\Newsletter\Campaigns\Tracking::class)->unsubscribed($delivery);
        }

        return view('torgash.newsletter::storefront.unsubscribe', ['subscriber' => $subscriber, 'done' => true]);
    }

    public function account(Request $request, Subscriptions $subscriptions)
    {
        return view('torgash.newsletter::storefront.account', ['subscriber' => $subscriptions->of($request->user())]);
    }

    public function accountSave(Request $request, Subscriptions $subscriptions)
    {
        $user = $request->user();
        $subscriber = $subscriptions->of($user);

        if ($request->boolean('subscribed')) {
            // An account may have no email (signed up with a phone).
            if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
                return back()->with('status', __('Your account has no email yet — add one in the profile first.'));
            }

            $result = $subscriptions->subscribe((string) $user->email, 'account', $user, __('Subscribed from the account.'));

            return back()->with('status', $result === 'confirm'
                ? __('We have sent a letter to :email: the subscription starts once you follow its link.', ['email' => $user->email])
                : __('You are subscribed.'));
        }

        if ($subscriber) {
            $subscriptions->unsubscribe($subscriber);
        }

        return back()->with('status', __('You are unsubscribed.'));
    }

    protected function answer(Request $request, string $result, ?string $message = null)
    {
        $text = $message ?? match ($result) {
            'subscribed' => __('You are subscribed. Thank you!'),
            'confirm' => __('Almost there: we have sent you a letter — follow its link to confirm the subscription.'),
            'already' => __('This address is already subscribed.'),
            'invalid' => __('That does not look like an email address.'),
            default => __('Please try again in a minute.'),
        };
        $ok = in_array($result, ['subscribed', 'confirm', 'already'], true);

        if ($request->header('HX-Request')) {
            return view('torgash.newsletter::blocks.answer', ['newsletterText' => $text, 'newsletterOk' => $ok]);
        }

        return back()->with($ok ? 'status' : 'error', $text);
    }
}
