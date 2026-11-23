<?php
// Regenerate: php -d memory_limit=2G scripts/cut-icon.php assets/images/logo-source.png assets/images
// (don't pipe it into head: PHP is killed before the files are written)
//
// Cuts the M&E app's icon variants from the official logo: a white phone with
// a checklist, a green location pin and a signal on a rounded tile that runs
// from blue (bottom left) to green (top right), drawn on a white page. The
// gradient is fitted as a plane per colour channel, so the tile can be
// cropped, its corners filled and the artwork lifted off it.
[$_, $src, $out] = $argv;
$src = imagecreatefrompng($src);
imagepalettetotruecolor($src);
$W = imagesx($src);
$H = imagesy($src);

$rgb = fn ($c) => [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
$white = fn (array $p) => min($p) > 225;

// The tile: the box of the pixels that are not the white page, along the middle row and column.
[$x0, $x1, $y0, $y1] = [0, $W - 1, 0, $H - 1];
while ($white($rgb(imagecolorat($src, $x0, intdiv($H, 2))))) $x0++;
while ($white($rgb(imagecolorat($src, $x1, intdiv($H, 2))))) $x1--;
while ($white($rgb(imagecolorat($src, intdiv($W, 2), $y0)))) $y0++;
while ($white($rgb(imagecolorat($src, intdiv($W, 2), $y1)))) $y1--;
$S = max($x1 - $x0, $y1 - $y0) + 1;
$t = 0;
while ($white($rgb(imagecolorat($src, $x0 + $t, $y0 + $t)))) $t++;
$r = $t / (1 - M_SQRT1_2);
printf("tile %d,%d to %d,%d, side %d, corner radius %.0f\n", $x0, $y0, $x1, $y1, $S, $r);

// Inside the rounded tile, pulled in 12 px so the white fringe stays out.
$inside = function (float $x, float $y) use ($x0, $y0, $x1, $y1, $r): bool {
    [$l, $t, $rt, $b] = [$x0 + 12, $y0 + 12, $x1 - 12, $y1 - 12];
    if ($x < $l || $y < $t || $x > $rt || $y > $b) return false;
    $dx = $x < $l + $r ? $l + $r - $x : ($x > $rt - $r ? $x - ($rt - $r) : 0);
    $dy = $y < $t + $r ? $t + $r - $y : ($y > $b - $r ? $y - ($b - $r) : 0);
    return $dx * $dx + $dy * $dy <= $r * $r || $dx == 0 || $dy == 0;
};

// The artwork's box: white and near-white pixels inside the tile.
[$ax0, $ay0, $ax1, $ay1] = [$W, $H, 0, 0];
for ($y = $y0; $y <= $y1; $y++) {
    for ($x = $x0; $x <= $x1; $x++) {
        if ($inside($x, $y) && min($rgb(imagecolorat($src, $x, $y))) > 200) {
            [$ax0, $ay0, $ax1, $ay1] = [min($ax0, $x), min($ay0, $y), max($ax1, $x), max($ay1, $y)];
        }
    }
}
// The pin and its dark outline reach a little past the white ring.
$ax0 -= 6; $ay0 -= 6; $ax1 += 6; $ay1 += 6;
printf("artwork %d,%d to %d,%d\n", $ax0, $ay0, $ax1, $ay1);

// The gradient: least squares c = a + b*x + c*y per channel, from the tile outside the artwork.
$n = 0;
$m = array_fill(0, 3, array_fill(0, 3, 0.0));
$v = array_fill(0, 3, array_fill(0, 3, 0.0));
for ($y = $y0; $y <= $y1; $y += 3) {
    for ($x = $x0; $x <= $x1; $x += 3) {
        if (! $inside($x, $y) || ($x > $ax0 - 20 && $x < $ax1 + 20 && $y > $ay0 - 20 && $y < $ay1 + 20)) continue;
        $p = $rgb(imagecolorat($src, $x, $y));
        $f = [1, $x / $W, $y / $H];
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) $m[$i][$j] += $f[$i] * $f[$j];
            for ($c = 0; $c < 3; $c++) $v[$c][$i] += $f[$i] * $p[$c];
        }
        $n++;
    }
}
function solve(array $a, array $b): array
{
    $k = count($b);
    for ($i = 0; $i < $k; $i++) {
        $p = $i;
        for ($j = $i + 1; $j < $k; $j++) if (abs($a[$j][$i]) > abs($a[$p][$i])) $p = $j;
        [$a[$i], $a[$p]] = [$a[$p], $a[$i]];
        [$b[$i], $b[$p]] = [$b[$p], $b[$i]];
        for ($j = $i + 1; $j < $k; $j++) {
            $f = $a[$j][$i] / $a[$i][$i];
            for ($l = $i; $l < $k; $l++) $a[$j][$l] -= $f * $a[$i][$l];
            $b[$j] -= $f * $b[$i];
        }
    }
    $x = array_fill(0, $k, 0.0);
    for ($i = $k - 1; $i >= 0; $i--) {
        $s = $b[$i];
        for ($l = $i + 1; $l < $k; $l++) $s -= $a[$i][$l] * $x[$l];
        $x[$i] = $s / $a[$i][$i];
    }
    return $x;
}
$coef = [solve($m, $v[0]), solve($m, $v[1]), solve($m, $v[2])];
$plane = fn (float $x, float $y) => array_map(fn ($k) => $k[0] + $k[1] * $x / $W + $k[2] * $y / $H, $coef);

