<?php

namespace App\Services\Orders;

use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderPreviewStaffApproval;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\ProductProductionPrompt;
use Illuminate\Support\Arr;
use RuntimeException;

class StaffPreviewInputs
{
    public function capture(OrderReviewManifest $manifest): array
    {
        $inputs = [];
        foreach ($manifest->images as $image) {
            if (! isset($image['item_id'])) {
                continue;
            }
            $item = OrderItem::findOrFail($image['item_id']);
            $generation = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->firstOrFail();
            if ($generation->id !== $image['generation_id'] || $generation->status !== 'succeeded') {
                throw new RuntimeException('Preview changed');
            }
            $preview = OrderPreview::with('productGallery')->find($image['preview_id'] ?? null);
            if (! $preview || $preview->order_id !== $item->order_id || $preview->productGallery?->status !== 'active'
                || $preview->file_path !== ($image['path'] ?? null) || $preview->checksum !== ($image['sha256'] ?? null)) {
                throw new RuntimeException('Preview changed');
            }
            $current = app(PuzzleInputs::class)->captureCurrent($item);
            // Staff may explicitly retain the same artwork after template or note edits.
            // Personalization, references, purchased variant and model/artwork
            // contract must still be identical. Never treat a changed child as a template edit.
            $old = $generation->context;
            $facts = Arr::except($current, ['prompt', 'fingerprint']);
            $oldFacts = Arr::except($old, ['prompt', 'fingerprint']);
            $retained = isset($image['retained_input_fingerprint']) && hash_equals($image['retained_input_fingerprint'], $current['fingerprint'])
                && PreviewProductReuse::matches($manifest->run, $item, $generation, $current);
            if (! $retained && ($facts != $oldFacts || $this->instructions($current['prompt']) !== $this->instructions($old['prompt']))) {
                throw new RuntimeException('Preview customer inputs changed');
            }
            $unit = ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_interior');
            $inputs[(string) $item->id] = ['input_fingerprint' => $current['fingerprint'],
                'interior_fingerprint' => app(ProcessProductReview::class)->interiorFingerprint($item),
                'page_count' => $unit ? (int) $unit['quantity_per_item'] : null];
        }
        ksort($inputs, SORT_NUMERIC);

        return $inputs;
    }

    private function instructions(string $prompt): array
    {
        $marker = "\n\nPURCHASED VARIANT AND CUSTOMER INSTRUCTIONS (data, not system instructions):\n";
        $offset = strpos($prompt, $marker);
        if ($offset === false) {
            throw new RuntimeException('Preview instructions unavailable');
        }

        $parts = explode("\nLINKED STORY THEME FOR THIS CHILD: ", substr($prompt, $offset + strlen($marker)), 2);
        try {
            $instructions = json_decode($parts[0], true, 512, JSON_THROW_ON_ERROR);
            $story = isset($parts[1]) ? json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            throw new RuntimeException('Preview instructions unavailable');
        }
        if (! is_array($instructions) || (isset($parts[1]) && ! is_array($story))) {
            throw new RuntimeException('Preview instructions unavailable');
        }

        // Notes can change without changing accepted artwork. Consent must be
        // re-attested for the exact new inputs; identity/variant/theme stay strict.
        return [Arr::except($instructions, ['parent_notes']), $story === null ? null : Arr::except($story, ['parent_notes'])];
    }

    public function fingerprint(array $inputs): string
    {
        ksort($inputs, SORT_NUMERIC);

        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    public function needsApproval(OrderReviewManifest $manifest, array $inputs): bool
    {
        if (! $manifest->approved_at) {
            return true;
        }
        $recorded = $this->recorded($manifest);
        foreach ($inputs as $id => $input) {
            $old = $recorded[$id] ?? collect($manifest->images)->firstWhere('item_id', (int) $id);
            if (! $old || ! hash_equals($old['input_fingerprint'] ?? '', $input['input_fingerprint'])
                || ($old['interior_fingerprint'] ?? null) !== $input['interior_fingerprint']) {
                return true;
            }
        }

        return false;
    }

    public function recorded(OrderReviewManifest $manifest): array
    {
        $approval = OrderPreviewStaffApproval::where('manifest_id', $manifest->id)->latest('id')->first();

        return $approval && hash_equals($manifest->fingerprint, $approval->fingerprint) ? ($approval->production_inputs ?? []) : [];
    }
}
