<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductProductionStage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit catalog-only upgrade; order snapshots, attempts and sent manifests are immutable. */
class UpgradeBookCoverDesign extends Command
{
    protected $signature = 'books:cover-design {products*} {--apply}';

    protected $description = 'Inspect or upgrade only the unchanged managed v1 cover prompt for future orders.';

    public function handle(): int
    {
        return DB::transaction(function () {
            $components = [];
            foreach (array_unique($this->argument('products')) as $id) {
                $product = Product::lockForUpdate()->findOrFail($id);
                $component = $product->productionComponents()->where('is_active', true)->where('production_stage', 'book_cover')->lockForUpdate()->sole();
                if ($component->stable_key !== 'automation-cover-v1'
                    || ! in_array($component->prompt_template, [ProductProductionStage::legacyCoverPrompt(), ProductProductionStage::coverPrompt()], true)) {
                    throw new RuntimeException('Custom cover prompt requires manual review; no changes applied.');
                }
                $components[] = $component;
            }
            foreach ($components as $component) {
                $old = hash('sha256', $component->prompt_template);
                if ($this->option('apply')) {
                    $component->update(['prompt_template' => ProductProductionStage::coverPrompt()]);
                }
                $this->line(json_encode(['product_id' => $component->product_id, 'component_id' => $component->id,
                    'previous_prompt_sha256' => $old, 'target_prompt_sha256' => hash('sha256', ProductProductionStage::coverPrompt()),
                    'applied' => (bool) $this->option('apply'), 'existing_orders_changed' => false]));
            }

            return self::SUCCESS;
        });
    }
}
