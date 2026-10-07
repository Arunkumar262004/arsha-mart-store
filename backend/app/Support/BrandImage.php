<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Turns an uploaded logo or favicon into a PNG data URI that fits inside a
 * box, keeping its proportions and transparency. A favicon is centred on a
 * transparent square so browsers don't stretch it.
 */
final class BrandImage
{
    public static function fromUpload(UploadedFile $file, string $field, int $maxSide, bool $square = false): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages([$field => 'This image could not be read. Try a PNG or JPG.']);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        [$canvasW, $canvasH] = $square ? [max($w, $h), max($w, $h)] : [$w, $h];

        $image = imagecreatetruecolor($canvasW, $canvasH);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        imagecopyresampled($image, $source, intdiv($canvasW - $w, 2), intdiv($canvasH - $h, 2), 0, 0, $w, $h, $width, $height);
        imagealphablending($image, false);

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
