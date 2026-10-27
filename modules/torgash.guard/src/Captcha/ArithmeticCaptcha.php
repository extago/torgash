<?php

namespace Torgash\Guard\Captcha;

use GdImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The one in the box: a small sum, drawn as a picture.
 *
 * The owner's design, and the reasoning is his: the same principle as Bitrix's
 * — the text is RENDERED INTO AN IMAGE rather than into HTML, so reading it
 * means looking at pixels — but the alphabet is digits and three signs instead
 * of letters. That buys two things at once. A ten-glyph alphabet with a known
 * grammar is far harder for a naive OCR bot to brute-force per unit of
 * distortion, so the distortion can stay MILD; and "what is seven plus two" is
 * a great deal kinder than six warped letters where an l, a 1 and an I are the
 * same three pixels.
 *
 * No font file is bundled, deliberately. GD's built-in bitmap font is upscaled
 * with resampling (which smooths it), each character is rotated and nudged on
 * its own, and a little noise goes over the top. That defeats the actual threat
 * — a fixed-grid template matcher — without a binary blob in the repository or
 * a font licence to argue about, and it works on a PHP built without FreeType.
 *
 * What it is NOT is a defence against a human solver farm or a modern OCR
 * service; nothing at this price is. It is the last cheap layer under the
 * honeypot, the form-age stamp and the rate limits, for the one form that is
 * being spammed anyway.
 */
class ArithmeticCaptcha implements Provider
{
    public static function key(): string
    {
        return 'arithmetic';
    }

    public static function label(): string
    {
        return 'Arithmetic (built in, no account needed)';
    }

    public function unavailable(): ?string
    {
        return extension_loaded('gd')
            ? null
            : __('PHP here was built without the GD extension, so no image can be drawn. Install it, or choose another provider.');
    }

    public function render(string $form): string
    {
        $nonce = Str::random(16);

        return view('torgash.guard::captcha.arithmetic', [
            'form' => $form,
            'nonce' => $nonce,
        ])->render();
    }

    public function verify(Request $request, string $form): bool
    {
        $nonce = (string) $request->input('captcha_id', '');
        $given = trim((string) $request->input('captcha', ''));

        if ($nonce === '' || $given === '' || ! ctype_digit(ltrim($given, '-'))) {
            return false;
        }

        $answer = Challenge::take($form, $nonce);

        return $answer !== null && $answer === (int) $given;
    }

    /**
     * A new sum for this nonce, stored, drawn, and handed back as PNG bytes.
     *
     * Called by the image route — which means asking for the image again is
     * what "give me another one" does, and a failed attempt leaves nothing
     * behind to retry against.
     */
    public function image(string $form, string $nonce): string
    {
        [$text, $answer] = $this->problem();

        Challenge::put($form, $nonce, $answer, max(60, (int) setting('torgash.guard', 'captcha_ttl', 10) * 60));

        return $this->draw($text);
    }

    /**
     * The sum. Addition and subtraction only, never below zero and never above
     * twenty: the point is to be trivial for a person and awkward for a script
     * reading pixels, and "17 × 8" is only the first of those.
     *
     * @return array{0: string, 1: int}
     */
    protected function problem(): array
    {
        if (random_int(0, 1) === 0) {
            $a = random_int(1, 9);
            $b = random_int(1, 9);

            return [sprintf('%d + %d = ?', $a, $b), $a + $b];
        }

        // Bigger first, so the answer is never negative — a minus sign in the
        // ANSWER box is a thing people get wrong for no useful reason.
        $a = random_int(5, 18);
        $b = random_int(1, $a - 1);

        return [sprintf('%d - %d = ?', $a, $b), $a - $b];
    }

    /** PNG bytes for the expression. */
    protected function draw(string $text): string
    {
        $scale = 3;
        $pad = 10;
        $glyphW = 10;
        $glyphH = 16;

        $canvas = imagecreatetruecolor(strlen($text) * $glyphW * $scale + $pad * 2, $glyphH * $scale + $pad * 2);
        imagealphablending($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));

        $x = $pad;

        foreach (str_split($text) as $char) {
            if ($char === ' ') {
                $x += 8;

                continue;
            }

            $glyph = $this->glyph($char, $scale, $glyphW, $glyphH);
            $rotated = imagerotate($glyph, random_int(-11, 11), imagecolorallocatealpha($glyph, 255, 255, 255, 127));
            imagealphablending($rotated, true);
            imagesavealpha($rotated, true);

            imagecopy($canvas, $rotated, $x, $pad + random_int(-4, 4), 0, 0, imagesx($rotated), imagesy($rotated));

            // Uneven spacing, so the glyphs do not sit on a grid something can
            // slice into fixed-width columns and match one at a time.
            $x += (int) ($glyphW * $scale * 0.88) + random_int(-2, 4);

            imagedestroy($glyph);
            imagedestroy($rotated);
        }

        $this->noise($canvas);

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);

        return $png;
    }

    /** One character, drawn small and resampled up so the edges are soft. */
    protected function glyph(string $char, int $scale, int $w, int $h): GdImage
    {
        $small = imagecreatetruecolor($w, $h);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 255, 255, 255, 127));

        $ink = imagecolorallocate($small, 20, 24, 34);
        // Twice, a pixel apart: the built-in font is thin, and a thin glyph
        // survives the upscale as a grey smudge.
        imagestring($small, 5, 0, 0, $char, $ink);
        imagestring($small, 5, 1, 0, $char, $ink);

        $big = imagecreatetruecolor($w * $scale, $h * $scale);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 255, 255, 255, 127));
        imagecopyresampled($big, $small, 0, 0, 0, 0, $w * $scale, $h * $scale, $w, $h);

        imagedestroy($small);

        return $big;
    }

    /** Speckle and a couple of lines — enough to spoil a clean threshold. */
    protected function noise(GdImage $canvas): void
    {
        $w = imagesx($canvas);
        $h = imagesy($canvas);
        $ink = imagecolorallocate($canvas, 130, 140, 160);

        for ($i = 0; $i < 260; $i++) {
            imagesetpixel($canvas, random_int(0, $w - 1), random_int(0, $h - 1), $ink);
        }

        for ($i = 0; $i < 2; $i++) {
            imageline($canvas, 0, random_int(8, $h - 8), $w, random_int(8, $h - 8), $ink);
        }
    }
}
