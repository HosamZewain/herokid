<?php

namespace App\Console\Commands;

use App\Models\OrderItemProductionComponent;
use App\Support\StudioProductionRecipe;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BackfillStudioProductionRecipes extends Command
{
    protected $signature = 'studio:backfill-production-recipes {--apply : Persist eligible recipe snapshots} {--product= : Limit to one source product ID}';

    protected $description = 'Preview or apply an idempotent Studio recipe backfill to matching order-item component snapshots';

    public function handle(): int
    {
        $query = OrderItemProductionComponent::query()
            ->with('sourceComponent.product')
            ->whereNull('studio_recipe')
            ->whereNull('studio_workflow')
            ->whereNull('studio_recipe_version')
            ->whereNotNull('product_production_component_id')
            ->when($this->option('product'), fn ($builder, $productId) => $builder->whereHas(
                'sourceComponent', fn ($component) => $component->where('product_id', $productId)
            ));

        $matched = 0;
        $eligible = 0;
        $invalid = 0;
        $updated = 0;

        $query->orderBy('id')->chunkById(200, function ($snapshots) use (&$matched, &$eligible, &$invalid, &$updated): void {
            foreach ($snapshots as $snapshot) {
                $matched++;
                $source = $snapshot->sourceComponent;
                if (! $source || $source->stable_key !== $snapshot->stable_key || ! $source->studio_enabled || ! is_array($source->studio_recipe)) {
                    continue;
                }

                try {
                    $recipe = StudioProductionRecipe::normalize(
                        (string) $source->studio_workflow,
                        (int) $source->studio_recipe_version,
                        $source->studio_recipe,
                    );
                } catch (ValidationException) {
                    $invalid++;

                    continue;
                }

                $eligible++;
                if (! $this->option('apply')) {
                    continue;
                }

                $updated += $snapshot->newQuery()->whereKey($snapshot->id)
                    ->whereNull('studio_recipe')
                    ->whereNull('studio_workflow')
                    ->whereNull('studio_recipe_version')
                    ->update([
                        'studio_enabled' => true,
                        'studio_workflow' => $source->studio_workflow,
                        'studio_recipe_version' => $source->studio_recipe_version,
                        'studio_recipe' => $recipe,
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->table(['Mode', 'Candidates', 'Eligible', 'Invalid source recipes', 'Updated'], [[
            $this->option('apply') ? 'APPLY' : 'DRY RUN', $matched, $eligible, $invalid, $updated,
        ]]);

        if (! $this->option('apply')) {
            $this->info('No rows changed. Re-run with --apply after reviewing the counts.');
        }

        return self::SUCCESS;
    }
}
