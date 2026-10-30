<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('profile')
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', $search)
                        ->orWhere('username', 'like', $search)
                        ->orWhere('email', 'like', $search);
                });
            })
            ->orderBy('name')
            ->paginate();

        return $users;
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $profile = $data['profile'] ?? [];
        unset($data['profile']);

        $user = DB::transaction(function () use ($data, $profile) {
            $user = User::create($data);
            $user->profile()->create($profile);

            return $user;
        });

        return response()->json($user->load('profile'), 201);
    }

    public function show(User $user)
    {
        return $user->load('profile');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();
        $profile = $data['profile'] ?? null;
        unset($data['profile']);
        $removesSuperAdminAccess = $user->isSuperAdmin()
            && (($data['role'] ?? $user->role) !== User::ROLE_SUPER_ADMIN
                || ($data['is_active'] ?? $user->is_active) === false);

        if ($removesSuperAdminAccess && $this->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'role' => 'The last active super administrator cannot be demoted or deactivated.',
            ]);
        }

        DB::transaction(function () use ($user, $data, $profile) {
            $user->update($data);
            if ($profile !== null) {
                $user->profile()->updateOrCreate([], $profile);
            }
        });

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return $user->fresh()->load('profile');
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }

        if ($user->isSuperAdmin() && $this->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages(['user' => 'The last active super administrator cannot be deleted.']);
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return ! User::whereKeyNot($user->getKey())
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->exists();
    }
}
