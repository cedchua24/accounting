<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userAccount = $this->route('user_account');
        $required = $userAccount ? 'sometimes' : 'required';

        return [
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'code' => [$required, 'string', 'max:40', Rule::unique('user_accounts')->ignore($userAccount?->id)],
            'type' => [$required, Rule::in(['individual', 'company'])],
            'name' => [$required, 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:80'],
            'details' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        if ($this->has('code')) {
            $values['code'] = strtoupper(trim((string) $this->input('code')));
        }
        if ($this->has('type')) {
            $values['type'] = strtolower(trim((string) $this->input('type')));
        }
        if ($this->has('name')) {
            $values['name'] = trim((string) $this->input('name'));
        }
        if ($this->filled('email')) {
            $values['email'] = strtolower(trim((string) $this->input('email')));
        }

        $this->merge($values);
    }
}
