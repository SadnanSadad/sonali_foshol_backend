<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditService $audit) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^01[3-9]\d{8}$/', Rule::unique('customers', 'phone')],
            'email' => ['required', 'email', 'max:190', Rule::unique('customers', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'district' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = Customer::query()->create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'district' => $data['district'] ?? 'ঢাকা',
            'address' => $data['address'] ?? '',
        ]);

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        $this->audit->log('customer.registered', Customer::class, $customer->id, [], $request);

        return $this->success($this->customerData($customer), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::query()->where('email', strtolower($data['email']))->first();

        if (! $customer || ! $customer->password || ! Hash::check($data['password'], $customer->password)) {
            return $this->error('Invalid credentials.', 401);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        $this->audit->log('customer.login', Customer::class, $customer->id, [], $request);

        return $this->success($this->customerData($customer));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->customerData($request->user('customer')));
    }

    public function logout(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $this->audit->log('customer.logout', Customer::class, $customer?->id, [], $request);

        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(['success' => true]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer = $request->user('customer');

        if (! $customer->password || ! Hash::check($data['current_password'], $customer->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $customer->password = $data['password'];
        $customer->save();
        $request->session()->regenerate();

        $this->audit->log('customer.password.updated', Customer::class, $customer->id, [], $request);

        return $this->success(['success' => true]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        Password::broker('customers')->sendResetLink(['email' => strtolower($data['email'])]);

        return $this->success(['success' => true, 'message' => 'If the account exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker('customers')->reset(
            ['email' => strtolower($data['email']), 'password' => $data['password'], 'password_confirmation' => $data['password_confirmation'], 'token' => $data['token']],
            function (Customer $customer) use ($request) {
                $customer->save();
                $this->audit->log('customer.password.reset', Customer::class, $customer->id, [], $request);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error('This password reset token is invalid or expired.', 422);
        }

        return $this->success(['success' => true]);
    }

    private function customerData(?Customer $customer): array
    {
        return [
            'id' => $customer?->id,
            'name' => $customer?->name,
            'phone' => $customer?->phone,
            'email' => $customer?->email,
            'district' => $customer?->district,
            'address' => $customer?->address,
        ];
    }
}
