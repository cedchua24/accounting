# Legacy payment migration

Source reviewed: the `baker` database used by `C:\xampp\htdocs\bakingstore_BE` and the supplied SQL exports for `bank`, `payment_term`, `payment_type_po`, `mode_of_payment`, `mode_of_payment_po`, `credit_card_due`, and `credit_card_pay`.

## Why the structure changed

`payment_type_po` combines provider, account, payment method, account capabilities, credit-card cycle settings, and mutable balances. `credit_card_due` also contains cheque obligations. Monetary values use `double`, foreign keys are not enforced, and controllers manually add/subtract stored totals. This makes drift, race conditions, and ambiguous status values likely.

The accounting API separates these responsibilities:

| Legacy | New structure |
| --- | --- |
| `bank` | `financial_institutions` |
| `payment_term` | `payment_methods` |
| `payment_type_po` | `financial_accounts` |
| `credit_card_due` with `type=CREDIT_CARD` | `payment_obligations.type=credit_card_statement` |
| `credit_card_due` with `type=CHEQUE` | `payment_obligations.type=issued_cheque` |
| `credit_card_pay` | posted `payments` plus `payment_allocations` |
| `mode_of_payment` | customer tender lines grouped by sales order and payment event |
| `mode_of_payment_po` | supplier tender lines grouped by PO and payment event/date |
| mutable `balance` / `total_balance_due` / `amount_paid` | derived from posted payments and allocations |

All money is `decimal(19,4)`. Account numbers are encrypted, with a one-way hash for duplicate detection and only the final four characters returned by the API. Source keys can be retained using `legacy_source` and `legacy_id`.

During migration, accounts genuinely shared by the company should use `is_company_wide = true`. Accounts shared by only selected locations use `is_company_wide = false` plus rows in `financial_account_branch_access`. `owner_branch_id` identifies the branch responsible for managing the account, not the branches allowed to use it. Historical transactions need an explicit branch mapping because the legacy single-branch tables do not contain one.

The reusable `accounting:import-legacy-payment-accounts` command imports these master records with fresh central IDs. It retains the old key in `legacy_source=baker.payment_type_po` and `legacy_id`, allowing branch integrations to resolve their old ID through the financial-account API. Imported accounts initially use `is_company_wide = true` because the source table contains no branch-access data; restrict local cash drawers such as `Store - Qc` to the correct branch before production cutover.

The legacy `is_customer` and `is_supplier` flags are transaction-specific, not merely generic receipt/payment flags. The importer creates `financial_account_applications` from both flags and actual use in `mode_of_payment`, `mode_of_payment_po`, `expenses_transaction`, and `credit_card_pay`. This recovers missing expense and obligation applicability for records whose legacy flags are both zero. Accounts with no flag and no observed use are marked `unclassified` and cannot be assumed safe for a transaction type until reviewed.

`interbranch_settlement` is never inferred during legacy import because the supplied source has no reliable field identifying an account authorized for IBO cash settlement. Assign it manually only to accounts that may move real money between branches; normal IBO inventory transfers remain non-cash internal-clearing transactions.

The old mode-of-payment records become `payment_lines`, not independent payment headers. Group by the related order plus the real payment event/date; do not blindly merge every historical row for an order because later partial payments may exist. This keeps split cash/GCash/card payments atomic and produces one balanced journal entry per business transaction.

Legacy `mode_of_payment.payment_type_id` is mixed: older rows reference `payment_type`, while newer rows use IDs from `payment_type_po`. The import must resolve both into one `financial_accounts` mapping before creating payment lines.

For legacy card activity, import each `mode_of_payment_po` card row as the original PO payment line and associate it with the correct generated statement cycle. Import each `credit_card_pay` row as a separate bank/cash outflow allocated to that statement obligation. Do not import a card charge and its later statement settlement as two payments against the PO; the latter settles the card liability only. Retain the legacy statement date and due date when present, rather than recalculating historical cycles.

The legacy `credit_card_due.is_installment` field is only a flag; it does not contain an installment count, sequence number, or plan identifier. In the supplied export all 751 rows currently have the value `0`. For any installment history from another snapshot, derive the schedule only when multiple due rows can be reliably tied to the same `mode_of_payment_po_id`; otherwise place the row in the migration exception report for manual installment-count mapping. In the new system, `payment_lines.installment_count` stores the plan and `obligation_charges.installment_number` links each monthly portion to its statement obligation.

## Source audit snapshot

- 12 institutions
- 4 legacy payment terms
- 47 payment accounts
- 751 due/obligation rows
- 607 due-payment rows
- 2,351 PO-payment rows
- 66,152 customer payment lines across 63,235 customer orders
- 2,703 customer orders use split tender; one order contains as many as 89 payment rows
- 70 supplier orders use split payments; the maximum is 5 rows
- no orphan references across the five supplied tables
- 4 duplicate institution/account-number groups
- 4 overpaid due rows
- 1 row containing a negative monetary value
- visible floating-point drift in aggregate totals

## Import policy

Do not copy cached balances or status integers directly. Import base amounts and payment events, derive balances from posted allocations, map `status` values explicitly, and send duplicates/negative/overpaid rows to an exception report for review. A production import should run against a database snapshot and reconcile per-account opening and closing totals before cutover.
