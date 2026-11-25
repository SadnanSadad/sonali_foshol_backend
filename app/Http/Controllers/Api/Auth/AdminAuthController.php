<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AdminAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditService $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $login = strtolower(trim((string) ($data['login'] ?? $data['email'] ?? '')));

        $user = User::query()
            ->where('email', $login)
            ->orWhere('username', $login)
            ->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            $this->audit->log('admin.login.failed', User::class, $user?->id, ['login' => $login], $request);

            return $this->error('Invalid credentials.', 401);
        }

        if ($user->two_factor_confirmed_at !== null && $user->two_factor_secret) {
            return $this->success([
                'two_factor_required' => true,
                'challenge_token' => $this->makeChallenge($user, $request),
            ]);
        }

        return $this->completeLogin($user, (bool) ($data['remember'] ?? false), $request);
    }

    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'max:30'],
        ]);

        try {
            $payload = json_decode(Crypt::decryptString($data['challenge_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->error('Invalid or expired challenge.', 401);
        }

        if (($payload['expires_at'] ?? 0) < time() || ($payload['ip'] ?? null) !== $request->ip()) {
            return $this->error('Invalid or expired challenge.', 401);
        }

        $user = User::query()->find($payload['user_id'] ?? null);

        if (! $user || ! $user->is_active || ! $user->two_factor_secret) {
            return $this->error('Invalid or expired challenge.', 401);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);
        $valid = $this->google2fa->verifyKey($secret, $data['code'], 1);

        if (! $valid) {
            $valid = $this->consumeRecoveryCode($user, $data['code']);
        }

        if (! $valid) {
            return $this->error('Invalid authentication code.', 401);
        }

        return $this->completeLogin($user, false, $request);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user('admin')));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user('admin');
        $this->audit->log('admin.logout', User::class, $user?->id, [], $request);

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(['success' => true]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user('admin');

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $user->password = $data['password'];
        $user->save();

        $request->session()->regenerate();
        $this->audit->log('admin.password.updated', User::class, $user->id, [], $request);

        return $this->success(['success' => true]);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user('admin');
        $secret = $this->google2fa->generateSecretKey(32);
        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(10)))->all();

        $user->two_factor_secret = Crypt::encryptString($secret);
        $user->two_factor_recovery_codes = Crypt::encryptString(json_encode(
            array_map(fn ($code) => Hash::make($code), $codes),
        ));
        $user->two_factor_confirmed_at = null;
        $user->save();

        $qrSvg = $this->google2fa->getQRCodeInline(config('app.name'), $user->email, $secret);

        $this->audit->log('admin.2fa.enable.requested', User::class, $user->id, [], $request);

        return $this->success([
            'secret' => $secret,
            'qr_svg' => $qrSvg,
            'recovery_codes' => $codes,
        ]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $user = $request->user('admin');

        if (! $user->two_factor_secret) {
            return $this->error('Two-factor authentication has not been started.', 409);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        if (! $this->google2fa->verifyKey($secret, $data['code'], 1)) {
            return $this->error('Invalid authentication code.', 422);
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->audit->log('admin.2fa.confirmed', User::class, $user->id, [], $request);

        return $this->success(['success' => true]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:30'],
        ]);

        $user = $request->user('admin');

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }

        $valid = $user->two_factor_secret
            && $this->google2fa->verifyKey(Crypt::decryptString($user->two_factor_secret), $data['code'], 1);

        if (! $valid) {
            $valid = $this->consumeRecoveryCode($user, $data['code']);
        }

        if (! $valid) {
            return $this->error('Invalid authentication code.', 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->log('admin.2fa.disabled', User::class, $user->id, [], $request);

        return $this->success(['success' => true]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user('admin');

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
        }

        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(10)))->all();

        $user->two_factor_recovery_codes = Crypt::encryptString(json_encode(
            array_map(fn ($code) => Hash::make($code), $codes),
        ));
        $user->save();

        $this->audit->log('admin.2fa.recovery-codes.regenerated', User::class, $user->id, [], $request);

        return $this->success(['recovery_codes' => $codes]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        Password::broker('users')->sendResetLink(['email' => strtolower($data['email'])]);

        return $this->success(['success' => true, 'message' => 'If the account exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $status = Password::broker('users')->reset(
            ['email' => strtolower($data['email']), 'password' => $data['password'], 'password_confirmation' => $data['password_confirmation'], 'token' => $data['token']],
            function (User $user) use ($request) {
                $user->save();
                $this->audit->log('admin.password.reset', User::class, $user->id, [], $request);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error('This password reset token is invalid or expired.', 422);
        }

        return $this->success(['success' => true]);
    }

    private function completeLogin(User $user, bool $remember, Request $request): JsonResponse
    {
        Auth::guard('admin')->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('admin.login', User::class, $user->id, [], $request);

        return $this->success(['user' => new UserResource($user)]);
    }

    private function makeChallenge(User $user, Request $request): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'expires_at' => time() + 300,
        ]));
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $hashes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->two_factor_recovery_codes = Crypt::encryptString(json_encode(array_values($hashes)));
                $user->save();

                return true;
            }
        }

        return false;
    }
}
