# Accounting API

Laravel 10 backend for bank-based customer receipts, PO and expense payments, non-cash interbranch orders, and direct chart-of-account payments. Every posted transaction creates a balanced double-entry journal. Posted transactions are immutable; voiding creates a reversing journal instead of deleting history.

## Setup

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8081
```

The development seeder creates `admin@example.test` with password `password`. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` before seeding outside local development.

Log in with `POST /api/login`, then send the returned token as `Authorization: Bearer <token>`.

## Main resources

- `/api/chart-accounts` - chart of accounts
- `/api/financial-institutions` - banks, cash providers, and e-wallet providers
- `/api/financial-accounts?branch_id=1&kind=credit_card&receipts=1&payments=1` - shared and branch-owned cash, bank, e-wallet, checking, and credit-card accounts
- `/api/financial-accounts?legacy_source=baker.payment_type_po&legacy_id=71` - translate a legacy payment-account ID to its fresh central ID
- `/api/financial-accounts?application=expense_payment&active=1` - accounts explicitly allowed for expense payments
- `/api/payment-methods` - cash, transfer, cheque, card, and e-wallet methods
- `/api/payment-obligations?type=credit_card_statement&status=open&due_before=YYYY-MM-DD`
- `/api/branches` - branch-specific inventory and interbranch clearing accounts
- `/api/interbranch-orders?branch_id=1&status=draft|posted|voided`
- `/api/sales-invoices?branch_id=1&status=unpaid|partially_paid|paid|cancelled`
- `/api/counterparties?type=customer|supplier`
- `/api/purchase-orders`
- `/api/expenses`
- `/api/payments?branch_id=1&type=customer|purchase_order|expense|chart_account&status=draft|posted|voided`
- `/api/account-transfers?status=draft|posted|voided&from=YYYY-MM-DD&to=YYYY-MM-DD`
- `/api/account-adjustments?financial_account_id=1&status=draft|posted|voided`
- `/api/financial-accounts/{id}/balance?branch_id=1&as_of=YYYY-MM-DD`
- `/api/financial-accounts/{id}/movements?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=1`
- `/api/journal-entries?from=YYYY-MM-DD&to=YYYY-MM-DD`
- `/api/integrations/customer-order?source=bakingstore-branch-1&external_order_id=123` - central payment summary for a branch shop order
- `/api/integrations/customer-order/payments` - import the branch/order/customer snapshot and post its customer payment atomically

Each hosted branch must use a stable, unique source name such as `bakingstore-branch-1` or `bakingstore-branch-2`. The source name plus each local record ID is the central identity; this prevents identical auto-increment IDs from different branch databases from colliding.

## Payment workflow

Create a draft with `POST /api/payments`. Required common fields are `branch_id`, `type`, `offset_account_id`, `payment_date`, `currency`, and one or more `lines`. The server calculates the payment total from the lines.

```json
{
  "branch_id": 1,
  "type": "customer",
  "sales_invoice_id": 10,
  "offset_account_id": 2,
  "payment_date": "2026-10-04",
  "currency": "PHP",
  "lines": [
    {"financial_account_id": 1, "payment_method_id": 1, "amount": 750},
    {"financial_account_id": 2, "payment_method_id": 5, "amount": 500, "reference": "GCASH-REF"}
  ],
  "post": true
}
```

Type-specific fields:

- `customer`: `sales_invoice_id`; the customer is inherited from the invoice. Split cash/GCash/card tenders are submitted as multiple lines in one atomic transaction.
- `purchase_order`: `purchase_order_id`; the supplier is inherited from the PO.
- `expense`: `expense_id`; the payee and expense account are inherited/validated.
- `chart_account`: no source document; use the selected offset account directly.

Optional `allocations` associate an outflow with one or more payable obligations:

```json
{
  "allocations": [
    {"payment_obligation_id": 1, "amount": 1500.00}
  ]
}
```

Set `post: true` during creation or call `POST /api/payments/{id}/post`. Outflows post debit offset / credit financial account. Posting rechecks every document and obligation balance inside the database transaction. Void a posted payment with `POST /api/payments/{id}/void` and body `{"reason":"..."}`; the journal is reversed and obligation balances/statuses are recalculated.

Financial account numbers are encrypted at rest and omitted from JSON. Responses expose only `masked_account_number`.

Financial accounts can be restricted to one or more exact applications:

- `customer_receipt` — customer-order payment
- `purchase_order_payment` — supplier/PO payment
- `expense_payment` — expense payment
- `interbranch_settlement` — exceptional cash/bank settlement between branches
- `account_transfer` — transfers between cash, e-wallet, and bank accounts
- `balance_adjustment` — audited reconciliation against a real account balance
- `obligation_payment` — credit-card statement, cheque, or other obligation settlement
- `direct_payment` — direct chart-account payment

