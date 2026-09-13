<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\OrderSceneTextService;
use App\Services\Orders\ProductionSceneSnapshotRefreshService;
use App\Services\Stories\ProductionSceneVariantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductionSceneSnapshotSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $gender = 'female'): array
    {
        $story = Story::create(['title' => 'قصة اختبار', 'slug' => 'sync-'.uniqid(), 'gender' => 'girl', 'language' => 'ar', 'active' => true]);
        foreach (range(1, 13) as $number) {
            $story->sceneTemplates()->create(['scene_number' => $number, 'title' => 'مشهد '.$number,
                'text_template' => 'استيقظت {{child_name}} وفتحت عينيها. '.$number,
                'alternate_text_template' => 'استيقظ {{child_name}} وفتح عينيه. '.$number]);
        }
        $order = Order::create(['order_number' => 'HK-SANITIZED-'.uniqid(), 'checkout_group_key' => 'CHK-SYNC',
            'story_id' => $story->id, 'child_name' => 'طفل اختبار', 'child_gender' => $gender, 'child_age' => 4,
            'parent_name' => 'Synthetic', 'status' => 'new', 'printing_status' => 'not_started', 'shipping_status' => 'not_ready']);
        app(OrderSceneTextService::class)->snapshotForOrder($order, $story);

        return [$order, $story];
    }

    public function test_gender_aliases_use_structured_gender_and_actual_variant(): void
    {
        [$order, $story] = $this->fixture();
        $resolver = app(ProductionSceneVariantResolver::class);
        foreach (['female' => 'female', 'girl' => 'female', 'male' => 'male', 'boy' => 'male'] as $input => $expected) {
            $order->child_gender = $input;
            $selected = $resolver->resolve($story->sceneTemplates()->first(), $order, $story);
            $this->assertSame($expected, $selected['resolved_variant']);
            $this->assertStringContainsString($expected === 'female' ? 'استيقظت' : 'استيقظ ', $selected['text']);
        }
        foreach ([null, '', 'unknown'] as $input) {
            $order->child_gender = $input;
            $this->assertSame('original', $resolver->resolve($story->sceneTemplates()->first(), $order, $story)['variant']);
        }
        $story->gender = 'both';
        $this->assertSame('neutral', $resolver->resolve($story->sceneTemplates()->first(), $order, $story)['resolved_variant']);
    }

    public function test_explicit_refresh_preserves_ids_siblings_and_get_is_read_only(): void
    {
        [$order, $story] = $this->fixture();
        $sibling = $order->replicate();
        $sibling->order_number .= '-B';
        $sibling->child_gender = 'male';
        $sibling->save();
        app(OrderSceneTextService::class)->snapshotForOrder($sibling, $story);
        $siblingBefore = $sibling->sceneTextSnapshots()->get()->toArray();
        $order->sceneTextSnapshots()->update(['rendered_text' => 'نص تاريخي قديم', 'render_context_snapshot' => json_encode(['child_gender' => 'girl'])]);
        $ids = $order->sceneTextSnapshots()->orderBy('scene_number')->pluck('id')->all();
        $identity = $order->fresh()->getAttributes();
        $admin = User::factory()->create(['role' => 'admin', 'agent_api_enabled' => true]);
        $token = $admin->createToken('synthetic', ['agent', 'agent:orders.read'])->plainTextToken;
        $url = '/api/agent/studio/orders/'.$order->order_number;
        $before = $this->withToken($token)->getJson($url)->assertOk()->json();
        $this->assertSame('نص تاريخي قديم', $before['production_stories'][0]['scenes'][0]['text']);
        $service = app(ProductionSceneSnapshotRefreshService::class);
        $dry = $service->refresh($order->id, $story->id, $admin, 'Synthetic repair');
        $this->assertCount(13, $dry['changed_snapshot_ids']);
        $this->assertSame('نص تاريخي قديم', $order->sceneTextSnapshots()->first()->rendered_text);
        $service->refresh($order->id, $story->id, $admin, 'Synthetic repair', true);
        $this->assertSame($identity, $order->fresh()->getAttributes());
        $this->assertSame($ids, $order->sceneTextSnapshots()->orderBy('scene_number')->pluck('id')->all());
        $this->assertSame($siblingBefore, $sibling->sceneTextSnapshots()->get()->toArray());
        $stored = $order->sceneTextSnapshots()->get()->toArray();
        $after = $this->withToken($token)->getJson($url)->assertOk()->json();
        $unit = collect($after['production_stories'])->firstWhere('production_unit_id', 'story:'.$order->id);
        $this->assertCount(13, $unit['scenes']);
        $this->assertSame(range(1, 13), array_column($unit['scenes'], 'number'));
        $this->assertSame('order_scene_snapshot:'.$ids[0], $unit['scenes'][0]['id']);
        $this->assertSame('استيقظت طفل اختبار وفتحت عينيها. 1', $unit['scenes'][0]['text']);
        $this->assertSame('female', $unit['scenes'][0]['metadata']['text_variant']);
        $boy = collect($after['production_stories'])->firstWhere('production_unit_id', 'story:'.$sibling->id);
        $this->assertSame('male', $boy['scenes'][0]['metadata']['text_variant']);
        $this->assertNotSame($before['order']['source_revision'], $after['order']['source_revision']);
        $again = $this->withToken($token)->getJson($url)->assertOk()->json();
        $this->assertSame($after['order']['source_revision'], $again['order']['source_revision']);
        $this->assertSame($stored, $order->sceneTextSnapshots()->get()->toArray());
        $this->assertSame([], $service->refresh($order->id, $story->id, $admin, 'No-op', true)['changed_snapshot_ids']);
    }

    public function test_completed_order_requires_explicit_exception(): void
    {
        [$order, $story] = $this->fixture();
        $order->update(['printing_status' => 'completed']);
        $admin = User::factory()->create(['role' => 'admin']);
        try {
            app(ProductionSceneSnapshotRefreshService::class)->refresh($order->id, $story->id, $admin, 'Synthetic', true);
            $this->fail('Completed protection required');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('allow-completed', $exception->getMessage());
        }
        $result = app(ProductionSceneSnapshotRefreshService::class)->refresh($order->id, $story->id, $admin, 'Approved exception', true, true);
        $this->assertTrue($result['completed_override']);
        $this->assertSame('completed', $order->fresh()->printing_status);
    }

    public function test_mismatch_and_missing_variant_fail_atomically(): void
    {
        [$order, $story] = $this->fixture('male');
        $before = $order->sceneTextSnapshots()->get()->toArray();
        $story->sceneTemplates()->where('scene_number', 13)->update(['alternate_text_template' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        try {
            app(ProductionSceneSnapshotRefreshService::class)->refresh($order->id, $story->id, $admin, 'Synthetic', true);
            $this->fail('Expected missing-variant failure');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('variant', $exception->getMessage());
        }
        $this->assertSame($before, $order->sceneTextSnapshots()->get()->toArray());
        $story->sceneTemplates()->where('scene_number', 13)->delete();
        $this->expectException(ValidationException::class);
        app(ProductionSceneSnapshotRefreshService::class)->refresh($order->id, $story->id, $admin, 'Synthetic', true);
    }

    public function test_catalog_save_automatically_updates_printed_units_and_archives_history(): void
    {
        [$girl, $story] = $this->fixture('girl');
        $girl->update(['status' => 'delivered', 'printing_status' => 'completed']);
        $boy = $girl->replicate();
        $boy->child_gender = 'boy';
        $boy->order_number .= '-BOY';
        $boy->save();
        app(OrderSceneTextService::class)->snapshotForOrder($boy, $story);
        [$unrelated] = $this->fixture();
        $unrelatedBefore = $unrelated->sceneTextSnapshots()->get()->toArray();
        $ids = $girl->sceneTextSnapshots()->pluck('id')->all();
        $old = $girl->sceneTextSnapshots()->first()->rendered_text;
        $admin = User::factory()->create(['role' => 'admin']);
        $scenes = $story->sceneTemplates()->get()->map(fn ($scene) => [
            'scene_number' => $scene->scene_number, 'title' => $scene->title,
            'text_template' => 'عادت {{child_name}} سعيدة. '.$scene->scene_number,
            'alternate_text_template' => 'عاد {{child_name}} سعيدًا. '.$scene->scene_number,
        ])->all();
        $this->actingAs($admin)->put(route('admin.stories.update', $story), [
            'title' => $story->title, 'slug' => $story->slug, 'gender' => 'girl', 'language' => 'ar', 'price' => 0,
            'active' => true, 'scenes' => $scenes,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('عادت طفل اختبار سعيدة. 1', $girl->sceneTextSnapshots()->first()->rendered_text);
        $this->assertSame('عاد طفل اختبار سعيدًا. 1', $boy->sceneTextSnapshots()->first()->rendered_text);
        $this->assertSame($ids, $girl->sceneTextSnapshots()->pluck('id')->all());
        $this->assertSame('completed', $girl->fresh()->printing_status);
        $this->assertSame('delivered', $girl->fresh()->status);
        $this->assertSame($unrelatedBefore, $unrelated->sceneTextSnapshots()->get()->toArray());
        $archive = DB::table('order_scene_text_snapshot_revisions')->where('snapshot_id', $ids[0])->first();
        $this->assertSame($old, json_decode($archive->previous_snapshot, true)['rendered_text']);
        $this->assertDatabaseCount('order_scene_text_snapshot_revisions', 26);
    }

    public function test_same_second_text_change_changes_revision(): void
    {
        [$order] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin', 'agent_api_enabled' => true]);
        $token = $admin->createToken('synthetic', ['agent', 'agent:orders.read'])->plainTextToken;
        $url = '/api/agent/studio/orders/'.$order->order_number;
        $before = $this->withToken($token)->getJson($url)->assertOk()->json('order.source_revision');
        // Intentionally bypass timestamp changes to verify content hashing itself.
        DB::table('order_scene_text_snapshots')->where('order_id', $order->id)->where('scene_number', 1)->update(['rendered_text' => 'نص مصحح']);
        $after = $this->withToken($token)->getJson($url)->assertOk()->json('order.source_revision');
        $this->assertNotSame($before, $after);
    }

    public function test_admin_refresh_route_requires_permission_and_target_identity(): void
    {
        [$order, $story] = $this->fixture();
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->detach();
        $this->actingAs($limited)->post(route('admin.orders.scene-snapshots.refresh', $order), [
            'story_id' => $story->id, 'reason' => 'Synthetic', 'confirm_refresh' => 1,
        ])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.orders.scene-snapshots.refresh', $order), [
            'story_id' => $story->id + 9999, 'reason' => 'Synthetic', 'confirm_refresh' => 1,
        ])->assertSessionHasErrors('scenes');
    }

    public function test_auto_derived_production_text_syncs_but_independent_text_is_preserved(): void
    {
        [$order, $story] = $this->fixture();
        $project = ProductionProject::create(['order_id' => $order->id, 'status' => 'draft', 'current_stage' => 'intake']);
        foreach ($order->sceneTextSnapshots()->get() as $snapshot) {
            $project->scenes()->create(['scene_number' => $snapshot->scene_number, 'story_text' => $snapshot->rendered_text, 'status' => 'draft']);
        }
        $ids = $project->scenes()->pluck('id')->all();
        $story->sceneTemplates()->update(['text_template' => 'عادت {{child_name}} إلى منزلها.']);
        $admin = User::factory()->create(['role' => 'admin']);
        app(ProductionSceneSnapshotRefreshService::class)->refresh($order->id, $story->id, $admin, 'Approved update', true);
        $this->assertSame($ids, $project->scenes()->pluck('id')->all());
        $this->assertSame('عادت طفل اختبار إلى منزلها.', $project->scenes()->first()->story_text);
        $this->assertSame('draft', $project->fresh()->status);
        $project->scenes()->where('scene_number', 13)->update(['story_text' => 'نص معدل مستقل']);
        $before = $order->sceneTextSnapshots()->get()->toArray();
        $story->sceneTemplates()->update(['text_template' => 'نسخة أخرى']);
        try {
            app(ProductionSceneSnapshotRefreshService::class)->refresh($order->id, $story->id, $admin, 'Blocked update', true);
            $this->fail('Independent text must not be overwritten');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Independently edited', $exception->getMessage());
        }
        $this->assertSame($before, $order->sceneTextSnapshots()->get()->toArray());
        $this->assertSame('نص معدل مستقل', $project->scenes()->where('scene_number', 13)->value('story_text'));
    }
}
