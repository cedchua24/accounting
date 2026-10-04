<?php

namespace Database\Seeders;

use App\Models\ChartAccount;
use App\Models\FinancialInstitution;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash and Cash Equivalents', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability'],
            ['code' => '2010', 'name' => 'Credit Card Payable', 'type' => 'liability'],
            ['code' => '2020', 'name' => 'Issued Cheques Payable', 'type' => 'liability'],
            ['code' => '1200', 'name' => 'Interbranch Due From', 'type' => 'asset'],
            ['code' => '2100', 'name' => 'Interbranch Due To', 'type' => 'liability'],
            ['code' => '3000', 'name' => 'Owner Equity', 'type' => 'equity'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue'],
            ['code' => '5000', 'name' => 'General Expense', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'IBO Commission Expense', 'type' => 'expense'],
        ];
        foreach ($accounts as $account) {
            ChartAccount::updateOrCreate(['code' => $account['code']], $account + ['is_active' => true]);
        }

        foreach ([
            ['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash'],
            ['code' => 'GCASH', 'name' => 'GCash', 'type' => 'e_wallet'],
            ['code' => 'BDO', 'name' => 'BDO', 'type' => 'bank'],
            ['code' => 'BPI', 'name' => 'BPI', 'type' => 'bank'],
            ['code' => 'UNIONBANK', 'name' => 'UnionBank', 'type' => 'bank'],
            ['code' => 'MAYA', 'name' => 'Maya', 'type' => 'e_wallet'],
            ['code' => 'HSBC', 'name' => 'HSBC', 'type' => 'bank'],
            ['code' => 'RCBC', 'name' => 'RCBC', 'type' => 'bank'],
            ['code' => 'METROBANK', 'name' => 'Metrobank', 'type' => 'bank'],
            ['code' => 'PNB', 'name' => 'PNB', 'type' => 'bank'],
            ['code' => 'EASTWEST', 'name' => 'EastWest', 'type' => 'bank'],
            ['code' => 'SECURITY_BANK', 'name' => 'Security Bank', 'type' => 'bank'],
        ] as $institution) {
            FinancialInstitution::updateOrCreate(['code' => $institution['code']], $institution + ['is_active' => true]);
        }

        foreach ([
            ['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash', 'creates_obligation' => false],
            ['code' => 'BANK_TRANSFER', 'name' => 'Online Transfer', 'type' => 'bank_transfer', 'creates_obligation' => false],
            ['code' => 'CHEQUE', 'name' => 'Cheque', 'type' => 'cheque', 'creates_obligation' => true],
            ['code' => 'CREDIT_CARD', 'name' => 'Credit Card', 'type' => 'credit_card', 'creates_obligation' => true],
            ['code' => 'E_WALLET', 'name' => 'E-Wallet', 'type' => 'e_wallet', 'creates_obligation' => false],
            ['code' => 'INTERNAL_CLEARING', 'name' => 'Interbranch Settlement', 'type' => 'other', 'creates_obligation' => false],
        ] as $method) {
            PaymentMethod::updateOrCreate(['code' => $method['code']], $method + ['is_active' => true]);
        }
    }
}
