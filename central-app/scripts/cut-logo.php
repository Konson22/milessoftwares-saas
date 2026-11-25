<?php
// Regenerate: php -d memory_limit=2G scripts/cut-logo.php public/images/logo-original.png public
//
// Cuts the Miles Global Softwares logo variants from the official navy and
// cyan logo (1774×887, white page): "MILES GLOBAL" in navy over a blue
// "SOFTWARES" pill and a navy "ERP" badge, in front of a navy globe with a
// cyan swoosh above and a blue swoosh below.
//
// The white page is removed by a flood fill from the edges (so the white
// lettering inside the pill and badge stays), with the one-pixel fringe
// softened by colour-to-alpha against white. The -light variants, for dark
// backgrounds, turn the navy "MILES GLOBAL" white, the navy "ERP" badge
// electric blue and the navy globe lines silver gray.
//
// The mark (logo-icon, favicons, apple-touch) is the globe with its two
// swooshes, without lettering. The lettering covers the middle of the globe
// in the original, so the mark is drawn here in the logo's colours, with
// strokes thick enough to survive at favicon size.
[$_, $src, $public] = $argv;
$images = "$public/images";

$im = imagecreatefrompng($src);
imagepalettetotruecolor($im);
$W = imagesx($im);
$H = imagesy($im);
$rgb = fn (int $c) => [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];

// 1. The page: white pixels connected to the edges.
$page = array_fill(0, $W * $H, false);
$queue = new SplQueue;
$isWhite = fn (int $x, int $y) => min($rgb(imagecolorat($im, $x, $y))) > 232;
for ($x = 0; $x < $W; $x++) foreach ([0, $H - 1] as $y) if ($isWhite($x, $y)) { $page[$y * $W + $x] = true; $queue->enqueue([$x, $y]); }
for ($y = 0; $y < $H; $y++) foreach ([0, $W - 1] as $x) if ($isWhite($x, $y) && ! $page[$y * $W + $x]) { $page[$y * $W + $x] = true; $queue->enqueue([$x, $y]); }
while (! $queue->isEmpty()) {
    [$x, $y] = $queue->dequeue();
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        [$nx, $ny] = [$x + $dx, $y + $dy];
        if ($nx < 0 || $ny < 0 || $nx >= $W || $ny >= $H || $page[$ny * $W + $nx] || ! $isWhite($nx, $ny)) continue;
        $page[$ny * $W + $nx] = true;
        $queue->enqueue([$nx, $ny]);
    }
}

// 2. The logo on transparency; pixels next to the page fade with their whiteness.
// The pill and badge (white lettering) in the original: x 330–1560, y 485–620.
$inPill = fn (int $x, int $y) => $x >= 330 && $x <= 1560 && $y >= 485 && $y <= 620;
$cut = imagecreatetruecolor($W, $H);
imagesavealpha($cut, true);
imagealphablending($cut, false);
imagefilledrectangle($cut, 0, 0, $W, $H, imagecolorallocatealpha($cut, 0, 0, 0, 127));
[$bx0, $by0, $bx1, $by1] = [$W, $H, 0, 0];
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        [$r, $g, $b] = $rgb(imagecolorat($im, $x, $y));
        // Outside the pill and badge every pixel is unmixed from white, so the
        // white cells enclosed by the globe lines go too; inside, only the page.
        if ($page[$y * $W + $x] || (! $inPill($x, $y) && min($r, $g, $b) > 232)) continue;
        $edge = ! $inPill($x, $y);
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = $x + $dx; $ny = $y + $dy;
            if ($nx >= 0 && $ny >= 0 && $nx < $W && $ny < $H && $page[$ny * $W + $nx]) { $edge = true; break; }
        }
        $a = 0;
        if ($edge) {
            // Colour-to-alpha against white: the least opacity that explains the pixel.
            $op = max((255 - $r), (255 - $g), (255 - $b)) / 255;
            if ($op < 0.04) continue;
            [$r, $g, $b] = array_map(fn ($v) => (int) round(255 - (255 - $v) / $op), [$r, $g, $b]);
            $a = (int) round(127 * (1 - $op));
        }
        imagesetpixel($cut, $x, $y, imagecolorallocatealpha($cut, max(0, $r), max(0, $g), max(0, $b), $a));
        [$bx0, $by0, $bx1, $by1] = [min($bx0, $x), min($by0, $y), max($bx1, $x), max($by1, $y)];
    }
}
printf("logo box %d,%d to %d,%d\n", $bx0, $by0, $bx1, $by1);

