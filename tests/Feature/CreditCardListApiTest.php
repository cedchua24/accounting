<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialInstitution;
use App\Models\JournalEntry;
use App\Models\PaymentObligation;
use App\Models\User;
use App\Models\UserAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditCardListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_credit_cards_are_listed_by_closest_due_date_with_amounts_due(): void
    {
        Carbon::setTestNow('2026-10-12 09:00:00');
        Sanctum::actingAs(User::factory()->create());

        $soon = $this->card('CC-1', 'Card Due Soon', 5, 15, 100000);
        $later = $this->card('CC-2', 'Card Due Later', 17, 7, 50000);
        $nothingDue = $this->card('CC-3', 'Card Without Payable Statement', 13, 1, 25000);
        $firstInstitution = FinancialInstitution::create([
            'code' => 'BANK-ONE', 'name' => 'Bank One', 'type' => 'bank',
        ]);
        $secondInstitution = FinancialInstitution::create([
            'code' => 'BANK-TWO', 'name' => 'Bank Two', 'type' => 'bank',
        ]);
        $holder = UserAccount::create([
            'code' => 'CHESTER', 'type' => 'individual', 'name' => 'CHESTER P. CHUA',
        ]);
        $soon->update(['financial_institution_id' => $firstInstitution->id]);
        $later->update([
            'financial_institution_id' => $secondInstitution->id,
            'account_holder' => $holder->id,
        ]);
        PaymentObligation::create([
            'number' => 'CCS-SOON', 'type' => 'credit_card_statement',
            'financial_account_id' => $soon->id, 'offset_account_id' => $soon->chart_account_id,
            'statement_date' => '2026-10-05', 'due_date' => '2026-10-15',
            'currency' => 'PHP', 'principal_amount' => 10000, 'status' => 'open',
        ]);
        PaymentObligation::create([
            'number' => 'CCS-LATER', 'type' => 'credit_card_statement',
            'financial_account_id' => $later->id, 'offset_account_id' => $later->chart_account_id,
            'statement_date' => '2026-10-17', 'due_date' => '2026-11-07',
            'currency' => 'PHP', 'principal_amount' => 5000, 'status' => 'open',
        ]);
        $entry = JournalEntry::create([
            'kind' => 'payment', 'number' => 'CARD-BALANCES', 'entry_date' => '2026-10-12',
        ]);
        $entry->lines()->createMany([
            ['chart_account_id' => $soon->chart_account_id, 'debit' => 0, 'credit' => 10000],
            ['chart_account_id' => $later->chart_account_id, 'debit' => 0, 'credit' => 5000],
        ]);

        $this->getJson('/api/credit-cards?as_of=2026-10-12')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('summary.currency', 'PHP')
            ->assertJsonPath('summary.total_credit_limit', '175000.0000')
            ->assertJsonPath('summary.total_outstanding_balance', '15000.0000')
            ->assertJsonPath('summary.total_available_credit', '160000.0000')
            ->assertJsonPath('summary.total_amount_due', '15000.0000')
            ->assertJsonPath('summary.nearest_payable_due_date', '2026-10-15')
            ->assertJsonPath('summary.nearest_payable_amount', '10000.0000')
            ->assertJsonPath('summary.by_currency.0.currency', 'PHP')
            ->assertJsonPath('data.0.id', $soon->id)
            ->assertJsonPath('data.0.amount_due', '10000.0000')
            ->assertJsonPath('data.0.nearest_payable_amount', '10000.0000')
            ->assertJsonPath('data.0.nearest_payable_due_date', '2026-10-15')
            ->assertJsonPath('data.0.nearest_due_date', '2026-10-15')
            ->assertJsonPath('data.0.days_until_due', 3)
            ->assertJsonPath('data.0.due_status', 'DUE_URGENT')
            ->assertJsonPath('data.0.next_closing_date', '2026-11-05')
            ->assertJsonPath('data.0.outstanding_balance', '10000.0000')
            ->assertJsonPath('data.0.available_credit', '90000.0000')
            ->assertJsonPath('data.1.id', $later->id)
            ->assertJsonPath('data.1.nearest_due_date', '2026-11-07')
            ->assertJsonPath('data.2.id', $nothingDue->id)
            ->assertJsonPath('data.2.nearest_payable_amount', '0.0000')
            ->assertJsonPath('data.2.nearest_payable_due_date', null)
            ->assertJsonPath('data.2.nearest_due_date', null)
            ->assertJsonPath('data.2.days_until_due', null)
            ->assertJsonPath('data.2.due_status', 'NO_AMOUNT_DUE');

        $this->getJson("/api/credit-cards?as_of=2026-10-12&financial_institution_id={$secondInstitution->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('summary.total_credit_limit', '50000.0000')
            ->assertJsonPath('data.0.id', $later->id)
            ->assertJsonPath('data.0.account_holder', $holder->id)
            ->assertJsonPath('data.0.user_account.name', 'CHESTER P. CHUA')
            ->assertJsonPath('data.0.institution.id', $secondInstitution->id);

        $this->getJson('/api/credit-cards?financial_institution_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('financial_institution_id');

        $this->getJson("/api/credit-cards/{$soon->id}?as_of=2026-10-12")
            ->assertOk()
            ->assertJsonPath('id', $soon->id)
            ->assertJsonPath('name', 'Card Due Soon')
            ->assertJsonPath('account_holder', null)
            ->assertJsonPath('amount_due', '10000.0000')
            ->assertJsonPath('unpaid_statements.0.number', 'CCS-SOON')
            ->assertJsonPath('statement_history.0.number', 'CCS-SOON')
            ->assertJsonPath('statement_history.0.payments', []);

        $bankLedger = ChartAccount::create(['code' => 'BANK-1', 'name' => 'Bank', 'type' => 'asset']);
        $bank = FinancialAccount::create([
            'name' => 'Bank', 'kind' => 'bank_account', 'currency' => 'PHP',
            'chart_account_id' => $bankLedger->id, 'is_company_wide' => true,
        ]);
        $this->getJson("/api/credit-cards/{$bank->id}")->assertNotFound();
    }

    public function test_credit_card_due_status_uses_red_and_orange_day_boundaries(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        Sanctum::actingAs(User::factory()->create());

        $boundaries = [
            ['code' => 'CC-7', 'days' => 7, 'status' => 'DUE_URGENT'],
            ['code' => 'CC-8', 'days' => 8, 'status' => 'DUE_SOON'],
            ['code' => 'CC-10', 'days' => 10, 'status' => 'DUE_SOON'],
            ['code' => 'CC-11', 'days' => 11, 'status' => 'UPCOMING'],
        ];

        foreach ($boundaries as $index => $boundary) {
            $card = $this->card($boundary['code'], "Boundary {$boundary['days']}", 1, 1, 10000);
            PaymentObligation::create([
                'number' => "BOUNDARY-{$boundary['days']}",
                'type' => 'credit_card_statement',
                'financial_account_id' => $card->id,
                'offset_account_id' => $card->chart_account_id,
                'statement_date' => '2026-10-01',
                'due_date' => Carbon::parse('2026-10-01')->addDays($boundary['days'])->toDateString(),
                'currency' => 'PHP',
                'principal_amount' => 1000 + $index,
                'status' => 'open',
            ]);
        }

        $response = $this->getJson('/api/credit-cards?as_of=2026-10-01')->assertOk();

        foreach ($boundaries as $index => $boundary) {
            $response
                ->assertJsonPath("data.{$index}.days_until_due", $boundary['days'])
                ->assertJsonPath("data.{$index}.due_status", $boundary['status']);
        }
    }

    private function card(string $code, string $name, int $statementDay, int $dueDay, float $limit): FinancialAccount
    {
        $ledger = ChartAccount::create(['code' => $code, 'name' => $name, 'type' => 'liability']);

        return FinancialAccount::create([
            'name' => $name, 'kind' => 'credit_card', 'currency' => 'PHP',
            'chart_account_id' => $ledger->id, 'is_company_wide' => true,
            'credit_limit' => $limit, 'statement_day' => $statementDay, 'due_day' => $dueDay,
        ]);
    }
}
