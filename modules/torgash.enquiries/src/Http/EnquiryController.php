<?php

namespace Torgash\Enquiries\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Enquiries\Kinds;
use Torgash\Enquiries\Submit;

/**
 * The storefront's side: one POST, whatever the form was for.
 *
 * The kind comes from the form, is checked against the registry, and decides
 * everything else — which fields are validated, whether a subject is required,
 * what the manager's email says. So a new kind needs no route and no controller
 * of its own.
 */
class EnquiryController extends Controller
{
    public function store(Request $request, Submit $submit)
    {
        $kind = (string) $request->input('kind', '');

        abort_unless(Kinds::exists($kind), 404);

        $subject = $this->subject($request, $kind);

        try {
            $submit->handle($request, $kind, $subject + ['page_url' => $request->input('page_url')]);
        } catch (ValidationException $e) {
            // Without HTMX this is Laravel's own redirect-back-with-errors. With
            // it, the form is inside a dialog: a redirect would close the dialog
            // and throw away everything typed, so the same form comes back with
            // its errors and replaces itself in place.
            if (! $request->header('HX-Request')) {
                throw $e;
            }

            // 200, not the honest 422: HTMX does not swap a 4xx at all, so the
            // re-rendered form went into the console as an error and the
            // customer saw NOTHING happen. Rare while the only refusals were a
            // honeypot (answered as success) and a rate limit; an everyday
            // event the moment a CAPTCHA is switched on, which is how it was
            // found. Same answer the admin's inline product editor already
            // gives, and the reason it is not a global HTMX handler: a theme
            // that did not copy the handler would silently lose it again.
            return response()->view('torgash.enquiries::partials.form', [
                'enquiryKind' => $kind,
                'enquirySubjectId' => $subject['subject_id'],
                'enquiryErrors' => $e->validator->errors(),
                // Explicitly, never old(): there was no redirect, so nothing
                // was flashed, and a wrong sum would otherwise wipe the
                // question they had just typed out.
                'enquiryValues' => $request->all(),
            ]);
        }

        // A form the shop built says its own thank-you (PLAN §48).
        $message = (Kinds::find($kind)['form'] ?? null)?->t('success') ?: __('Thank you — we will be in touch.');

        // HTMX swaps the form for the thank-you in place; a browser without it
        // (or with JavaScript off) gets the page back with a flash. The same
        // form markup serves both, which is why the answer is not JSON.
        if ($request->header('HX-Request')) {
            return response()->view('torgash.enquiries::partials.sent', ['message' => $message]);
        }

        return back()->with('status', $message);
    }

    /**
     * What the enquiry is about, taken from the form and CHECKED, never
     * trusted: subject_type is what the kind declares it must be, so a POST
     * cannot file a callback against an order it invented.
     *
     * @return array{subject_type: string|null, subject_id: int|null}
     */
    protected function subject(Request $request, string $kind): array
    {
        $expected = Kinds::find($kind)['subject'] ?? null;

        if (! $expected) {
            return ['subject_type' => null, 'subject_id' => null];
        }

        $id = (int) $request->input('subject_id');

        abort_if($id <= 0, 422);

        return ['subject_type' => $expected, 'subject_id' => $id];
    }
}
