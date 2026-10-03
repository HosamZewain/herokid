<?php

namespace Tests\Feature;

use App\Models\ExpenseActivityLog;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseTransaction;
use App\Models\Permission;
use App\Models\User;
use App\Services\Expenses\ExpenseLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminExpenseEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('transactionTypes')]
    public function test_inline_category_update_preserves_financial_values_and_all_attachments(string $type): void
    {
        Storage::fake('local');
        $admin = $this->admin(['expenses.view', 'expenses.edit', 'expenses.view_reports']);
        $transaction = $this->transaction($admin, $type);
        $transaction->update(['attachment_path' => 'old.pdf', 'attachment_original_name' => 'old.pdf', 'notes' => 'ملاحظة محفوظة']);
        Storage::disk('local')->put('old.pdf', 'invoice');
        $attachment = $transaction->attachments()->create(['disk' => 'local', 'path' => 'extra.pdf', 'original_name' => 'extra.pdf', 'size' => 7]);
        Storage::disk('local')->put('extra.pdf', 'invoice');
        $before = $transaction->fresh()->getAttributes();
        $ledger = app(ExpenseLedgerService::class);
        $balance = $ledger->dashboard(['date_preset' => 'all']);
        $category = ExpenseCategory::where('type', $type)->where('id', '!=', $transaction->category_id)->firstOrFail();

        $this->actingAs($admin)->patchJson(route('admin.expenses.category.update', $transaction), [
            'category_id' => $category->id, 'expected_category_id' => $transaction->category_id,
            'amount' => 9999, 'type' => $type === 'income' ? 'expense' : 'income', 'status' => 'voided', 'notes' => 'malicious edit',
        ])->assertOk()->assertJsonPath('category_id', $category->id);

        $after = $transaction->fresh()->getAttributes();
        unset($before['category_id'], $after['category_id'], $before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($balance, $ledger->dashboard(['date_preset' => 'all']));
        $this->assertSame($category->id, $ledger->categoryBreakdown(['date_preset' => 'all'])[$type]->sole()->category_id);
        $log = $transaction->activityLogs()->sole();
        $this->assertSame($category->id, $log->new_values_json['category_id']);
        $this->assertSame($before['amount'], $log->old_values_json['amount']);
        Storage::disk('local')->assertExists(['old.pdf', $attachment->path]);
    }

    public static function transactionTypes(): array
    {
        return [['income'], ['expense']];
    }

    #[DataProvider('invalidCategoryStates')]
    public function test_inline_update_rejects_invalid_or_stale_changes(string $state, int $status): void
    {
        $admin = $this->admin(['expenses.edit']);
        $transaction = $this->transaction($admin);
        $category = ExpenseCategory::where('type', $state === 'wrong_type' ? 'income' : 'expense')->where('id', '!=', $transaction->category_id)->firstOrFail();
        if ($state === 'inactive') {
            $category->update(['is_active' => false]);
        }
        if ($state === 'voided') {
            $transaction->update(['status' => 'voided']);
        }
        $before = $transaction->fresh()->getAttributes();

        $this->actingAs($admin)->patchJson(route('admin.expenses.category.update', $transaction), [
            'category_id' => $state === 'missing' ? 999999 : $category->id,
            'expected_category_id' => $state === 'stale' ? 999999 : $transaction->category_id,
        ])->assertStatus($status);

        $this->assertSame($before, $transaction->fresh()->getAttributes());
        $this->assertSame(0, $transaction->activityLogs()->count());
    }

    public static function invalidCategoryStates(): array
    {
        return [['wrong_type', 422], ['inactive', 422], ['missing', 422], ['voided', 422], ['stale', 409]];
    }

    public function test_current_inactive_category_is_allowed_as_noop_without_duplicate_audit(): void
    {
        $admin = $this->admin(['expenses.edit']);
        $transaction = $this->transaction($admin);
        $transaction->category->update(['is_active' => false]);
        $this->actingAs($admin)->patchJson(route('admin.expenses.category.update', $transaction), [
            'category_id' => $transaction->category_id, 'expected_category_id' => $transaction->category_id,
        ])->assertOk();
        $this->assertSame(0, $transaction->activityLogs()->count());
    }

    public function test_inline_edit_requires_permission_and_login(): void
    {
        $admin = $this->admin(['expenses.view']);
        $transaction = $this->transaction($admin);
        $payload = ['category_id' => $transaction->category_id, 'expected_category_id' => $transaction->category_id];
        $this->patchJson(route('admin.expenses.category.update', $transaction), $payload)->assertUnauthorized();
        $this->actingAs($admin)->patchJson(route('admin.expenses.category.update', $transaction), $payload)->assertForbidden();
        $this->get(route('admin.expenses.index', ['date_preset' => 'all']))->assertOk()->assertDontSee('data-expense-category-form', false);
    }

    public function test_listing_exposes_selects_only_for_posted_rows_and_same_type_active_categories(): void
    {
        $admin = $this->admin(['expenses.view', 'expenses.edit']);
        $transaction = $this->transaction($admin);
        $transaction->category->update(['is_active' => false]);
        $voided = $this->transaction($admin);
        $voided->update(['status' => 'voided']);
        $inactive = ExpenseCategory::where('type', 'expense')->where('id', '!=', $transaction->category_id)->firstOrFail();
        $inactive->update(['is_active' => false]);
        $html = $this->actingAs($admin)->get(route('admin.expenses.index', ['date_preset' => 'all']))->assertOk()->getContent();
        preg_match_all('/<select[^>]*data-expense-category[^>]*>(.*?)<\/select>/s', $html, $matches);
        $this->assertCount(2, $matches[1]); // desktop + mobile
        foreach ($matches[1] as $options) {
            $this->assertStringContainsString('(غير فعال)', $options);
            $this->assertStringNotContainsString('value="'.$inactive->id.'"', $options);
            foreach (ExpenseCategory::where('type', 'income')->pluck('id') as $id) {
                $this->assertStringNotContainsString('value="'.$id.'"', $options);
            }
        }
    }

    public function test_date_presets_and_creation_default_use_cairo_day_and_month_not_utc(): void
    {
        config(['display.timezone' => 'Africa/Cairo']);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 22:30:00', 'UTC'));
        $admin = $this->admin(['expenses.view', 'expenses.create_income']);
        $transaction = $this->transaction($admin, 'income');
        $transaction->update(['transaction_date' => '2026-10-01']);
        $ledger = app(ExpenseLedgerService::class);
        $this->assertSame(['2026-10-01', '2026-10-01'], $ledger->dateRange(['date_preset' => 'today']));
        $this->assertSame(['2026-10-01', '2026-10-31'], $ledger->dateRange([]));
        $this->assertSame(123.45, $ledger->dashboard([])['month_income']);
        $this->actingAs($admin)->get(route('admin.expenses.create', 'income'))->assertOk()->assertSee('value="2026-10-01"', false);
    }

    #[DataProvider('transactionTypes')]
    public function test_edit_screen_opens_existing_transactions_with_and_without_legacy_invoice(string $type): void
    {
        $admin = $this->admin(['expenses.edit']);
        $transaction = $this->transaction($admin, $type);
        $this->actingAs($admin)->get(route('admin.expenses.edit', $transaction))->assertOk()->assertSee('حفظ التعديلات')->assertSee('123.45');
        $transaction->update(['attachment_path' => 'historical.pdf', 'attachment_original_name' => 'historical.pdf']);
        $this->get(route('admin.expenses.edit', $transaction))->assertOk()->assertSee('historical.pdf');
        $this->assertSame('123.45', $transaction->fresh()->amount);
    }

    public function test_multiple_private_invoices_are_appended_and_legacy_attachment_is_preserved(): void
    {
        Storage::fake('s3_private');
        config(['media.private_disk' => 's3_private']);
        $admin = $this->admin(['expenses.create_expense', 'expenses.edit', 'expenses.view', 'expenses.view_attachments', 'expenses.download_attachments', 'expenses.export']);
        $payload = $this->payload();
        $this->actingAs($admin)->post(route('admin.expenses.store'), $payload + [
            'attachment' => UploadedFile::fake()->create('legacy.pdf', 10, 'application/pdf'),
            'attachments' => [UploadedFile::fake()->create('invoice-one.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('invoice-two.png')],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $transaction = ExpenseTransaction::sole();
        $legacyPath = $transaction->attachment_path;
        $oldIds = $transaction->attachments->modelKeys();
        $this->put(route('admin.expenses.update', $transaction), $payload + [
            'attachments' => [UploadedFile::fake()->create('invoice-three.pdf', 10, 'application/pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $transaction->refresh();
        $this->assertSame($legacyPath, $transaction->attachment_path);
        $this->assertCount(3, $transaction->attachments);
        $this->assertSame($oldIds, $transaction->attachments->take(2)->modelKeys());
        $this->assertSame('123.45', $transaction->amount);
        Storage::disk('s3_private')->assertExists($legacyPath);
        foreach ($transaction->attachments as $attachment) {
            $this->assertSame('s3_private', $attachment->disk);
            $this->assertSame($admin->id, $attachment->uploaded_by_user_id);
            Storage::disk('s3_private')->assertExists($attachment->path);
            $this->get(route('admin.expenses.attachments.show', [$transaction, $attachment]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->get(route('admin.expenses.attachments.download', [$transaction, $attachment]))->assertOk()->assertDownload($attachment->original_name);
        }
        $this->get(route('admin.expenses.show', $transaction))->assertOk()->assertSee('legacy.pdf')->assertSee('invoice-one.pdf')->assertSee('invoice-three.pdf');
        $export = $this->get(route('admin.expenses.export', ['date_preset' => 'all']))->streamedContent();
        $this->assertStringContainsString('invoice-three.pdf', $export);
        $this->assertStringNotContainsString($transaction->attachments->first()->path, $export);
    }

    public function test_multiple_attachments_are_optional_and_hostinger_default_is_local(): void
    {
        Storage::fake('local');
        $this->assertSame('local', config('media.private_disk'));
        $admin = $this->admin(['expenses.create_income']);
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->payload('income'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('expense_attachments', 0);
        $this->post(route('admin.expenses.store'), $this->payload('income') + ['attachments' => [UploadedFile::fake()->image('invoice.png')]])->assertRedirect()->assertSessionHasNoErrors();
        $attachment = ExpenseAttachment::sole();
        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_multiple_uploads_reject_whole_operation(string $case): void
    {
        Storage::fake('local');
        $admin = $this->admin(['expenses.create_expense']);
        $files = match ($case) {
            'type' => [UploadedFile::fake()->create('script.js', 1, 'application/javascript')],
            'size' => [UploadedFile::fake()->create('huge.pdf', 5121, 'application/pdf')],
            'count' => array_map(fn () => UploadedFile::fake()->image('invoice.png'), range(1, 21)),
        };
        $this->actingAs($admin)->post(route('admin.expenses.store'), $this->payload() + ['attachments' => $files])->assertSessionHasErrors();
        $this->assertDatabaseCount('expense_transactions', 0);
        $this->assertDatabaseCount('expense_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function invalidUploads(): array
    {
        return [['type'], ['size'], ['count']];
    }

    public function test_attachment_access_is_private_and_scoped_to_correct_transaction(): void
    {
        Storage::fake('local');
        $admin = $this->admin(['expenses.view_attachments', 'expenses.download_attachments']);
        $transaction = $this->transaction($admin);
        $other = $this->transaction($admin);
        $attachment = $transaction->attachments()->create(['disk' => 'local', 'path' => 'invoice.pdf', 'original_name' => 'invoice.pdf', 'size' => 7, 'mime' => 'application/pdf']);
        Storage::disk('local')->put('invoice.pdf', 'invoice');
        $this->getJson(route('admin.expenses.attachments.show', [$transaction, $attachment]))->assertUnauthorized();
        $this->actingAs($admin)->get(route('admin.expenses.attachments.show', [$other, $attachment]))->assertNotFound();
        $this->get(route('admin.expenses.attachments.download', [$other, $attachment]))->assertNotFound();
        $this->actingAs($this->admin(['expenses.view']))->get(route('admin.expenses.attachments.show', [$transaction, $attachment]))->assertForbidden();
        $this->get(route('admin.expenses.attachments.download', [$transaction, $attachment]))->assertForbidden();
    }

    public function test_voided_operation_keeps_all_attachments_and_rejects_additions(): void
    {
        Storage::fake('local');
        $admin = $this->admin(['expenses.edit', 'expenses.void', 'expenses.view_attachments']);
        $transaction = $this->transaction($admin);
        $attachment = $transaction->attachments()->create(['disk' => 'local', 'path' => 'invoice.pdf', 'original_name' => 'invoice.pdf', 'size' => 7]);
        Storage::disk('local')->put('invoice.pdf', 'invoice');
        $this->actingAs($admin)->post(route('admin.expenses.void', $transaction), ['void_reason' => 'تم التسجيل بالخطأ'])->assertRedirect();
        $this->put(route('admin.expenses.update', $transaction), $this->payload() + ['attachments' => [UploadedFile::fake()->image('new.png')]])->assertStatus(422);
        $this->assertSame(1, $transaction->attachments()->count());
        $this->get(route('admin.expenses.attachments.show', [$transaction, $attachment]))->assertOk();
        $this->assertSame(0.0, app(ExpenseLedgerService::class)->dashboard([])['current_balance']);
    }

    public function test_failed_audit_rolls_back_new_transaction_and_cleans_all_new_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin([]);
        ExpenseActivityLog::creating(fn () => throw new RuntimeException('simulated audit failure'));
        try {
            app(ExpenseLedgerService::class)->create($this->payload(), $admin, UploadedFile::fake()->image('legacy.png'), [UploadedFile::fake()->image('new.png')]);
            $this->fail('Expected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('expense_transactions', 0);
        $this->assertDatabaseCount('expense_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_update_preserves_original_invoice_and_values_and_cleans_new_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin([]);
        $transaction = $this->transaction($admin);
        $transaction->update(['attachment_path' => 'original.pdf', 'attachment_original_name' => 'original.pdf']);
        Storage::disk('local')->put('original.pdf', 'invoice');
        ExpenseActivityLog::creating(fn () => throw new RuntimeException('simulated audit failure'));
        try {
            app(ExpenseLedgerService::class)->update($transaction, array_replace($this->payload(), ['amount' => '999.00']), $admin, UploadedFile::fake()->image('replacement.png'), [UploadedFile::fake()->image('extra.png')]);
            $this->fail('Expected failure');
        } catch (RuntimeException) {
        }
        $this->assertSame('original.pdf', $transaction->fresh()->attachment_path);
        $this->assertSame('123.45', $transaction->fresh()->amount);
        $this->assertDatabaseCount('expense_attachments', 0);
        $this->assertSame(['original.pdf'], Storage::disk('local')->allFiles());
    }

    public function test_original_invoice_is_only_removed_after_successful_audit_and_commit(): void
    {
        Storage::fake('local');
        $admin = $this->admin([]);
        $transaction = $this->transaction($admin);
        $transaction->update(['attachment_path' => 'original.pdf', 'attachment_original_name' => 'original.pdf']);
        Storage::disk('local')->put('original.pdf', 'invoice');
        ExpenseActivityLog::creating(function (): void {
            Storage::disk('local')->assertExists('original.pdf');
        });
        app(ExpenseLedgerService::class)->update($transaction, $this->payload(), $admin, UploadedFile::fake()->image('replacement.png'));
        Storage::disk('local')->assertMissing('original.pdf');
        Storage::disk('local')->assertExists($transaction->fresh()->attachment_path);
    }

    public function test_failed_inline_audit_rolls_back_category(): void
    {
        $admin = $this->admin([]);
        $transaction = $this->transaction($admin);
        $categoryId = $transaction->category_id;
        $next = ExpenseCategory::where('type', 'expense')->where('id', '!=', $categoryId)->firstOrFail();
        ExpenseActivityLog::creating(fn () => throw new RuntimeException('simulated audit failure'));
        try {
            app(ExpenseLedgerService::class)->updateCategory($transaction, $next->id, $categoryId, $admin);
            $this->fail('Expected failure');
        } catch (RuntimeException) {
        }
        $this->assertSame($categoryId, $transaction->fresh()->category_id);
        $this->assertSame(0, $transaction->activityLogs()->count());
    }

    public function test_financial_amount_cannot_be_silently_rounded(): void
    {
        $admin = $this->admin(['expenses.create_expense']);
        $this->actingAs($admin)->post(route('admin.expenses.store'), array_replace($this->payload(), ['amount' => '123.456']))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('expense_transactions', 0);
    }

    private function admin(array $permissions): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return $admin->refresh();
    }

    private function payload(string $type = 'expense'): array
    {
        return ['kind' => $type, 'type' => $type, 'transaction_date' => '2026-10-03', 'amount' => '123.45', 'category_id' => ExpenseCategory::where('type', $type)->firstOrFail()->id];
    }

    private function transaction(User $admin, string $type = 'expense'): ExpenseTransaction
    {
        $data = $this->payload($type);
        unset($data['kind']);

        return ExpenseTransaction::create($data + ['created_by_user_id' => $admin->id, 'status' => 'posted', 'currency' => 'EGP']);
    }
}