Supply an `applications` array when creating or updating an account. The broad `usable_for_receipts` and `usable_for_payments` flags still control direction; an account with configured applications must also match the requested transaction type. Ordinary IBO inventory transfers do not use a financial account and continue through Due To/Due From clearing; `interbranch_settlement` is only for a later real movement of money between branches.

## Credit-card workflow

A company credit card is a liability account, not cash leaving the bank immediately. Configure its financial account with `kind: credit_card`, its liability `chart_account_id`, `credit_limit`, `statement_day`, `due_day`, and `usable_for_payments: true`. Use a payment method whose `type` is `credit_card`.

Paying a PO or expense with that account performs the first stage:

1. The source PO/expense is settled immediately.
2. The journal debits the source offset account and credits the credit-card liability.
3. The API automatically groups the charge into a `credit_card_statement` obligation for that card's cycle.

For example, a charge dated `2026-10-04` on a card with statement day `17` and due day `7` receives statement date `2026-10-17` and due date `2026-11-07`. A charge after October 17 goes into the November 17 statement and is due December 7. Days that do not exist in a short month are moved to that month's final day.

On the due date, perform the second stage with a `chart_account` outflow. Use the bank as the payment line, the card's liability chart account as `offset_account_id`, and allocate the amount to the statement obligation:

```json
{
  "branch_id": 1,
  "type": "chart_account",
  "offset_account_id": 21,
  "payment_date": "2026-11-07",
  "currency": "PHP",
  "lines": [
    {"financial_account_id": 3, "payment_method_id": 2, "amount": 10000, "reference": "BANK-SETTLEMENT-1"}
  ],
  "allocations": [
    {"payment_obligation_id": 8, "amount": 10000}
  ],
  "post": true
}
```

This settlement debits the card liability and credits the bank. It marks the card statement paid without paying the original PO a second time. Multiple card charges in the same cycle share one statement obligation; partial statement payments are supported through allocations.

### Credit-card installments

Set `installment_count` on a credit-card payment line when the card issuer offers installments:

```json
{
  "branch_id": 1,
  "type": "expense",
  "expense_id": 25,
  "offset_account_id": 61,
  "payment_date": "2026-10-20",
  "currency": "PHP",
  "lines": [
    {
      "financial_account_id": 4,
      "payment_method_id": 6,
      "amount": 2500,
      "installment_count": 3,
      "reference": "CARD-INSTALLMENT-1"
    }
  ],
  "post": true
}
```

Because October 20 is after a statement day of 17, this example creates three statement portions:

| Installment | Statement date | Due date | Amount |
| --- | --- | --- | ---: |
| 1 of 3 | 2026-11-17 | 2026-12-07 | 833.3333 |
| 2 of 3 | 2026-12-17 | 2027-01-07 | 833.3333 |
| 3 of 3 | 2027-01-17 | 2027-02-07 | 833.3334 |

Each due entry can be paid and allocated independently. The final portion receives any four-decimal rounding difference, so all installments always equal the original charge. `installment_count` defaults to `1`, accepts `1` through `60`, and is rejected for non-credit-card accounts. Known issuer interest or processing charges should be recorded separately as interest/fees on the applicable obligation rather than increasing the original PO or expense payment.

## Centralized multi-branch operation

All branches use this single API and database. Every payment, PO, expense, journal line, and interbranch order records its originating branch. List endpoints return company-wide data when `branch_id` is omitted, so Branch 2 can see a payment recorded by Branch 1. Pass `branch_id` only when a branch-specific view is needed.

Financial-account ownership and access are separate:

- `owner_branch_id`: optional branch responsible for managing or reconciling the account; it does not determine access.
- `is_company_wide: true`: available to every current and future branch; `branch_ids` must be empty.
- `is_company_wide: false`: available only to the selected `branch_ids`; at least one active branch is required.

Do not create separate copies of the same shared GCash account per branch. Create it once and either make it company-wide or give access to selected branches. For example, one account with `branch_ids: [1, 2]` is available to Branches 1 and 2 but not Branch 3. Each payment still records the branch that initiated it.

Use a UUID `idempotency_key` on payment creation to guard clients against duplicate submissions.

## Financial-account transfers

An account transfer is independent of branches, customer payments, POs, and expenses. It works like moving money between normal bank or savings accounts. Enable both accounts for `account_transfer`, and make sure the source permits payments while the destination permits receipts.

```json
POST /api/account-transfers
{
  "source_financial_account_id": 12,
  "destination_financial_account_id": 29,
  "transfer_date": "2026-10-04",
  "currency": "PHP",
  "amount": 2000,
  "reference": "GCASH-TO-BDO-001",
  "memo": "Transfer GCash balance to BDO Ced",
  "post": true
}
```

The journal credits GCash and debits BDO without linking or changing any underlying receipt. For a GCash balance of ₱2,000 and existing BDO balance of ₱1,000, transferring ₱2,000 produces GCash ₱0 and BDO ₱3,000. The API rejects transfers larger than the current source balance. Create a draft without `post`, post it later with `POST /api/account-transfers/{id}/post`, or reverse a posted transfer through `POST /api/account-transfers/{id}/void` with a reason. Confirm balances through each account's `/balance` endpoint.

