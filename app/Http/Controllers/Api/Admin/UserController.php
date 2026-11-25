<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        $users = User::query()->orderBy('created_at')->get();

        return $this->success(UserResource::collection($users)->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:60', Rule::unique('users', 'username')],
            'password' => ['required', 'confirmed', Password::min(12)],
            'role' => ['required', Rule::enum(AdminRole::class)],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'username' => strtolower($data['username']),
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => true,
        ]);

        $this->audit->log('user.created', User::class, $user->id, ['role' => $user->role->value], $request);

        return $this->success(new UserResource($user), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::enum(AdminRole::class)],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        if ($user->id === $request->user('admin')->id
            && (($data['isActive'] ?? true) === false || (isset($data['role']) && $data['role'] !== $user->role->value))) {
            return $this->error('You cannot deactivate or change the role of your own account.', 422);
        }

        $roleChanged = isset($data['role']) && $data['role'] !== $user->role->value;
        $deactivated = array_key_exists('isActive', $data) && $data['isActive'] === false;

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'role' => $data['role'] ?? $user->role,
            'is_active' => $data['isActive'] ?? $user->is_active,
        ])->save();

        if ($roleChanged || $deactivated) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
        }

        $this->audit->log('user.updated', User::class, $user->id, $data, $request);

        return $this->success(new UserResource($user));
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::query()->findOrFail($id);
        $user->forceFill(['password' => $data['password']])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        $this->audit->log('user.password.reset', User::class, $user->id, [], $request);

        return $this->success(['success' => true]);
    }
}
