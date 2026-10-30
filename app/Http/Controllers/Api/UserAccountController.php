<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserAccountRequest;
use App\Models\UserAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserAccountController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request, true);

        return $this->query($filters)->paginate($filters['per_page'] ?? 15);
    }

    public function list(Request $request)
    {
        $filters = $this->filters($request);

        return $this->query($filters)->get();
    }

    private function filters(Request $request, bool $withPagination = false): array
    {
        $rules = [
            'type' => ['nullable', Rule::in(['individual', 'company'])],
            'active' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ];
        if ($withPagination) {
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:100'];
        }

        return $request->validate($rules);
    }

    private function query(array $filters)
    {
        return UserAccount::with('user:id,name,username,email')
            ->withCount(['financialAccounts', 'loans'])
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $filters['active']))
            ->when($filters['user_id'] ?? null, fn ($query, $value) => $query->where('user_id', $value))
            ->when($filters['search'] ?? null, fn ($query, $value) => $query->where(function ($search) use ($value) {
                $search->where('name', 'like', "%{$value}%")
                    ->orWhere('code', 'like', "%{$value}%")
                    ->orWhere('email', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%");
            }))
            ->orderBy('name');
    }

    public function store(StoreUserAccountRequest $request)
    {
        return response()->json(
            UserAccount::create($request->validated())
                ->load('user:id,name,username,email')
                ->loadCount(['financialAccounts', 'loans']),
            201
        );
    }

    public function show(UserAccount $userAccount)
    {
        return $userAccount
            ->load(
                'user:id,name,username,email',
                'financialAccounts.institution',
                'financialAccounts.ownerBranch',
                'loans.financialInstitution:id,code,name,type',
            )
            ->loadCount(['financialAccounts', 'loans']);
    }

    public function update(StoreUserAccountRequest $request, UserAccount $userAccount)
    {
        $userAccount->update($request->validated());

        return $userAccount->fresh()
            ->load('user:id,name,username,email')
            ->loadCount(['financialAccounts', 'loans']);
    }

    public function destroy(UserAccount $userAccount)
    {
        abort_if($userAccount->financialAccounts()->exists(), 409, 'An account holder used by a financial account cannot be deleted.');
        abort_if($userAccount->loans()->exists(), 409, 'A user account used by a loan cannot be deleted.');
        $userAccount->delete();

        return response()->noContent();
    }
}
