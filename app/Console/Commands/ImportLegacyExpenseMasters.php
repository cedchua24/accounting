<?php

namespace App\Console\Commands;

use App\Models\ChartAccount;
use App\Models\ChartAccountGroup;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseType;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ImportLegacyExpenseMasters extends Command
{
    protected $signature = 'accounting:import-legacy-expense-masters
                            {--source=baker : Stable name of the source system}
                            {--dry-run : Validate and summarize without writing}';

    protected $description = 'Import only the legacy chart and expense type/category/item masters';

    public function handle(): int
    {
        $source = Str::limit(trim((string) $this->option('source')), 30, '');
        if ($source === '') {
            $this->error('The source name cannot be empty.');

            return self::FAILURE;
        }

        try {
            $legacy = DB::connection('legacy_mysql');
            $groups = $legacy->table('chart_of_accounts')->orderBy('id')->get();
            $types = $legacy->table('expenses_type_v2')->orderBy('id')->get();
            $categories = $legacy->table('expenses_category_v2')->orderBy('id')->get();
            $items = $legacy->table('expenses_v2')->orderBy('id')->get();
            $this->validateMasters($groups, $types, $categories, $items);
        } catch (\Throwable $exception) {
            $this->error('Unable to read the legacy expense masters: '.$exception->getMessage());

            return self::FAILURE;
        }

        $duplicateCodes = $items
            ->groupBy(fn ($item) => $item->expense_category_id.'|'.trim((string) $item->expense_code))
            ->filter(fn (Collection $matches) => $matches->count() > 1)
            ->count();
        $this->info(
            "Found {$groups->count()} chart groups, {$types->count()} expense types, "
            ."{$categories->count()} categories, and {$items->count()} expense items."
        );
        if ($duplicateCodes > 0) {
            $this->comment("Preserving {$duplicateCodes} duplicate item-code groups from the legacy system.");
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Master', 'Rows', 'Will import transactions?'],
                [
                    ['Chart-account groups', $groups->count(), 'No'],
                    ['Expense types', $types->count(), 'No'],
                    ['Expense categories', $categories->count(), 'No'],
                    ['Expense items', $items->count(), 'No'],
                    ['Expense transactions', 0, 'No'],
                ]
            );
            $this->comment('Dry run complete. No expense transaction or monetary amount was read or written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($source, $groups, $types, $categories, $items) {
            $groupMap = $this->importGroups($source, $groups);
            $typeMap = $this->importTypes($source, $types, $groupMap);
            $categoryMap = $this->importCategories($source, $categories, $typeMap);
            $this->importItems($source, $items, $categoryMap);
        });

        $this->info(
            "Imported {$groups->count()} chart groups, {$types->count()} expense types, "
            ."{$categories->count()} categories, and {$items->count()} expense items."
        );
        $this->comment('Historical expenses_transaction rows were intentionally not imported.');

        return self::SUCCESS;
    }

    private function validateMasters(Collection $groups, Collection $types, Collection $categories, Collection $items): void
    {
        if ($groups->isEmpty() || $types->isEmpty() || $categories->isEmpty() || $items->isEmpty()) {
            throw new RuntimeException('One or more legacy expense master tables are empty.');
        }

        $groupIds = $groups->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $typeIds = $types->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $categoryIds = $categories->pluck('id')->map(fn ($id) => (int) $id)->flip();
        foreach ($types as $type) {
            if (! $groupIds->has((int) $type->chart_of_account_id)) {
                throw new RuntimeException("Expense type {$type->id} references a missing chart_of_accounts row.");
            }
        }
        foreach ($categories as $category) {
            if (! $typeIds->has((int) $category->expense_type_id)) {
                throw new RuntimeException("Expense category {$category->id} references a missing expense type.");
            }
        }
        foreach ($items as $item) {
            if (! $categoryIds->has((int) $item->expense_category_id)) {
                throw new RuntimeException("Expense item {$item->id} references a missing expense category.");
            }
        }
    }

    private function importGroups(string $source, Collection $rows): Collection
    {
        $map = collect();
        foreach ($rows as $row) {
            $code = $this->groupCode((string) $row->chart_of_account_name);
            $group = ChartAccountGroup::where('code', $code)->firstOrFail();
            $group->update([
                'legacy_source' => $source.'.chart_of_accounts',
                'legacy_id' => (int) $row->id,
            ]);
            $map->put((int) $row->id, $group);
        }

        return $map;
    }

    private function importTypes(string $source, Collection $rows, Collection $groupMap): Collection
    {
        $map = collect();
        foreach ($rows as $row) {
            $identity = [
                'legacy_source' => $source.'.expenses_type_v2',
                'legacy_id' => (int) $row->id,
            ];
            $type = ExpenseType::where($identity)->first() ?? new ExpenseType;
            $type->fill([
                'chart_account_group_id' => $groupMap->get((int) $row->chart_of_account_id)->id,
                'chart_account_id' => null,
                'code' => $this->uniqueTypeCode((string) $row->expense_type_code, (int) $row->id, $type->id),
                'name' => $this->uniqueTypeName((string) $row->expense_type, (int) $row->id, $type->id),
                'is_profit' => (bool) ($row->is_profit ?? false),
                'display_order' => (int) $row->id,
                'is_active' => $this->isActive($row->status ?? null),
            ] + $identity)->save();
            $map->put((int) $row->id, $type);
        }

        return $map;
    }

    private function importCategories(string $source, Collection $rows, Collection $typeMap): Collection
    {
        $map = collect();
        foreach ($rows as $row) {
            $type = $typeMap->get((int) $row->expense_type_id);
            $accountIdentity = [
                'legacy_source' => $source.'.expenses_category_v2',
                'legacy_id' => (int) $row->id,
            ];
            $account = ChartAccount::where($accountIdentity)->first() ?? new ChartAccount;
            $account->fill([
                'group_id' => $type->chart_account_group_id,
                'code' => $this->categoryAccountCode($source, (int) $row->id),
                'name' => trim((string) $row->expense_category_name),
                'type' => 'expense',
                'parent_id' => null,
                'description' => 'Imported legacy expense category.',
                'is_postable' => true,
                'display_order' => (int) $row->id,
                'is_active' => $this->isActive($row->status ?? null),
            ] + $accountIdentity)->save();

            $identity = [
                'legacy_source' => $source.'.expenses_category_v2',
                'legacy_id' => (int) $row->id,
            ];
            $category = ExpenseCategory::where($identity)->first() ?? new ExpenseCategory;
            $category->fill([
                'expense_type_id' => $type->id,
                'chart_account_id' => $account->id,
                'code' => $this->masterCode((string) $row->expense_category_code, 'CAT-'.$row->id),
                'name' => trim((string) $row->expense_category_name),
                'description' => 'Imported from the legacy expense category list.',
                'display_order' => (int) $row->id,
                'is_active' => $this->isActive($row->status ?? null),
            ] + $identity)->save();
            $map->put((int) $row->id, $category);
        }

        return $map;
    }

    private function importItems(string $source, Collection $rows, Collection $categoryMap): void
    {
        foreach ($rows as $row) {
            $category = $categoryMap->get((int) $row->expense_category_id);
            $identity = [
                'legacy_source' => $source.'.expenses_v2',
                'legacy_id' => (int) $row->id,
            ];
            $item = ExpenseItem::where($identity)->first() ?? new ExpenseItem;
            $item->fill([
                'expense_category_id' => $category->id,
                'chart_account_id' => $category->chart_account_id,
                'code' => $this->masterCode((string) $row->expense_code, 'ITEM-'.$row->id),
                'name' => trim((string) $row->expense_name),
                'details' => trim((string) ($row->details ?? '')) ?: null,
                'is_hidden' => (bool) ($row->is_hidden ?? false),
                'is_active' => $this->isActive($row->status ?? null),
                'display_order' => (int) $row->id,
            ] + $identity)->save();
        }
    }

    private function groupCode(string $name): string
    {
        return match (Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $name))) {
            'ASSET', 'ASSETS' => 'ASSET',
            'LIABILITY', 'LIABILITIES' => 'LIABILITY',
            'EQUITY' => 'EQUITY',
            'REVENUE', 'REVENUES' => 'REVENUE',
            'COSTOFGOODSSOLD', 'COSTOFSALES' => 'COGS',
            'EXPENSE', 'EXPENSES' => 'EXPENSE',
            default => throw new RuntimeException("Unknown legacy chart group: {$name}"),
        };
    }

    private function uniqueTypeCode(string $value, int $legacyId, ?int $ignoreId): string
    {
        $base = $this->masterCode($value, 'TYPE-'.$legacyId);
        $exists = ExpenseType::where('code', $base)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists();

        return $exists ? Str::limit($base.'-L'.$legacyId, 30, '') : $base;
    }

    private function uniqueTypeName(string $value, int $legacyId, ?int $ignoreId): string
    {
        $name = trim($value) ?: 'Legacy Expense Type '.$legacyId;
        $exists = ExpenseType::where('name', $name)->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->exists();

        return $exists ? $name.' (Legacy '.$legacyId.')' : $name;
    }

    private function masterCode(string $value, string $fallback): string
    {
        $code = trim($value);

        return Str::limit($code === '' ? $fallback : $code, 30, '');
    }

    private function categoryAccountCode(string $source, int $legacyId): string
    {
        return Str::limit('LX-'.Str::upper(dechex(crc32($source))).'-CAT-'.$legacyId, 30, '');
    }

    private function isActive(mixed $status): bool
    {
        return $status === null || (int) $status === 0;
    }
}