/**
 * For dark backgrounds: "MILES GLOBAL" (navy, rows 330–470 of the original)
 * in white, and the navy "ERP" badge (x 1170–1560, y 485–620) in electric
 * blue, its white lettering kept. Navy is told from the cyan and blue by
 * green: navy and its mixes with white have g close to r.
 */
function lighten(GdImage $img, int $ox, int $oy): GdImage
{
    $w = imagesx($img); $h = imagesy($img);
    $out = imagecreatetruecolor($w, $h);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
    for ($y = 0; $y < $h; $y++) {
        $oyy = $y + $oy;
        for ($x = 0; $x < $w; $x++) {
            $oxx = $x + $ox;
            $c = imagecolorat($img, $x, $y);
            $a = ($c >> 24) & 127;
            [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            if ($a === 127 || $g - $r > 60 || $b < $r) continue;
            if ($oyy >= 330 && $oyy < 470 && $r < 80 && $g < 100 && $b > $r + 20) {
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, $a));
            } elseif ($oxx >= 1170 && $oxx <= 1560 && $oyy >= 485 && $oyy <= 620) {
                // How navy the pixel is (0 white .. 1 navy), remapped to blue (#087bf5).
                $t = max(0, min(1, (255 - $r) / 248));
                $m = fn ($blue) => (int) round(255 + ($blue - 255) * $t);
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $m(0x08), $m(0x7b), $m(0xf5), $a));
            } elseif ($r < 80 && $g < 100 && $b > $r + 20) {
                // The navy globe lines, in silver gray (#82869e).
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 0x82, 0x86, 0x9e, $a));
            }
        }
    }

    return $out;
}

function crop(GdImage $img, int $x, int $y, int $w, int $h): GdImage
{
    $c = imagecreatetruecolor($w, $h);
    imagesavealpha($c, true);
    imagealphablending($c, false);
    imagefilledrectangle($c, 0, 0, $w, $h, imagecolorallocatealpha($c, 0, 0, 0, 127));
    imagecopy($c, $img, 0, 0, $x, $y, $w, $h);

    return $c;
}

function resize(GdImage $img, int $w, int $h, ?array $bg = null): GdImage
{
    $c = imagecreatetruecolor($w, $h);
    imagesavealpha($c, true);
    imagealphablending($c, false);
    imagefilledrectangle($c, 0, 0, $w, $h, $bg ? imagecolorallocate($c, ...$bg) : imagecolorallocatealpha($c, 0, 0, 0, 127));
    imagealphablending($c, true);
    // Fit, centred.
    $k = min($w / imagesx($img), $h / imagesy($img));
    [$tw, $th] = [(int) round(imagesx($img) * $k), (int) round(imagesy($img) * $k)];
    imagecopyresampled($c, $img, intdiv($w - $tw, 2), intdiv($h - $th, 2), 0, 0, $tw, $th, imagesx($img), imagesy($img));

    return $c;
}

/**
 * The mark: a navy globe (outline, meridians, latitudes) with a cyan swoosh
 * over its upper right and a blue swoosh under its lower left, the globe lines
 * cut back around each swoosh. Drawn per pixel with 4×4 supersampling, in
 * units where the globe has radius 1 and the image spans ±1.32.
 */
