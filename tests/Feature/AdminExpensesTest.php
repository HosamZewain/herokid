<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\ExpenseTransaction;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminExpensesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_menu_is_hidden_and_dashboard_is_forbidden_without_permission(): void
    {
        $admin = $this->adminWithPermissions(['dashboard.view']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard.index'))
            ->assertOk()
            ->assertDontSee('المصروفات');

        $this->actingAs($admin)->get(route('admin.expenses.index'))->assertForbidden();
    }

    public function test_authorized_admin_can_view_empty_dashboard_and_seeded_categories(): void
    {
        $admin = $this->adminWithPermissions(['expenses.view']);

        $this->actingAs($admin)
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee('تسجيل الوارد والصادر ومتابعة الرصيد')
            ->assertSee('لا توجد عمليات مالية بعد');

        $this->assertDatabaseHas('expense_categories', ['type' => 'income', 'slug' => 'opening-balance', 'name' => 'رصيد افتتاحي']);
        $this->assertDatabaseHas('expense_categories', ['type' => 'expense', 'slug' => 'printing-ink', 'name' => 'أحبار وطباعة']);
    }

    public function test_opening_balance_income_and_expense_calculate_balance_correctly(): void
    {
        $admin = $this->adminWithPermissions(['expenses.view', 'expenses.create_income', 'expenses.create_expense']);

        $this->actingAs($admin)->post(route('admin.expenses.store'), [
            'kind' => 'opening',
            'type' => 'income',
            'transaction_date' => today()->toDateString(),
            'amount' => 100000,
            'description' => 'ignored opening description',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.expenses.store'), [
            'kind' => 'income',
            'type' => 'income',
            'transaction_date' => today()->toDateString(),
            'amount' => 2500,
            'category_id' => $this->category('income', 'owner-funding')->id,
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.expenses.store'), [
            'kind' => 'expense',
            'type' => 'expense',
            'transaction_date' => today()->toDateString(),
            'amount' => 5000,
            'category_id' => $this->category('expense', 'printing-ink')->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('expense_transactions', [
            'type' => 'income',
            'amount' => 100000,
            'description' => 'ignored opening description',
            'status' => 'posted',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee('١٠٢,٥٠٠ ج.م')
            ->assertSee('٥,٠٠٠ ج.م')
            ->assertSee('٩٧,٥٠٠ ج.م');
    }

    public function test_second_opening_balance_requires_explicit_confirmation(): void
    {
        $admin = $this->adminWithPermissions(['expenses.create_income']);
        $this->createTransaction($admin, 'income', 100, $this->category('income', 'opening-balance'));

        $payload = [
            'kind' => 'opening',
            'type' => 'income',
            'transaction_date' => today()->toDateString(),
            'amount' => 200,
        ];

        $this->actingAs($admin)
            ->post(route('admin.expenses.store'), $payload)
            ->assertSessionHasErrors('confirm_existing_opening_balance');

        $this->actingAs($admin)
            ->post(route('admin.expenses.store'), $payload + ['confirm_existing_opening_balance' => 1])
            ->assertRedirect();

        $this->assertSame(2, ExpenseTransaction::query()->count());
    }

    public function test_negative_amount_and_category_type_mismatch_are_rejected(): void
    {
        $admin = $this->adminWithPermissions(['expenses.create_expense']);

        $this->actingAs($admin)->post(route('admin.expenses.store'), [
            'kind' => 'expense',
            'type' => 'expense',
            'transaction_date' => today()->toDateString(),
            'amount' => -1,
            'category_id' => $this->category('income', 'sales')->id,
        ])->assertSessionHasErrors(['amount', 'category_id']);

        $this->assertDatabaseCount('expense_transactions', 0);
    }

    public function test_date_category_and_payment_filters_control_period_results(): void
    {
        $admin = $this->adminWithPermissions(['expenses.view', 'expenses.view_reports']);
        $ink = $this->category('expense', 'printing-ink');
        $paper = $this->category('expense', 'paper-materials');

        $this->createTransaction($admin, 'expense', 100, $ink, today()->subDays(2)->toDateString(), 'cash');
        $this->createTransaction($admin, 'expense', 250, $paper, today()->subDays(2)->toDateString(), 'card');
        $this->createTransaction($admin, 'expense', 500, $ink, today()->subDays(20)->toDateString(), 'cash');

        $this->actingAs($admin)
            ->get(route('admin.expenses.index', [
                'date_preset' => 'last_7_days',
                'type' => 'expense',
                'category_id' => $ink->id,
                'payment_method' => 'cash',
            ]))
            ->assertOk()
            ->assertSee('١٠٠ ج.م')
            ->assertDontSee('٢٥٠ ج.م')
            ->assertDontSee('٥٠٠ ج.م');
    }

    public function test_attachment_is_stored_privately_and_requires_separate_permissions(): void
    {
        Storage::fake('local');
        $creator = $this->adminWithPermissions(['expenses.create_expense', 'expenses.view']);

        $this->actingAs($creator)->post(route('admin.expenses.store'), [
            'kind' => 'expense',
            'type' => 'expense',
            'transaction_date' => today()->toDateString(),
            'amount' => 5000,
            'category_id' => $this->category('expense', 'printing-ink')->id,
            'attachment' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $transaction = ExpenseTransaction::query()->firstOrFail();
        $this->assertStringStartsWith('expenses/transactions/'.$transaction->id.'/', $transaction->attachment_path);
        Storage::disk('local')->assertExists($transaction->attachment_path);

        $this->actingAs($creator)
            ->get(route('admin.expenses.attachment', $transaction))
            ->assertForbidden();

        $viewer = $this->adminWithPermissions(['expenses.view_attachments']);
        $response = $this->actingAs($viewer)
            ->get(route('admin.expenses.attachment', $transaction))
            ->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));

        $this->actingAs($viewer)
            ->get(route('admin.expenses.attachment.download', $transaction))
            ->assertForbidden();

        $downloader = $this->adminWithPermissions(['expenses.download_attachments']);
        $this->actingAs($downloader)
            ->get(route('admin.expenses.attachment.download', $transaction))
            ->assertDownload('invoice.pdf');
    }

    public function test_void_requires_reason_and_removes_transaction_from_balance_without_deleting_it(): void
    {
        $admin = $this->adminWithPermissions(['expenses.view', 'expenses.void']);
        $transaction = $this->createTransaction($admin, 'expense', 5000, $this->category('expense', 'printing-ink'));

        $this->actingAs($admin)
            ->post(route('admin.expenses.void', $transaction), [])
            ->assertSessionHasErrors('void_reason');

        $this->actingAs($admin)
            ->post(route('admin.expenses.void', $transaction), ['void_reason' => 'تم تسجيل الفاتورة بالخطأ'])
            ->assertRedirect();

        $this->assertDatabaseHas('expense_transactions', [
            'id' => $transaction->id,
            'status' => 'voided',
            'void_reason' => 'تم تسجيل الفاتورة بالخطأ',
        ]);
        $this->assertDatabaseHas('expense_activity_logs', ['transaction_id' => $transaction->id, 'action' => 'voided']);

        $this->actingAs($admin)
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee('٠ ج.م');
    }

    public function test_edit_and_safe_delete_actions_are_visible_from_the_expenses_list_with_permissions(): void
    {
        $admin = $this->adminWithPermissions(['expenses.view', 'expenses.edit', 'expenses.void']);
        $transaction = $this->createTransaction($admin, 'expense', 500, $this->category('expense', 'printing-ink'));

        $this->actingAs($admin)
            ->get(route('admin.expenses.index', ['date_preset' => 'all']))
            ->assertOk()
            ->assertSee(route('admin.expenses.edit', $transaction), false)
            ->assertSee('تعديل')
            ->assertSee('حذف عملية مسجلة بالخطأ')
            ->assertSee(route('admin.expenses.void', $transaction), false);
    }

    public function test_edit_and_safe_delete_actions_are_hidden_without_their_permissions(): void
    {
        $admin = $this->adminWithPermissions(['expenses.view']);
        $transaction = $this->createTransaction($admin, 'expense', 500, $this->category('expense', 'printing-ink'));

        $this->actingAs($admin)
            ->get(route('admin.expenses.index', ['date_preset' => 'all']))
            ->assertOk()
            ->assertDontSee(route('admin.expenses.edit', $transaction), false)
            ->assertDontSee('حذف عملية مسجلة بالخطأ');
    }

    public function test_voided_transactions_cannot_be_edited(): void
    {
        $admin = $this->adminWithPermissions(['expenses.edit']);
        $transaction = $this->createTransaction($admin, 'income', 100, $this->category('income', 'sales'));
        $transaction->update(['status' => 'voided', 'void_reason' => 'mistake', 'voided_at' => now()]);

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $transaction), [
                'type' => 'income',
                'transaction_date' => today()->toDateString(),
                'amount' => 200,
                'category_id' => $transaction->category_id,
            ])
            ->assertStatus(422);
    }

    public function test_edit_logs_changes_and_replacing_attachment_requires_confirmation(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithPermissions(['expenses.edit']);
        $transaction = $this->createTransaction($admin, 'expense', 100, $this->category('expense', 'paper-materials'));
        $oldPath = 'expenses/transactions/'.$transaction->id.'/old.pdf';
        Storage::disk('local')->put($oldPath, 'old');
        $transaction->update(['attachment_path' => $oldPath, 'attachment_original_name' => 'old.pdf']);

        $payload = [
            'type' => 'expense',
            'transaction_date' => today()->toDateString(),
            'amount' => 150,
            'category_id' => $transaction->category_id,
            'attachment' => UploadedFile::fake()->create('new.pdf', 50, 'application/pdf'),
        ];

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $transaction), $payload)
            ->assertSessionHasErrors('confirm_replace_attachment');

        $this->actingAs($admin)
            ->put(route('admin.expenses.update', $transaction), $payload + ['confirm_replace_attachment' => 1])
            ->assertRedirect();

        $this->assertDatabaseHas('expense_transactions', ['id' => $transaction->id, 'amount' => 150]);
        $this->assertDatabaseHas('expense_activity_logs', ['transaction_id' => $transaction->id, 'action' => 'updated']);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_csv_export_uses_filters_and_does_not_expose_private_path(): void
    {
        $admin = $this->adminWithPermissions(['expenses.export']);
        $transaction = $this->createTransaction($admin, 'income', 300, $this->category('income', 'sales'));
        $transaction->update([
            'description' => '=unsafe',
            'attachment_path' => 'expenses/transactions/secret.pdf',
            'attachment_original_name' => 'receipt.pdf',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.expenses.export', ['date_preset' => 'all']));

        $response->assertOk()->assertDownload();
        $content = $response->streamedContent();
        $this->assertStringContainsString('receipt.pdf', $content);
        $this->assertStringNotContainsString('expenses/transactions/secret.pdf', $content);
        $this->assertStringContainsString("'=unsafe", $content);
    }

    public function test_category_manager_can_create_and_deactivate_categories_without_deleting_history(): void
    {
        $admin = $this->adminWithPermissions(['expenses.manage_categories']);

        $this->actingAs($admin)
            ->post(route('admin.expenses.categories.store'), [
                'type' => 'expense',
                'name' => 'ضيافة',
                'sort_order' => 5,
            ])
            ->assertRedirect();

        $category = ExpenseCategory::query()->where('name', 'ضيافة')->firstOrFail();
        $this->actingAs($admin)
            ->put(route('admin.expenses.categories.update', $category), [
                'name' => 'ضيافة المكتب',
                'sort_order' => 6,
                'is_active' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'name' => 'ضيافة المكتب', 'is_active' => false]);
    }

    private function adminWithPermissions(array $permissionKeys): User
    {
        $admin = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));

        return $admin->refresh();
    }

    private function category(string $type, string $slug): ExpenseCategory
    {
        return ExpenseCategory::query()->where('type', $type)->where('slug', $slug)->firstOrFail();
    }

    private function createTransaction(
        User $admin,
        string $type,
        float $amount,
        ExpenseCategory $category,
        ?string $date = null,
        ?string $paymentMethod = null,
    ): ExpenseTransaction {
        return ExpenseTransaction::create([
            'type' => $type,
            'transaction_date' => $date ?? today()->toDateString(),
            'amount' => $amount,
            'currency' => 'EGP',
            'category_id' => $category->id,
            'payment_method' => $paymentMethod,
            'status' => 'posted',
            'created_by_user_id' => $admin->id,
        ]);
    }
}
