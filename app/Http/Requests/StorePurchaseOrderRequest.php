<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
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
        $id = $this->route('purchase_order')?->id;

        return [
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'number' => ['required', 'string', 'max:50', Rule::unique('purchase_orders')->ignore($id)],
            'supplier_id' => ['required', Rule::exists('counterparties', 'id')->where('type', 'supplier')],
            'order_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'total_amount' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(['open', 'partially_paid', 'paid', 'cancelled'])],
            'description' => ['nullable', 'string'],
        ];
    }
}
