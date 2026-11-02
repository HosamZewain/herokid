<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductAutomation\BookCatalog;
use Illuminate\Console\Command;

class ConfigureColoringBook extends Command
{
    protected $signature = 'books:configure {product} {--default-pages=20} {--variant-pages=* : Explicit variantId:pageCount pairs} {--apply}';

    protected $description = 'Inspect or explicitly configure separate A4 coloring-book stages; never generates or sends.';

    public function handle(): int
    {
        $product = Product::with('productionComponents', 'variants')->findOrFail($this->argument('product'));
        $variants = [];
        foreach ($this->option('variant-pages') as $pair) {
            if (! preg_match('/\A([0-9]+):([0-9]+)\z/', $pair, $m)) {
                $this->error('Expected variantId:pageCount');

                return self::FAILURE;
            }
            $variants[(int) $m[1]] = (int) $m[2];
        }
        $this->line(json_encode(['product_id' => $product->id, 'default_pages' => (int) $this->option('default-pages'), 'variant_pages' => $variants,
            'previous_component_ids' => $product->productionComponents->pluck('id'), 'apply' => (bool) $this->option('apply')], JSON_UNESCAPED_UNICODE));
        if ($this->option('apply')) {
            app(BookCatalog::class)->configure($product, (int) $this->option('default-pages'), $variants);
        }

        return self::SUCCESS;
    }
}