// The tile is not quite a plane (it glows a little in the middle): what the
// plane misses, on a coarse grid, measured where the tile shows and spread
// out from there by averaging neighbours, so the gradient carries on past
// the tile's edge without a seam.
$G = 24;
$cell = $W / $G;
$sum = [];
for ($y = $y0; $y <= $y1; $y += 2) {
    for ($x = $x0; $x <= $x1; $x += 2) {
        if (! $inside($x, $y) || ($x > $ax0 - 20 && $x < $ax1 + 20 && $y > $ay0 - 20 && $y < $ay1 + 20)) continue;
        $p = $rgb(imagecolorat($src, $x, $y));
        $q = $plane($x, $y);
        $k = (int) ($y / $cell) * $G + (int) ($x / $cell);
        $sum[$k] ??= [0, 0, 0, 0];
        $sum[$k] = [$sum[$k][0] + $p[0] - $q[0], $sum[$k][1] + $p[1] - $q[1], $sum[$k][2] + $p[2] - $q[2], $sum[$k][3] + 1];
    }
}
$res = array_fill(0, $G * $G, null);
$known = [];
foreach ($sum as $k => $s) {
    if ($s[3] >= 40) {
        $res[$k] = [$s[0] / $s[3], $s[1] / $s[3], $s[2] / $s[3]];
        $known[$k] = true;
    }
}
for ($it = 0; $it < 400; $it++) {
    $next = $res;
    for ($k = 0; $k < $G * $G; $k++) {
        if (isset($known[$k])) continue;
        [$gy, $gx] = [intdiv($k, $G), $k % $G];
        $acc = [0, 0, 0];
        $c = 0;
        foreach ([[0, 1], [0, -1], [1, 0], [-1, 0]] as [$dy, $dx]) {
            [$ny, $nx] = [$gy + $dy, $gx + $dx];
            if ($ny < 0 || $nx < 0 || $ny >= $G || $nx >= $G || $res[$ny * $G + $nx] === null) continue;
            $o = $res[$ny * $G + $nx];
            $acc = [$acc[0] + $o[0], $acc[1] + $o[1], $acc[2] + $o[2]];
            $c++;
        }
        if ($c) $next[$k] = [$acc[0] / $c, $acc[1] / $c, $acc[2] / $c];
    }
    $res = $next;
}
$bg = function (float $x, float $y) use ($plane, $res, $G, $cell): array {
    $q = $plane($x, $y);
    $gx = max(0, min($G - 1.001, $x / $cell - 0.5));
    $gy = max(0, min($G - 1.001, $y / $cell - 0.5));
    [$ix, $iy] = [(int) $gx, (int) $gy];
    [$fx, $fy] = [$gx - $ix, $gy - $iy];
    $at = fn ($a, $b) => $res[$b * $G + $a] ?? [0, 0, 0];
    $out = [];
    for ($c = 0; $c < 3; $c++) {
        $top = $at($ix, $iy)[$c] * (1 - $fx) + $at($ix + 1, $iy)[$c] * $fx;
        $bot = $at($ix, $iy + 1)[$c] * (1 - $fx) + $at($ix + 1, $iy + 1)[$c] * $fx;
        $out[] = max(0, min(255, $q[$c] + $top * (1 - $fy) + $bot * $fy));
    }
    return $out;
};
$err = 0;
$cnt = 0;
for ($y = $y0; $y <= $y1; $y += 7) {
    for ($x = $x0; $x <= $x1; $x += 7) {
        if (! $inside($x, $y) || ($x > $ax0 - 20 && $x < $ax1 + 20 && $y > $ay0 - 20 && $y < $ay1 + 20)) continue;
        $p = $rgb(imagecolorat($src, $x, $y));
        $q = $bg($x, $y);
        $err += sqrt(($p[0] - $q[0]) ** 2 + ($p[1] - $q[1]) ** 2 + ($p[2] - $q[2]) ** 2);
        $cnt++;
    }
}
$hex = fn (array $p) => sprintf('#%02x%02x%02x', ...array_map('intval', $p));
printf("gradient from %d samples, mean error %.1f; bottom left %s, middle %s, top right %s\n", $n, $err / $cnt, $hex($bg($x0, $y1)), $hex($bg(($x0 + $x1) / 2, ($y0 + $y1) / 2)), $hex($bg($x1, $y0)));

