<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Laravel's `image` validation rule only sniffs MIME type/extension — a
 * file that passes that check can still fail a real decode (a corrupted
 * upload, a truncated transfer, a non-image renamed to look like one).
 * Without this, that failure surfaces as an uncaught
 * Intervention\Image\Exceptions\ImageDecoderException: a 500 with an
 * internal stack trace instead of a predictable, clean error.
 */
class InvalidImageException extends RuntimeException
{
    public function __construct(string $message = 'The uploaded image could not be processed.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Renders the actual message rather than a hardcoded one — the previous
     * fixed string silently discarded whatever a caller passed to the
     * constructor, and its "one or more files" wording was wrong for the
     * single-file uploads (a shop logo, a cover image) that also use this.
     * The default above is deliberately count-agnostic so it reads correctly
     * whether one logo or one of ten product images failed to decode.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
