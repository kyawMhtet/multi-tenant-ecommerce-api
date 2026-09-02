<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * The `image` rule only sniffs MIME/extension; a file that passes can still
 * fail a real decode. Without this that surfaces as a 500 with a stack trace.
 */
class InvalidImageException extends RuntimeException
{
    public function __construct(string $message = 'The uploaded image could not be processed.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Renders getMessage(), not a fixed string — a hardcoded one silently
     * discards whatever a caller passed. The default is count-agnostic so it
     * reads correctly for one logo or one of ten product images.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
