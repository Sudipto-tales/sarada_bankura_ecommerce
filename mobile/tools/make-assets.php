<?php
/**
 * App icons and splash from the stone mark (public/assets/image/logo-mark.png, made by
 * tools/make-logos.php). Runs on its own (php tools/make-assets.php) or after @capacitor/assets:
 *
 *   assets/*.png                                        sources (icon-only, splash, splash-dark…)
 *   android/…/mipmap-*dpi/ic_launcher*.png               legacy + round icons, adaptive layers at full size
 *   android/…/mipmap-anydpi-v26/ic_launcher*.xml         adaptive icon with a monochrome layer
 *   android/…/drawable-nodpi/ic_launcher_monochrome.png  themed icon (Android 13+)
 *   android/…/drawable-*dpi/ic_stat_notify.png           white status-bar icon for push
 *   android/…/drawable-…/splash.png                       splash screens, redrawn at their current sizes
 */
$root = dirname(__DIR__);
$res = $root . '/android/app/src/main/res';
$logo = imagecreatefrompng($root . '/../public/assets/image/logo-mark.png');
[$cx, $cy, $cw, $ch] = [0, 0, imagesx($logo), imagesy($logo)];

$cream = [0xF6, 0xF2, 0xEA];
$ink = [0x15, 0x21, 0x1F];

function canvas(int $w, int $h, ?array $bg): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, $bg ? imagecolorallocatealpha($im, ...[...$bg, 0]) : imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);

    return $im;
}

/** Mark centred, $frac of the canvas width (height kept in proportion). */
function crest(GdImage $dst, float $frac): void
{
    global $logo, $cx, $cy, $cw, $ch;
    $w = (int) round(imagesx($dst) * $frac);
    $h = (int) round($w * $ch / $cw);
    imagecopyresampled($dst, $logo, intdiv(imagesx($dst) - $w, 2), intdiv(imagesy($dst) - $h, 2), $cx, $cy, $w, $h, $cw, $ch);
}

/** Same shape, solid white: for monochrome / notification icons. */
function silhouette(GdImage $src): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $out = canvas($w, $h, null);
    imagealphablending($out, false);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $a = (imagecolorat($src, $x, $y) >> 24) & 127;
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, $a));
        }
    }

    return $out;
}

function save(GdImage $im, string $path): void
{
    @mkdir(dirname($path), 0777, true);
    imagepng($im, $path, 9);
    echo "  $path\n";
}

@mkdir($root . '/assets');
$i = canvas(1024, 1024, $cream); crest($i, .62); save($i, $root . '/assets/icon-only.png');
$i = canvas(1024, 1024, null); crest($i, .44); save($i, $root . '/assets/icon-foreground.png');
$i = canvas(1024, 1024, $cream); save($i, $root . '/assets/icon-background.png');
$i = canvas(2732, 2732, $cream); crest($i, .2); save($i, $root . '/assets/splash.png');
$i = canvas(2732, 2732, $ink); crest($i, .2); save($i, $root . '/assets/splash-dark.png');
foreach (glob("$res/drawable*/splash.png") ?: [] as $f) {
    [$w, $h] = getimagesize($f);
    $i = canvas($w, $h, str_contains($f, '-night') ? $ink : $cream);
    $k = (int) round(min($w, $h) * .28);
    imagecopyresampled($i, $logo, intdiv($w - $k, 2), intdiv($h - $k, 2), 0, 0, $k, $k, $cw, $ch);
    save($i, $f);
}

$fg = canvas(432, 432, null); crest($fg, .44);
save(silhouette($fg), $res . '/drawable-nodpi/ic_launcher_monochrome.png');
foreach (['mdpi' => 24, 'hdpi' => 36, 'xhdpi' => 48, 'xxhdpi' => 72, 'xxxhdpi' => 96] as $d => $px) {
    $n = canvas($px, $px, null); crest($n, .92);
    save(silhouette($n), "$res/drawable-$d/ic_stat_notify.png");
}

// Launcher icons. Adaptive layers are 108dp; the mark stays inside the 66dp safe circle.
$dpi = ['mdpi' => 1, 'hdpi' => 1.5, 'xhdpi' => 2, 'xxhdpi' => 3, 'xxxhdpi' => 4];
foreach ($dpi as $d => $k) {
    $a = (int) (108 * $k);
    $i = canvas($a, $a, null); crest($i, .44); save($i, "$res/mipmap-$d/ic_launcher_foreground.png");
    $i = canvas($a, $a, $cream); save($i, "$res/mipmap-$d/ic_launcher_background.png");
    $l = (int) (48 * $k);
    $i = canvas($l, $l, $cream); crest($i, .62); save($i, "$res/mipmap-$d/ic_launcher.png");
    $r = canvas($l, $l, null);
    imagefilledellipse($r, intdiv($l, 2), intdiv($l, 2), $l, $l, imagecolorallocate($r, ...$cream));
    crest($r, .6); save($r, "$res/mipmap-$d/ic_launcher_round.png");
}
foreach (glob("$res/mipmap-ldpi/*") ?: [] as $f) {
    unlink($f);
}
@rmdir("$res/mipmap-ldpi");
$xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<!-- Written by tools/make-assets.php. The mark already sits inside the 66dp safe zone. -->
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@mipmap/ic_launcher_background" />
    <foreground android:drawable="@mipmap/ic_launcher_foreground" />
    <monochrome android:drawable="@drawable/ic_launcher_monochrome" />
</adaptive-icon>

XML;
foreach (['ic_launcher', 'ic_launcher_round'] as $n) {
    file_put_contents("$res/mipmap-anydpi-v26/$n.xml", $xml);
}
echo "  adaptive icon xml\n";
