<?php

namespace Torgash\Media;

use GdImage;

/**
 * Crops the uniform border off an image — the "white background" trim.
 *
 * Studio and supplier photos routinely arrive with wide empty margins. In a
 * fixed-size catalogue cell those margins are what gets scaled to fit, so the
 * product itself ends up small and every card looks differently zoomed. Cutting
 * the border makes the subject fill the frame consistently.
 *
 * Implemented on GD because this build has no Imagick (the add-on this
 * mirrors leans on Imagick::trimImage). Two things keep it honest:
 *
 *   - the bounding box is measured on a DOWNSCALED probe (long side ~300px).
 *     Scanning a 2400×2400 original pixel by pixel in PHP costs seconds; the
 *     probe costs milliseconds and is then mapped back with a one-probe-pixel
 *     safety margin, so the result errs towards keeping a sliver rather than
 *     shaving the product;
 *   - it refuses rather than guesses. If the four corners disagree, there is no
 *     uniform border to speak of; if the box comes out implausibly small, the
 *     image is probably a gradient or a flat-lay and trimming would eat it. In
 *     both cases the file is left exactly as it was.
 */
class BorderTrimmer
{
    /** Long side of the probe used to find the border. */
    protected const PROBE = 300;

    /**
     * Smallest result worth writing, per side.
     *
     * This started out as a share-of-area guard (keep at least 5%), which was
     * wrong: a small product photographed on a big white sweep is EXACTLY the
     * case this feature exists for, and a 200×100 subject on an 800×600 sheet
     * is 4.5% of the area — the guard rejected the very thing it was meant to
     * serve. The real failure to protect against is a degenerate box, so the
     * limit is now absolute. A blank image is already handled earlier, by
     * refusing when no row differs from the background at all.
     */
    protected const MIN_SIDE = 16;

