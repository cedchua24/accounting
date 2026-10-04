<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    protected $fillable = ['application', 'source'];

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

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