function mark(int $S, ?array $bg = null, array $globe = [0x07, 0x20, 0x59]): GdImage
{
    $blue = [0x11, 0xad, 0xec];   // the upper swoosh (cyan)
    $green = [0x00, 0x74, 0xc1];  // the lower swoosh (blue)
    $line = 0.075;   // globe stroke width
    $halo = 0.07;    // gap between a swoosh and the globe lines

    $ellipse = function (float $x, float $y, float $rx, float $ry): float {
        $f = sqrt(($x / $rx) ** 2 + ($y / $ry) ** 2);
        $g = sqrt(($x / $rx ** 2) ** 2 + ($y / $ry ** 2) ** 2) / max($f, 1e-9);

        return abs($f - 1) / max($g, 1e-9);
    };
    $onGlobe = function (float $x, float $y) use ($ellipse, $line): bool {
        $h = $line / 2;
        if (abs(sqrt($x * $x + $y * $y) - 1) < $h) return true;                 // outline
        if (abs($x) < $h && abs($y) < 1) return true;                           // central meridian
        if ($ellipse($x, $y, 0.5, 1) < $h) return true;                         // meridians
        foreach ([[-0.45, 0.893, 0.16], [0.45, 0.893, 0.16]] as [$cy, $rx, $ry]) {
            if ($y >= $cy && $ellipse($x, $y - $cy, $rx, $ry) < $h) return true; // latitudes, front half
        }

        return false;
    };

    // A swoosh: an arc through three points whose width follows $t(s), s = 0..1 along it.
    $swoosh = function (array $p0, array $pm, array $p1, callable $t) {
        [$ax, $ay] = $p0; [$bx, $by] = $pm; [$cx, $cy] = $p1;
        $d = 2 * ($ax * ($by - $cy) + $bx * ($cy - $ay) + $cx * ($ay - $by));
        $ux = (($ax ** 2 + $ay ** 2) * ($by - $cy) + ($bx ** 2 + $by ** 2) * ($cy - $ay) + ($cx ** 2 + $cy ** 2) * ($ay - $by)) / $d;
        $uy = (($ax ** 2 + $ay ** 2) * ($cx - $bx) + ($bx ** 2 + $by ** 2) * ($ax - $cx) + ($cx ** 2 + $cy ** 2) * ($bx - $ax)) / $d;
        $R = hypot($ax - $ux, $ay - $uy);
        $ang = fn ($x, $y) => atan2($y - $uy, $x - $ux);
        $wrap = fn ($a) => fmod(fmod($a, 2 * M_PI) + 2 * M_PI, 2 * M_PI);
        $a0 = $ang($ax, $ay);
        $dir = $wrap($ang($bx, $by) - $a0) < M_PI ? 1 : -1;
        $span = $dir > 0 ? $wrap($ang($cx, $cy) - $a0) : $wrap($a0 - $ang($cx, $cy));

        // Distance outside the swoosh (<= 0 inside).
        return function (float $x, float $y) use ($ux, $uy, $R, $ang, $wrap, $a0, $dir, $span, $t): float {
            $s = ($dir > 0 ? $wrap($ang($x, $y) - $a0) : $wrap($a0 - $ang($x, $y))) / $span;
            if ($s > 1) return 9;

            return abs(hypot($x - $ux, $y - $uy) - $R) - $t($s) / 2;
        };
    };
    $blueSwoosh = $swoosh([-0.2, -0.42], [0.62, -0.8], [1.24, -0.16],
        fn ($s) => 0.3 * sin(M_PI * $s) ** 0.75 * (0.35 + 0.65 * $s));
    $greenSwoosh = $swoosh([-1.26, 0.1], [-0.78, 0.8], [0.4, 0.78],
        fn ($s) => 0.32 * sin(M_PI * (0.12 + 0.88 * $s)) ** 0.75 * (1 - 0.55 * $s));

    $img = imagecreatetruecolor($S, $S);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    $n = 4;
    $k = 2.64 / $S;
    for ($py = 0; $py < $S; $py++) {
        for ($px = 0; $px < $S; $px++) {
            [$r, $g, $b, $cover] = [0, 0, 0, 0];
            for ($j = 0; $j < $n; $j++) {
                for ($i = 0; $i < $n; $i++) {
                    $x = ($px + ($i + 0.5) / $n) * $k - 1.32;
                    $y = ($py + ($j + 0.5) / $n) * $k - 1.32;
                    $db = $blueSwoosh($x, $y);
                    $dg = $greenSwoosh($x, $y);
                    $c = match (true) {
                        $db <= 0 => $blue,
                        $dg <= 0 => $green,
                        $db < $halo || $dg < $halo => null,
                        $onGlobe($x, $y) => $globe,
                        default => null,
                    };
                    if ($c) { $r += $c[0]; $g += $c[1]; $b += $c[2]; $cover++; }
                }
            }
            $all = $n * $n;
            if ($bg) {
                // Composite on the background.
                $f = fn ($sum, $bgc) => (int) round(($sum + $bgc * ($all - $cover)) / $all);
                imagesetpixel($img, $px, $py, imagecolorallocate($img, $f($r, $bg[0]), $f($g, $bg[1]), $f($b, $bg[2])));
            } elseif ($cover === 0) {
                imagesetpixel($img, $px, $py, imagecolorallocatealpha($img, 0, 0, 0, 127));
            } else {
                imagesetpixel($img, $px, $py, imagecolorallocatealpha($img,
                    intdiv($r, $cover), intdiv($g, $cover), intdiv($b, $cover),
                    127 - (int) round(127 * $cover / $all)));
            }
        }
    }

    return $img;
}

