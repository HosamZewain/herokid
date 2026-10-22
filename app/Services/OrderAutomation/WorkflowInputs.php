<?php

namespace App\Services\OrderAutomation;

use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderChildIdentityPromptService;
use App\Support\OrderStatusRegistry;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WorkflowInputs
{
    public function eligible(Order $order, array $definition): bool
    {
        return ! $order->trashed() && $order->story_id && $order->story
            && (! $definition['story_ids'] || in_array($order->story_id, array_map('intval', $definition['story_ids']), true))
            && ! in_array($order->status, array_merge(['cancelled', 'completed'], OrderStatusRegistry::keysForBehavior('order', 'cancelled')), true)
            && ! $order->child_identity_approved_attempt_id && ! $order->childIdentityRequest?->approved_attempt_id
            && app(AdminOrderGroupService::class)->activeOrdersQuery()->whereKey($order->id)->exists();
    }

    public function capture(Order $order, array $definition): array
    {
        if (! $this->eligible($order, $definition)) {
            throw new RuntimeException('order_ineligible');
        }
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));
        if (! $photos || ! filled($order->child_name)) {
            throw new RuntimeException('missing_inputs');
        }
        $references = [];
        foreach (array_slice($photos, 0, (int) $definition['photo_limit']) as $path) {
            if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '://')) {
                throw new RuntimeException('invalid_photo');
            }
            $found = false;
            foreach (array_unique([config('media.private_disk', 'local'), config('media.public_disk', 'public'), 'local', 'public']) as $disk) {
                $storage = Storage::disk($disk);
                if (! $storage->exists($path)) {
                    continue;
                }
                if ($storage->size($path) > 15 * 1024 * 1024) {
                    throw new RuntimeException('photo_too_large');
                }
                $bytes = $storage->get($path);
                $size = @getimagesizefromstring($bytes);
                if (! $size || ! in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $size[0] * $size[1] > 25000000) {
                    throw new RuntimeException('invalid_photo');
                }
                $references[] = ['disk' => $disk, 'path' => $path, 'checksum' => hash('sha256', $bytes), 'mime' => $size['mime']];
                $found = true;
                break;
            }
            if (! $found) {
                throw new RuntimeException('missing_inputs');
            }
        }
        $prompts = app(OrderChildIdentityPromptService::class);
        $override = $order->childIdentityPromptOverride?->prompt_text;
        $identityPrompt = filled($override) ? $prompts->withCurrentContext($override, $order) : $prompts->forOrder($order);
        // Provider receives image bytes separately. Do not leak signed photo URLs in prompts.
        $identityPrompt = preg_replace('/## Secure Original Child Photo References.*?<!-- HERO_KID_IDENTITY_CONTEXT_END -->/s', '<!-- HERO_KID_IDENTITY_CONTEXT_END -->', $identityPrompt);
        $variables = ['identity_prompt' => $identityPrompt, 'child_name' => $order->child_name, 'child_age' => $order->child_age,
            'child_gender' => $order->child_gender, 'story_title' => $order->story->title, 'order_reference' => $order->order_number];
        $prompt = preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/u', fn ($m) => (string) ($variables[$m[1]] ?? ''), $definition['prompt']);

        return ['photos' => $references, 'prompt' => $prompt, 'fingerprint' => hash('sha256', json_encode([$variables, $photos, $references, $order->story_id, $order->checkoutGroupKey(), data_get($order->delivery_details, 'phone')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    public function images(array $context): array
    {
        return array_map(function ($photo): string {
            $bytes = Storage::disk($photo['disk'])->get($photo['path']);
            if (! hash_equals($photo['checksum'], hash('sha256', $bytes))) {
                throw new RuntimeException('inputs_changed');
            }

            return 'data:'.$photo['mime'].';base64,'.base64_encode($bytes);
        }, $context['photos']);
    }
}
