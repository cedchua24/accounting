<?php

namespace App\Http\Requests;

use App\Models\ChartAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChartAccountRequest extends FormRequest
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
        $id = $this->route('chart_account')?->id;

        return [
            'group_id' => ['nullable', Rule::exists('chart_account_groups', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:30', Rule::unique('chart_accounts')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'parent_id' => ['nullable', 'exists:chart_accounts,id', Rule::notIn([$id])],
            'description' => ['nullable', 'string'],
            'is_postable' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_id');
            if (! $parentId) {
                return;
            }

            $account = $this->route('chart_account');
            $parent = ChartAccount::find($parentId);
            if (! $parent) {
                return;
            }
            $type = $this->input('type', $account?->type);
            if ($type && $parent->type !== $type) {
                $validator->errors()->add('parent_id', 'The parent and child must use the same accounting type.');
            }
            $groupId = $this->input('group_id', $account?->group_id);
            if ($groupId && $parent->group_id && (int) $parent->group_id !== (int) $groupId) {
                $validator->errors()->add('parent_id', 'The parent and child must belong to the same chart-account group.');
            }

            $accountId = $account?->id;
            $cursor = $parent;
            while ($accountId && $cursor) {
                if ((int) $cursor->id === (int) $accountId) {
                    $validator->errors()->add('parent_id', 'The selected parent would create a chart-account cycle.');
                    break;
                }
                $cursor = $cursor->parent_id ? ChartAccount::find($cursor->parent_id) : null;
            }
        });
    }
}
