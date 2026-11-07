<?php

namespace App\Services\SalesAssistant;

use App\Services\Catalog\CatalogSalesRankingService;
use App\Services\Catalog\CustomerKnowledge;
use Illuminate\Support\Facades\Cache;

/** Ground semantic model choices in the published catalog; never gate them on query words. */
class SalesCatalogContext
{
    public function eligible(array $context, ?int $budget = null, ?string $language = null, ?string $kind = null): array
    {
        $catalog = app(SalesCatalog::class);
        $items = array_values(array_filter($catalog->all(), fn ($item) => $catalog->eligible($item, $context)
            && ($kind === null || $item['kind'] === $kind)
            && ($budget === null || $item['price_cents'] <= $budget)
            && ($language === null || $item['kind'] !== 'story' || in_array($language, $item['languages'] ?? [$item['language']], true))));
        usort($items, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $items;
    }

    public function overview(array $context, int $bytes, int $cursor = 0, ?int $budget = null, ?string $language = null, ?string $kind = null): array
    {
        $all = $this->eligible($context, $budget, $language, $kind);
        $remaining = array_slice($all, $cursor);
        foreach ([180, 100, 60, 0] as $summaryLength) {
            $items = array_map(fn ($item) => $this->summary($item, $summaryLength), $remaining);
            $page = ['items' => $items, 'total_eligible' => count($all), 'complete' => true, 'next_cursor' => null];
            if ($this->bytes($page) <= $bytes) {
                return $page;
            }
        }
        // Catalog growth never silently turns an incomplete sample into "no match".
        $page = ['items' => [], 'total_eligible' => count($all), 'complete' => false, 'next_cursor' => $cursor];
        foreach ($remaining as $item) {
            $next = $page;
            $next['items'][] = $this->summary($item, 100);
            $next['next_cursor']++;
            if ($this->bytes($next) > $bytes) {
                break;
            }
            $page = $next;
        }

        return $page;
    }

    public function popularStories(array $context): array
    {
        $all = app(SalesCatalog::class)->all();
        $ids = array_values(array_map(fn ($item) => $item['id'], array_filter($all, fn ($item) => $item['kind'] === 'story')));
        sort($ids);
        $counts = Cache::remember('sales-assistant:public-story-ranking:v1:'.hash('sha256', json_encode($ids)), 300,
            fn () => app(CatalogSalesRankingService::class)->counts(collect($ids), collect())['stories']);
        $stories = array_values(array_filter($this->eligible($context, kind: 'story'), fn ($item) => ($counts[$item['id']] ?? 0) > 0));
        usort($stories, fn ($a, $b) => (($counts[$b['id']] ?? 0) <=> ($counts[$a['id']] ?? 0)) ?: strcmp($a['key'], $b['key']));

        // Only public item keys/order, never quantities, revenue, customers or order rows.
        return array_column(array_slice($stories, 0, 12), 'key');
    }

    public function offers(array $context): array
    {
        return array_column(array_filter($this->eligible($context), fn ($item) => $item['offer_applied'] ?? false), 'key');
    }

    private function summary(array $item, int $length): array
    {
        $summary = ['key' => $item['key'], 'title' => $item['title'], 'age' => $item['age'], 'gender' => $item['gender'],
            'categories' => $item['categories'], 'offer_applied' => $item['offer_applied'] ?? false];
        if ($length > 0) {
            $summary['value'] = CustomerKnowledge::text($item['value'], $length);
            $summary['description'] = CustomerKnowledge::text($item['description'], $length);
            $summary['keywords'] = CustomerKnowledge::text($item['keywords'], min(80, $length));
        }

        return $summary;
    }

    public function bytes(array $data): int
    {
        return strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
