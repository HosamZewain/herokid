<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\ExpenseTransaction;
use App\Models\User;
use App\Services\Expenses\ExpenseLedgerService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function index(Request $request, ExpenseLedgerService $ledger): View
    {
        $filters = $this->filters($request);
        $transactions = $ledger->filteredQuery($filters)
            ->latest('transaction_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.expenses.index', [
            'filters' => $filters,
            'transactions' => $transactions,
            'summary' => $ledger->dashboard($filters),
            'breakdown' => $request->user()->hasPermission('expenses.view_reports')
                ? $ledger->categoryBreakdown($filters)
                : null,
            'categories' => ExpenseCategory::query()->orderBy('type')->orderBy('sort_order')->orderBy('name')->get(),
            'admins' => User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => config('expenses.payment_methods', []),
        ]);
    }

    public function create(Request $request, string $kind = 'expense'): View
    {
        abort_unless(in_array($kind, ['income', 'expense', 'opening'], true), 404);
        $type = $kind === 'opening' ? 'income' : $kind;
        $this->authorizeCreate($request, $type);

        return view('admin.expenses.form', [
            'transaction' => new ExpenseTransaction([
                'type' => $type,
                'transaction_date' => today(),
                'description' => $kind === 'opening' ? 'رصيد افتتاحي' : null,
            ]),
            'kind' => $kind,
            'categories' => ExpenseCategory::query()
                ->where('type', $type)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'paymentMethods' => config('expenses.payment_methods', []),
            'openingBalanceExists' => $kind === 'opening' && $this->postedOpeningBalanceExists(),
        ]);
    }

    public function store(Request $request, ExpenseLedgerService $ledger): RedirectResponse
    {
        $kind = (string) $request->input('kind', $request->input('type'));
        abort_unless(in_array($kind, ['income', 'expense', 'opening'], true), 404);
        $type = $kind === 'opening' ? 'income' : $kind;
        $this->authorizeCreate($request, $type);

        if ($kind === 'opening' && $this->postedOpeningBalanceExists() && ! $request->boolean('confirm_existing_opening_balance')) {
            throw ValidationException::withMessages([
                'confirm_existing_opening_balance' => 'يوجد رصيد افتتاحي مسجل بالفعل. فعّل التأكيد للمتابعة أو أضف العملية كوارد جديد.',
            ]);
        }

        $request->merge([
            'type' => $type,
            'category_id' => $kind === 'opening' ? $this->openingCategoryId() : $request->input('category_id'),
            'description' => $kind === 'opening' ? ($request->input('description') ?: 'رصيد افتتاحي') : $request->input('description'),
        ]);
        $data = $this->validateTransaction($request, activeCategoryOnly: true);
        $transaction = $ledger->create($data, $request->user(), $request->file('attachment'));

        return redirect()
            ->route('admin.expenses.show', $transaction)
            ->with('success', $type === 'income' ? 'تم تسجيل الوارد بنجاح.' : 'تم تسجيل المصروف بنجاح.');
    }

    public function show(ExpenseTransaction $expense): View
    {
        $expense->load(['category', 'createdBy', 'voidedBy', 'activityLogs.actor']);

        return view('admin.expenses.show', ['transaction' => $expense]);
    }

    public function edit(Request $request, ExpenseTransaction $expense): View
    {
        abort_if($expense->status === 'voided', 422, 'لا يمكن تعديل عملية ملغاة.');

        return view('admin.expenses.form', [
            'transaction' => $expense,
            'kind' => $expense->type,
            'categories' => ExpenseCategory::query()
                ->where('type', $expense->type)
                ->where(fn ($query) => $query->where('is_active', true)->orWhereKey($expense->category_id))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'paymentMethods' => config('expenses.payment_methods', []),
            'openingBalanceExists' => false,
        ]);
    }

    public function update(Request $request, ExpenseTransaction $expense, ExpenseLedgerService $ledger): RedirectResponse
    {
        abort_if($expense->status === 'voided', 422, 'لا يمكن تعديل عملية ملغاة.');
        $request->merge(['type' => $expense->type]);

        if ($expense->attachment_path && $request->hasFile('attachment') && ! $request->boolean('confirm_replace_attachment')) {
            throw ValidationException::withMessages([
                'confirm_replace_attachment' => 'أكد استبدال المرفق الحالي أولًا.',
            ]);
        }

        $data = $this->validateTransaction($request, activeCategoryOnly: false);
        $ledger->update($expense, $data, $request->user(), $request->file('attachment'));

        return redirect()->route('admin.expenses.show', $expense)->with('success', 'تم تحديث العملية بنجاح.');
    }

    public function void(Request $request, ExpenseTransaction $expense, ExpenseLedgerService $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'void_reason.required' => 'سبب الإلغاء مطلوب.',
            'void_reason.min' => 'اكتب سببًا واضحًا للإلغاء.',
        ]);

        $ledger->void($expense, $validated['void_reason'], $request->user());

        return redirect()->route('admin.expenses.show', $expense)->with('success', 'تم إلغاء أثر العملية مع الاحتفاظ بالسجل.');
    }

    public function attachment(ExpenseTransaction $expense)
    {
        $this->ensureAttachmentExists($expense);

        return Storage::disk('local')->response(
            $expense->attachment_path,
            $expense->attachment_original_name,
            [
                'Content-Type' => $expense->attachment_mime ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function download(Request $request, ExpenseTransaction $expense)
    {
        $this->ensureAttachmentExists($expense);
        AdminActivityLogger::log(
            action: 'expenses.attachment.downloaded',
            description: 'تم تنزيل مرفق العملية المالية رقم '.$expense->id.'.',
            subject: $expense,
            properties: ['attachment_name' => $expense->attachment_original_name],
            admin: $request->user(),
        );

        return Storage::disk('local')->download(
            $expense->attachment_path,
            $expense->attachment_original_name,
            ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function export(Request $request, ExpenseLedgerService $ledger): StreamedResponse
    {
        $filters = $this->filters($request);
        $transactions = $ledger->filteredQuery($filters)->latest('transaction_date')->latest('id')->get();
        AdminActivityLogger::log(
            action: 'expenses.exported',
            description: 'تم تصدير دفتر المصروفات اليدوي.',
            properties: ['filters' => $filters, 'row_count' => $transactions->count()],
            admin: $request->user(),
        );

        return response()->streamDownload(function () use ($transactions): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'transaction_date', 'type', 'category', 'description', 'vendor_name',
                'payment_method', 'reference_number', 'amount', 'currency', 'status',
                'attachment_original_name', 'created_by', 'created_at',
            ]);

            foreach ($transactions as $transaction) {
                fputcsv($output, array_map($this->csvCell(...), [
                    $transaction->transaction_date?->format('Y-m-d'),
                    $transaction->type,
                    $transaction->category?->name,
                    $transaction->description,
                    $transaction->vendor_name,
                    $transaction->payment_method
                        ? (config('expenses.payment_methods.'.$transaction->payment_method) ?? $transaction->payment_method)
                        : null,
                    $transaction->reference_number,
                    number_format((float) $transaction->amount, 2, '.', ''),
                    $transaction->currency,
                    $transaction->status,
                    $transaction->attachment_original_name,
                    $transaction->createdBy?->name,
                    $transaction->created_at?->format('Y-m-d H:i:s'),
                ]));
            }

            fclose($output);
        }, 'herokid-expenses-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    private function validateTransaction(Request $request, bool $activeCategoryOnly): array
    {
        $type = (string) $request->input('type');
        $categoryRule = Rule::exists('expense_categories', 'id')->where(function ($query) use ($type, $activeCategoryOnly): void {
            $query->where('type', $type);
            if ($activeCategoryOnly) {
                $query->where('is_active', true);
            }
        });
        $maxKb = max(1, (int) config('expenses.attachment_max_mb', 5)) * 1024;

        return $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'category_id' => ['required', $categoryRule],
            'payment_method' => ['nullable', Rule::in(array_keys(config('expenses.payment_methods', [])))],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.$maxKb],
        ], [
            'amount.min' => 'يجب أن يكون المبلغ أكبر من صفر.',
            'category_id.exists' => 'التصنيف غير صالح أو لا يطابق نوع العملية.',
            'attachment.mimes' => 'المرفق يجب أن يكون PDF أو JPG أو PNG أو WEBP.',
            'attachment.max' => 'حجم المرفق يتجاوز الحد المسموح.',
        ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_preset' => ['nullable', Rule::in(['all', 'today', 'last_7_days', 'this_month', 'last_month', 'custom'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'type' => ['nullable', Rule::in(['all', 'income', 'expense'])],
            'category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'payment_method' => ['nullable', Rule::in(array_keys(config('expenses.payment_methods', [])))],
            'vendor' => ['nullable', 'string', 'max:255'],
            'amount_min' => ['nullable', 'numeric', 'min:0'],
            'amount_max' => ['nullable', 'numeric', 'gte:amount_min'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['all', 'posted', 'voided'])],
        ]) + ['date_preset' => 'this_month', 'type' => 'all', 'status' => 'all'];
    }

    private function authorizeCreate(Request $request, string $type): void
    {
        $permission = $type === 'income' ? 'expenses.create_income' : 'expenses.create_expense';
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    private function openingCategoryId(): int
    {
        return (int) ExpenseCategory::query()
            ->where('type', 'income')
            ->where('slug', 'opening-balance')
            ->valueOrFail('id');
    }

    private function postedOpeningBalanceExists(): bool
    {
        return ExpenseTransaction::query()
            ->posted()
            ->whereHas('category', fn ($query) => $query->where('type', 'income')->where('slug', 'opening-balance'))
            ->exists();
    }

    private function ensureAttachmentExists(ExpenseTransaction $expense): void
    {
        abort_unless($expense->attachment_path && Storage::disk('local')->exists($expense->attachment_path), 404);
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
