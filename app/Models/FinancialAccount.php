<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class FinancialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_branch_id', 'is_company_wide', 'financial_institution_id', 'chart_account_id', 'kind', 'name', 'account_number',
        'account_holder', 'description', 'currency', 'credit_limit', 'statement_day',
        'due_day', 'buffer_days', 'usable_for_receipts', 'usable_for_payments', 'is_active',
        'legacy_source', 'legacy_id',
    ];

    protected $hidden = ['account_number', 'account_number_hash'];

    protected $appends = ['masked_account_number'];

    protected $casts = [
        'credit_limit' => 'decimal:4', 'is_company_wide' => 'boolean', 'usable_for_receipts' => 'boolean',
        'current_balance' => 'decimal:4', 'available_balance' => 'decimal:4',
        'balance_updated_at' => 'datetime', 'usable_for_payments' => 'boolean', 'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (FinancialAccount $account) {
            if (! $account->exists || $account->isDirty(['kind', 'credit_limit', 'current_balance'])) {
                $current = bcadd((string) ($account->current_balance ?? 0), '0', 4);
                $account->current_balance = $current;
                $account->available_balance = $account->kind === 'credit_card'
                    ? bcsub((string) ($account->credit_limit ?? 0), $current, 4)
                    : $current;
                if (! $account->exists && ! $account->balance_updated_at) {
                    $account->balance_updated_at = now();
                }
            }
        });
    }

    public function institution()
    {
        return $this->belongsTo(FinancialInstitution::class, 'financial_institution_id');
    }

    public function ownerBranch()
    {
        return $this->belongsTo(Branch::class, 'owner_branch_id');
    }

    public function userAccount()
    {
        return $this->belongsTo(UserAccount::class, 'account_holder');
    }

    public function accessibleBranches()
    {
        return $this->belongsToMany(Branch::class, 'financial_account_branch_access')->withTimestamps();
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function applications()
    {
        return $this->hasMany(FinancialAccountApplication::class)->with('type')->orderBy('application_id');
    }

    public function creditCardStatements()
    {
        return $this->hasMany(PaymentObligation::class)
            ->where('type', 'credit_card_statement')
            ->orderBy('due_date');
    }

    public function scopeAvailableToBranch($query, int $branchId)
    {
        return $query->where(fn ($scope) => $scope
            ->where('is_company_wide', true)
            ->orWhereHas('accessibleBranches', fn ($branches) => $branches->whereKey($branchId)));
    }

    public function isAvailableToBranch(int $branchId): bool
    {
        return $this->is_company_wide || $this->accessibleBranches()->whereKey($branchId)->exists();
    }

    protected function accountNumber(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: function (?string $value) {
                $normalized = preg_replace('/\s+/', '', trim((string) $value));

                return [
                    'account_number' => $normalized === '' ? null : Crypt::encryptString($normalized),
                    'account_number_hash' => $normalized === '' ? null : hash('sha256', strtoupper($normalized)),
                    'account_number_last4' => $normalized === '' ? null : substr($normalized, -4),
                ];
            },
        );
    }

    protected function maskedAccountNumber(): Attribute
    {
        return Attribute::get(fn () => $this->account_number_last4 ? '•••• '.$this->account_number_last4 : null);
    }
}
