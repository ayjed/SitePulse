<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Handles single-owner web session login and logout flows.
 */
class AuthenticatedSessionController extends Controller
{
    public const MAX_ATTEMPTS_PER_MINUTE = 5;

    public const DECAY_SECONDS = 60;

    /**
     * Display the owner sign-in page.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Authenticate an owner sign-in attempt with IP-based rate limiting.
     */
    public function store(Request $request): SymfonyResponse
    {
        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            $seconds = max(1, RateLimiter::availableIn($throttleKey));
            $message = sprintf('Too many login attempts. Please try again in %d seconds.', $seconds);

            if ($request->expectsJson()) {
                return new JsonResponse(['message' => $message], SymfonyResponse::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => (string) $seconds,
                ]);
            }

            return response()
                ->view('auth.login', [
                    'throttleMessage' => $message,
                    'prefilledEmail' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '',
                ], SymfonyResponse::HTTP_TOO_MANY_REQUESTS)
                ->header('Retry-After', (string) $seconds);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $authenticated = Auth::attemptWhen(
            [
                'email' => $email,
                'password' => $validated['password'],
            ],
            static fn (User $user): bool => $user->isOwner(),
            false
        );

        if (! $authenticated) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            return back()
                ->withInput(['email' => $email])
                ->withErrors([
                    'email' => 'These credentials do not match our records.',
                ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Terminate the active owner session and rotate the CSRF token.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    private function throttleKey(Request $request): string
    {
        return 'login:ip:'.(string) ($request->ip() ?: 'unknown');
    }
}
