<?php

namespace App\Services\Expenses;

use App\Models\ExpenseActivityLog;
use App\Models\ExpenseCategory;
use App\Models\ExpenseTransaction;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\AppDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseLedgerService
{
    public function filteredQuery(array $filters, bool $withRelations = true): Builder
    {
        [$from, $to] = $this->dateRange($filters);
        $query = ExpenseTransaction::query();

        if ($withRelations) {
            $query->with(['category:id,name,type', 'createdBy:id,name', 'voidedBy:id,name']);
        }

        $query
            ->when($from, fn (Builder $builder): Builder => $builder->whereDate('transaction_date', '>=', $from))
            ->when($to, fn (Builder $builder): Builder => $builder->whereDate('transaction_date', '<=', $to))
            ->when(in_array($filters['type'] ?? null, ['income', 'expense'], true), fn (Builder $builder): Builder => $builder->where('type', $filters['type']))
            ->when(filled($filters['category_id'] ?? null), fn (Builder $builder): Builder => $builder->where('category_id', (int) $filters['category_id']))
            ->when(filled($filters['payment_method'] ?? null), fn (Builder $builder): Builder => $builder->where('payment_method', $filters['payment_method']))
            ->when(filled($filters['vendor'] ?? null), fn (Builder $builder): Builder => $builder->where('vendor_name', 'like', '%'.$filters['vendor'].'%'))
            ->when(is_numeric($filters['amount_min'] ?? null), fn (Builder $builder): Builder => $builder->where('amount', '>=', (float) $filters['amount_min']))
            ->when(is_numeric($filters['amount_max'] ?? null), fn (Builder $builder): Builder => $builder->where('amount', '<=', (float) $filters['amount_max']))
            ->when(filled($filters['created_by'] ?? null), fn (Builder $builder): Builder => $builder->where('created_by_user_id', (int) $filters['created_by']))
            ->when(in_array($filters['status'] ?? null, ['posted', 'voided'], true), fn (Builder $builder): Builder => $builder->where('status', $filters['status']));

        return $query;
    }

    public function dashboard(array $filters): array
    {
        $allTime = ExpenseTransaction::query()->posted();
        $totalIncome = (float) (clone $allTime)->where('type', 'income')->sum('amount');
        $totalExpenses = (float) (clone $allTime)->where('type', 'expense')->sum('amount');
        $monthStart = CarbonImmutable::now(AppDateTime::timezone())->startOfMonth()->toDateString();
        $monthEnd = CarbonImmutable::now(AppDateTime::timezone())->endOfMonth()->toDateString();
        $monthQuery = ExpenseTransaction::query()
            ->posted()
            ->whereBetween('transaction_date', [$monthStart, $monthEnd]);
        $periodQuery = $this->filteredQuery($filters, false)->posted();
        $periodIncome = (float) (clone $periodQuery)->where('type', 'income')->sum('amount');
        $periodExpenses = (float) (clone $periodQuery)->where('type', 'expense')->sum('amount');

        return [
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'current_balance' => $totalIncome - $totalExpenses,
            'month_income' => (float) (clone $monthQuery)->where('type', 'income')->sum('amount'),
            'month_expenses' => (float) (clone $monthQuery)->where('type', 'expense')->sum('amount'),
            'period_income' => $periodIncome,
            'period_expenses' => $periodExpenses,
            'period_net' => $periodIncome - $periodExpenses,
        ];
    }

    public function categoryBreakdown(array $filters): array
    {
        $rows = $this->filteredQuery($filters, false)
            ->posted()
            ->selectRaw('type, category_id, SUM(amount) as total')
            ->with('category:id,name,type')
            ->groupBy('type', 'category_id')
            ->orderByDesc('total')
            ->get();

        return [
            'income' => $rows->where('type', 'income')->values(),
            'expense' => $rows->where('type', 'expense')->values(),
        ];
    }

    public function create(array $data, User $actor, ?UploadedFile $attachment = null, array $attachments = []): ExpenseTransaction
    {
        return $this->withStoredFiles(function (array &$storedFiles) use ($data, $actor, $attachment, $attachments): ExpenseTransaction {
            $transaction = ExpenseTransaction::create($this->transactionPayload($data) + [
                'status' => 'posted',
                'created_by_user_id' => $actor->id,
            ]);

            if ($attachment) {
                $this->storeAttachment($transaction, $attachment, $storedFiles);
            }
            $this->appendAttachments($transaction, $attachments, $actor, $storedFiles);

            $this->activity(
                $transaction,
                $actor,
                'created',
                $transaction->type === 'income' ? 'تم تسجيل وارد يدوي.' : 'تم تسجيل مصروف يدوي.',
                null,
                $this->auditValues($transaction->fresh()),
            );
            AdminActivityLogger::log(
                action: 'expenses.transaction.created',
                description: 'تم إنشاء عملية مالية يدوية رقم '.$transaction->id.'.',
                subject: $transaction,
                properties: ['type' => $transaction->type, 'amount' => $transaction->amount, 'category_id' => $transaction->category_id],
                admin: $actor,
            );

            return $transaction->fresh(['category', 'createdBy']);
        });
    }

    public function update(ExpenseTransaction $transaction, array $data, User $actor, ?UploadedFile $attachment = null, array $attachments = []): ExpenseTransaction
    {
        if ($transaction->status === 'voided') {
            throw ValidationException::withMessages(['transaction' => 'لا يمكن تعديل عملية ملغاة.']);
        }

        return $this->withStoredFiles(function (array &$storedFiles) use ($transaction, $data, $actor, $attachment, $attachments): ExpenseTransaction {
            $locked = ExpenseTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status === 'voided') {
                throw ValidationException::withMessages(['transaction' => 'لا يمكن تعديل عملية ملغاة.']);
            }

            $before = $this->auditValues($locked);
            $locked->update($this->transactionPayload($data));

            if ($attachment) {
                $this->replaceAttachment($locked, $attachment, $storedFiles);
            }
            $this->appendAttachments($locked, $attachments, $actor, $storedFiles);

            $after = $this->auditValues($locked->fresh());
            $changes = AdminActivityLogger::changedValues($before, $after);
            $this->activity($locked, $actor, 'updated', 'تم تعديل العملية المالية.', $before, $after);
            AdminActivityLogger::log(
                action: 'expenses.transaction.updated',
                description: 'تم تعديل العملية المالية رقم '.$locked->id.'.',
                subject: $locked,
                properties: ['changes' => $changes],
                admin: $actor,
            );

            return $locked->fresh(['category', 'createdBy']);
        });
    }

    public function updateCategory(ExpenseTransaction $transaction, int $categoryId, int $expectedCategoryId, User $actor): ExpenseTransaction
    {
        return DB::transaction(function () use ($transaction, $categoryId, $expectedCategoryId, $actor): ExpenseTransaction {
            $locked = ExpenseTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status !== 'posted') {
                throw ValidationException::withMessages(['category_id' => 'لا يمكن تعديل تصنيف عملية ملغاة.']);
            }
            abort_if($locked->category_id !== $expectedCategoryId, 409, 'تم تعديل التصنيف بواسطة مستخدم آخر. حدّث الصفحة أولًا.');
            $category = ExpenseCategory::query()->lockForUpdate()->find($categoryId);
            if (! $category || $category->type !== $locked->type || (! $category->is_active && $categoryId !== $locked->category_id)) {
                throw ValidationException::withMessages(['category_id' => 'اختر تصنيفًا فعالًا يطابق نوع العملية.']);
            }
            if ($categoryId === $locked->category_id) {
                return $locked;
            }

            $before = $this->auditValues($locked);
            $locked->update(['category_id' => $categoryId]);
            $after = $this->auditValues($locked);
            $this->activity($locked, $actor, 'updated', 'تم تعديل تصنيف العملية المالية.', $before, $after);
            AdminActivityLogger::log(
                action: 'expenses.transaction.updated',
                description: 'تم تعديل تصنيف العملية المالية رقم '.$locked->id.'.',
                subject: $locked,
                properties: ['changes' => AdminActivityLogger::changedValues($before, $after)],
                admin: $actor,
            );

            return $locked;
        });
    }

    public function void(ExpenseTransaction $transaction, string $reason, User $actor): ExpenseTransaction
    {
        return DB::transaction(function () use ($transaction, $reason, $actor): ExpenseTransaction {
            $locked = ExpenseTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status === 'voided') {
                throw ValidationException::withMessages(['void_reason' => 'هذه العملية ملغاة بالفعل.']);
            }

            $before = $this->auditValues($locked);
            $locked->update([
                'status' => 'voided',
                'voided_by_user_id' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $after = $this->auditValues($locked->fresh());
            $this->activity($locked, $actor, 'voided', 'تم إلغاء أثر العملية المالية. السبب: '.$reason, $before, $after);
            AdminActivityLogger::log(
                action: 'expenses.transaction.voided',
                description: 'تم إلغاء العملية المالية رقم '.$locked->id.'.',
                subject: $locked,
                properties: ['reason' => $reason, 'type' => $locked->type, 'amount' => $locked->amount],
                admin: $actor,
            );

            return $locked->fresh(['category', 'createdBy', 'voidedBy']);
        });
    }

    public function dateRange(array $filters): array
    {
        $preset = $filters['date_preset'] ?? 'this_month';
        $today = CarbonImmutable::today(AppDateTime::timezone());

        return match ($preset) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'last_7_days' => [$today->subDays(6)->toDateString(), $today->toDateString()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'custom' => [
                filled($filters['date_from'] ?? null) ? (string) $filters['date_from'] : null,
                filled($filters['date_to'] ?? null) ? (string) $filters['date_to'] : null,
            ],
            'all' => [null, null],
            default => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()],
        };
    }

    public static function formatMoney(float|int|string|null $amount): string
    {
        $value = (float) ($amount ?? 0);
        $decimals = abs($value - round($value)) < 0.001 ? 0 : 2;

        return arabic_number(number_format($value, $decimals)).' '.config('expenses.currency_label', 'ج.م');
    }

    private function transactionPayload(array $data): array
    {
        return [
            'type' => $data['type'],
            'transaction_date' => $data['transaction_date'],
            'amount' => $data['amount'],
            'currency' => config('expenses.default_currency', 'EGP'),
            'category_id' => $data['category_id'],
            'payment_method' => $data['payment_method'] ?? null,
            'vendor_name' => $data['vendor_name'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function storeFile(ExpenseTransaction $transaction, UploadedFile $file, array &$storedFiles): array
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $disk = (string) config('media.private_disk', 'local');
        $path = $file->storeAs(
            'expenses/transactions/'.$transaction->id,
            Str::uuid().'.'.$extension,
            $disk,
        );

        if (! $path) {
            throw ValidationException::withMessages(['attachment' => 'تعذر حفظ المرفق. حاول مرة أخرى.']);
        }

        $storedFiles[] = [$disk, $path];

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function storeAttachment(ExpenseTransaction $transaction, UploadedFile $file, array &$storedFiles): void
    {
        $stored = $this->storeFile($transaction, $file, $storedFiles);
        $transaction->update([
            'attachment_path' => $stored['path'],
            'attachment_original_name' => $stored['original_name'],
            'attachment_mime' => $stored['mime'],
            'attachment_size' => $stored['size'],
        ]);
    }

    private function appendAttachments(ExpenseTransaction $transaction, array $files, User $actor, array &$storedFiles): void
    {
        foreach ($files as $file) {
            $transaction->attachments()->create($this->storeFile($transaction, $file, $storedFiles) + [
                'uploaded_by_user_id' => $actor->id,
            ]);
        }
    }

    private function replaceAttachment(ExpenseTransaction $transaction, UploadedFile $file, array &$storedFiles): void
    {
        $oldPath = $transaction->attachment_path;
        $disk = (string) config('media.private_disk', 'local');
        $this->storeAttachment($transaction, $file, $storedFiles);

        if ($oldPath && $oldPath !== $transaction->attachment_path) {
            // Never remove the original invoice before its replacement and audit are committed.
            DB::afterCommit(fn () => $this->cleanupFile($disk, $oldPath));
        }
    }

    private function withStoredFiles(callable $operation): ExpenseTransaction
    {
        $storedFiles = [];
        try {
            return DB::transaction(function () use ($operation, &$storedFiles): ExpenseTransaction {
                return $operation($storedFiles);
            });
        } catch (\Throwable $exception) {
            foreach ($storedFiles as [$disk, $path]) {
                $this->cleanupFile($disk, $path);
            }
            throw $exception;
        }
    }

    private function cleanupFile(string $disk, string $path): void
    {
        try {
            if (! Storage::disk($disk)->delete($path)) {
                Log::warning('Expense attachment cleanup failed.', ['disk' => $disk]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Expense attachment cleanup failed.', ['disk' => $disk, 'exception_type' => $exception::class]);
        }
    }

    private function auditValues(ExpenseTransaction $transaction): array
    {
        return [
            'type' => $transaction->type,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'category_id' => $transaction->category_id,
            'payment_method' => $transaction->payment_method,
            'vendor_name' => $transaction->vendor_name,
            'reference_number' => $transaction->reference_number,
            'description' => $transaction->description,
            'notes' => $transaction->notes,
            'attachment_original_name' => $transaction->attachment_original_name,
            'attachments' => $transaction->attachments()->get(['id', 'original_name', 'mime', 'size'])->toArray(),
            'status' => $transaction->status,
            'void_reason' => $transaction->void_reason,
        ];
    }

    private function activity(
        ExpenseTransaction $transaction,
        User $actor,
        string $action,
        string $description,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        ExpenseActivityLog::create([
            'transaction_id' => $transaction->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'description' => $description,
            'old_values_json' => $oldValues,
            'new_values_json' => $newValues,
            'created_at' => now(),
        ]);
    }
}
