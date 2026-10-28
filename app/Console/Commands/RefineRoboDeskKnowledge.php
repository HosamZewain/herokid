<?php

namespace App\Console\Commands;

use App\Models\PricingPackage;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RefineRoboDeskKnowledge extends Command
{
    protected $signature = 'robodesk:refine-knowledge {--apply : Apply the approved gift package price once}';

    protected $description = 'Set the gift package catalog price to 926 EGP without changing historical orders';

    public function handle(): int
    {
        $marker = 'robodesk_gift_integer_price_v1';
        if (Setting::where('key', $marker)->value('value') === 'applied') {
            $this->info('Gift price correction already applied; subsequent admin edits preserved.');

            return self::SUCCESS;
        }
        $package = PricingPackage::where('slug', 'bak-hdy-hyrokyd')->first();
        if (! $package || ! in_array((int) round((float) $package->price * 100), [92550, 92600], true)) {
            $this->error('Gift package missing or price changed unexpectedly. No data changed; review before retrying.');

            return self::FAILURE;
        }
        $this->info('Gift package catalog price: '.$package->price.' -> 926 EGP. Existing orders are unchanged.');
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($package, $marker): void {
            $locked = PricingPackage::whereKey($package->id)->lockForUpdate()->firstOrFail();
            if (! in_array((int) round((float) $locked->price * 100), [92550, 92600], true)) {
                throw new \RuntimeException('Gift package price changed during preparation.');
            }
            $backup = 'private/robodesk-content/gift-price-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
            if (! Storage::disk('local')->put($backup, json_encode([
                'table' => $locked->getTable(), 'id' => $locked->id, 'slug' => $locked->slug,
                'before' => ['price' => $locked->price], 'after' => ['price' => 926],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                throw new \RuntimeException('Private backup failed; no price changed.');
            }
            $locked->update(['price' => 926]);
            Setting::updateOrCreate(['key' => $marker], ['value' => 'applied']);
            $this->info('Applied. Private rollback record: '.$backup);
        });

        return self::SUCCESS;
    }
}
