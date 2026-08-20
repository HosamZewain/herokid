<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use App\Services\BookletPreviews\BookletPreviewManager;
use App\Services\Orders\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mpdf\Mpdf;
use Tests\TestCase;

class MobilePreviewApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_owner_can_read_private_current_version_and_approve_it_immutably(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $preview = app(BookletPreviewManager::class)->createOrReplaceForOrder($order, $this->pdf('v1.pdf', 2), null, null);
        $order->update(['status' => 'preview_uploaded']);
        Sanctum::actingAs($owner, ['mobile']);

        $this->getJson('/api/v1/orders/'.$order->id.'/preview')
            ->assertOk()
            ->assertJsonPath('data.version.id', $preview->current_version_id)
            ->assertJsonPath('data.version.number', 1)
            ->assertJsonPath('data.version.page_count', 2)
            ->assertJsonPath('data.can_decide', true);
        $this->get('/api/v1/orders/'.$order->id.'/preview/document', ['Accept' => 'application/pdf'])
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson('/api/v1/orders/'.$order->id.'/preview/approve', ['preview_version_id' => 999])->assertConflict();
        $this->postJson('/api/v1/orders/'.$order->id.'/preview/approve', ['preview_version_id' => $preview->current_version_id])
            ->assertOk()->assertJsonPath('data.status', 'approved_for_printing');

        $order->refresh();
        $this->assertSame('approved_for_print', $order->status);
        $this->assertSame($preview->current_version_id, $order->approved_booklet_preview_version_id);
        $this->assertNotNull($order->preview_approved_at);
        $this->assertDatabaseHas('booklet_preview_decisions', ['order_id' => $order->id, 'booklet_preview_version_id' => $preview->current_version_id, 'user_id' => $owner->id, 'decision' => 'approved']);
        $this->postJson('/api/v1/orders/'.$order->id.'/preview/approve', ['preview_version_id' => $preview->current_version_id])
            ->assertOk()->assertJsonPath('data.version_id', $preview->current_version_id);
    }

    public function test_revision_is_page_specific_encrypted_and_a_new_version_requires_a_new_decision(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $manager = app(BookletPreviewManager::class);
        $preview = $manager->createOrReplaceForOrder($order, $this->pdf('v1.pdf', 2), null, null);
        $order->update(['status' => 'preview_uploaded']);
        Sanctum::actingAs($owner, ['mobile']);
        $this->postJson('/api/v1/orders/'.$order->id.'/preview/revision', [
            'preview_version_id' => $preview->current_version_id,
            'page_number' => 2,
            'comments' => 'يرجى تعديل لون القميص في هذه الصفحة.',
        ])->assertOk()->assertJsonPath('data.status', 'revision_requested');
        $this->assertSame('revision_requested', $order->refresh()->status);
        $raw = (string) $this->getConnection()->table('booklet_preview_decisions')->value('comments');
        $this->assertStringNotContainsString('لون القميص', $raw);

        $manager->replace($preview->fresh(), $this->pdf('v2.pdf', 3), 'customer revision', null);
        $preview->refresh();
        $order->refresh()->update(['status' => 'preview_uploaded']);
        $this->assertNull($order->fresh()->approved_booklet_preview_version_id);
        $this->getJson('/api/v1/orders/'.$order->id.'/preview')
            ->assertOk()->assertJsonPath('data.version.number', 2)->assertJsonPath('data.decision', null)->assertJsonPath('data.can_decide', true);
        $this->postJson('/api/v1/orders/'.$order->id.'/preview/approve', ['preview_version_id' => $preview->current_version_id])->assertOk();
        $this->assertDatabaseCount('booklet_preview_decisions', 2);
    }

    public function test_preview_and_decisions_are_owner_scoped_and_printing_is_blocked_without_current_approval(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->order($owner);
        app(BookletPreviewManager::class)->createOrReplaceForOrder($order, $this->pdf(), null, null);
        $order->update(['status' => 'preview_uploaded']);
        Sanctum::actingAs($other, ['mobile']);
        $this->getJson('/api/v1/orders/'.$order->id.'/preview')->assertNotFound();
        $this->postJson('/api/v1/orders/'.$order->id.'/preview/approve', ['preview_version_id' => 1])->assertNotFound();

        $this->expectException(ValidationException::class);
        $request = Request::create('/admin/orders/'.$order->id, 'PATCH');
        $request->setUserResolver(fn () => $owner);
        app(OrderStatusService::class)->update($order, 'printing', null, $request);
    }

    private function order(User $owner): Order
    {
        $story = Story::create(['title' => 'قصة المعاينة', 'slug' => 'mobile-preview-'.uniqid(), 'language' => 'ar', 'gender' => 'both', 'price' => 300, 'active' => true]);

        return Order::create([
            'order_number' => 'HK-MOB-PRE-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $owner->id,
            'parent_name' => $owner->name,
            'story_id' => $story->id,
            'child_name' => 'مريم',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '201000000000'],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
    }

    private function pdf(string $name = 'preview.pdf', int $pages = 1): UploadedFile
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        for ($page = 1; $page <= $pages; $page++) {
            if ($page > 1) {
                $pdf->AddPage();
            }
            $pdf->WriteHTML('<h1>HeroKid preview '.$page.'</h1>');
        }
        $path = tempnam(sys_get_temp_dir(), 'herokid-mobile-preview-');
        file_put_contents($path, $pdf->Output('', 'S'));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
