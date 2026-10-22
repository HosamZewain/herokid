<?php

namespace Tests\Support;

use App\Models\AiProvider;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\OrderAutomation\WorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class OrderAutomationQaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== '/qa/automation.sqlite') {
            throw new \RuntimeException('Disposable QA database only.');
        }
        $user = User::factory()->create(['name' => 'مدير اختبار الأتمتة', 'email' => 'automation@example.test', 'role' => 'admin', 'password' => 'automation-qa-only']);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.photos.view', 'order_automation.view', 'order_automation.manage', 'order_automation.activate', 'order_automation.execute'])->pluck('id'));
        $story = Story::create(['title' => 'رحلة النجوم — قصة اختبار', 'slug' => 'automation-qa', 'language' => 'ar', 'gender' => 'both', 'price' => 350, 'active' => true]);
        ob_start();
        imagepng(imagecreatetruecolor(100, 100));
        $bytes = ob_get_clean();
        Storage::disk('local')->put('automation-qa/input.png', $bytes);
        Order::create(['order_number' => 'AUTOMATION-QA-ONLY', 'story_id' => $story->id, 'child_name' => 'طفل اختبار', 'child_age' => 5, 'status' => 'new', 'uploaded_photos' => ['automation-qa/input.png']]);
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'OpenAI', 'display_name' => 'OpenAI']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'موديل اختبار بدون مفاتيح']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['character_sheet'], 'configuration_json' => ['supports_multiple_references' => true], 'estimated_cost_amount' => 0.1, 'estimated_cost_currency' => 'USD']);
        $workflow = OrderAutomation::firstOrFail();
        $draft = $workflow->draft;
        $draft['model_id'] = $model->id;
        $workflow->update(['draft' => $draft]);
        app(WorkflowService::class)->publish($workflow, 1, $user);
    }
}
