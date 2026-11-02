<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\OrderAttachment;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BookReferences
{
    public function capture(OrderItem $item, array $photos, int $count): array
    {
        $references = [];
        foreach ($photos as $i => $photo) {
            $references[] = ['file' => 'child-reference-'.($i + 1).'.'.match ($photo['mime']) {
                'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'png'
            }, 'role' => 'child_photo',
                'disk' => $photo['disk'], 'path' => $photo['path'], 'sha256' => $photo['checksum']];
        }
        $linked = $item->linked_order_item_id ? $item->linkedOrderItem : null;
        if ($item->linked_order_item_id && (! $linked || $linked->item_type !== 'story')) {
            throw new RuntimeException('book_story_link_ambiguous');
        }
        $storyOrder = $linked?->order ?? ($item->order->story_id ? $item->order : null);
        $storyTexts = [];
        if ($storyOrder) {
            if ($storyOrder->checkoutGroupKey() !== $item->order->checkoutGroupKey()
                || $storyOrder->child_name !== $item->order->child_name || (int) $storyOrder->child_age !== (int) $item->order->child_age
                || $storyOrder->child_gender !== $item->order->child_gender) {
                throw new RuntimeException('book_story_link_ambiguous');
            }
            $storyRun = CodexSceneRun::where('order_id', $storyOrder->id)->where('production_kind', 'story')->latest('created_at')->first();
            if (! $storyRun || $storyRun->status !== 'completed' || ! $storyOrder->childIdentityApprovedAttempt
                || $storyRun->snapshot['identity_checksum'] !== $storyOrder->childIdentityApprovedAttempt->output_checksum
                || ! hash_equals($storyRun->fingerprint, app(ManualSceneService::class)->fingerprint($storyOrder))) {
                throw new RuntimeException('book_waiting_linked_story');
            }
            if (count($storyRun->scenes) !== $storyRun->scene_count || $count < $storyRun->scene_count) {
                throw new RuntimeException('book_story_pages_incomplete');
            }
            foreach (range(1, $storyRun->scene_count) as $n) {
                $scene = $storyRun->scenes[(string) $n] ?? null;
                $attachment = $scene ? OrderAttachment::find($scene['attachment_id']) : null;
                if (! $attachment || $attachment->order_id !== $storyOrder->id || $attachment->production_unit_key !== 'story:'.$storyOrder->id
                    || $attachment->disk !== 's3_private' || $attachment->path !== $scene['path']) {
                    throw new RuntimeException('book_story_reference_changed');
                }
                $references[] = ['file' => 'linked-story-'.sprintf('%02d', $n).'.png', 'role' => 'story_scene', 'scene' => $n,
                    'disk' => $attachment->disk, 'path' => $attachment->path, 'sha256' => $scene['sha256']];
                $storyTexts[$n] = $storyRun->snapshot['texts'][$n - 1] ?? ['text' => 'Adapt linked story scene '.$n];
            }
        }
        foreach ($references as $reference) {
            $this->bytes($reference);
        }

        return ['references' => $references, 'texts' => $this->pages($count, $storyTexts)];
    }

    public function bytes(array $reference): string
    {
        $bytes = Storage::disk($reference['disk'])->get($reference['path']);
        if (! is_string($bytes) || strlen($bytes) > 30 * 1024 * 1024 || ! @getimagesizefromstring($bytes)
            || ! hash_equals($reference['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('book_reference_checksum');
        }

        return $bytes;
    }

    private function pages(int $count, array $storyTexts): array
    {
        $activities = ['drawing at an easel', 'planting flowers', 'flying a kite', 'exploring a library', 'building a sandcastle', 'baking biscuits',
            'feeding a rabbit', 'riding a bicycle', 'visiting an aquarium', 'camping under stars', 'playing football', 'dancing to music',
            'making a paper boat', 'discovering butterflies', 'picnicking in a park', 'playing with building blocks', 'exploring a museum',
            'collecting seashells', 'visiting a farm', 'watering a vegetable garden', 'performing a puppet show', 'painting pottery',
            'playing a drum', 'watching birds', 'building a snowman', 'stargazing through a telescope', 'making a fruit salad',
            'walking a friendly dog', 'enjoying a carousel', 'decorating a birthday cake', 'playing hopscotch', 'making a leaf collage',
            'visiting a fire station', 'exploring dinosaur fossils', 'playing a board game', 'sorting colorful buttons', 'collecting autumn leaves',
            'pretending to be an astronaut', 'planting a young tree', 'building a toy train', 'learning to swim with an adult',
            'visiting a bakery', 'assembling a puzzle', 'making a friendship bracelet', 'watching a rainbow', 'playing mini golf',
            'dressing for a school play', 'discovering a waterfall safely', 'helping at a pet shelter', 'celebrating a family picnic'];
        $pages = [];
        for ($n = 1; $n <= $count; $n++) {
            $text = isset($storyTexts[$n])
                ? 'Adapt linked-story-'.sprintf('%02d', $n).'.png, preserving its chronological event: '.json_encode($storyTexts[$n], JSON_UNESCAPED_UNICODE)
                : 'Distinct activity: '.$activities[($n - count($storyTexts) - 1) % count($activities)].'. Follow explicit customer theme and approved cover; adapt the activity to age and use a new setting and pose.';
            $pages[] = ['scene_number' => $n, 'title' => 'صفحة '.$n, 'text' => $text];
        }

        return $pages;
    }
}
