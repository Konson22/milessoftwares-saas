<?php
// Regenerate: php -d memory_limit=2G scripts/cut-icon.php assets/images/logo-source.png assets/images
// (don't pipe it into head: PHP is killed before the files are written)
//
// Cuts the petrol app's icon variants from the official logo: the white pump,
// orange drop and nozzle, and the white and green road on a navy rounded
// tile, drawn on a white page. The tile is cropped and its corners filled
// with navy, so every variant is full bleed or transparent artwork.
[$_, $src, $out] = $argv;
$src = imagecreatefrompng($src);
imagepalettetotruecolor($src);
$W = imagesx($src);
$H = imagesy($src);

$rgb = fn ($c) => [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
$luma = fn (array $p) => 0.3 * $p[0] + 0.59 * $p[1] + 0.11 * $p[2];

// The navy tile: the box of the dark pixels, made square.
[$x0, $y0, $x1, $y1] = [$W, $H, 0, 0];
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        if ($luma($rgb(imagecolorat($src, $x, $y))) < 90) {
            [$x0, $y0, $x1, $y1] = [min($x0, $x), min($y0, $y), max($x1, $x), max($y1, $y)];
        }
    }
}
$S = max($x1 - $x0, $y1 - $y0) + 1;
$cx = intdiv($x0 + $x1, 2);
$cy = intdiv($y0 + $y1, 2);
$ox = $cx - intdiv($S, 2);
$oy = $cy - intdiv($S, 2);

// Corner radius: where the tile starts along the diagonal from its corner.
$t = 0;
while ($t < $S / 2 && $luma($rgb(imagecolorat($src, $x0 + $t, $y0 + $t))) >= 90) {
    $t++;
}
$r = $t / (1 - M_SQRT1_2);
printf("tile %d,%d to %d,%d, side %d, corner radius %.0f\n", $x0, $y0, $x1, $y1, $S, $r);

$tile = imagecreatetruecolor($S, $S);
imagecopy($tile, $src, 0, 0, $ox, $oy, $S, $S);

// The navy shades a little from top to bottom: its colour on each row,
// from the tile's left and right edges (inside the rounding).
$navy = [];
for ($y = 0; $y < $S; $y++) {
    $yy = (int) min(max($y, $r), $S - 1 - $r);
    $a = $rgb(imagecolorat($tile, 6, $yy));
    $b = $rgb(imagecolorat($tile, $S - 7, $yy));
    $navy[$y] = [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2, ($a[2] + $b[2]) / 2];
}
$mid = $navy[intdiv($S, 2)];
printf("navy top #%02x%02x%02x, middle #%02x%02x%02x, bottom #%02x%02x%02x\n", ...array_map('intval', [...$navy[0], ...$mid, ...$navy[$S - 1]]));

// Full bleed: everything outside the rounded tile (pulled in 10 px, so the
// white fringe goes too) becomes that row's navy.
// The tile's own edges inside the square (it is a few pixels off square).
[$l, $t, $rt, $b] = [$x0 - $ox + 10, $y0 - $oy + 10, $x1 - $ox - 10, $y1 - $oy - 10];
$outside = function (int $x, int $y) use ($l, $t, $rt, $b, $r): bool {
    if ($x < $l || $y < $t || $x > $rt || $y > $b) {
        return true;
    }
    $dx = $x < $l + $r ? $l + $r - $x : ($x > $rt - $r ? $x - ($rt - $r) : 0);
    $dy = $y < $t + $r ? $t + $r - $y : ($y > $b - $r ? $y - ($b - $r) : 0);

    return $dx > 0 && $dy > 0 && $dx * $dx + $dy * $dy > $r * $r;
};
for ($y = 0; $y < $S; $y++) {
    for ($x = 0; $x < $S; $x++) {
        if ($outside($x, $y)) {
            $n = $navy[$y];
            imagesetpixel($tile, $x, $y, imagecolorallocate($tile, (int) $n[0], (int) $n[1], (int) $n[2]));
        }
    }
}

