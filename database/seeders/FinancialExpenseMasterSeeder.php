<?php

namespace Database\Seeders;

use App\Models\ChartAccount;
use App\Models\ChartAccountGroup;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseType;
use Illuminate\Database\Seeder;

class FinancialExpenseMasterSeeder extends Seeder
{
    /**
     * Seed the financial expense masters used by loans, cards, banks, and e-wallets.
     */
    public function run(): void
    {
        $expenseGroup = ChartAccountGroup::updateOrCreate(
            ['code' => 'EXPENSE'],
            [
                'name' => 'Expenses',
                'normal_balance' => 'debit',
                'display_order' => 60,
                'is_active' => true,
            ]
        );

        $parent = $this->account(
            $expenseGroup,
            'FINANCING-COSTS',
            'Bank and Financing Costs',
            null,
            false,
            100
        );

        $accounts = [
            'bank_fees' => $this->account($expenseGroup, 'BANK-FEES', 'Bank and E-Wallet Fees', $parent, true, 110),
            'card_interest' => $this->account($expenseGroup, 'CC-INTEREST-EXPENSE', 'Credit Card Interest Expense', $parent, true, 120),
            'card_fees' => $this->account($expenseGroup, 'CC-FEES-EXPENSE', 'Credit Card Fees', $parent, true, 130),
            'loan_interest' => $this->account($expenseGroup, 'LOAN-INTEREST-EXPENSE', 'Loan Interest Expense', $parent, true, 140),
            'loan_overdue' => $this->account($expenseGroup, 'LOAN-OVERDUE-INTEREST', 'Loan Overdue Interest Expense', $parent, true, 150),
        ];

        $type = ExpenseType::updateOrCreate(
            ['code' => 'FINANCIAL_COSTS'],
            [
                'chart_account_group_id' => $expenseGroup->id,
                'chart_account_id' => $parent->id,
                'name' => 'Bank and Financing Costs',
                'is_profit' => false,
                'display_order' => 100,
                'is_active' => true,
            ]
        );

        $categories = [
            'BANK_FEES' => ['Bank and E-Wallet Charges', $accounts['bank_fees'], 10],
            'CREDIT_CARD_FEES' => ['Credit Card Interest and Fees', $accounts['card_interest'], 20],
            'LOAN_COSTS' => ['Loan Interest and Penalties', $accounts['loan_interest'], 30],
        ];

        foreach ($categories as $code => [$name, $account, $displayOrder]) {
            $categories[$code] = ExpenseCategory::updateOrCreate(
                ['expense_type_id' => $type->id, 'code' => $code],
                [
                    'chart_account_id' => $account->id,
                    'name' => $name,
                    'description' => null,
                    'display_order' => $displayOrder,
                    'is_active' => true,
                ]
            );
        }

        $items = [
            ['BANK_SERVICE_CHARGE', 'Bank Service Charge', 'BANK_FEES', $accounts['bank_fees'], 10],
            ['BANK_TRANSFER_FEE', 'Bank Transfer Fee', 'BANK_FEES', $accounts['bank_fees'], 20],
            ['E_WALLET_FEE', 'E-Wallet Transfer Fee', 'BANK_FEES', $accounts['bank_fees'], 30],
            ['CREDIT_CARD_INTEREST', 'Credit Card Interest', 'CREDIT_CARD_FEES', $accounts['card_interest'], 10],
            ['CC_MONTHLY_MEMBERSHIP', 'Credit Card Monthly Membership Fee', 'CREDIT_CARD_FEES', $accounts['card_fees'], 20],
            ['CC_ANNUAL_FEE', 'Credit Card Annual Fee', 'CREDIT_CARD_FEES', $accounts['card_fees'], 30],
            ['CC_OVERLIMIT_FEE', 'Credit Card Overlimit Fee', 'CREDIT_CARD_FEES', $accounts['card_fees'], 40],
            ['LOAN_INTEREST', 'Loan Interest Expense', 'LOAN_COSTS', $accounts['loan_interest'], 10],
            ['LOAN_OVERDUE_INTEREST', 'Loan Overdue Interest', 'LOAN_COSTS', $accounts['loan_overdue'], 20],
        ];

        foreach ($items as [$systemKey, $name, $categoryCode, $account, $displayOrder]) {
            ExpenseItem::updateOrCreate(
                ['system_key' => $systemKey],
                [
                    'expense_category_id' => $categories[$categoryCode]->id,
                    'chart_account_id' => $account->id,
                    'code' => $systemKey,
                    'name' => $name,
                    'details' => 'System default; may also be selected for manual expense entry.',
                    'is_hidden' => false,
                    'is_active' => true,
                    'display_order' => $displayOrder,
                ]
            );
        }
    }

    private function account(
        ChartAccountGroup $group,
        string $code,
        string $name,
        ?ChartAccount $parent,
        bool $isPostable,
        int $displayOrder
    ): ChartAccount {
        return ChartAccount::updateOrCreate(
            ['code' => $code],
            [
                'group_id' => $group->id,
                'name' => $name,
                'type' => 'expense',
                'parent_id' => $parent?->id,
                'description' => null,
                'is_postable' => $isPostable,
                'display_order' => $displayOrder,
                'is_active' => true,
            ]
        );
    }
}