$save = function (GdImage $img, string $path) {
    str_ends_with($path, '.webp') ? imagewebp($img, $path, 90) : imagepng($img, $path, 9);
    echo basename($path).' '.imagesx($img).'x'.imagesy($img)."\n";
};

$pad = 4;
$full = crop($cut, $bx0 - $pad, $by0 - $pad, $bx1 - $bx0 + 1 + 2 * $pad, $by1 - $by0 + 1 + 2 * $pad);
$fullLight = lighten($full, $bx0 - $pad, $by0 - $pad);
[$fw, $fh] = [imagesx($full), imagesy($full)];
printf("full %dx%d\n", $fw, $fh);

// Wide logo, 320 px high (png) and 160 px (webp, the public site header).
$h = 320; $w = (int) round($fw * $h / $fh);
$save(resize($full, $w, $h), "$images/logo-with-text.png");
$save(resize($fullLight, $w, $h), "$images/logo-with-text-light.png");
$save(resize($full, $w, $h), "$images/logo-site.png");
$save(resize($fullLight, $w, $h), "$images/logo-site-light.png");
$h = 160; $w = (int) round($fw * $h / $fh);
$save(resize($full, $w, $h), "$images/logo-site.webp");
$save(resize($fullLight, $w, $h), "$images/logo-site-light.webp");

// The mark; the -light copy, for dark backgrounds, has a silver globe.
$save(mark(256), "$images/logo-icon.png");
$save(mark(256, null, [0xe4, 0xe6, 0xec]), "$images/logo-icon-light.png");
$save(mark(96), "$public/favicon-96x96.png");
$save(mark(180, [255, 255, 255]), "$public/apple-touch-icon.png");

// favicon.ico: PNG images inside an ICO (16, 32, 48).
$entries = [];
foreach ([16, 32, 48] as $s) {
    ob_start();
    imagepng(mark($s), null, 9);
    $entries[$s] = ob_get_clean();
}
$ico = pack('vvv', 0, 1, count($entries));
$offset = 6 + 16 * count($entries);
$data = '';
foreach ($entries as $s => $png) {
    $ico .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, strlen($png), $offset);
    $offset += strlen($png);
    $data .= $png;
}
file_put_contents("$public/favicon.ico", $ico.$data);
echo "favicon.ico 16/32/48\n";
