<?php

namespace App\Exceptions;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Platform staff have locked this shop out of its own admin.
 *
 * 403, not 402: unlike every billing refusal, paying does not fix this. The
 * distinction matters to the client — the admin app renders a 402 as an
 * upgrade prompt, which would be nonsense here and would send the owner off to
 * pay for something that will not help.
 *
 * The `reason` field is machine-readable so the app can show a real
 * explanation rather than a bare "forbidden", and the human reason travels
 * with it because the owner cannot act on the word "suspended" alone.
 */
class ShopSuspendedException extends RuntimeException
{
    public function __construct(private readonly ?string $shopReason = null)
    {
        parent::__construct('This shop is suspended.');
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant->suspension_reason);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'This shop has been suspended. Your storefront is still online, but changes are paused while we sort this out.',
            'reason' => 'shop_suspended',
            'detail' => $this->shopReason,
        ], 403);
    }
}
