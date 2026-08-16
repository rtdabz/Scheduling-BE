<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthenticationAuditService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly AuthenticationAuditService $audit) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $login = strtolower(trim($credentials['username']));
        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [$login])
            ->orWhereRaw('LOWER(email) = ?', [$login])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been disabled. Contact the VPAA office.'], 403);
        }

        return $this->authenticatedResponse($request, $user, 'password');
    }

    public function googleRedirect(): JsonResponse
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return response()->json(['message' => 'Google login is not configured.'], 503);
        }

        $state = Str::random(64);
        Cache::put("google-oauth-state:{$state}", true, now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return response()->json([
            'url' => "https://accounts.google.com/o/oauth2/v2/auth?{$query}",
            'state' => $state,
        ]);
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('services.frontend_url'), '/');

        if ($request->filled('error')) {
            return $this->googleErrorRedirect($frontendUrl, 'Google sign-in was cancelled.');
        }

        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        if (! Cache::pull("google-oauth-state:{$validated['state']}")) {
            return $this->googleErrorRedirect($frontendUrl, 'The Google sign-in request expired. Please try again.');
        }

        try {
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $validated['code'],
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect'),
                'grant_type' => 'authorization_code',
            ])->throw()->json();

            $googleUser = Http::withToken($tokenResponse['access_token'])
                ->get('https://openidconnect.googleapis.com/v1/userinfo')
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            report($exception);

            return $this->googleErrorRedirect($frontendUrl, 'Google authentication could not be completed.');
        }

        $email = strtolower(trim((string) ($googleUser['email'] ?? '')));
        $googleId = (string) ($googleUser['sub'] ?? '');
        $emailVerified = filter_var($googleUser['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if (! $email || ! $googleId || ! $emailVerified) {
            return $this->googleErrorRedirect($frontendUrl, 'Google did not provide a verified email address.');
        }

        $allowedDomain = strtolower(trim((string) config('services.google.allowed_domain')));
        if ($allowedDomain && ! str_ends_with($email, '@'.$allowedDomain)) {
            return $this->googleErrorRedirect($frontendUrl, 'Use your approved institutional Google account.');
        }

        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhere(function ($query) use ($email) {
                $query->whereNull('google_id')->whereRaw('LOWER(email) = ?', [$email]);
            })
            ->first();

        if (! $user || ! $user->allow_google_login) {
            return $this->googleErrorRedirect($frontendUrl, 'This Google account has not been approved by VPAA.');
        }

        if (! $user->is_active) {
            return $this->googleErrorRedirect($frontendUrl, 'This account has been disabled. Contact the VPAA office.');
        }

        if (strtolower((string) $user->email) !== $email) {
            return $this->googleErrorRedirect($frontendUrl, 'The Google email does not match the approved account email.');
        }

        if (! $user->google_id) {
            $user->forceFill([
                'google_id' => $googleId,
                'google_email' => $email,
                'google_linked_at' => now(),
            ])->save();
            $this->audit->record($request, 'google_linked', $user, ['email' => $email]);
        }

        $exchangeCode = Str::random(80);
        Cache::put("google-login-exchange:{$exchangeCode}", [
            'user_id' => $user->id,
            'state' => $validated['state'],
        ], now()->addMinutes(2));

        return redirect()->away($frontendUrl.'/?'.http_build_query([
            'google_code' => $exchangeCode,
            'google_state' => $validated['state'],
        ]));
    }

    public function googleExchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);
        $exchange = Cache::pull("google-login-exchange:{$validated['code']}");
        $user = is_array($exchange) && isset($exchange['state'], $exchange['user_id']) && hash_equals($exchange['state'], $validated['state'])
            ? User::find($exchange['user_id'])
            : null;

        if (! $user || ! $user->is_active || ! $user->allow_google_login) {
            return response()->json(['message' => 'The Google login request is invalid or expired.'], 401);
        }

        return $this->authenticatedResponse($request, $user, 'google');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email']);
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->first();

        if ($user && $user->is_active) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return response()->json([
            'message' => 'If an active account matches that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->mixedCase()->numbers()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password) use ($request) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
            $this->audit->record($request, 'password_reset', $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Password reset successfully. You may now sign in.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record($request, 'logout', $request->user());
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load(['department', 'program']));
    }

    private function authenticatedResponse(Request $request, User $user, string $method): JsonResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken("wicars-{$method}")->plainTextToken;
        $this->audit->record($request, 'login_succeeded', $user, ['method' => $method]);

        return response()->json([
            'token' => $token,
            'user' => $user->fresh()->load(['department', 'program']),
        ]);
    }

    private function googleErrorRedirect(string $frontendUrl, string $message): RedirectResponse
    {
        return redirect()->away($frontendUrl.'/?'.http_build_query(['google_error' => $message]));
    }
}
