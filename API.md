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

The development seeder creates the super administrator `superadmin` / `admin@example.test` with password `password`. Set `ADMIN_USERNAME`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD` before seeding outside local development.

Log in with `POST /api/login` using `{"login":"superadmin","password":"password"}`. The `login` value may be either a username or email address. The legacy `email` request field is also accepted. Send the returned token as `Authorization: Bearer <token>`.

Log out the current device with `POST /api/logout` and the same bearer token. The endpoint returns `204 No Content` and immediately revokes that token; tokens issued to the user's other devices remain active.

```http
POST /api/logout
Authorization: Bearer <token>
Accept: application/json
```

Public registration is available through `POST /api/register`. Self-registered accounts always receive the `viewer` role and cannot assign their own role or active status. Registration creates the linked profile and returns a Sanctum token. A super administrator can promote the account later through `/api/users/{id}`.

```json
POST /api/register
{
  "name": "Juan Dela Cruz",
  "username": "juan.cruz",
  "email": "juan@example.test",
  "password": "Secure123",
  "password_confirmation": "Secure123",
  "device_name": "web",
  "profile": {
    "first_name": "Juan",
    "last_name": "Dela Cruz",
    "phone": "+63 917 123 4567",
    "country": "PH"
  }
}
```

## Users and profiles

Only an active `super_admin` can access `/api/users`. The API supports list, create, show, update, and delete through the standard REST endpoints. Initial roles are `super_admin`, `admin`, `accountant`, and `viewer`. Role-specific restrictions for accounting resources can be added later without changing the user schema.

Every user has a one-to-one record in `user_profiles`. Credentials and access fields stay on `users`; personal and contact fields are sent as the nested `profile` object. The API prevents deletion, deactivation, or demotion of the last active super administrator.

```json
POST /api/users
{
  "name": "Maria Santos",
  "username": "maria.santos",
  "email": "maria@example.test",
  "password": "Secure123",
  "password_confirmation": "Secure123",
  "role": "accountant",
  "is_active": true,
  "profile": {
    "first_name": "Maria",
    "last_name": "Santos",
    "phone": "+63 917 123 4567",
    "job_title": "Senior Accountant",
    "department": "Finance",
    "country": "PH"
  }
}
```

List filters are available as `/api/users?role=accountant&active=1&search=maria`. Updating a user accepts any subset of the create fields; a password is changed only when a new `password` and matching `password_confirmation` are supplied. Deactivating a user immediately revokes all of that user's API tokens.

## Main resources

- `/api/dashboard/total-cash-balance?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD` - total liquid funds across cash, bank, checking, and e-wallet accounts; credit-card accounts are excluded. Without dates, `total_cash_balance` is the sum of the cached current balances. `day_to` returns the journal balance as of that date. When `day_from` is supplied, the response also includes the opening balance immediately before that date, net change, and percentage change.
- `/api/dashboard/collections?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD` - total posted customer-payment inflows within the inclusive date range. When both dates are supplied, the response also calculates an immediately preceding period of equal length, net change, and percentage change. Draft and voided payments, supplier/expense payments, and customer refunds are not counted as collections.
- `/api/dashboard/payments?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD` - total posted outgoing payments within the inclusive date range, including purchase-order, expense, and direct chart-account payments. When both dates are supplied, the response also calculates an immediately preceding period of equal length, net change, and percentage change. Draft and voided payments, customer collections, and separately recorded customer refunds are excluded.
- `/api/dashboard/upcoming-dues?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD` - total remaining balance and obligation count for open, partially paid, or overdue obligations whose due dates fall within the inclusive range. Posted allocations reduce the amount due; draft and voided allocations do not. The response also includes the nearest due date. With no dates, all currently unpaid obligations are included.
- `/api/dashboard/upcoming-dues-list?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD&as_of=YYYY-MM-DD&limit=3` - frontend-ready obligations requiring attention, ordered by due date. Each item contains `vendor`, `ref`, `due`, `amount`, and `urgency` for the dashboard panel, plus normalized identifiers and due-date fields. The range defaults from today through the following 14 days, `as_of` controls relative urgency wording, and the limit defaults to three items (maximum 50). Summary totals cover every matching obligation before the display limit.
- `/api/dashboard/cash-flow?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD` - money in, money out, net cash flow, and gap-filled daily chart points across cash, bank, checking, and e-wallet accounts. Each point includes the daily and cumulative net cash flow. Internal transfers between liquid accounts cancel out, while credit-card-only activity is excluded. Supplying only one date produces one point; without dates, the endpoint returns the latest 30 calendar days.
- `/api/dashboard/recent-activity?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD&as_of=YYYY-MM-DD&limit=4` - newest-first dashboard activity across payments, account transfers, customer refunds, account adjustments, and end-of-day settlements. Items expose the frontend fields `type`, `title`, `meta`, `account`, `amount`, `time`, and `status`, plus stable source identifiers and timestamps. The optional date range filters each record's business date; the display limit defaults to four and has a maximum of 50.
- `/api/users` - super-admin-only credential, access, and profile management
- `/api/chart-accounts` - chart of accounts
- `/api/chart-account-groups` and `/api/chart-account-groups/list` - the familiar top-level Assets, Liabilities, Equity, Revenue, Cost of Goods Sold, and Expenses groups. Responses include the legacy-friendly `chart_of_account_code`, `chart_of_account_name`, and `status` aliases.
- `/api/chart-accounts/list` - complete non-paginated ledger-account list for dropdowns. `/api/chart-accounts/tree` returns the parent/child account hierarchy. Ledger accounts now identify their group, whether they are postable, display order, and description.
- `/api/expense-types`, `/api/expense-categories`, and `/api/expense-items` - the legacy-style expense master hierarchy. Each also has a non-paginated `/list` endpoint and returns familiar aliases such as `expense_type_code`, `expense_category_name`, `expense_code`, and `expense_name`.
- `/api/expense-setup` - one active, non-paginated type → category → item tree for the expense-entry form. Hidden items are excluded unless `include_hidden=1`.
- `/api/expenses/ledger` (also `/api/expense-ledger`) - combined expense register containing manual expense documents and read-only journal expenses such as loan interest, credit-card adjustments, and bank charges without double-counting them.
- `/api/financial-institutions?type=bank&active=1` - complete non-paginated JSON array of banks, cash providers, and e-wallet providers. Optional filters are `type`, `active`, and `search`. `/api/financial-institutions/list` is a compatible alias with the same response.
- `/api/user-accounts?type=individual|company&active=1&search=Ced` - paginated account-holder directory for financial accounts. Records may link to a login user and store contact, tax, address, and extensible JSON `details`.
- `/api/user-accounts/list?type=individual|company&active=1&search=Ced` - complete non-paginated user-account array for dropdowns. It supports the same filters except `per_page`.
- `POST /api/user-accounts` - create an individual or company account holder. Required fields are `code`, `type`, and `name`; optional fields are `user_id`, `email`, `phone`, `address`, `tax_identifier`, `details`, and `is_active`. Codes are trimmed and normalized to uppercase.
- `GET /api/user-accounts/{id}` - fetch one user account with its linked login user, financial accounts, loans, `financial_accounts_count`, and `loans_count`.
- `PATCH /api/user-accounts/{id}` - partially update only the submitted fields. `PUT` uses the same endpoint and validation behavior.
- `DELETE /api/user-accounts/{id}` - delete an unlinked user account. The API returns `409 Conflict` instead of deleting a holder referenced by a financial account or loan; set `is_active=false` when the record must be retained for history.
- `/api/financial-accounts?branch_id=1&kind=credit_card&payment_method_id=2&receipts=1&payments=1` - shared and branch-owned cash, bank, e-wallet, checking, and credit-card accounts. `payment_method_id` optionally limits results to accounts compatible with that active payment method. Every item includes the stored snapshot fields `current_balance`, `available_balance`, and `balance_updated_at`. Credit cards also include `credit_limit`, plus the compatibility aliases `outstanding_balance` and `available_credit`.
- `/api/financial-accounts?legacy_source=baker.payment_type_po&legacy_id=71` - translate a legacy payment-account ID to its fresh central ID
- `/api/financial-accounts?application_id=3&active=1` - accounts explicitly allowed for the selected application type. The legacy `application=expense_payment` filter remains accepted for compatibility.
- `/api/financial-account-application-types` - active workflow definitions for financial-account setup. Use `?active=0` to inspect internal inactive definitions such as legacy `unclassified` records.
- `PATCH /api/financial-accounts/{id}` - edit any subset of an account's initialization details: `name`, `account_number`, `account_holder`, `description`, `kind`, `financial_institution_id`, `chart_account_id`, `currency`, `credit_limit`, statement/due days, buffer days, receipt/payment usage, active status, `application_ids`, ownership, and branch access. `account_holder` is a `user_accounts.id` foreign key; responses include the related `user_account` object and its `name`. Account numbers are encrypted and only the masked last four digits are returned.
- `/api/payment-methods?active=1&type=bank_transfer&creates_obligation=0` - paginated cash, transfer, cheque, card, and e-wallet methods, with optional active, type, and obligation filters
- `/api/credit-cards?branch_id=1&financial_institution_id=2&active=1&as_of=YYYY-MM-DD` - all credit cards with optional branch, financial-institution, active-status, and as-of-date filters. The portfolio `summary` contains total credit limit, outstanding balance, available credit, and amount due (also grouped by currency); cards are sorted by closest due date.
- `/api/credit-cards/{financial_account_id}?as_of=YYYY-MM-DD` - one credit card's complete details, including the `account_holder` user-account ID, related `user_account`, unpaid statements, statement history, and payments applied to each statement
- `/api/credit-cards/{financial_account_id}/payment-methods` - non-paginated dropdown choices applicable to paying the card. Returns only active, non-obligation cash, bank-transfer, and e-wallet methods, with each method's compatible funding-account kinds.
- `/api/credit-cards/{financial_account_id}/funding-accounts?payment_method_id=2&branch_id=1` - non-paginated **Pay from** choices for the selected method. Returns every active asset account enabled for payments, in the card's currency, available to the optional branch, and compatible with the selected method. Operational application tags do not hide otherwise valid funding accounts.
- `POST /api/credit-cards/{financial_account_id}/payments` - post a partial, exact, or overpayment from a cash, bank, checking, or e-wallet account; applies to a selected statement or automatically to the oldest unpaid statements
- `/api/payment-obligations?type=credit_card_statement&status=open&due_before=YYYY-MM-DD`
- `/api/loans?direction=lent&status=active&user_account_id=2&financial_institution_id=1&due_before=YYYY-MM-DD&as_of_date=YYYY-MM-DD` - borrowed and lent loans, including generated installments and overdue interest. Bank loans can be filtered by their independently selected user account and institution.
- `/api/loans/list?direction=borrowed&status=active&search=BDO&per_page=15` - explicit alias for the same paginated loan transaction list. Each row includes `user_account` and `user_account_name` when applicable; `principal_amount`, `interest_amount`, `total_loan_amount`, and `balance_amount`; paid-interest fields `interest_expense_amount` and `overdue_interest_expense_amount`; `overpayment_amount`; and a `payment_entries` array. Each payment entry identifies its payment method, selected financial account, and financial institution/bank. The top-level `totals` object aggregates these amounts for the current page. Use each row's `id` with `GET /api/loans/{id}` to fetch full details.
- `/api/loans/upcoming-dues` and `/api/loan-installments/upcoming-dues` - list unpaid loan installments due in a selected window, with earlier overdue installments included by default. Filters are `day_from`, `day_to`, `as_of_date`, `direction`, `counterparty_id`, `financial_institution_id`, `user_account_id`, `financial_account_id`, `include_overdue`, and `limit`. Each row contains `user_account_id`, the complete `user_account`, `user_account_name`, and `display_person` (the user-account name when applicable, otherwise the lender); the full installment/payment breakdown; `payment_entries`; and a nested `loan` object with the same holder fields, institution, financial account, original amounts, paid amounts, balances, interest settings, and payment count. Summary fields include `total_amount_due`, `overdue_count`, `overdue_amount`, `due_within_7_days_count`, and `due_within_7_days_amount`.
- `/api/loans/creation-options?currency=PHP` - lender choices for the create-loan form: `External person / company`, `Bank`, and `Credit Card`. Bank reports `requires_financial_institution=true`, `requires_user_account=true`, and `requires_financial_account=false`; its `user_accounts` contains every active user account regardless of financial-account links.
- `/api/loans/upcoming-dues?day_from=YYYY-MM-DD&day_to=YYYY-MM-DD&as_of_date=YYYY-MM-DD&direction=borrowed|lent&limit=20` - unpaid loan installments ordered by due date, including party, loan ID, inflow/outflow direction, accrued overdue interest, and amount due. The default window is today through the following 30 days.
- `/api/loans/{loan_id}/payment-methods` - the only payment methods accepted for loan payments: active Cash, Bank Transfer, Cheque, and E-Wallet records. Each item includes its compatible financial-account kinds. The response also returns the `overdue_interest` and `overpayment` excess-treatment choices.
- `/api/loans/{loan_id}/payment-accounts?payment_method_id=2&financial_institution_id=1&branch_id=1` - compatible active payment accounts in the loan currency. Call it first with `payment_method_id` to populate `financial_institutions`, then optionally filter the returned accounts by institution.
- `/api/branches` - branch-specific inventory and interbranch clearing accounts
- `/api/interbranch-orders?branch_id=1&status=draft|posted|voided`
- `/api/sales-invoices?branch_id=1&status=unpaid|partially_paid|paid|cancelled`
- `/api/counterparties?type=customer|supplier`
- `/api/purchase-orders`
- `/api/expenses`
- `/api/payments?branch_id=1&type=customer|purchase_order|expense|chart_account&status=draft|posted|voided`
- `/api/customer-refunds?sales_invoice_id=1&status=draft|posted|voided`
- `/api/account-transfers?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&status=draft|posted|void|voided&financial_account_id={id}` - paginated newest-first transfer list. Every filter is optional. The financial-account filter matches transfers where the selected bank/account is either the source or internal destination. `bank_id` is accepted as an alias for `financial_account_id`, while `from` and `to` remain aliases for the date parameters.
- `/api/account-adjustments?financial_account_id=1&status=draft|posted|voided`
- `/api/financial-accounts/{id}/balance?branch_id=1&as_of=YYYY-MM-DD`
- `/api/financial-accounts/{id}/transactions?branch_id=1&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&debit=100.00&credit=0&status=posted&event=DEBIT&sort=latest` - paginated account history; every filter is optional. `debit` and `credit` match exact amounts, `event` accepts `DEBIT` or `CREDIT`, and `sort` accepts `latest` (the default) or `oldest`. Results include payment details and customer order, purchase order, expense, transfer, refund, settlement, or adjustment source details.
- `/api/financial-accounts/{id}/movements?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=1`
- `/api/journal-entries?from=YYYY-MM-DD&to=YYYY-MM-DD`
- `/api/integrations/customer-order?source=bakingstore-branch-1&external_order_id=123` - central payment summary for a branch shop order
- `GET|POST /api/integrations/customer-orders/refund-candidates` - returns only online orders whose remaining Accounting receipts exceed the current shop total. `GET` (or `POST` without `orders`) reads the configured legacy shop database directly and accepts optional `source`, `date_from`, and `date_to` filters. `POST` can instead compare up to 500 supplied order snapshots.
- `POST /api/shopOrderTransaction/fetchOnlineShopOrderRefundTransactionListV2` - compatibility alias for the self-contained Accounting refund-candidate list
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

## Customer return and refund workflow

A refund for returned merchandise is not the same as voiding an incorrect payment. A void removes a mistaken payment's accounting effect while leaving the original order total unchanged. A customer refund records a real return, reduces the effective sale total, and sends money out of a selected cash, bank, or e-wallet account.

Create and post a partial or full refund against the exact original customer payment:

```json
POST /api/customer-refunds
{
  "original_payment_id": 25,
  "financial_account_id": 12,
  "payment_method_id": 5,
  "refund_date": "2026-10-10",
  "currency": "PHP",
  "amount": 500,
  "reference": "RETURN-1001",
  "reason": "Customer returned one item",
  "idempotency_key": "15426c4e-4622-4b67-9665-91e8c4ca0085",
  "post": true
}
```

Supported refund methods are `cash`, `bank_transfer`, and `e_wallet`. The selected `payment_method_id` must match the funding account: cash uses a cash account, bank transfer uses a bank or checking account, and e-wallet uses an e-wallet account. The financial account must be active, enabled for outgoing payments, available to the original sale's branch, and have enough balance. The UUID can be supplied as `idempotency_key` or through the `Idempotency-Key` header.

For an order originally worth and paid ₱1,000, a ₱500 refund produces an original total of ₱1,000, refunded total of ₱500, net sale of ₱500, and net payment of ₱500. The balance remains zero because the customer returned goods rather than becoming indebted. Refunding the full ₱1,000 changes the invoice status to `refunded` and both net sale and net payment become zero.

The refund journal debits `Sales Returns and Allowances` and credits the selected financial account. Multiple partial refunds are allowed, but their posted total cannot exceed the original payment or the available account balance. An original payment with posted refunds cannot be voided until its refunds are voided. Void a refund through `POST /api/customer-refunds/{id}/void` with `{"reason":"..."}`; this creates a reversing journal instead of deleting history.

The integrated customer-order response includes `payment_entries`, a chronological display list of posted receipts and refunds. Every entry contains the external `transaction_id`; refund entries also contain `linked_payment_id` and expose a negative `amount`. For example, transaction `78211` can contain a payment amount of `4200.0000` followed by a refund amount of `-840.0000`, while `total_payment` and sales reporting return the net `3360.0000`. The original `data` payment collection remains available for compatibility.

Financial account numbers are encrypted at rest and omitted from JSON. Responses expose only `masked_account_number`.

Financial-account list and detail reads use the cached balance columns on `financial_accounts`; they do not sum journal lines on every request. Posted journal lines and reversal journal lines update those snapshots inside the same database transaction. For a credit card, `current_balance` is the outstanding liability and `available_balance = credit_limit - current_balance`; for cash, bank, checking, and e-wallet accounts, `available_balance` equals `current_balance`. `credit_limit` is configuration and does not change when a transaction posts. Journals remain the accounting source of truth, so after a manual import or direct database repair run `php artisan accounting:rebuild-financial-account-balances` to rebuild every snapshot.

Financial accounts can be restricted to one or more exact applications:

- `customer_receipt` — customer-order payment
- `purchase_order_payment` — supplier/PO payment
- `expense_payment` — expense payment
- `interbranch_settlement` — exceptional cash/bank settlement between branches
- `account_transfer` — transfers between cash, e-wallet, and bank accounts
- `balance_adjustment` — audited reconciliation against a real account balance
- `obligation_payment` — credit-card statement, cheque, or other obligation settlement
- `direct_payment` — direct chart-account payment

Supply an `application_ids` array when creating or updating an account. Each ID references `financial_account_application_types.id`. The broad `usable_for_receipts` and `usable_for_payments` flags still control direction; an account with configured applications must also match the requested operational transaction type. Audited balance reconciliation is available to every active account with a valid asset or credit-card liability ledger and does not depend on this application list. Ordinary IBO inventory transfers do not use a financial account and continue through Due To/Due From clearing; `interbranch_settlement` is only for a later real movement of money between branches.

```json
{
  "application_ids": [1, 2, 3]
}
```

Financial-account responses return each assignment with `application_id` and its related `type` object (`id`, `code`, `name`, and description). Obtain valid selectable IDs from `GET /api/financial-account-application-types`.

For branch-restricted accounts, send `is_company_wide: false`, an optional managing `owner_branch_id`, and one or more `branch_ids`. Create and update responses include `branch_ids`; the assignments are stored in `financial_account_branch_access`. Company-wide accounts use `is_company_wide: true` and return an empty `branch_ids` array.

## Expense master and register workflow

The expense module keeps the legacy workflow familiar without duplicating the general ledger:

1. `chart_account_groups` contains Assets, Liabilities, Equity, Revenue, Cost of Goods Sold, and Expenses.
2. `expense_types` contains broad staff-facing classifications.
3. `expense_categories` belongs to an expense type.
4. `expense_items` is the selectable expense name and maps to one postable expense chart account.
5. The existing `expenses` table remains the transaction document and now records its selected item, creator, approver, approval state, receipt state, reference, and system source.

Load `GET /api/expense-setup` once for all active selectors. The seeded Bank and Financing Costs setup includes Bank Service Charge, Bank Transfer Fee, E-Wallet Transfer Fee, Credit Card Interest, monthly membership fee, annual fee, overlimit fee, Loan Interest Expense, and Loan Overdue Interest.

```json
POST /api/expenses
{
  "branch_id": 1,
  "number": "EXP-2026-001",
  "expense_item_id": 8,
  "expense_date": "2026-10-28",
  "currency": "PHP",
  "amount": 5000,
  "approval_status": "pending",
  "is_received": true,
  "received_date": "2026-10-28",
  "reference": "BANK-SOA-1028",
  "description": "October loan interest"
}
```

When `expense_item_id` is supplied, the API derives `chart_account_id` from the item and rejects a conflicting account. Approve or reject through `POST /api/expenses/{id}/approve` and `/reject`; an expense cannot be posted as paid until approved. Posted or system-generated history cannot be deleted—void or reverse its source transaction instead.

Use `GET /api/expenses/ledger?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&expense_type_id=...&source_type=loan_interest` for the staff expense register. Borrowed-loan interest and overdue interest already post from the loan workflow, so the register reads those journal debits as read-only system entries. It does not create a second expense payment. Lent-loan interest remains revenue and is therefore not shown as an expense.

To carry over the familiar legacy dropdown masters without importing historical monetary transactions, run the importer first as a dry run and then apply it:

```bash
php artisan accounting:import-legacy-expense-masters --dry-run
php artisan accounting:import-legacy-expense-masters
```

The importer is idempotent and reads only `chart_of_accounts`, `expenses_type_v2`, `expenses_category_v2`, and `expenses_v2`. It preserves legacy IDs, hidden items, and even duplicate legacy item codes. It intentionally never reads or imports `expenses_transaction`.

## Credit-card workflow

A company credit card is a liability account, not cash leaving the bank immediately. Configure its financial account with `kind: credit_card`, its liability `chart_account_id`, `credit_limit`, `statement_day`, `due_day`, and `usable_for_payments: true`. Use a payment method whose `type` is `credit_card`.

Paying a PO or expense with that account performs the first stage:

1. The source PO/expense is settled immediately.
2. The journal debits the source offset account and credits the credit-card liability.
3. The API automatically groups the charge into a `credit_card_statement` obligation for that card's cycle.

For example, a charge dated `2026-10-04` on a card with statement day `17` and due day `7` receives statement date `2026-10-17` and due date `2026-11-07`. A charge after October 17 goes into the November 17 statement and is due December 7. Days that do not exist in a short month are moved to that month's final day.

Use the dedicated card-payment endpoint for the second stage:

```json
POST /api/credit-cards/4/payments
{
  "branch_id": 1,
  "statement_id": 8,
  "funding_account_id": 3,
  "payment_method_id": 2,
  "payment_date": "2026-11-07",
  "amount": 12000,
  "reference": "BANK-SETTLEMENT-1",
  "memo": "RCBC statement payment",
  "idempotency_key": "47d3aa60-6cad-4d12-a204-7166dc01492c"
}
```

`statement_id` is optional. When supplied, that statement is settled first and any excess is carried into its next open statements in due-date order. Without it, the API applies payment to all of the card's oldest unpaid statements first. A payment below the selected/applicable balance is `partial`, an equal payment is `exact`, and any excess is an `overpayment`. Excess that cannot yet be allocated remains as a debit balance on the card liability and is automatically applied when a future statement is generated. The response separates `allocated_amount`, `carried_forward_amount`, and `unapplied_credit_amount`.

The funding account must have enough recorded balance and be an active compatible cash, bank, checking, or e-wallet account. A card SOA adjustment changes only the card liability—it does not add money to the funding account. If the real bank or wallet balance is higher than the recorded balance, reconcile it first through `POST /api/financial-accounts/{fundingAccount}/adjustments`; the insufficient-balance response includes the available balance, shortfall, and this endpoint.

The generic payment API can perform the same accounting operation as a `chart_account` outflow. Use the bank as the payment line, the card's liability chart account as `offset_account_id`, and allocate the amount to the statement obligation:

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

## Loan workflow

Create a borrowed or lent loan with `POST /api/loans`. A loan can identify an external person either with an existing `counterparty_id` or directly with `party_name`. Bank loans instead use `financial_institution_id` plus any active `user_account_id`; they do not require `financial_account_id`. Credit-card loans still require an internal credit-card `financial_account_id`. `direction=lent` means the business lent money to the party; `direction=borrowed` means the business borrowed from the party.

Load the first selector from `GET /api/loans/creation-options`. Send its `value` as `borrower_type`: `company`, `bank`, or `credit_card`. For `bank`, submit `financial_institution_id` and `user_account_id` directly; the user account does not need to own a financial account at that bank. For `credit_card`, submit one of the returned internal credit-card accounts as `financial_account_id`. `company` uses `party_name` or `counterparty_id`.

Bank loan example:

```json
POST /api/loans
{
  "direction": "borrowed",
  "borrower_type": "bank",
  "financial_institution_id": 1,
  "user_account_id": 3,
  "start_date": "2026-10-27",
  "first_due_date": "2026-11-27",
  "currency": "PHP",
  "principal_amount": 1000000,
  "total_interest_amount": 200000,
  "term_months": 12
}
```

This example creates a PHP 1,000,000 loan with PHP 200,000 total fixed interest over 12 months. The API generates twelve exact PHP 100,000 installments:

```json
POST /api/loans
{
  "number": "LOAN-001",
  "direction": "lent",
  "borrower_type": "company",
  "party_name": "Juan Dela Cruz",
  "financial_account_id": 1,
  "start_date": "2026-10-22",
  "first_due_date": "2026-11-22",
  "currency": "PHP",
  "principal_amount": 1000000,
  "total_interest_amount": 200000,
  "term_months": 12,
  "overdue_interest_rate": 2,
  "overdue_interest_period": "monthly",
  "notes": "Personal loan"
}
```

`overdue_interest_rate` is a simple percentage charged against the unpaid scheduled installment after its due date. Its period may be `daily`, `monthly` (prorated over 30 days), or `annual` (prorated over 365 days). Omit the rate or set it to zero when there is no late interest.

Use `GET /api/loans?search=Juan&as_of_date=YYYY-MM-DD` for the paginated loan transaction list. Each lightweight row includes its `id`, party, funding type, installment amount, amount paid, balance, next due date and amount, payment count, and overdue-installment count. The list intentionally omits installment and payment arrays.

When a row is clicked, call `GET /api/loans/{id}?as_of_date=YYYY-MM-DD` with that row's `id`. The detail response includes the complete schedule and payment history. Each installment includes scheduled principal and interest, amounts paid, accrued overdue interest, total currently due, and `pending`, `partial`, `overdue`, or `paid` status. `paid_amount` is the amount shown in the schedule: it includes the full payment received for that installment plus any credit carried into it from an earlier overpayment. `payment_received_amount` is the actual transaction amount received while that installment was current, `scheduled_paid_amount` is the amount applied against its scheduled principal and interest, and `overpayment_applied_amount` is credit brought forward from an earlier installment. For example, paying PHP 110,000 on a PHP 100,000 installment reports PHP 110,000 as that installment's `paid_amount`, PHP 10,000 as the next installment's `overpayment_applied_amount`, and PHP 90,000 as the next installment's `outstanding_amount`.

Use `GET /api/loans/upcoming-dues` for the upcoming-payment panel or calendar. By default it returns unpaid installments due from today through the next 30 days. Optional filters are `day_from`, `day_to`, `as_of_date`, `direction`, `counterparty_id`, `financial_institution_id`, `user_account_id`, `financial_account_id`, and `limit` (maximum 100). The response includes full summary totals even when `limit` restricts the displayed rows. A lent loan is marked as an `inflow`; a borrowed loan is marked as an `outflow`.

Record a repayment or collection with:

```json
POST /api/loans/1/payments
{
  "payment_method_id": 2,
  "financial_account_id": 2,
  "excess_treatment": "overdue_interest",
  "payment_date": "2026-11-22",
  "amount": 100000,
  "reference": "PAY-001"
}
```

Loan payments accept only `cash`, `bank_transfer`, `cheque`, and `e_wallet` payment methods. Cash requires a cash account, Bank Transfer requires a bank or checking account, Cheque requires a checking account, and E-Wallet requires an e-wallet account. The selected account must be active, enabled for payments, and use the loan currency.

Recording a borrowed-loan payment immediately posts a journal that credits the selected cash, bank, checking, or e-wallet account, so its `current_balance` and `available_balance` decrease by the complete payment amount. A lent-loan collection debits the selected account and increases its balance. Principal is posted against Loans Payable or Loans Receivable, while regular and overdue interest is posted to Loan Interest Expense or Loan Interest Income. Deleting the latest loan payment removes its linked journal and restores the financial-account balance.

When a payment exceeds the scheduled installment, send `excess_treatment=overdue_interest` to classify the extra amount as overdue interest without reducing a future installment. For a borrowed loan the response marks that amount as `expense`; for a lent loan it is `income`. Send `excess_treatment=overpayment` to waive the current installment's accrued overdue interest and apply the excess to the next installment. Payment responses include `overdue_interest_amount`, `overdue_interest_classification`, and `overpayment_amount`. Loan list/detail responses include `overpayment_amount`, `interest_paid_amount`, `overdue_interest_paid_amount`, and `total_interest_paid_amount`; detail schedule rows include the same breakdown per installment.

Loan list/detail responses also include `payment_entries`. Each detail schedule row has its own `payment_entries` array so multiple partial payments remain separate. An entry contains the payment date and total transaction amount, the amount allocated to that installment, principal/interest splits, `is_direct_payment`, reference and notes, plus nested `payment_method`, `financial_account`, and `financial_institution` objects. `financial_institution` is `null` for cash or an account without a bank.

Payments are allocated oldest-first, within each installment to overdue interest, regular interest, then principal. An overpayment can carry into later installments, but an unclassified payment larger than the loan's entire remaining balance is rejected. Explicit overdue-interest excess remains on the selected installment. Payments must be entered chronologically; only the latest payment can be deleted, using `DELETE /api/loans/{loan}/payments/{payment}`. Other endpoints are `GET /api/loans`, `GET /api/loans/{id}`, `PUT/PATCH /api/loans/{id}`, `DELETE /api/loans/{id}`, `GET /api/loans/{id}/payments`, and `POST /api/loans/{id}/cancel`. Loans with payments cannot be edited, deleted, or cancelled.

### Adjust a loan installment

Use `PATCH /api/loans/{loan}/installments/{installment}` to revise one unpaid or partially paid schedule row:

```json
{
  "due_date": "2027-03-15",
  "principal_due": 120000,
  "interest_due": 15000,
  "reason": "Lender approved a revised final installment.",
  "as_of_date": "2026-10-29"
}
```

`reason` is required, along with at least one of `due_date`, `principal_due`, or `interest_due`. The due date must remain after the previous installment and before the next installment. Principal and interest cannot be reduced below their already-paid amounts, and a fully paid or cancelled installment cannot be adjusted. Changing an amount recalculates the loan's principal, contractual interest, total loan amount, and balance from the revised schedule. The response contains the audit record and refreshed full loan details.

Use `GET /api/loans/{loan}/installments/{installment}/adjustments` for the paginated before/after history. Each entry includes the old and new due date, principal, interest, required reason, `adjusted_by`, and `adjusted_by_user`.

## Centralized multi-branch operation

All branches use this single API and database. Every payment, PO, expense, journal line, and interbranch order records its originating branch. List endpoints return company-wide data when `branch_id` is omitted, so Branch 2 can see a payment recorded by Branch 1. Pass `branch_id` only when a branch-specific view is needed.

Financial-account ownership and access are separate:

- `owner_branch_id`: optional branch responsible for managing or reconciling the account; it does not determine access.
- `is_company_wide: true`: available to every current and future branch; `branch_ids` must be empty.
- `is_company_wide: false`: available only to the selected `branch_ids`; at least one active branch is required.

Do not create separate copies of the same shared GCash account per branch. Create it once and either make it company-wide or give access to selected branches. For example, one account with `branch_ids: [1, 2]` is available to Branches 1 and 2 but not Branch 3. Each payment still records the branch that initiated it.

Use a UUID `idempotency_key` on payment creation to guard clients against duplicate submissions.

## Financial-account transfers

An account transfer is independent of branches and supports two destinations:

- `internal`: money moves to another financial account stored in this database.
- `external`: money leaves the source account for an outside account or person. The destination is stored as a recipient snapshot and the debit is posted to the selected `offset_account_id` (for example, an expense, payable, receivable, owner draw, or transfer-clearing ledger).

The source must be active, enabled for payments and `account_transfer`, and have enough journal balance. An internal destination must also be active, enabled for receipts and `account_transfer`, and use the same currency.

### Internal transfer

```json
POST /api/account-transfers
{
  "source_financial_account_id": 12,
  "destination_financial_account_id": 29,
  "destination_type": "internal",
  "transfer_method": "e_wallet",
  "transfer_date": "2026-10-04",
  "currency": "PHP",
  "amount": 2000,
  "reference": "GCASH-TO-BDO-001",
  "memo": "Transfer GCash balance to BDO Ced",
  "post": true
}
```

The journal credits GCash and debits BDO without linking or changing any underlying receipt. For a GCash balance of ₱2,000 and existing BDO balance of ₱1,000, transferring ₱2,000 produces GCash ₱0 and BDO ₱3,000. The API rejects transfers larger than the current source balance. Create a draft without `post`, post it later with `POST /api/account-transfers/{id}/post`, or reverse a posted transfer through `POST /api/account-transfers/{id}/void` with a reason. Confirm balances through each account's `/balance` endpoint.

### External transfer

```json
POST /api/account-transfers
Idempotency-Key: 8b74dcc0-b377-48c7-8107-e385f0f481e8
{
  "source_financial_account_id": 29,
  "destination_type": "external",
  "transfer_method": "online",
  "offset_account_id": 90,
  "counterparty_id": 42,
  "recipient_name": "Juan Dela Cruz",
  "recipient_type": "individual",
  "external_institution_name": "Outside Bank",
  "external_account_name": "Juan Dela Cruz",
  "external_account_number": "123456789012",
  "recipient_contact": "juan@example.com",
  "transfer_date": "2026-10-21",
  "currency": "PHP",
  "amount": 1250,
  "reference": "EXT-ONLINE-001",
  "post": true
}
```

`counterparty_id` is optional, so one-off recipients do not need a master record. External account numbers are encrypted at rest; API responses expose only `masked_external_account_number`. Use a UUID `idempotency_key` in the body or `Idempotency-Key` header to prevent duplicate submissions.

Allowed source/method combinations are `cash` from a cash account, `online` from a bank or checking account, `e_wallet` from an e-wallet account, and `cheque` from a checking account. Cheques also require `cheque_number`. Online and e-wallet external transfers require the external institution and account number.

This endpoint records and posts the accounting transfer. It does not itself instruct a bank or e-wallet provider to move money; actual network disbursement requires a separate provider integration and reconciliation workflow.

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

For the credit-card portal, prefer the dedicated endpoint below. `actual_amount_due` is the real remaining amount shown by the bank after payments, so the client does not need to convert it back into the original statement total:

```json
POST /api/credit-cards/4/adjustments
{
  "payment_obligation_id": 18,
  "adjustment_account_id": 91,
  "actual_amount_due": 101000,
  "adjustment_date": "2026-10-13",
  "reference": "BDO-AMEX-SOA-2026-10",
  "reason": "Actual SOA includes an unrecorded annual fee"
}
```

If the recorded remaining due is ₱100,000, this posts a ₱1,000 liability increase. The response includes the expected due before adjustment, payments already applied, actual due, calculated difference and direction, credit-card and statement details, the selected offset account, journal lines, and the users and timestamps in the audit trail.

- `GET /api/credit-cards/{creditCard}/adjustments` lists the card's adjustment history and accepts `status`, `from`, and `to` filters.
- `GET /api/credit-cards/{creditCard}/adjustments/{adjustment}` returns one complete adjustment transaction.
- `POST /api/credit-cards/{creditCard}/adjustments/{adjustment}/void` reverses it and requires a `reason`.

For cash, savings/bank, checking, and e-wallet balances, use the account-specific endpoint. The account's currency and system balance are derived by the API:

```json
POST /api/financial-accounts/29/adjustments
{
  "adjustment_account_id": 90,
  "actual_balance": 101000,
  "adjustment_date": "2026-10-13",
  "reference": "BDO-ONLINE-BALANCE-2026-10-13",
  "reason": "Actual savings balance does not tally with the system"
}
```

If the system balance is ₱100,000, this posts and records a ₱1,000 increase. A lower actual balance posts a decrease. The response contains both balances, the discrepancy and direction, financial and adjustment accounts, journal lines, and the complete user audit trail. Online transfer is a payment method rather than an account type, so reconcile the bank or e-wallet account that actually holds the transferred funds.

- `GET /api/financial-accounts/{account}/adjustments` lists its history and accepts `status`, `from`, and `to` filters.
- `GET /api/financial-accounts/{account}/adjustments/{adjustment}` returns the full reconciliation transaction.
- `POST /api/financial-accounts/{account}/adjustments/{adjustment}/void` reverses it and requires a `reason`.

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

The command imports `bank` into `financial_institutions` and `payment_type_po` into `financial_accounts`, generating a separate chart account for every financial account. Central IDs are generated fresh. The original IDs remain available through `legacy_source` and `legacy_id`, and rerunning the command updates the same imported records without duplication. Applicability comes from `is_customer`, `is_supplier`, and observed customer, PO, expense, and obligation-payment history. Never-used accounts are marked `unclassified` for manual review. Imported accounts start as company-wide because the legacy source has no branch-access data; review their access before production cutover. Mutable legacy `balance` and `total_balance_due` values are intentionally excluded; cached balances are rebuilt from posted journals.
