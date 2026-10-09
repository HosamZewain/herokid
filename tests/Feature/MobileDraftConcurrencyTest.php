<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\MobileDraftController;
use App\Models\MobileDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MobileDraftConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_checks_the_current_locked_row_not_a_stale_bound_model(): void
    {
        $user = User::factory()->create();
        $draft = $user->mobileDrafts()->create(['draft_type' => 'personalization', 'status' => 'active',
            'payload' => ['dedication' => 'Original'], 'version' => 1, 'last_activity_at' => now()]);
        $staleBinding = MobileDraft::findOrFail($draft->id);
        $draft->update(['payload' => ['dedication' => 'Saved on another device'], 'version' => 2]);
        $request = Request::create('/api/v1/drafts/'.$draft->uuid, 'PATCH', ['version' => 1, 'payload' => ['dedication' => 'Stale overwrite']]);
        $request->setUserResolver(fn () => $user);
        $response = app(MobileDraftController::class)->update($request, $staleBinding);
        $this->assertSame(409, $response->status());
        $this->assertSame(2, $response->getData(true)['data']['version']);
        $this->assertSame('Saved on another device', $draft->fresh()->payload['dedication']);
    }

    public function test_minimal_child_profile_creation_and_edit_preserve_other_owned_profiles(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('QA', ['mobile'])->plainTextToken);
        $first = $this->postJson('/api/v1/children', ['name' => 'First test child'])->assertCreated()->assertJsonPath('data.age', null);
        $second = $this->postJson('/api/v1/children', ['name' => 'Second test child', 'age' => 6, 'gender' => 'girl'])->assertCreated();
        $this->patchJson('/api/v1/children/'.$first->json('data.id'), ['name' => 'Edited first child', 'age' => 7, 'gender' => 'boy', 'interests' => ['Space'], 'preferred_language' => 'en'])
            ->assertOk()->assertJsonPath('data.name', 'Edited first child')->assertJsonPath('data.gender', 'boy');
        $this->getJson('/api/v1/children/'.$second->json('data.id'))->assertOk()->assertJsonPath('data.name', 'Second test child')->assertJsonPath('data.gender', 'girl');
    }
}
