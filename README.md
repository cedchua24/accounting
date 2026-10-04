# Accounting API

Backend-first Laravel API for cash/bank/e-wallet/credit-card accounts, customer receipts, purchase-order payments, non-cash interbranch orders, expenses, payable obligations, direct chart-of-account payments, and double-entry journals.

See [API.md](API.md) for setup, authentication, endpoints, and the payment workflow.

## Quick start

```powershell
composer install
php artisan migrate --seed
php artisan serve --port=8081
```

Run the automated checks with `php artisan test`.
