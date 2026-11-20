<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\RecoveryEmailVerificationNotification;
use App\Services\AuthenticationAuditService;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Self-service account security for the seeded VPAA account, which has no registration or
 * invitation flow of its own. Other roles keep their existing VPAA-managed workflows.
 *
 * The recovery Gmail is only written to users.email after the emailed code is confirmed, so a
 * VPAA email on file is always a verified one and the standard password broker can use it as-is.
 */
class VpaaAccountSecurityController extends Controller
{
    private const CODE_TTL_MINUTES = 15;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(private readonly AuthenticationAuditService $audit) {}

    public function requestEmailVerification(Request $request): JsonResponse
    {
        $user = $this->vpaa($request);
        $validated = $request->validate([
            'email' => [
                'required', 'string', 'max:255', 'email:rfc',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! self::isGoogleMailbox((string) $value)) {
                        $fail('Enter a Gmail address (for example name@gmail.com).');
                    }
                },
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ], ['email.unique' => 'This email is already used by another account.']);
        $email = strtolower(trim($validated['email']));

        if ($email === strtolower((string) $user->email)) {
            throw ValidationException::withMessages(['email' => 'This Gmail address is already linked and verified.']);
        }

        $cooldownKey = "vpaa-recovery-email-cooldown:{$user->id}";
        if (! Cache::add($cooldownKey, true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            return response()->json(['message' => 'Please wait a minute before requesting another code.'], 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Notification::route('mail', $email)
                ->notifyNow(new RecoveryEmailVerificationNotification($code, self::CODE_TTL_MINUTES));
        } catch (Throwable $exception) {
            report($exception);
            Cache::forget($cooldownKey);

            return response()->json(['message' => 'The verification email could not be sent. Check the mail settings and try again.'], 503);
        }

        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);
        Cache::put($this->pendingKey($user), [
            'email' => $email,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);
        $this->audit->record($request, 'recovery_email_code_sent', $user, ['email' => $email]);

        return response()->json([
            'message' => "A 6-digit verification code was sent to {$email}. It expires in ".self::CODE_TTL_MINUTES.' minutes.',
            'pending_email' => $email,
        ]);
    }

    public function confirmEmailVerification(Request $request): JsonResponse
    {
        $user = $this->vpaa($request);
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.regex' => 'Enter the 6-digit code from the email.',
        ]);

        $key = $this->pendingKey($user);
        $pending = Cache::get($key);
        if (! is_array($pending) || ! isset($pending['email'], $pending['code_hash'], $pending['expires_at'])) {
            throw ValidationException::withMessages(['code' => 'The verification code has expired. Request a new one.']);
        }

        if (! Hash::check($validated['code'], $pending['code_hash'])) {
            $pending['attempts'] = ((int) ($pending['attempts'] ?? 0)) + 1;
            if ($pending['attempts'] >= self::MAX_CODE_ATTEMPTS) {
                Cache::forget($key);
                throw ValidationException::withMessages(['code' => 'Too many incorrect attempts. Request a new code.']);
            }
            // Keep the original expiry window: a wrong guess must not extend the code's life.
            Cache::put($key, $pending, now()->setTimestamp((int) $pending['expires_at']));
            throw ValidationException::withMessages(['code' => 'The verification code is incorrect.']);
        }

        Cache::forget($key);
        $email = $pending['email'];

        DB::transaction(function () use ($user, $email) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (User::withTrashed()->whereKeyNot($locked->id)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['code' => 'This email is now used by another account.']);
            }
            // Reset links issued to the previous address must not outlive the change.
            $this->forgetResetTokens($locked);
            $locked->forceFill(['email' => $email])->save();
        });
        $this->audit->record($request, 'recovery_email_verified', $user->fresh(), [
            'previous_email' => $user->email,
            'email' => $email,
        ]);

        return response()->json([
            'message' => 'Gmail address verified. Password reset links will be sent here.',
            'email' => $email,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->vpaa($request);
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => [
                'required', 'string', 'max:255', 'confirmed', 'different:current_password',
                PasswordRule::min(10)->letters()->mixedCase()->numbers(),
            ],
        ], [
            'current_password.current_password' => 'The current password is incorrect.',
            'password.different' => 'The new password must be different from the current password.',
        ]);

        $currentTokenId = $user->currentAccessToken()?->id;
        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();
        // Sign out every other device; the session that made the change stays signed in.
        $user->tokens()->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))->delete();
        $this->forgetResetTokens($user);
        $this->audit->record($request, 'password_changed', $user);

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
    }

    /** Gmail, Googlemail, or the institution's Google Workspace domain when one is configured. */
    private static function isGoogleMailbox(string $email): bool
    {
        $domain = strtolower(Str::afterLast(trim($email), '@'));
        $workspace = strtolower(trim((string) config('services.google.allowed_domain')));

        return in_array($domain, ['gmail.com', 'googlemail.com'], true) || ($workspace !== '' && $domain === $workspace);
    }

    private function vpaa(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isVpaa(), 403, 'Only the VPAA account can use this setting.');

        return $user;
    }

    private function forgetResetTokens(User $user): void
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker();
        $broker->deleteToken($user);
    }

    private function pendingKey(User $user): string
    {
        return "vpaa-recovery-email-pending:{$user->id}";
    }
}