function canvas(int $w, int $h)
{
    $c = imagecreatetruecolor($w, $h);
    imagealphablending($c, false);
    imagesavealpha($c, true);
    imagefill($c, 0, 0, imagecolorallocatealpha($c, 0, 0, 0, 127));

    return $c;
}

function put($img, int $x, int $y, array $rgb, float $a): void
{
    $cl = fn ($v) => max(0, min(255, (int) round($v)));
    imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $cl($rgb[0]), $cl($rgb[1]), $cl($rgb[2]), 127 - (int) round(max(0, min(1, $a)) * 127)));
}

// The artwork alone (transparent where the navy shows) and its white silhouette.
$art = canvas($S, $S);
$mono = canvas($S, $S);
[$minX, $minY, $maxX, $maxY] = [$S, $S, 0, 0];
for ($y = 0; $y < $S; $y++) {
    $bg = $navy[$y];
    for ($x = 0; $x < $S; $x++) {
        $p = $rgb(imagecolorat($tile, $x, $y));
        $d = sqrt(($p[0] - $bg[0]) ** 2 + ($p[1] - $bg[1]) ** 2 + ($p[2] - $bg[2]) ** 2);
        $a = min(1, max(0, ($d - 14) / 40));
        if ($a <= 0) {
            continue;
        }
        put($art, $x, $y, [($p[0] - (1 - $a) * $bg[0]) / $a, ($p[1] - (1 - $a) * $bg[1]) / $a, ($p[2] - (1 - $a) * $bg[2]) / $a], $a);
        // Themed (monochrome) icons: everything that is not navy.
        put($mono, $x, $y, [255, 255, 255], $a);
        if ($a > 0.5) {
            [$minX, $minY, $maxX, $maxY] = [min($minX, $x), min($minY, $y), max($maxX, $x), max($maxY, $y)];
        }
    }
}
printf("artwork %d,%d to %d,%d of %d\n", $minX, $minY, $maxX, $maxY, $S);

// The artwork in a square, centred, `$fill` of the side taken up by it.
function framed($layer, array $box, int $size, float $fill)
{
    [$x0, $y0, $x1, $y1] = $box;
    $bw = $x1 - $x0 + 1;
    $bh = $y1 - $y0 + 1;
    $scale = $size * $fill / max($bw, $bh);
    $dst = canvas($size, $size);
    imagecopyresampled($dst, $layer, (int) round(($size - $bw * $scale) / 2), (int) round(($size - $bh * $scale) / 2), $x0, $y0, (int) round($bw * $scale), (int) round($bh * $scale), $bw, $bh);

    return $dst;
}

function write($img, string $path): void
{
    imagepng($img, $path, 9);
    echo "$path\n";
}

$box = [$minX, $minY, $maxX, $maxY];

// Full bleed, as drawn: iOS and launchers round it themselves; the web favicon.
$full = canvas(1024, 1024);
imagecopyresampled($full, $tile, 0, 0, 0, 0, 1024, 1024, $S, $S);
write($full, "$out/icon.png");
$fav = canvas(196, 196);
imagecopyresampled($fav, $tile, 0, 0, 0, 0, 196, 196, $S, $S);
write($fav, "$out/favicon.png");

// Transparent, for the navy surfaces: the splash, headers, loading and sign-in.
write(framed($art, $box, 1024, 1.0), "$out/splash-icon.png");
write(framed($art, $box, 512, 1.0), "$out/logo-light.png");

// Android adaptive layers: the mask keeps the middle ~61%.
$bgl = canvas(1024, 1024);
imagefill($bgl, 0, 0, imagecolorallocatealpha($bgl, (int) $mid[0], (int) $mid[1], (int) $mid[2], 0));
write($bgl, "$out/android-icon-background.png");
write(framed($art, $box, 1024, 0.6), "$out/android-icon-foreground.png");
write(framed($mono, $box, 1024, 0.6), "$out/android-icon-monochrome.png");
