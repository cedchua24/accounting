<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('payment_method')?->id;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('payment_methods')->ignore($id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('payment_methods')->ignore($id)],
            'type' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'credit_card', 'e_wallet', 'other'])],
            'creates_obligation' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
