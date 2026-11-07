<?php

namespace App\Services\SalesAssistant;

use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\SalesTurn;
use App\Services\Cart\PackagePersonalizationService;
use App\Services\Catalog\CustomerKnowledge;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalesCapabilities
{
    public const TOOLS = ['search_catalog', 'get_catalog_item', 'get_purchase_requirements', 'get_store_policies', 'get_my_cart_summary'];

    public function issue(SalesTurn $turn, array $summary): string
    {
        return Crypt::encryptString(json_encode(['purpose' => 'storefront-sales-read', 'turn' => $turn->id,
            'nonce' => $turn->capability_nonce, 'expires' => now()->timestamp + config('sales_assistant.capability_ttl', 180),
            'child_context' => app(SalesChildContext::class)->forTurn($turn),
            'summary' => $summary], JSON_THROW_ON_ERROR));
    }

    public function resolve(string $token): SalesTurn
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 10, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(403);
        }
        abort_unless(($data['purpose'] ?? '') === 'storefront-sales-read' && ($data['expires'] ?? 0) >= now()->timestamp, 403);
        $turn = SalesTurn::find($data['turn'] ?? '');
        abort_unless($turn && $turn->status === 'processing' && hash_equals($turn->capability_nonce, $data['nonce'] ?? ''), 403);
        $turn->setAttribute('safe_cart_summary', $data['summary'] ?? []);
        $turn->setAttribute('child_context', $data['child_context'] ?? app(SalesChildContext::class)->forTurn($turn));

        return $turn;
    }

    public function tool(SalesTurn $turn, string $name, array $args): array
    {
        abort_unless(in_array($name, self::TOOLS, true), 403);
        $allowed = match ($name) {
            'search_catalog' => ['query', 'age', 'gender', 'budget_cents', 'language', 'cursor', 'kind'], 'get_catalog_item','get_purchase_requirements' => ['key'], 'get_store_policies' => ['topic'], default => []
        };
        abort_if(array_diff(array_keys($args), $allowed), 422);
        $rules = match ($name) {
            'search_catalog' => ['query' => 'required|string|max:500', 'age' => 'nullable|integer|min:0|max:18', 'gender' => 'nullable|in:boy,girl', 'budget_cents' => 'nullable|integer|min:0|max:10000000', 'language' => 'nullable|in:ar,en', 'cursor' => 'nullable|integer|min:0|max:100000', 'kind' => 'nullable|in:story,product,package'],
            'get_catalog_item', 'get_purchase_requirements' => ['key' => ['required', 'string', 'regex:/^(story|product|package):[1-9][0-9]*$/']],
            'get_store_policies' => ['topic' => 'required|in:payment,delivery,privacy,photos,preview,tracking,library,identity,offers'],
            default => [],
        };
        $args = Validator::make($args, $rules)->validate();

        return DB::transaction(function () use ($turn, $name, $args) {
            $locked = SalesTurn::whereKey($turn->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'processing' && $locked->tool_calls < config('sales_assistant.max_tool_calls', 4), 429);
            $catalog = app(SalesCatalog::class);
            $context = $turn->getAttribute('child_context') ?? app(SalesChildContext::class)->forTurn($turn);
            $items = [];
            $result = match ($name) {
                'search_catalog' => app(SalesCatalogContext::class)->overview(
                    ['age' => $context['age'] ?? $args['age'] ?? null, 'gender' => $context['gender'] ?? $args['gender'] ?? null, 'needs_child_choice' => $context['needs_child_choice'] ?? false],
                    (int) floor(config('sales_assistant.max_tool_bytes', 12000) / 3), (int) ($args['cursor'] ?? 0), $args['budget_cents'] ?? null, $args['language'] ?? null, $args['kind'] ?? null),
                'get_catalog_item' => ['item' => $catalog->publicFacts($items = [$catalog->item((string) ($args['key'] ?? ''))])[0]],
                'get_purchase_requirements' => ['requirements' => $this->requirements(($items = [$catalog->item((string) ($args['key'] ?? ''))])[0])],
                'get_store_policies' => ($args['topic'] ?? '') === 'offers'
                    ? ['public_offer_keys' => app(SalesCatalogContext::class)->offers($context), 'conditions' => 'Published automatic offers/package savings only; inspect get_catalog_item for current prices. Private coupon codes are not disclosed. Customers can enter their own code in cart; checkout validates eligibility and total.']
                    : ['policy' => app(CustomerKnowledge::class)->policy((string) ($args['topic'] ?? ''))],
                'get_my_cart_summary' => ['cart' => $turn->getAttribute('safe_cart_summary') ?? []],
            };
            if ($name === 'search_catalog') {
                $items = $result['items'];
            }
            foreach ($items as $item) {
                abort_unless($catalog->eligible($item, $context), 422, 'اختار منتجًا مناسبًا لعمر الطفل ونوعه المسجلين في المحادثة.');
            }
            if (strlen(json_encode($result)) > config('sales_assistant.max_tool_bytes', 12000)) {
                $result = ['message' => 'Narrow your search.'];
                $items = [];
            }
            $locked->update(['tool_calls' => $locked->tool_calls + 1, 'allowed_items' => array_values(array_unique(array_merge($locked->allowed_items ?? [], array_column($items, 'key'))))]);

            return $result;
        });
    }

    public function requirements(array $item): array
    {
        return match ($item['kind']) {
            'story' => ['child_name' => true, 'child_age' => true, 'child_gender' => true,
                'photos_min' => config('photo_uploads.min_files', 2), 'photos_max' => config('photo_uploads.max_files', 3)],
            'product' => ProductPersonalizationSchema::forProduct(Product::findOrFail($item['id'])),
            'package' => app(PackagePersonalizationService::class)->schema(PricingPackage::findOrFail($item['id'])),
        };
    }
}
