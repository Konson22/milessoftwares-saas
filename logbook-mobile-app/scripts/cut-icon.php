<?php
// Regenerate: php -d memory_limit=2G scripts/cut-icon.php assets/images/logo-source.png assets/images [web public dir]
//
// Cuts Tatook Logbook's icon variants from the official logo: a blue
// spiral notebook with green ticks and a pen, on a white rounded tile with a
// blue border and a green and blue wave along the bottom. The source is a
// transparent PNG with a few stray specks around the tile.
//
// With a third argument (logbook-app/public/logbook/images) it also writes
// the web app's sidebar logo and tab icons.
[$_, $src, $out] = $argv;
$web = $argv[3] ?? null;

$im = imagecreatefrompng($src);
imagepalettetotruecolor($im);
imagesavealpha($im, true);
$W = imagesx($im);
$H = imagesy($im);
$alpha = fn (int $x, int $y) => (imagecolorat($im, $x, $y) >> 24) & 127;

// The tile: the box of nearly opaque pixels along the middle row and column
// (the specks are elsewhere and smaller).
[$x0, $x1, $y0, $y1] = [0, $W - 1, 0, $H - 1];
while ($alpha($x0, intdiv($H, 2)) > 40) $x0++;
while ($alpha($x1, intdiv($H, 2)) > 40) $x1--;
while ($alpha(intdiv($W, 2), $y0) > 40) $y0++;
while ($alpha(intdiv($W, 2), $y1) > 40) $y1--;
$S = max($x1 - $x0, $y1 - $y0) + 1;
printf("tile %d,%d to %d,%d, side %d\n", $x0, $y0, $x1, $y1, $S);

// The tile alone on a transparent square, specks outside it cleared.
$tile = imagecreatetruecolor($S, $S);
imagesavealpha($tile, true);
imagealphablending($tile, false);
imagefilledrectangle($tile, 0, 0, $S, $S, imagecolorallocatealpha($tile, 0, 0, 0, 127));
$ox = $x0 + intdiv(($x1 - $x0 + 1) - $S, 2);
$oy = $y0 + intdiv(($y1 - $y0 + 1) - $S, 2);
imagecopy($tile, $im, 0, 0, $ox, $oy, $S, $S);

/** $img scaled to $size and centred at $k of a $canvas px square, over $bg (null: transparent). */
function place(GdImage $img, int $canvas, float $k, ?array $bg): GdImage
{
    $c = imagecreatetruecolor($canvas, $canvas);
    imagesavealpha($c, true);
    imagealphablending($c, false);
    imagefilledrectangle($c, 0, 0, $canvas, $canvas, $bg ? imagecolorallocate($c, ...$bg) : imagecolorallocatealpha($c, 0, 0, 0, 127));
    imagealphablending($c, true);
    $size = (int) round($canvas * $k);
    $at = intdiv($canvas - $size, 2);
    imagecopyresampled($c, $img, $at, $at, 0, 0, $size, $size, imagesx($img), imagesy($img));

    return $c;
}

// The notebook alone, as a white silhouette: its saturated strokes inside the
// tile's border, above the wave (Android's themed icon).
function mono(GdImage $tile): GdImage
{
    $S = imagesx($tile);
    $m = imagecreatetruecolor($S, $S);
    imagesavealpha($m, true);
    imagealphablending($m, false);
    $clear = imagecolorallocatealpha($m, 0, 0, 0, 127);
    imagefilledrectangle($m, 0, 0, $S, $S, $clear);
    for ($y = 0; $y < $S; $y++) {
        for ($x = 0; $x < $S; $x++) {
            [$u, $v] = [$x / $S, $y / $S];
            // Inside the border, and clear of the wave (it rises on the right).
            if ($u < 0.13 || $u > 0.89 || $v < 0.14 || $v > 0.81 || ($u > 0.80 && $v > 0.55)) continue;
            $c = imagecolorat($tile, $x, $y);
            if ((($c >> 24) & 127) > 60) continue;
            [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            $sat = max($r, $g, $b) - min($r, $g, $b);
            if ($sat > 60) {
                // Edges fade with the saturation, so the strokes stay smooth.
                $a = (int) round(127 * (1 - min(1, ($sat - 60) / 60)));
                imagesetpixel($m, $x, $y, imagecolorallocatealpha($m, 255, 255, 255, $a));
            }
        }
    }

    return $m;
}

$save = function (GdImage $img, string $path) {
    imagepng($img, $path, 9);
    echo basename($path).' '.imagesx($img)."px\n";
};

const WHITE = [255, 255, 255];

$save(place($tile, 1024, 1.0, null), "$out/logo-source-cut.png");
// Store icon and legacy launcher icon: opaque (iOS refuses transparency), tile on white.
$save(place($tile, 1024, 0.9, WHITE), "$out/icon.png");
// Adaptive icon: launchers mask to the middle 66/108, so the tile shrinks to fit.
$save(place($tile, 1024, 0.6, null), "$out/android-icon-foreground.png");
$save(place($tile, 1024, 0.0, WHITE), "$out/android-icon-background.png");
$save(place(mono($tile), 1024, 0.9, null), "$out/android-icon-monochrome.png");
$save(place($tile, 1024, 1.0, null), "$out/splash-icon.png");
$save(place($tile, 512, 1.0, null), "$out/logo-light.png");
$save(place($tile, 48, 1.0, null), "$out/favicon.png");
unlink("$out/logo-source-cut.png");

if ($web) {
    @mkdir($web, 0777, true);
    $save(place($tile, 256, 1.0, null), "$web/logo.png");
    $save(place($tile, 96, 1.0, null), "$web/favicon-96x96.png");
    $save(place($tile, 32, 1.0, null), "$web/favicon-32x32.png");
    $save(place($tile, 180, 0.9, WHITE), "$web/apple-touch-icon.png");
}
