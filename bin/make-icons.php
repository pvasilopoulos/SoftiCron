<?php

declare(strict_types=1);

// Generates the PWA PNG icons (requires ext-gd). Usage: php bin/make-icons.php [#RRGGBB]

$color = $argv[1] ?? '#4F46E5';
[$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
$out = __DIR__ . '/../public/assets/icons';
@mkdir($out, 0775, true);

function draw(int $size, array $rgb, bool $badge): GdImage
{
    $s = 4; // supersampling
    $n = $size * $s;
    $im = imagecreatetruecolor($n, $n);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $white = imagecolorallocate($im, 255, 255, 255);
    if ($badge) {
        $fg = $white; // monochrome badge: white glyph on transparent
    } else {
        $bg = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
        $rad = (int) ($n * 0.22);
        imagefilledrectangle($im, $rad, 0, $n - $rad, $n, $bg);
        imagefilledrectangle($im, 0, $rad, $n, $n - $rad, $bg);
        foreach ([[$rad, $rad], [$n - $rad, $rad], [$rad, $n - $rad], [$n - $rad, $n - $rad]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $rad * 2, $rad * 2, $bg);
        }
        $fg = $white;
    }
    $c = $n / 2;
    $ro = $n * 0.33;
    $th = $n * 0.075;
    imagesetthickness($im, (int) $th);
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($ro * 2 + $th), (int) ($ro * 2 + $th), $fg);
    $hole = $badge ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    imagealphablending($im, !$badge);
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($ro * 2 - $th), (int) ($ro * 2 - $th), $hole);
    imagealphablending($im, true);
    imageline($im, (int) $c, (int) $c, (int) $c, (int) ($c - $ro * 0.62), $fg);
    imageline($im, (int) $c, (int) $c, (int) ($c + $ro * 0.5), (int) ($c + $ro * 0.3), $fg);
    imagefilledellipse($im, (int) $c, (int) $c, (int) $th, (int) $th, $fg);
    $dst = imagecreatetruecolor($size, $size);
    imagesavealpha($dst, true);
    imagealphablending($dst, false);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $size, $size, $n, $n);
    return $dst;
}

foreach ([192 => false, 512 => false] as $size => $badge) {
    imagepng(draw($size, [$r, $g, $b], false), "$out/icon-$size.png");
}
imagepng(draw(72, [$r, $g, $b], true), "$out/badge-72.png");
echo "Icons written to $out\n";
