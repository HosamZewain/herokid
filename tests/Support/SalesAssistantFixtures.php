<?php

namespace Tests\Support;

use App\Models\SalesConversation;
use App\Models\Story;
use App\Services\SalesAssistant\SalesSettings;
use Illuminate\Support\Str;

trait SalesAssistantFixtures
{
    protected function salesEnabled(): void
    {
        config(['sales_assistant.enabled' => true]);
        $this->mock(SalesSettings::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('verified')->andReturn(true);
            $mock->shouldReceive('workerReady')->andReturn(true);
            $mock->shouldReceive('credential')->andReturn('synthetic-openai-key');
            $mock->shouldReceive('budget')->andReturn(5000000);
        });
    }

    protected function openSales(): void
    {
        $this->postJson(route('sales.state'))->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }

    protected function salesConversation(): SalesConversation
    {
        return SalesConversation::create(['id' => (string) Str::uuid(), 'session_hash' => hash('sha256', (string) Str::uuid()), 'visitor_hash' => hash('sha256', (string) Str::uuid())]);
    }

    protected function salesStory(string $slug = 'prayer', string $title = 'صلاة الفجر مع بابا', string $value = 'حب الصلاة والمسجد', string $age = '3-9'): Story
    {
        return Story::create(['slug' => $slug, 'title' => $title, 'lesson_value' => $value, 'price' => 199, 'language' => 'ar', 'age_range' => $age, 'gender' => 'both', 'active' => true]);
    }

    protected function salesReply(array $recommendations = [], string $message = 'دي اختيارات مناسبة لاهتمام طفلك.', bool $upsell = false): array
    {
        return ['status' => 'completed', 'usage' => ['input_tokens' => 250, 'output_tokens' => 70], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['message' => $message, 'recommendations' => $recommendations, 'upsell' => $upsell, 'next' => 'choose'], JSON_UNESCAPED_UNICODE)]]]]];
    }
}
