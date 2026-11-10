<?php
// Regenerate: php scripts/draw-icon.php assets/images
// Draws the petrol app's icon variants: a white fuel pump on fuel orange.
// Shapes are drawn on a 100-unit grid at 8x and scaled down, for smooth edges.
[$_, $out] = $argv;

const ORANGE = [0xB8, 0x3A, 0x0A];

/** A transparent square of $size pixels, drawn at 8x. */
function canvas(int $size, ?array $fill)
{
    $c = imagecreatetruecolor($size, $size);
    imagealphablending($c, false);
    imagesavealpha($c, true);
    $bg = $fill === null ? imagecolorallocatealpha($c, 0, 0, 0, 127) : imagecolorallocate($c, ...$fill);
    imagefilledrectangle($c, 0, 0, $size - 1, $size - 1, $bg);

    return $c;
}

function roundRect($img, float $x1, float $y1, float $x2, float $y2, float $r, int $color, callable $u): void
{
    // $r is a length: scaled, not moved.
    [$x1, $y1, $x2, $y2, $r] = [$u($x1), $u($y1), $u($x2), $u($y2), $u($r) - $u(0)];
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $color);
    }
}

/**
 * The pump, filling $share of a $size square, centred, on $fill (null:
 * transparent). The display window is cut through to the background.
 */
function pump(int $size, ?array $fill, float $share)
{
    $big = $size * 8;
    $img = canvas($big, $fill);
    $white = imagecolorallocatealpha($img, 255, 255, 255, 0);
    $hole = $fill === null ? imagecolorallocatealpha($img, 0, 0, 0, 127) : imagecolorallocate($img, ...$fill);
    // Grid units → pixels: the 100-unit drawing scaled to $share and centred.
    $u = fn (float $v) => (int) round($big * ((1 - $share) / 2 + $v / 100 * $share));

    roundRect($img, 18, 8, 62, 90, 7, $white, $u);         // the body
    roundRect($img, 10, 84, 70, 96, 3, $white, $u);        // the base
    roundRect($img, 26, 17, 54, 42, 4, $hole, $u);         // the display
    roundRect($img, 30, 50, 50, 54, 2, $hole, $u);         // a slot under it
    roundRect($img, 60, 24, 78, 31, 3, $white, $u);        // the hose out
    roundRect($img, 72, 24, 79, 74, 3.5, $white, $u);      // the hose down
    roundRect($img, 79, 10, 90, 40, 4, $white, $u);        // the nozzle, hung up
    roundRect($img, 82, 40, 87, 50, 2, $white, $u);        // its spout

    $small = canvas($size, null);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $size, $size, $big, $big);

    return $small;
}

function save($img, string $path): void
{
    imagepng($img, $path, 9);
    echo $path, PHP_EOL;
}

save(pump(1024, ORANGE, 0.62), "$out/icon.png");
save(pump(196, ORANGE, 0.66), "$out/favicon.png");
save(canvas(1024, ORANGE), "$out/android-icon-background.png");
// Adaptive icons crop to the middle two thirds: the pump stays well inside.
save(pump(1024, null, 0.44), "$out/android-icon-foreground.png");
save(pump(512, null, 0.86), "$out/logo-light.png");
save(pump(512, null, 0.86), "$out/splash-icon.png");
