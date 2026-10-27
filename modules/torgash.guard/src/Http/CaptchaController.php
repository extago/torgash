<?php

namespace Torgash\Guard\Http;

use App\Core\Support\FormGuard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Torgash\Guard\Captcha\ArithmeticCaptcha;

/**
 * The picture.
 *
 * A public GET that WRITES to the session, which is unusual enough to say out
 * loud: fetching the image is what creates the challenge, and that is the whole
 * point — the answer is never in the page. Re-fetching the same address draws a
 * fresh sum under the same nonce, which is what the "another one" button does
 * and what makes "regenerate on error" free.
 *
 * Two things are checked before anything is drawn, both because this is a
 * public address anybody can hammer: the form must be one the shop actually
 * has (a name from the registry, not whatever is in the URL), and the nonce
 * must look like one of ours. Beyond that the session cap in `Challenge` is
 * what stops a script growing somebody's session file.
 */
class CaptchaController extends Controller
{
    public function __invoke(string $form, string $nonce, ArithmeticCaptcha $captcha): Response
    {
        abort_unless(array_key_exists($form, FormGuard::forms()), 404);
        abort_unless(preg_match('/^[A-Za-z0-9]{16}$/', $nonce) === 1, 404);
        abort_if($captcha->unavailable() !== null, 404);

        return response($captcha->image($form, $nonce), 200, [
            'Content-Type' => 'image/png',
            // Never cached, anywhere. A cached image is a picture of a sum
            // whose answer the session has already forgotten, and the customer
            // is told they typed it wrong.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
