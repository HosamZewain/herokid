<?php

namespace Tests\Feature;

use App\Models\LibraryStory;
use App\Models\Permission;
use App\Models\User;
use App\Support\AdminPermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Tests\TestCase;

class PublicStoryLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['story_library.disk' => 'local']);
    }

    private function admin(bool $manage = true): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->permissions()->sync(Permission::whereIn('key', $manage ? ['story_library.view', 'story_library.manage'] : ['story_library.view'])->pluck('id'));

        return $admin;
    }

    private function pdf(int $pages = 2, bool $encrypted = false): UploadedFile
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        if ($encrypted) {
            $pdf->SetProtection(['print'], 'test-password', 'test-owner');
        }
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $pdf->AddPage();
            }
            $pdf->WriteHTML('<h1>Synthetic story page '.$i.'</h1><p>A safe public test story.</p>');
        }

        return UploadedFile::fake()->createWithContent('test-story.pdf', $pdf->Output('', 'S'));
    }

    private function data(array $extra = []): array
    {
        return [...['title' => 'حكاية تجريبية', 'slug' => 'test-story', 'description' => 'مغامرة لطيفة للأطفال',
            'category' => 'مغامرات', 'language' => 'ar', 'reading_direction' => 'rtl', 'age_min' => 4, 'age_max' => 9,
            'status' => 'draft', 'sort_order' => 0], ...$extra];
    }

    private function story(array $extra = []): LibraryStory
    {
        $file = $this->pdf();
        $path = $file->storeAs('story-library/test', Str::uuid().'.pdf', 'local');

        return LibraryStory::create([...$this->data(), 'slug' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(),
            'disk' => 'local', 'pdf_path' => $path, 'page_count' => 2, 'file_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()), 'status' => 'published', 'published_at' => now(), ...$extra]);
    }

    public function test_admin_can_upload_draft_publish_and_guests_can_browse_and_read_without_login(): void
    {
        $this->actingAs($this->admin())->get(route('admin.story-library.create'))->assertOk()->assertSee('إضافة قصة للمكتبة');
        $this->post(route('admin.story-library.store'), [...$this->data(), 'pdf_file' => $this->pdf(), 'cover' => UploadedFile::fake()->image('cover.jpg', 100, 140)])
            ->assertRedirect();
        $story = LibraryStory::sole();
        $this->assertSame(2, $story->page_count);
        $this->assertSame('draft', $story->status);
        Storage::disk('local')->assertExists($story->pdf_path);
        $this->get(route('admin.story-library.document', $story))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->put(route('admin.story-library.update', $story), $this->data(['status' => 'published', 'publication_confirmed' => 1]))->assertRedirect();
        $this->assertNotNull($story->fresh()->published_at);
        auth()->logout();
        $this->get(route('story-library.index'))->assertOk()->assertSee('حكاية تجريبية');
        $this->get(route('story-library.show', $story))->assertOk()->assertSee('ابدأ قراءة القصة');
        $this->get(route('story-library.read', $story))->assertOk()->assertSee('data-booklet-reader', false)
            ->assertSee('data-mobile-single-page="true"', false)
            ->assertSee('data-reading-direction="rtl"', false)->assertSee('قراءة مجانية')->assertDontSee('معاينة خاصة للعرض فقط');
        $this->get(route('story-library.document', $story))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('story-library.cover', $story))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/about')->assertOk()->assertSee(route('story-library.index'), false);
    }

    public function test_drafts_deleted_and_unpublished_stories_never_leak_on_public_web_or_api(): void
    {
        $draft = $this->story(['status' => 'draft']);
        $deleted = $this->story();
        $deleted->delete();
        $future = $this->story(['published_at' => now()->addDay()]);
        foreach ([$draft, $deleted, $future] as $story) {
            foreach (['show', 'read', 'document', 'cover'] as $route) {
                $this->get(route('story-library.'.$route, $story))->assertNotFound();
            }
            $this->getJson(route('api.story-library.show', $story))->assertNotFound();
            $this->get(route('api.story-library.document', $story))->assertNotFound();
        }
        $this->getJson(route('api.story-library.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_public_api_has_safe_stable_document_contract_filters_and_range_requests(): void
    {
        $story = $this->story();
        $this->story(['title' => 'English sample', 'language' => 'en', 'category' => 'Learning', 'age_min' => 10, 'age_max' => 12]);
        $this->getJson(route('api.story-library.index', ['category' => 'مغامرات', 'language' => 'ar', 'age' => 6, 'q' => 'حكاية']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $story->uuid)->assertJsonPath('meta.total', 1);
        $response = $this->getJson(route('api.story-library.show', $story))->assertOk()
            ->assertJsonPath('data.document_url', route('api.story-library.document', $story))
            ->assertJsonPath('data.reader_url', route('story-library.read', $story))->assertJsonPath('data.page_count', 2)
            ->assertJsonPath('data.version', $story->checksum)->assertJsonMissingPath('data.disk')->assertJsonMissingPath('data.pdf_path');
        $this->assertStringNotContainsString($story->pdf_path, $response->getContent());
        $document = $this->get(route('api.story-library.document', $story), ['Range' => 'bytes=0-7'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-7/'.$story->file_size)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $document->headers->get('Cache-Control'));
        $this->getJson(route('api.story-library.index', ['language' => 'ar', 'age' => 15]))->assertJsonCount(0, 'data');
        $this->getJson(route('api.story-library.index', ['language' => 'invalid']))->assertUnprocessable();
        $this->getJson(route('api.story-library.index', ['age' => -1]))->assertUnprocessable();
    }

    public function test_upload_permission_publish_confirmation_and_private_paths_are_enforced(): void
    {
        $viewer = $this->admin(false);
        $this->actingAs($viewer)->get(route('admin.story-library.index'))->assertOk()->assertSee('مكتبة القصص');
        $this->get(route('admin.story-library.create'))->assertForbidden();
        $this->post(route('admin.story-library.store'), [])->assertForbidden();
        $this->actingAs($this->admin())->post(route('admin.story-library.store'), [...$this->data(['status' => 'published']), 'pdf_file' => $this->pdf()])->assertSessionHasErrors('publication_confirmed');
        $this->assertDatabaseCount('library_stories', 0);
        $this->post(route('admin.story-library.store'), [...$this->data(['status' => 'published', 'publication_confirmed' => 1]), 'pdf_file' => $this->pdf(), 'disk' => 'public', 'pdf_path' => '/secret/customer.pdf'])->assertRedirect();
        $story = LibraryStory::sole();
        $this->assertSame('local', $story->disk);
        $this->assertStringStartsWith('story-library/', $story->pdf_path);
        $this->assertSame('admin.story-library.index', AdminPermissionRegistry::firstAllowedRoute(['story_library.view']));
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get(route('admin.story-library.index'))->assertForbidden();
    }

    public function test_corrupt_encrypted_oversized_and_over_page_limit_files_are_rejected(): void
    {
        $this->actingAs($this->admin());
        foreach ([UploadedFile::fake()->createWithContent('bad.pdf', '%PDF-1.4 broken'), $this->pdf(1, true), UploadedFile::fake()->create('large.pdf', 51201, 'application/pdf')] as $file) {
            $this->post(route('admin.story-library.store'), [...$this->data(), 'pdf_file' => $file])->assertSessionHasErrors('pdf_file');
        }
        config(['story_library.max_pages' => 1]);
        $this->post(route('admin.story-library.store'), [...$this->data(), 'pdf_file' => $this->pdf(2)])->assertSessionHasErrors('pdf_file');
        $this->assertDatabaseCount('library_stories', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('story-library'));
    }

    public function test_pdf_replacement_updates_version_invalid_update_preserves_original_and_unpublish_blocks_access(): void
    {
        $story = $this->story();
        $oldPath = $story->pdf_path;
        $oldChecksum = $story->checksum;
        $this->actingAs($this->admin())->put(route('admin.story-library.update', $story), [...$this->data(['slug' => $story->slug, 'status' => 'published', 'publication_confirmed' => 1]), 'pdf_file' => UploadedFile::fake()->createWithContent('bad.pdf', '%PDF-broken')])->assertSessionHasErrors('pdf_file');
        $this->assertSame($oldPath, $story->fresh()->pdf_path);
        $this->put(route('admin.story-library.update', $story), [...$this->data(['slug' => $story->slug, 'status' => 'published', 'publication_confirmed' => 1]), 'pdf_file' => $this->pdf(3)])->assertRedirect();
        $this->assertNotSame($oldPath, $story->fresh()->pdf_path);
        $this->assertNotSame($oldChecksum, $story->fresh()->checksum);
        $this->assertSame(3, $story->fresh()->page_count);
        Storage::disk('local')->assertExists($oldPath);
        $this->put(route('admin.story-library.update', $story), $this->data(['slug' => $story->slug, 'status' => 'draft']))->assertRedirect();
        auth()->logout();
        $response = $this->get(route('story-library.document', $story))->assertNotFound();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_removal_requires_confirmation_and_reason_and_preserves_private_file(): void
    {
        $story = $this->story();
        $this->actingAs($this->admin())->delete(route('admin.story-library.destroy', $story), [])->assertSessionHasErrors(['confirmed', 'reason']);
        $this->assertDatabaseCount('library_stories', 1);
        $this->delete(route('admin.story-library.destroy', $story), ['confirmed' => 1, 'reason' => 'مراجعة المحتوى'])->assertRedirect(route('admin.story-library.index'));
        $this->assertSoftDeleted($story);
        Storage::disk('local')->assertExists($story->pdf_path);
        $this->get(route('story-library.document', $story))->assertNotFound();
    }

    public function test_catalog_paginates_and_does_not_require_login_or_publish_other_content(): void
    {
        $template = $this->story();
        foreach (range(1, 25) as $index) {
            $this->storyCopy($template, $index);
        }
        $this->getJson(route('api.story-library.index'))->assertOk()->assertJsonCount(24, 'data')->assertJsonPath('meta.total', 26)->assertJsonPath('meta.last_page', 2);
        $this->getJson(route('api.story-library.index', ['page' => 2]))->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stories', 0);
    }

    private function storyCopy(LibraryStory $template, int $index): void
    {
        $copy = $template->replicate();
        $copy->uuid = (string) Str::uuid();
        $copy->slug = 'copy-'.$index;
        $copy->save();
    }
}