// A source pixel, with the fitted gradient wherever the tile does not reach.
$at = function (float $x, float $y) use ($src, $inside, $bg, $rgb, $W, $H): array {
    $xi = (int) round($x);
    $yi = (int) round($y);
    if ($xi >= 0 && $yi >= 0 && $xi < $W && $yi < $H && $inside($xi, $yi)) return $rgb(imagecolorat($src, $xi, $yi));
    return $bg($x, $y);
};

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

// A square of `$size` px showing the source square at ($sx, $sy) of side $side, sampled 2x2 per pixel.
$render = function (float $sx, float $sy, float $side, int $size, callable $pix) {
    $img = canvas($size, $size);
    $k = $side / $size;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $acc = [0, 0, 0, 0];
            foreach ([[0.25, 0.25], [0.75, 0.25], [0.25, 0.75], [0.75, 0.75]] as [$u, $w]) {
                [$c, $a] = $pix($sx + ($x + $u) * $k, $sy + ($y + $w) * $k);
                $acc[0] += $c[0] * $a;
                $acc[1] += $c[1] * $a;
                $acc[2] += $c[2] * $a;
                $acc[3] += $a;
            }
            $a = $acc[3] / 4;
            if ($a > 0) put($img, $x, $y, [$acc[0] / $acc[3], $acc[1] / $acc[3], $acc[2] / $acc[3]], $a);
        }
    }
    return $img;
};

function write($img, string $path): void
{
    imagepng($img, $path, 9);
    echo "$path\n";
}

$full = fn ($x, $y) => [$at($x, $y), 1.0];
// The rounded tile alone, transparent outside it (for the splash and the dark surfaces).
$badge = fn ($x, $y) => [$at($x, $y), $inside($x, $y) ? 1.0 : 0.0];

// Full bleed: iOS and launchers round it themselves; the web favicon.
$cx = ($x0 + $x1) / 2;
$cy = ($y0 + $y1) / 2;
write($render($cx - $S / 2, $cy - $S / 2, $S, 1024, $full), "$out/icon.png");
write($render($cx - $S / 2, $cy - $S / 2, $S, 196, $full), "$out/favicon.png");

// The rounded tile, transparent outside: the splash and the logo on the app's dark surfaces.
$pad = 14;
write($render($x0 + 12 - $pad, $y0 + 12 - $pad, $x1 - $x0 - 24 + 2 * $pad, 1024, $badge), "$out/splash-icon.png");
write($render($x0 + 12 - $pad, $y0 + 12 - $pad, $x1 - $x0 - 24 + 2 * $pad, 512, $badge), "$out/logo-light.png");

// Android adaptive layers: the mask keeps the middle ~61%, so the artwork is
// centred and fills 60% of the side; the gradient carries on past the tile.
$acx = ($ax0 + $ax1) / 2;
$acy = ($ay0 + $ay1) / 2;
$side = max($ax1 - $ax0, $ay1 - $ay0) / 0.6;
write($render($acx - $side / 2, $acy - $side / 2, $side, 1024, $full), "$out/android-icon-foreground.png");
$mid = $bg($acx, $acy);
$bgl = canvas(1024, 1024);
imagefill($bgl, 0, 0, imagecolorallocatealpha($bgl, (int) $mid[0], (int) $mid[1], (int) $mid[2], 0));
write($bgl, "$out/android-icon-background.png");
// Themed icons: the white of the artwork (phone, card, signal, ring) as a silhouette.
$mono = function ($x, $y) use ($at, $inside) {
    if (! $inside($x, $y)) return [[255, 255, 255], 0.0];
    $p = $at($x, $y);
    return [[255, 255, 255], max(0, min(1, (min($p) - 150) / 70))];
};
write($render($acx - $side / 2, $acy - $side / 2, $side, 1024, $mono), "$out/android-icon-monochrome.png");
printf("adaptive background %s\n", $hex($mid));
