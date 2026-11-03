<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Services\Orders\BookPromptSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncBookPrompts extends Command
{
    protected $signature = 'orders:sync-book-prompts {--apply} {--actor=} {--expect=}';

    protected $description = 'Preview or explicitly apply current book templates to unstarted order stages; never starts work';

    public function handle(BookPromptSync $sync): int
    {
        $actor = $this->option('actor') ? User::findOrFail((int) $this->option('actor')) : null;
        if ($this->option('apply') && (! $actor?->is_active || ! $this->option('expect'))) {
            $this->error('Apply requires active actor and reviewed catalog hash.');

            return self::FAILURE;
        }
        DB::beginTransaction();
        try {
            $products = Product::whereHas('productionComponents', fn ($q) => $q->whereIn('production_stage', ['book_cover', 'book_interior']))->orderBy('id')->lockForUpdate()->get();
            $catalog = $products->mapWithKeys(fn ($p) => [$p->id => $p->productionComponents()->orderBy('id')->lockForUpdate()->get()->map(fn ($c) => [$c->id, $c->stable_key, $c->production_stage, (bool) $c->is_active, hash('sha256', $c->prompt_template ?? '')])->all()])->all();
            $hash = hash('sha256', json_encode($catalog));
            if ($this->option('apply') && ! hash_equals($hash, $this->option('expect'))) {
                throw new \RuntimeException('Catalog changed; inspect again before applying.');
            }
            $result = [];
            foreach ($products as $product) {
                $result[$product->id] = $sync->sync($product, $actor?->id);
            }
            $this->line(json_encode(['applied' => (bool) $this->option('apply'), 'catalog_hash' => $hash, 'products' => $result], JSON_PRETTY_PRINT));
            if ($this->option('apply')) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
