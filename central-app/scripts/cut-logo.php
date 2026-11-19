<?php
// Regenerate: php -d memory_limit=2G scripts/cut-logo.php public/images/logo-original.png public
//
// Cuts the Miles Softwares logo variants from the official logo: "Miles" in
// navy over "Softwares" in blue (both with a white outline), and "ERP" in
// white on a green, blue and cyan panel, on a white page.
//
// The white page is removed by a flood fill from the edges (so the white
// outlines and the white "ERP" inside the panel stay), with the one-pixel
// fringe softened by colour-to-alpha against white. The -light variants
// turn the navy "Miles" white, for dark backgrounds. The mark (icon) is the
// panel with "ERP".
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
$cut = imagecreatetruecolor($W, $H);
imagesavealpha($cut, true);
imagealphablending($cut, false);
imagefilledrectangle($cut, 0, 0, $W, $H, imagecolorallocatealpha($cut, 0, 0, 0, 127));
[$bx0, $by0, $bx1, $by1] = [$W, $H, 0, 0];
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        if ($page[$y * $W + $x]) continue;
        [$r, $g, $b] = $rgb(imagecolorat($im, $x, $y));
        $edge = false;
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

/** Where "Miles" ends: a line parallel to the italic of "ERP", between the "s" and the "E" (original pixels). */
function split(float $y): float
{
    return 1000 - ($y - 300) * 45 / 260;
}

/** The navy "Miles" (left of the split) in white. $ox, $oy: where $img sits in the original. */
function lighten(GdImage $img, int $ox, int $oy): GdImage
{
    $w = imagesx($img); $h = imagesy($img);
    $out = imagecreatetruecolor($w, $h);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < min($w, (int) split($y + $oy) - $ox); $x++) {
            $c = imagecolorat($img, $x, $y);
            $a = ($c >> 24) & 127;
            [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            // Navy and its antialiased edge towards the white outline.
            if ($a < 127 && $r < 120 && $g < 140 && $b < 130 && $b >= $r && $b - $g < 60) {
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, $a));
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

$save = function (GdImage $img, string $path) {
    str_ends_with($path, '.webp') ? imagewebp($img, $path, 90) : imagepng($img, $path, 9);
    echo basename($path).' '.imagesx($img).'x'.imagesy($img)."\n";
};

$pad = 4;
$full = crop($cut, $bx0 - $pad, $by0 - $pad, $bx1 - $bx0 + 1 + 2 * $pad, $by1 - $by0 + 1 + 2 * $pad);
$fullLight = lighten($full, $bx0 - $pad, $by0 - $pad);
[$fw, $fh] = [imagesx($full), imagesy($full)];

// Wide logo, 230 px high.
$h = 230; $w = (int) round($fw * $h / $fh);
$save(resize($full, $w, $h), "$images/logo-with-text.png");
$save(resize($fullLight, $w, $h), "$images/logo-with-text-light.png");
$save(resize($full, $w, $h), "$images/logo-site.png");
$save(resize($fullLight, $w, $h), "$images/logo-site-light.png");
$h = 128; $w = (int) round($fw * $h / $fh);
$save(resize($full, $w, $h), "$images/logo-site.webp");
$save(resize($fullLight, $w, $h), "$images/logo-site-light.webp");

// The mark: the panel with "ERP", cut along the split so no part of "Miles" shows.
[$mx, $my, $mw, $mh] = [935, 236, 625, 440];
$mark = crop($cut, $mx, $my, $mw, $mh);
imagealphablending($mark, false);
for ($y = 0; $y < $mh; $y++) {
    $edge = split($y + $my) - $mx;
    for ($x = 0; $x < min($mw, (int) ceil($edge) + 1); $x++) {
        $keep = max(0, min(1, $x - $edge + 1)); // 1 px soft edge
        $c = imagecolorat($mark, $x, $y);
        $a = 127 - (int) round((127 - (($c >> 24) & 127)) * $keep);
        imagesetpixel($mark, $x, $y, ($c & 0xffffff) | ($a << 24));
    }
}
$save(resize($mark, 280, 246), "$images/logo-icon.png");
$save(resize($mark, 280, 246), "$images/logo-icon-light.png");
$save(resize($mark, 96, 96), "$public/favicon-96x96.png");
$save(resize($mark, 180, 180, [255, 255, 255]), "$public/apple-touch-icon.png");

// favicon.ico: PNG images inside an ICO (16, 32, 48).
$entries = [];
foreach ([16, 32, 48] as $s) {
    ob_start();
    imagepng(resize($mark, $s, $s), null, 9);
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
