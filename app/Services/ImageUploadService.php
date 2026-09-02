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
 * GD, not Imagick: it needs no extra configuration and nothing here uses a
 * feature that would benefit from Imagick.
 */
class ImageUploadService
{
    /** Longest side, not exact dimensions — preserves any aspect ratio. */
    private const MAX_DIMENSION = 1600;

    private const JPEG_QUALITY = 80;

    /**
     * Re-encodes every upload as JPEG whatever the source: that, not the
     * dimension cap, is what keeps files small — a PNG screenshot can be 5-10x
     * an equivalent JPEG at this quality.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $manager = ImageManager::usingDriver(GdDriver::class);

        try {
            $image = $manager->decodeSplFileInfo($file);
        } catch (Throwable $e) {
            // Catches what the `image` rule can't: a file whose MIME looks
            // right but whose bytes aren't an intact image.
            throw new InvalidImageException(previous: $e);
        }

        // scaleDown only shrinks; it never upscales a smaller image.
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
