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
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Handles single-owner web session login and logout flows.
 */
class AuthenticatedSessionController extends Controller
{
    public const MAX_ATTEMPTS_PER_MINUTE = 5;

    public const DECAY_SECONDS = 60;

    private const INVALID_CREDENTIALS_MESSAGE = 'These credentials do not match our records.';

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
        $safeEmail = $this->normalizeEmailInput($request->input('email'));

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
                    'prefilledEmail' => $safeEmail,
                ], SymfonyResponse::HTTP_TOO_MANY_REQUESTS)
                ->header('Retry-After', (string) $seconds);
        }

        $validator = Validator::make($request->only(['email', 'password']), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        if ($validator->fails()) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            return $this->failedLoginResponse($request, $safeEmail);
        }

        $validated = $validator->validated();
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

            return $this->failedLoginResponse($request, $email);
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

    private function normalizeEmailInput(mixed $rawEmail): string
    {
        if (! is_string($rawEmail)) {
            return '';
        }

        return mb_substr(mb_strtolower(trim($rawEmail)), 0, 255);
    }

    private function failedLoginResponse(Request $request, string $safeEmail): SymfonyResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => self::INVALID_CREDENTIALS_MESSAGE,
                'errors' => [
                    'email' => [self::INVALID_CREDENTIALS_MESSAGE],
                ],
            ], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return back()
            ->withInput(['email' => $safeEmail])
            ->withErrors([
                'email' => self::INVALID_CREDENTIALS_MESSAGE,
            ]);
    }
}
