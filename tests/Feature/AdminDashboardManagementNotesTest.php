<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\AdminDashboardNote;
use App\Models\AdminDashboardNoteAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDashboardManagementNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_management_notes_section_to_every_dashboard_admin(): void
    {
        $author = $this->admin();
        $viewer = $this->admin();
        AdminDashboardNote::create(['body' => 'مراجعة طلبات الطباعة غدًا', 'created_by' => $author->id]);

        $this->actingAs($viewer)->get(route('admin.dashboard.index'))
            ->assertOk()
            ->assertSee('ملاحظات الإدارة')
            ->assertSee('مراجعة طلبات الطباعة غدًا')
            ->assertSee($author->name)
            ->assertSee('name="attachments[]"', false);
    }

    public function test_admin_adds_multiple_independent_notes_without_overwriting_previous_notes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.dashboard.management-notes.store'), [
            'body' => 'الملاحظة الأولى',
        ])->assertRedirect(route('admin.dashboard.index').'#management-notes');

        $this->actingAs($admin)->post(route('admin.dashboard.management-notes.store'), [
            'body' => 'الملاحظة الثانية',
        ])->assertRedirect(route('admin.dashboard.index').'#management-notes');

        $this->assertSame(['الملاحظة الثانية', 'الملاحظة الأولى'], AdminDashboardNote::latest('id')->pluck('body')->all());
        $this->actingAs($admin)->get(route('admin.dashboard.index'))
            ->assertSeeInOrder(['الملاحظة الثانية', 'الملاحظة الأولى']);
    }

    public function test_note_accepts_multiple_private_attachments_and_download_requires_admin_access(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.dashboard.management-notes.store'), [
            'body' => 'راجع المرفقات',
            'attachments' => [
                UploadedFile::fake()->image('preview.jpg'),
                UploadedFile::fake()->create('instructions.txt', 4, 'text/plain'),
            ],
        ])->assertSessionHasNoErrors();

        $note = AdminDashboardNote::with('attachments')->firstOrFail();
        $this->assertCount(2, $note->attachments);
        foreach ($note->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
            Storage::disk('public')->assertMissing($attachment->path);
        }

        $attachment = $note->attachments->first();
        $this->actingAs($admin)
            ->get(route('admin.dashboard.management-notes.attachments.download', $attachment))
            ->assertOk()
            ->assertDownload($attachment->original_name)
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        auth()->logout();
        $this->get(route('admin.dashboard.management-notes.attachments.download', $attachment))
            ->assertRedirect(route('login'));
    }

    public function test_note_may_contain_only_attachments_but_cannot_be_completely_empty(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.dashboard.management-notes.store'), [
            'attachments' => [UploadedFile::fake()->image('only-file.png')],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('admin_dashboard_notes', 1);
        $this->assertDatabaseCount('admin_dashboard_note_attachments', 1);

        $this->actingAs($admin)->from(route('admin.dashboard.index'))
            ->post(route('admin.dashboard.management-notes.store'), [])
            ->assertSessionHasErrors(['body', 'attachments']);
        $this->assertDatabaseCount('admin_dashboard_notes', 1);
    }

    public function test_unsafe_or_excessive_attachments_are_rejected(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.dashboard.management-notes.store'), [
            'body' => 'ملف غير آمن',
            'attachments' => [UploadedFile::fake()->create('program.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors('attachments.0');

        $this->actingAs($admin)->post(route('admin.dashboard.management-notes.store'), [
            'body' => 'ملفات كثيرة',
            'attachments' => collect(range(1, 6))->map(fn (int $number) => UploadedFile::fake()->image("file-{$number}.jpg"))->all(),
        ])->assertSessionHasErrors('attachments');

        $this->assertDatabaseCount('admin_dashboard_notes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_customer_cannot_create_or_download_management_note_attachments(): void
    {
        Storage::fake('local');
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $attachment = AdminDashboardNoteAttachment::create([
            'admin_dashboard_note_id' => AdminDashboardNote::create(['body' => 'خاص بالإدارة'])->id,
            'disk' => 'local',
            'path' => 'admin/dashboard-management-notes/private.txt',
            'original_name' => 'private.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
        ]);
        Storage::disk('local')->put($attachment->path, 'private');

        $this->actingAs($customer)->post(route('admin.dashboard.management-notes.store'), ['body' => 'غير مسموح'])
            ->assertForbidden();
        $this->actingAs($customer)->get(route('admin.dashboard.management-notes.attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_activity_log_records_metadata_without_note_body_or_file_contents(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $secretText = 'تفاصيل داخلية لا تسجل في سجل النشاط';

        $this->actingAs($admin)->post(route('admin.dashboard.management-notes.store'), [
            'body' => $secretText,
            'attachments' => [UploadedFile::fake()->create('team.txt', 2, 'text/plain')],
        ])->assertSessionHasNoErrors();

        $log = AdminActivityLog::where('action', 'admin.dashboard_management_note.created')->firstOrFail();
        $this->assertSame(1, $log->properties['attachment_count']);
        $this->assertSame(['team.txt'], $log->properties['attachment_names']);
        $this->assertStringNotContainsString($secretText, json_encode($log->properties, JSON_UNESCAPED_UNICODE));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }
}
