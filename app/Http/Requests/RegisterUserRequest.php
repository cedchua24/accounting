<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'device_name' => ['nullable', 'string', 'max:100'],
            'profile' => ['sometimes', 'array'],
            'profile.first_name' => ['nullable', 'string', 'max:255'],
            'profile.middle_name' => ['nullable', 'string', 'max:255'],
            'profile.last_name' => ['nullable', 'string', 'max:255'],
            'profile.phone' => ['nullable', 'string', 'max:30'],
            'profile.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'profile.job_title' => ['nullable', 'string', 'max:255'],
            'profile.department' => ['nullable', 'string', 'max:255'],
            'profile.address_line_1' => ['nullable', 'string', 'max:255'],
            'profile.address_line_2' => ['nullable', 'string', 'max:255'],
            'profile.city' => ['nullable', 'string', 'max:100'],
            'profile.province' => ['nullable', 'string', 'max:100'],
            'profile.postal_code' => ['nullable', 'string', 'max:20'],
            'profile.country' => ['nullable', 'string', 'size:2'],
            'profile.avatar_url' => ['nullable', 'url', 'max:2048'],
            'profile.bio' => ['nullable', 'string', 'max:5000'],
            'profile.metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('profile.country')) {
            $profile = $this->input('profile');
            $profile['country'] = strtoupper($profile['country']);
            $this->merge(['profile' => $profile]);
        }
    }
}
