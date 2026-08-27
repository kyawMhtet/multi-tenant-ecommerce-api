<?php

namespace App\Services;

use App\Exceptions\InvalidImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * GD, not Imagick: both are available on this server, but GD needs no
 * extra configuration and is sufficient for a plain resize-and-recompress
 * — nothing here uses a feature (e.g. advanced color profiles) that would
 * actually benefit from Imagick.
 */
class ImageUploadService
{
    /**
     * Longest side, not exact dimensions: a product photo's aspect ratio
     * is never known ahead of time, so capping one side and letting
     * scaleDown() preserve the ratio avoids distorting or cropping it.
     */
    private const MAX_DIMENSION = 1600;

    private const JPEG_QUALITY = 80;

    /**
     * Re-encodes every upload as JPEG regardless of the source format —
     * this is what actually keeps stored files small (a PNG screenshot
     * or a phone-camera photo can be 5-10x the size of an equivalent
     * JPEG at this quality), not just the dimension cap. Storage size,
     * not just display size, is the point of "optimize."
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $manager = ImageManager::usingDriver(GdDriver::class);

        try {
            $image = $manager->decodeSplFileInfo($file);
        } catch (Throwable $e) {
            // The `image` validation rule already ran before this — this
            // catches what it can't: a file whose extension/MIME looks
            // right but whose actual bytes aren't a real, intact image.
            throw new InvalidImageException(previous: $e);
        }

        // scaleDown (not scale/resize): only shrinks images larger than
        // the cap, never upscales a smaller one.
        $image->scaleDown(width: self::MAX_DIMENSION, height: self::MAX_DIMENSION);

        $encoded = $image->encodeUsingFormat(Format::JPEG, quality: self::JPEG_QUALITY);

        $path = trim($directory, '/').'/'.Str::uuid().'.jpg';

        Storage::disk('public')->put($path, $encoded->toString());

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }
}
