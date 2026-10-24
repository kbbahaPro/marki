<?php

/*
 * Regenerates the Marki favicons and email logo in public/ from the mark's
 * geometry (the same shapes as resources/views/components/brand-mark.blade.php).
 *
 *   php resources/brand/build-icons.php [primaryHex] [markerHex]
 *
 * Deployments with their own brand can instead set BRAND_FAVICON_URL and
 * BRAND_LOGO_URL; this script is for the built-in Marki identity.
 */

$primary = sscanf(ltrim($argv[1] ?? '#1D5FAF', '#'), '%02x%02x%02x');
$marker = sscanf(ltrim($argv[2] ?? '#F4A93C', '#'), '%02x%02x%02x');
$public = dirname(__DIR__, 2).'/public';

/**
 * Draw the mark on a transparent square of $size px (supersampled for smooth edges).
 */
function renderMark(int $size, array $primary, array $marker): GdImage
{
    $scale = 8;
    $big = $size * $scale;
    $s = $big / 100; // the mark is drawn on a 100×100 grid

    $canvas = imagecreatetruecolor($big, $big);
    imagesavealpha($canvas, true);
    imagealphablending($canvas, false);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagealphablending($canvas, true);

    // Bookmark outline: rounded top corners (r = 8), notch at the bottom.
    $points = [18, 94, 18, 14];
    foreach ([[26, 14, 180, 270], [74, 14, 270, 360]] as [$cx, $cy, $from, $to]) {
        for ($a = $from; $a <= $to; $a += 5) {
            $points[] = $cx + 8 * cos(deg2rad($a));
            $points[] = $cy + 8 * sin(deg2rad($a));
        }
    }
    array_push($points, 82, 94, 50, 74);
    $points = array_map(fn ($p) => (int) round($p * $s), $points);

    imagefilledpolygon($canvas, $points, imagecolorallocate($canvas, ...$primary));

    // White dots at 85 % opacity over the primary colour.
    $dot = array_map(fn ($c) => (int) round($c + (255 - $c) * 0.85), $primary);
    $dotColour = imagecolorallocate($canvas, ...$dot);
    foreach ([[36, 24], [50, 24], [64, 24], [36, 38], [50, 38], [36, 52], [50, 52], [64, 52]] as [$x, $y]) {
        imagefilledellipse($canvas, (int) ($x * $s), (int) ($y * $s), (int) (8 * $s), (int) (8 * $s), $dotColour);
    }

    // The marked time.
    imagefilledellipse($canvas, (int) (64 * $s), (int) (38 * $s), (int) (15 * $s), (int) (15 * $s), imagecolorallocate($canvas, ...$marker));

    $out = imagecreatetruecolor($size, $size);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);

    return $out;
}

function png(GdImage $image): string
{
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

// PNGs.
@mkdir("{$public}/brand", 0755, true);
file_put_contents("{$public}/apple-touch-icon.png", png(renderMark(180, $primary, $marker)));
file_put_contents("{$public}/brand/mark-128.png", png(renderMark(128, $primary, $marker)));
file_put_contents("{$public}/brand/mark-512.png", png(renderMark(512, $primary, $marker)));

// favicon.ico with 16, 32 and 48 px PNG images inside.
$images = array_map(fn ($size) => [$size, png(renderMark($size, $primary, $marker))], [16, 32, 48]);
$ico = pack('vvv', 0, 1, count($images));
$offset = 6 + 16 * count($images);
foreach ($images as [$size, $data]) {
    $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($data), $offset);
    $offset += strlen($data);
}
foreach ($images as [, $data]) {
    $ico .= $data;
}
file_put_contents("{$public}/favicon.ico", $ico);

// SVG favicon (fixed colours: browser tabs do not see the page's CSS variables).
$hex = fn (array $c) => sprintf('#%02X%02X%02X', ...$c);
file_put_contents("{$public}/favicon.svg", <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><path d="M26 6H74A8 8 0 0 1 82 14V94L50 74L18 94V14A8 8 0 0 1 26 6Z" fill="{$hex($primary)}"/><g fill="#fff" fill-opacity=".85"><circle cx="36" cy="24" r="4"/><circle cx="50" cy="24" r="4"/><circle cx="64" cy="24" r="4"/><circle cx="36" cy="38" r="4"/><circle cx="50" cy="38" r="4"/><circle cx="36" cy="52" r="4"/><circle cx="50" cy="52" r="4"/><circle cx="64" cy="52" r="4"/></g><circle cx="64" cy="38" r="7.5" fill="{$hex($marker)}"/></svg>

SVG);

echo "Wrote favicon.ico, favicon.svg, apple-touch-icon.png and brand/mark-{128,512}.png\n";
