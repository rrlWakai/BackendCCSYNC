<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\ApiResponse;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class AuthController extends Controller
{
    private const MAX_EMAIL_IP_ATTEMPTS = 5;

    private const MAX_IP_ATTEMPTS = 20;

    private const DECAY_SECONDS = 60;

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = Str::lower($validated['email']);
        $emailKey = $this->emailKey($email, $request);
        $ipKey = $this->ipKey($request);

        if (RateLimiter::tooManyAttempts($emailKey, self::MAX_EMAIL_IP_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipKey, self::MAX_IP_ATTEMPTS)) {
            return $this->throttledResponse($emailKey, $ipKey);
        }

        $remember = (bool) ($validated['remember'] ?? false);

        if (! Auth::attempt(['email' => $email, 'password' => $validated['password']], $remember)) {
            return $this->failedLoginResponse($emailKey, $ipKey);
        }

        $user = Auth::user();

        if (! $user instanceof \App\Models\User || ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->failedLoginResponse($emailKey, $ipKey);
        }

        RateLimiter::clear($emailKey);
        RateLimiter::clear($ipKey);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::success(
            ['user' => UserPresenter::toArray($user)],
            'Signed in.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['user' => UserPresenter::toArray($request->user())],
            'Authenticated user loaded.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null, 'Signed out.');
    }

    private function failedLoginResponse(string $emailKey, string $ipKey): JsonResponse
    {
        RateLimiter::hit($emailKey, self::DECAY_SECONDS);
        RateLimiter::hit($ipKey, self::DECAY_SECONDS);

        return ApiResponse::error('The provided credentials are incorrect.', 401);
    }

    private function throttledResponse(string $emailKey, string $ipKey): JsonResponse
    {
        $retryAfter = max(
            RateLimiter::availableIn($emailKey),
            RateLimiter::availableIn($ipKey),
        );

        return ApiResponse::error(
            'Too many login attempts. Please try again later.',
            429,
        )->header('Retry-After', (string) max(1, $retryAfter));
    }

    private function emailKey(string $email, Request $request): string
    {
        return 'login:email:'.sha1($email.'|'.$request->ip());
    }

    private function ipKey(Request $request): string
    {
        return 'login:ip:'.sha1((string) $request->ip());
    }
}
