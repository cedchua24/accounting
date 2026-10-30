<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterUserRequest $request)
    {
        $data = $request->validated();
        $profile = $data['profile'] ?? [];
        $deviceName = $data['device_name'] ?? 'api';
        unset($data['profile'], $data['device_name']);

        $user = DB::transaction(function () use ($data, $profile) {
            $user = User::create([
                ...$data,
                'role' => User::ROLE_VIEWER,
                'is_active' => true,
            ]);
            $user->profile()->create($profile);

            return $user;
        });

        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => $user->load('profile'),
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['nullable', 'string', 'required_without:email'],
            'email' => ['nullable', 'email', 'required_without:login'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $login = $data['login'] ?? $data['email'];
        $user = User::where('email', $login)->orWhere('username', $login)->first();
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            $credentialField = isset($data['login']) ? 'login' : 'email';
            throw ValidationException::withMessages([$credentialField => 'The provided credentials are incorrect.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['token' => $user->createToken($data['device_name'] ?? 'api')->plainTextToken, 'user' => $user->load('profile')]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
