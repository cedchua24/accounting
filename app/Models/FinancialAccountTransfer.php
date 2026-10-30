<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class FinancialAccountTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'source_financial_account_id', 'destination_financial_account_id',
        'destination_type', 'transfer_method', 'counterparty_id', 'offset_account_id',
        'recipient_name', 'recipient_type', 'external_institution_name', 'external_account_name',
        'external_account_number', 'recipient_contact', 'cheque_number', 'idempotency_key',
        'transfer_date', 'currency', 'amount', 'reference', 'memo', 'status',
        'created_by', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $hidden = ['external_account_number', 'external_account_number_hash'];

    protected $appends = ['masked_external_account_number'];

    protected $casts = [
        'transfer_date' => 'date', 'amount' => 'decimal:4',
        'posted_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function sourceAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'source_financial_account_id');
    }

    public function destinationAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    public function counterparty()
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function offsetAccount()
    {
        return $this->belongsTo(ChartAccount::class, 'offset_account_id');
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    protected function externalAccountNumber(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: function (?string $value) {
                $normalized = preg_replace('/\s+/', '', trim((string) $value));

                return [
                    'external_account_number' => $normalized === '' ? null : Crypt::encryptString($normalized),
                    'external_account_number_hash' => $normalized === '' ? null : hash('sha256', strtoupper($normalized)),
                    'external_account_number_last4' => $normalized === '' ? null : substr($normalized, -4),
                ];
            },
        );
    }

    protected function maskedExternalAccountNumber(): Attribute
    {
        return Attribute::get(fn () => $this->external_account_number_last4
            ? '•••• '.$this->external_account_number_last4
            : null);
    }
}
