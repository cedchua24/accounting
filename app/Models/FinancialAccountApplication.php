<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FinancialAccountApplication extends Model
{
    use HasFactory;

    public const CUSTOMER_RECEIPT = 'customer_receipt';

    public const PURCHASE_ORDER_PAYMENT = 'purchase_order_payment';

    public const EXPENSE_PAYMENT = 'expense_payment';

    public const INTERBRANCH_SETTLEMENT = 'interbranch_settlement';

    public const END_OF_DAY_SETTLEMENT = 'end_of_day_settlement';

    public const ACCOUNT_TRANSFER = 'account_transfer';

    public const BALANCE_ADJUSTMENT = 'balance_adjustment';

    public const OBLIGATION_PAYMENT = 'obligation_payment';

    public const DIRECT_PAYMENT = 'direct_payment';

    public const UNCLASSIFIED = 'unclassified';

    protected $fillable = ['application_id', 'application', 'source'];

    public static function selectable(): array
    {
        return [
            self::CUSTOMER_RECEIPT,
            self::PURCHASE_ORDER_PAYMENT,
            self::EXPENSE_PAYMENT,
            self::INTERBRANCH_SETTLEMENT,
            self::END_OF_DAY_SETTLEMENT,
            self::ACCOUNT_TRANSFER,
            self::BALANCE_ADJUSTMENT,
            self::OBLIGATION_PAYMENT,
            self::DIRECT_PAYMENT,
        ];
    }

    public static function normalize(mixed $application): mixed
    {
        if (is_array($application)) {
            $application = $application['value']
                ?? $application['application']
                ?? $application['id']
                ?? $application;
        }

        if (is_int($application) || (is_string($application) && ctype_digit($application))) {
            return FinancialAccountApplicationType::query()->whereKey((int) $application)->value('code')
                ?? self::query()->with('type')->find((int) $application)?->application
                ?? $application;
        }

        if (! is_string($application)) {
            return $application;
        }

        $value = (string) Str::of($application)
            ->trim()
            ->lower()
            ->replace(['-', ' '], '_');

        return [
            'customer_receipts' => self::CUSTOMER_RECEIPT,
            'purchase_order_payments' => self::PURCHASE_ORDER_PAYMENT,
            'expense_payments' => self::EXPENSE_PAYMENT,
            'interbranch_settlements' => self::INTERBRANCH_SETTLEMENT,
            'end_of_day_settlements' => self::END_OF_DAY_SETTLEMENT,
            'account_transfers' => self::ACCOUNT_TRANSFER,
            'balance_adjustments' => self::BALANCE_ADJUSTMENT,
            'obligation_payments' => self::OBLIGATION_PAYMENT,
            'direct_payments' => self::DIRECT_PAYMENT,
        ][$value] ?? $value;
    }

    public static function normalizeId(mixed $application): mixed
    {
        if (is_array($application)) {
            $application = $application['application_id']
                ?? $application['value']
                ?? $application['application']
                ?? $application['id']
                ?? $application;
        }

        if (is_int($application) || (is_string($application) && ctype_digit($application))) {
            return (int) $application;
        }

        $code = self::normalize($application);

        return is_string($code)
            ? FinancialAccountApplicationType::query()->where('code', $code)->value('id') ?? $application
            : $application;
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function type()
    {
        return $this->belongsTo(FinancialAccountApplicationType::class, 'application_id');
    }

    protected function application(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->relationLoaded('type')
                ? $this->type?->code
                : $this->type()->value('code'),
            set: function (string $value) {
                $applicationId = FinancialAccountApplicationType::query()
                    ->where('code', $value)
                    ->value('id');

                if (! $applicationId) {
                    throw new InvalidArgumentException("Unknown financial account application: {$value}");
                }

                return ['application_id' => $applicationId];
            },
        );
    }
}
