<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformLoginRequest;
use App\Services\Platform\PlatformAuthService;
use Illuminate\Http\JsonResponse;

class PlatformAuthController extends Controller
{
    public function __construct(private readonly PlatformAuthService $auth) {}

    public function login(PlatformLoginRequest $request): JsonResponse
    {
        [$admin, $token] = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        return response()->json(['data' => [
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
            'token' => $token,
        ]]);
    }

    public function me(): JsonResponse
    {
        $admin = request()->user();

        return response()->json(['data' => [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'last_login_at' => $admin->last_login_at,
        ]]);
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout(request()->user());

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }
}
