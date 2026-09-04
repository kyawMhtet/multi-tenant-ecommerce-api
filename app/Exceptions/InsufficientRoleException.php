<?php

namespace App\Exceptions;

use App\Services\Tenants\ShopRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InsufficientRoleException extends RuntimeException
{
    public function __construct(
        public readonly ShopRole $required,
        public readonly ?string $actual,
    ) {
        parent::__construct("This action requires the {$required->value} role; the user is [{$actual}].");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Your account does not have permission to do this. Ask the shop owner.',
            'reason' => 'insufficient_role',
            'required_role' => $this->required->value,
        ], 403);
    }
}
