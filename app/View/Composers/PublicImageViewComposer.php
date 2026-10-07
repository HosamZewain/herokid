<?php

namespace App\View\Composers;

use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Services\Images\PublicCatalogImageSources;
use App\Services\Images\PublicImageVariants;
use App\ViewModels\Catalog\UnifiedCatalogItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PublicImageViewComposer
{
    public function __construct(private PublicImageVariants $images) {}

    public function compose(View $view): void
    {
        $urls = [];
        $this->collect($view->getData(), $urls);
        $this->images->prime($urls);
    }

    private function collect(mixed $data, array &$urls, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }
        if ($data instanceof UnifiedCatalogItem) {
            $urls[] = $data->imageUrl;
        } elseif ($data instanceof Product || $data instanceof ProductVariant || $data instanceof Story || $data instanceof PricingPackage) {
            foreach (PublicCatalogImageSources::paths($data) as $path) {
                $urls[] = Storage::disk((string) config('media.public_disk', 'public'))->url($path);
            }
            $this->collect($data->getRelations(), $urls, $depth + 1);
        } elseif ($data instanceof Model) {
            // Loaded package items/homepage sections only; never lazy-load relations.
            $this->collect($data->getRelations(), $urls, $depth + 1);
        } elseif (is_array($data) || $data instanceof \Traversable) {
            foreach ($data as $item) {
                $this->collect($item, $urls, $depth + 1);
            }
        }
    }
}
