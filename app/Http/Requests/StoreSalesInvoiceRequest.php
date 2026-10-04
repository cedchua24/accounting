<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesInvoiceRequest extends FormRequest
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
        $id = $this->route('sales_invoice')?->id;

        return [
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'customer_id' => ['nullable', Rule::exists('counterparties', 'id')->where('type', 'customer')],
            'number' => ['required', 'string', 'max:50', Rule::unique('sales_invoices')->ignore($id)],
            'invoice_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'total_amount' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(['unpaid', 'partially_paid', 'paid', 'cancelled'])],
            'description' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->currency)]);
        }
    }
}
