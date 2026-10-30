<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustLoanInstallmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'due_date' => ['sometimes', 'date'],
            'principal_due' => ['sometimes', 'numeric', 'gte:0', 'decimal:0,4'],
            'interest_due' => ['sometimes', 'numeric', 'gte:0', 'decimal:0,4'],
            'reason' => ['required', 'string', 'max:1000'],
            'as_of_date' => ['sometimes', 'date'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $this->hasAny(['due_date', 'principal_due', 'interest_due'])) {
                $validator->errors()->add(
                    'installment',
                    'Provide a new due date, principal amount, or interest amount.'
                );
            }
        }];
    }
}
