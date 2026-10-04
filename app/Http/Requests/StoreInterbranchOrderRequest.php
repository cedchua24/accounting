<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterbranchOrderRequest extends FormRequest
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
        $id = $this->route('interbranch_order')?->id;

        return [
            'number' => ['required', 'string', 'max:50', Rule::unique('interbranch_orders')->ignore($id)],
            'source_branch_id' => ['required', 'different:destination_branch_id', Rule::exists('branches', 'id')->where('is_active', true)],
            'destination_branch_id' => ['required', 'different:source_branch_id', Rule::exists('branches', 'id')->where('is_active', true)],
            'order_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'settlement_mode' => ['required', Rule::in(['inventory_transfer', 'internal_clearing'])],
            'memo' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