    /**
     * Trims $path in place. Returns true when the file was actually rewritten.
     *
     * @param  float  $tolerance  0..0.5, share of the 0-255 channel range a pixel
     *                            may differ from the corner colour and still
     *                            count as background
     */
    public function trim(string $path, float $tolerance): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! $this->canWrite($extension)) {
            return false;
        }

        $contents = @file_get_contents($path);
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            return false;
        }

        // No imagedestroy() anywhere here: it is deprecated as of PHP 8.5 and
        // has done nothing since 8.0 — GdImage is garbage-collected.
        $box = $this->boundingBox($image, $tolerance);

        if ($box === null) {
            return false;
        }

        [$x, $y, $w, $h] = $box;
        $cropped = @imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);

        return $cropped instanceof GdImage && $this->write($cropped, $path, $extension);
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null [x, y, width, height], or null to leave the image alone
     */
    protected function boundingBox(GdImage $image, float $tolerance): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 4 || $height < 4) {
            return null;
        }

        $scale = max($width, $height) > self::PROBE ? self::PROBE / max($width, $height) : 1.0;
        $probe = $scale < 1.0
            ? imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)))
            : $image;

        if (! $probe instanceof GdImage) {
            return null;
        }

        $pw = imagesx($probe);
        $ph = imagesy($probe);
        $background = $this->uniformCorner($probe, $pw, $ph, $tolerance);

        if ($background === null) {
            return null;
        }

        $bounds = $this->scanProbe($probe, $pw, $ph, $background, $tolerance);

        if ($bounds === null) {
            return null;
        }

        [$left, $top, $right, $bottom] = $bounds;

        // Nothing to cut: the content already reaches every edge.
        if ($left === 0 && $top === 0 && $right === $pw - 1 && $bottom === $ph - 1) {
            return null;
        }

        // Back to full-size coordinates, one probe pixel wider on each side.
        $factor = $scale < 1.0 ? 1 / $scale : 1.0;
        $margin = (int) ceil($factor);

        $x = max(0, (int) floor($left * $factor) - $margin);
        $y = max(0, (int) floor($top * $factor) - $margin);
        $x2 = min($width - 1, (int) ceil($right * $factor) + $margin);
        $y2 = min($height - 1, (int) ceil($bottom * $factor) + $margin);

        $w = $x2 - $x + 1;
        $h = $y2 - $y + 1;

        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) {
            return null;
        }

        return [$x, $y, $w, $h];
    }

    /**
     * The background colour, taken from the corners — but only if all four
     * agree. Disagreement means there is no border to trim (a photo bled to the
     * edge, a gradient), and guessing from one corner would cut into content.
     *
     * @return array{r:int,g:int,b:int,a:int}|null
     */
    protected function uniformCorner(GdImage $probe, int $w, int $h, float $tolerance): ?array
    {
        $corners = [
            $this->pixel($probe, 0, 0),
            $this->pixel($probe, $w - 1, 0),
            $this->pixel($probe, 0, $h - 1),
            $this->pixel($probe, $w - 1, $h - 1),
        ];

        foreach ($corners as $corner) {
            if (! $this->matches($corner, $corners[0], $tolerance)) {
                return null;
            }
        }

        return $corners[0];
    }

    /**
     * @param  array{r:int,g:int,b:int,a:int}  $background
     * @return array{0:int,1:int,2:int,3:int}|null [left, top, right, bottom] in probe coordinates
     */
    protected function scanProbe(GdImage $probe, int $w, int $h, array $background, float $tolerance): ?array
    {
        $top = null;
        for ($y = 0; $y < $h && $top === null; $y++) {
            if (! $this->rowIsBackground($probe, $w, $y, $background, $tolerance)) {
                $top = $y;
            }
        }

        // Every row is background: the image is blank. Trimming it to nothing
        // helps no one, so it is left alone.
        if ($top === null) {
            return null;
        }

        $bottom = $h - 1;
        for ($y = $h - 1; $y > $top; $y--) {
            if (! $this->rowIsBackground($probe, $w, $y, $background, $tolerance)) {
                break;
            }
            $bottom = $y - 1;
        }

        $left = 0;
        for ($x = 0; $x < $w; $x++) {
            if (! $this->columnIsBackground($probe, $top, $bottom, $x, $background, $tolerance)) {
                break;
            }
            $left = $x + 1;
        }

        $right = $w - 1;
        for ($x = $w - 1; $x > $left; $x--) {
            if (! $this->columnIsBackground($probe, $top, $bottom, $x, $background, $tolerance)) {
                break;
            }
            $right = $x - 1;
        }

        return [$left, $top, min($right, $w - 1), min($bottom, $h - 1)];
    }

    /** @param array{r:int,g:int,b:int,a:int} $background */
    protected function rowIsBackground(GdImage $probe, int $w, int $y, array $background, float $tolerance): bool
    {
        for ($x = 0; $x < $w; $x++) {
            if (! $this->matches($this->pixel($probe, $x, $y), $background, $tolerance)) {
                return false;
            }
        }

        return true;
    }

    /** @param array{r:int,g:int,b:int,a:int} $background */
    protected function columnIsBackground(GdImage $probe, int $top, int $bottom, int $x, array $background, float $tolerance): bool
    {
        for ($y = $top; $y <= $bottom; $y++) {
            if (! $this->matches($this->pixel($probe, $x, $y), $background, $tolerance)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{r:int,g:int,b:int,a:int} */
    protected function pixel(GdImage $image, int $x, int $y): array
    {
        $rgba = imagecolorat($image, $x, $y);

        return [
            'r' => ($rgba >> 16) & 0xFF,
            'g' => ($rgba >> 8) & 0xFF,
            'b' => $rgba & 0xFF,
            // GD alpha is 0 (opaque) to 127 (transparent).
            'a' => ($rgba >> 24) & 0x7F,
        ];
    }

    /**
     * Two fully transparent pixels match whatever their colour channels hold —
     * a PNG's invisible area often carries leftover RGB that would otherwise
     * read as content.
     *
     * @param  array{r:int,g:int,b:int,a:int}  $pixel
     * @param  array{r:int,g:int,b:int,a:int}  $reference
     */
    protected function matches(array $pixel, array $reference, float $tolerance): bool
    {
        if ($pixel['a'] === 127 && $reference['a'] === 127) {
            return true;
        }

        $limit = $tolerance * 255;

        return abs($pixel['r'] - $reference['r']) <= $limit
            && abs($pixel['g'] - $reference['g']) <= $limit
            && abs($pixel['b'] - $reference['b']) <= $limit
            && abs($pixel['a'] - $reference['a']) <= $tolerance * 127;
    }

    protected function canWrite(string $extension): bool
    {
        return match ($extension) {
            'jpg', 'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            'gif' => function_exists('imagegif'),
            'webp' => function_exists('imagewebp'),
            default => false,
        };
    }

    protected function write(GdImage $image, string $path, string $extension): bool
    {
        if (in_array($extension, ['png', 'gif', 'webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        return match ($extension) {
            'jpg', 'jpeg' => imagejpeg($image, $path, ImageSettings::originalQuality()),
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            'webp' => imagewebp($image, $path, ImageSettings::originalQuality()),
            default => false,
        };
    }
}
