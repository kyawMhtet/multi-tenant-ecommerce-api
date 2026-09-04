<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        [$user, $token] = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }

    /**
     * The signed-in user, so a client that reloads with a stored token can
     * recover its identity and role rather than trusting a cached copy that
     * goes stale the moment an owner changes someone's role.
     *
     * Outside the 'tenant' middleware on purpose: a suspended shop's owner
     * must still be able to identify themselves, so the admin app can render
     * the suspension notice rather than an anonymous error.
     */
    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()->load('tenant')))->response();
    }

    /**
     * Returns the created account but NO token — registering is not signing
     * in. The client sends the owner to /login, where they enter the password
     * they just chose.
     *
     * The account is still returned so the login screen can greet them by
     * name and prefill their email rather than making them retype it.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        return (new UserResource($this->authService->register($request->validated())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logged out.']);
    }
}
