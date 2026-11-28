<?php
/**
 * Web logos from the brand PNGs in tools/brand (white background → transparent, trimmed):
 *   public/assets/image/logo-mark.png|webp   the stone mark (header, footers, favicon, app icon source)
 *   public/assets/image/logo-full.png|webp   mark + "Ma Sarada · Marble & Sanitation" (intro, documents)
 *   public/assets/image/favicon.png          192 px mark
 * Run: php tools/make-logos.php, then `cd mobile && php tools/make-assets.php` for the app icons.
 */
$src = __DIR__ . '/brand/ma-sarada-name-logo.png';
$out = dirname(__DIR__) . '/public/assets/image';

function keyed(GdImage $im): GdImage
{
    [$w, $h] = [imagesx($im), imagesy($im)];
    $o = imagecreatetruecolor($w, $h);
    imagealphablending($o, false);
    imagesavealpha($o, true);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            $a = max(0, min(1, (245 - min($r, $g, $b)) / 115));
            if ($a <= 0) {
                imagesetpixel($o, $x, $y, imagecolorallocatealpha($o, 255, 255, 255, 127));
                continue;
            }
            if ($a < 1) {
                [$r, $g, $b] = solid($im, $x, $y, [$r, $g, $b]);
            }
            imagesetpixel($o, $x, $y, imagecolorallocatealpha($o, $r, $g, $b, (int) round(127 * (1 - $a))));
        }
    }

    return $o;
}

/** Colour of the nearest solid (non-edge) pixel, so soft edges don't keep a light rim. */
function solid(GdImage $im, int $x, int $y, array $fallback): array
{
    [$w, $h] = [imagesx($im), imagesy($im)];
    for ($d = 1; $d <= 10; $d++) {
        for ($dy = -$d; $dy <= $d; $dy++) {
            foreach ([-$d, $d] as $dx) {
                foreach ([[$x + $dx, $y + $dy], [$x + $dy, $y + $dx]] as [$px, $py]) {
                    if ($px < 0 || $py < 0 || $px >= $w || $py >= $h) {
                        continue;
                    }
                    $c = imagecolorat($im, $px, $py);
                    $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                    if (min($rgb) < 130) {
                        return $rgb;
                    }
                }
            }
        }
    }

    return $fallback;
}

function trimmed(GdImage $im, int $pad): GdImage
{
    [$w, $h] = [imagesx($im), imagesy($im)];
    [$x0, $y0, $x1, $y1] = [$w, $h, 0, 0];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ((imagecolorat($im, $x, $y) >> 24 & 127) < 120) {
                $x0 = min($x0, $x); $x1 = max($x1, $x); $y0 = min($y0, $y); $y1 = max($y1, $y);
            }
        }
    }
    $cw = $x1 - $x0 + 1 + 2 * $pad; $ch = $y1 - $y0 + 1 + 2 * $pad;
    $o = imagecreatetruecolor($cw, $ch);
    imagealphablending($o, false);
    imagesavealpha($o, true);
    imagefill($o, 0, 0, imagecolorallocatealpha($o, 0, 0, 0, 127));
    imagecopy($o, $im, $pad, $pad, $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1);

    return $o;
}

function fit(GdImage $im, int $max): GdImage
{
    [$w, $h] = [imagesx($im), imagesy($im)];
    $k = min(1, $max / max($w, $h));
    $o = imagecreatetruecolor((int) round($w * $k), (int) round($h * $k));
    imagealphablending($o, false);
    imagesavealpha($o, true);
    imagecopyresampled($o, $im, 0, 0, 0, 0, imagesx($o), imagesy($o), $w, $h);

    return $o;
}

function square(GdImage $im, int $size, float $frac): GdImage
{
    $o = imagecreatetruecolor($size, $size);
    imagealphablending($o, false);
    imagesavealpha($o, true);
    imagefill($o, 0, 0, imagecolorallocatealpha($o, 0, 0, 0, 127));
    $k = $size * $frac / max(imagesx($im), imagesy($im));
    [$w, $h] = [(int) round(imagesx($im) * $k), (int) round(imagesy($im) * $k)];
    imagecopyresampled($o, $im, intdiv($size - $w, 2), intdiv($size - $h, 2), 0, 0, $w, $h, imagesx($im), imagesy($im));

    return $o;
}

function save(GdImage $im, string $base, bool $webp = true): void
{
    imagepng($im, "$base.png", 9);
    echo "  $base.png " . imagesx($im) . 'x' . imagesy($im) . "\n";
    if ($webp) {
        imagewebp($im, "$base.webp", 90);
        echo "  $base.webp\n";
    }
}

$full = keyed(imagecreatefrompng($src));
$mark = trimmed(imagecrop($full, ['x' => 600, 'y' => 500, 'width' => 720, 'height' => 680]), 0);
save($mark, "$out/logo-mark");
save(fit(trimmed($full, 24), 1200), "$out/logo-full");
save(square($mark, 192, .9), "$out/favicon", false);