### Unified account movements

Use `/api/financial-accounts/{id}/movements` as the account passbook. Movement codes are derived from posted journals, so no second balance-transaction table or duplicated send/receive records are required:

| Legacy concept | Central movement code | Direction |
| --- | --- | --- |
| Online order | `CUSTOMER_PAYMENT` | `IN_BOUND` |
| Purchase order | `PURCHASE_ORDER_PAYMENT` | `OUT_BOUND` |
| Expense | `EXPENSE_PAYMENT` | `OUT_BOUND` |
| Bank transfer send | `ACCOUNT_TRANSFER_SEND` | `OUT_BOUND` |
| Bank transfer receive | `ACCOUNT_TRANSFER_RECEIVE` | `IN_BOUND` |
| Lalamove | `EXPENSE_PAYMENT` with Lalamove as expense category/payee | `OUT_BOUND` |
| Bank reconciliation | `BALANCE_ADJUSTMENT` | Based on the difference |
| Credit-card SOA reconciliation | `CREDIT_CARD_SOA_ADJUSTMENT` | Liability increase/decrease |

One account transfer generates a send movement on the source and a receive movement on the destination from the same transaction and journal. Asset-account directions are `IN_BOUND` and `OUT_BOUND`. Credit cards use `LIABILITY_INCREASE` and `LIABILITY_DECREASE`, because a card purchase increases debt rather than moving cash into the card.

## Financial-account balance adjustments

Never edit a calculated financial-account balance directly. If the system says BDO has ₱3,000 but the real bank statement says ₱2,950, create a separate reconciliation transaction:

```json
POST /api/account-adjustments
{
  "financial_account_id": 29,
  "adjustment_account_id": 90,
  "adjustment_date": "2026-10-04",
  "currency": "PHP",
  "actual_balance": 2950,
  "reference": "BDO-STATEMENT-2026-10-04",
  "reason": "Bank service charge missing from the system",
  "post": true
}
```

The transaction stores the system balance before adjustment (`3000.0000`), the real balance (`2950.0000`), and the calculated discrepancy (`-50.0000`). It credits BDO ₱50 and debits the selected reconciliation expense account ₱50. A positive discrepancy performs the opposite entry. The adjustment has its own reference, reason, creator, posting status, timestamps, journal, and reversal history. Void through `POST /api/account-adjustments/{id}/void`; the original record remains for audit.

For a credit card, also provide the exact `payment_obligation_id` for the statement. Here `actual_balance` means the total shown on that SOA, before subtracting later payments:

```json
POST /api/account-adjustments
{
  "financial_account_id": 4,
  "payment_obligation_id": 18,
  "adjustment_account_id": 91,
  "adjustment_date": "2026-10-17",
  "currency": "PHP",
  "actual_balance": 10250,
  "reference": "BDO-SOA-2026-10",
  "reason": "SOA contains an unrecorded annual fee",
  "post": true
}
```

If the system statement is ₱10,000 and the SOA is ₱10,250, the transaction records a `+250` statement adjustment, debits the selected fee/reconciliation expense, credits the credit-card liability, and changes that statement's total due to ₱10,250. A lower SOA reverses the debit and credit direction. The statement cannot be reconciled below an amount already paid. Adjustments must be voided newest-first, and voiding restores both the liability journal and statement amount due.

## Interbranch orders

IBOs are not payments and never require a financial account. Create one at `POST /api/interbranch-orders` with source/destination branches, amount, and one of these modes:

- `inventory_transfer`: debit destination inventory and credit source inventory.
- `internal_clearing`: each branch receives its own balanced entry using inventory plus Due To/Due From accounts.

Post with `POST /api/interbranch-orders/{id}/post`. Void with `POST /api/interbranch-orders/{id}/void`; voiding creates a complete reversal rather than deleting history.

See [LEGACY_MIGRATION.md](LEGACY_MIGRATION.md) for the mapping from the baking-store tables and the data issues that must be handled during import.

## Verification

```powershell
php artisan test
```

## Legacy payment-account import

Configure the read-only legacy connection with `LEGACY_DB_*`, preview the mapping, and then import:

```powershell
php artisan accounting:import-legacy-payment-accounts --dry-run
php artisan accounting:import-legacy-payment-accounts
```

The command imports `bank` into `financial_institutions` and `payment_type_po` into `financial_accounts`, generating a separate chart account for every financial account. Central IDs are generated fresh. The original IDs remain available through `legacy_source` and `legacy_id`, and rerunning the command updates the same imported records without duplication. Applicability comes from `is_customer`, `is_supplier`, and observed customer, PO, expense, and obligation-payment history. Never-used accounts are marked `unclassified` for manual review. Imported accounts start as company-wide because the legacy source has no branch-access data; review their access before production cutover. Mutable legacy `balance` and `total_balance_due` values are intentionally excluded; balances are derived from posted journals.
